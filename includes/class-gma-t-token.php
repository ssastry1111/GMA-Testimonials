<?php
if (!defined('ABSPATH')) exit;

/**
 * Upload tokens.
 *
 * Reminder emails previously pointed at a page that required the recipient to
 * be logged in. Members do have accounts (created silently when they submitted
 * the testimonial), but most do not know that, so the link was a dead end -
 * and with PMPro restricting the page it read as "buy a membership".
 *
 * A token lets one specific testimonial receive one photo, with no login:
 *
 *   /upload-testimonial-photo/?gma_photo_token=<32 hex characters>
 *
 * Properties:
 *   - 128 bits from random_bytes, so it cannot be guessed or enumerated.
 *   - Scoped to a single testimonial. It authorises one action - replacing
 *     that testimonial's photo - and nothing else. It is not a login.
 *   - Expires (default 30 days), and is cleared once a photo is uploaded.
 *   - Reusable until then, so a member who starts on a phone and finishes on
 *     a laptop is not locked out by a single-use rule.
 */
class GMA_T_Token {

    const META_TOKEN   = '_gma_t_upload_token';
    const META_EXPIRES = '_gma_t_upload_token_expires';
    const QUERY_VAR    = 'gma_photo_token';

    /** Token lifetime in seconds, from the configured number of days. */
    private static function lifetime() {
        $days = (int) gma_t_opt('token_expiry_days');
        if ($days < 1)   $days = 30;
        if ($days > 365) $days = 365;
        return $days * DAY_IN_SECONDS;
    }

    /**
     * Current token for a testimonial, generating or renewing when needed.
     *
     * An existing token is kept (so a second reminder carries the same link as
     * the first) unless it has expired or is close to expiring, in which case
     * the member would otherwise receive a link that dies before they use it.
     *
     * @param int $post_id Testimonial post ID.
     * @return string 32 hex characters, or '' on failure.
     */
    public static function get_or_create($post_id) {

        $post_id = (int) $post_id;
        if (!$post_id) return '';

        $token   = (string) get_post_meta($post_id, self::META_TOKEN, true);
        $expires = (int) get_post_meta($post_id, self::META_EXPIRES, true);

        // Renew if missing, malformed, expired, or expiring within a week -
        // a reminder should never carry a link that dies before it is read.
        $stale = ($expires > 0 && $expires < (time() + WEEK_IN_SECONDS));

        if ($token === '' || strlen($token) !== 32 || !ctype_xdigit($token) || $stale) {
            $token = self::generate();
            if ($token === '') return '';
            update_post_meta($post_id, self::META_TOKEN, $token);
            update_post_meta($post_id, self::META_EXPIRES, time() + self::lifetime());
        }

        return $token;
    }

    /** 32 hex characters of cryptographic randomness. */
    private static function generate() {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $e) {
            // Extremely unlikely, but never fall back to a weak source.
            return '';
        }
    }

    /**
     * Resolve a token to its testimonial.
     *
     * @param string $token Raw token from the URL.
     * @return int Post ID, or 0 when the token is unknown or expired.
     */
    public static function resolve($token) {

        $token = strtolower(trim((string) $token));

        // Reject anything that is not shaped like a token before touching the
        // database, so a malformed query string cannot drive a meta query.
        if (strlen($token) !== 32 || !ctype_xdigit($token)) return 0;

        $posts = get_posts([
            'post_type'        => GMA_T_PT,
            'post_status'      => ['publish', 'pending', 'draft', 'private'],
            'posts_per_page'   => 1,
            'suppress_filters' => false,
            'meta_query'       => [[
                'key'     => self::META_TOKEN,
                'value'   => $token,
                'compare' => '=',
            ]],
        ]);

        if (empty($posts)) return 0;

        $post_id = (int) $posts[0]->ID;
        $stored  = (string) get_post_meta($post_id, self::META_TOKEN, true);

        // Constant-time comparison, so a near-miss cannot be timed.
        if (!hash_equals($stored, $token)) return 0;

        $expires = (int) get_post_meta($post_id, self::META_EXPIRES, true);
        if ($expires > 0 && $expires < time()) return 0;

        return $post_id;
    }

    /** Invalidate a testimonial's token, after a successful upload. */
    public static function clear($post_id) {
        delete_post_meta((int) $post_id, self::META_TOKEN);
        delete_post_meta((int) $post_id, self::META_EXPIRES);
    }

    /**
     * Full upload URL carrying a testimonial's token.
     *
     * Falls back to the bare upload page when tokens are switched off or a
     * token could not be produced - the page still works for a logged-in
     * member, so the link is never broken, only less convenient.
     */
    public static function upload_url($post_id) {

        $base = trim((string) gma_t_opt('upload_url'));
        if ($base === '') return '';

        if (!gma_t_opt('tokens_enabled')) return $base;

        $token = self::get_or_create($post_id);
        if ($token === '') return $base;

        return add_query_arg(self::QUERY_VAR, $token, $base);
    }

    /** Token supplied on the current request, if any. */
    public static function from_request() {
        if (empty($_GET[self::QUERY_VAR])) return '';
        return sanitize_text_field(wp_unslash($_GET[self::QUERY_VAR]));
    }
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Photo Uploader module.
 *
 * Shortcode: [gma_testimonial_photo_upload]
 *
 * Carries forward the v1.1 hardening of the standalone plugin: real error
 * messages from WP_Error, detection of uploads that exceed post_max_size
 * (where PHP discards the request and the form would otherwise just reload),
 * a CSRF nonce, explicit size and type validation with an iPhone HEIC hint,
 * and cleanup of the previous photo when one is replaced.
 */
class GMA_T_Uploader {

    const MARKER_META = '_gma_photo_upload';

    private $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private $label   = 'JPG, PNG, GIF or WebP';

    public function __construct() {
        add_shortcode('gma_testimonial_photo_upload', [$this, 'shortcode']);
    }

    public function shortcode() {
        if (!gma_t_opt('uploader_enabled')) return '';

        $max_mb    = (int) gma_t_opt('upload_max_mb');
        if ($max_mb < 1) $max_mb = 5;
        $max_bytes = $max_mb * 1024 * 1024;

        $post_id    = 0;
        $via_token  = false;
        $token      = '';

        // ── 1. A token from the reminder email, in the URL or carried through
        //       the form post. This authorises one testimonial's photo and
        //       nothing else, so no login is required.
        if (gma_t_opt('tokens_enabled')) {

            $token = GMA_T_Token::from_request();

            if ($token === '' && !empty($_POST['gma_upload_token'])) {
                $token = sanitize_text_field(wp_unslash($_POST['gma_upload_token']));
            }

            if ($token !== '') {
                $post_id = GMA_T_Token::resolve($token);

                if (!$post_id) {
                    return '<p><strong>This upload link has expired.</strong><br><br>'
                         . 'Links stay valid for a limited time. Please reply to the reminder email '
                         . 'and we will send you a fresh one.</p>';
                }

                $via_token = true;
            }
        }

        // ── 2. No token: fall back to matching the logged-in user by email.
        if (!$post_id) {

            if (!is_user_logged_in()) {
                return '<p><strong>Please open this page using the link in your reminder email.</strong><br><br>'
                     . 'That link takes you straight to your own testimonial. '
                     . 'If you already have an account here you can also log in instead.</p>';
            }

            $user  = wp_get_current_user();
            $posts = get_posts([
                'post_type'      => GMA_T_PT,
                'posts_per_page' => 1,
                'post_status'    => ['publish', 'pending', 'draft'],
                'meta_query'     => [[
                    'key'     => gma_t_opt('meta_email'),
                    'value'   => $user->user_email,
                    'compare' => '=',
                ]],
            ]);

            if (empty($posts)) {
                return '<p>No testimonial found for your account.</p>';
            }

            $post_id = (int) $posts[0]->ID;
        }

        $error = '';

        // Oversized POST: PHP throws the whole request away, so $_POST and
        // $_FILES arrive empty even though the browser sent content.
        if (
            isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
            && empty($_POST) && empty($_FILES)
            && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0
        ) {
            $error = 'Your photo is too large for the server to accept. Please resize it to under ' . $max_mb . ' MB and try again.';
        }

        if (!$error && isset($_POST['gma_upload_submit'])) {

            // A token is itself proof the request is authorised, and a
            // logged-out visitor has no reliable nonce session, so the nonce
            // is only required on the logged-in path.
            $nonce_ok = $via_token || (
                isset($_POST['gma_upload_nonce'])
                && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gma_upload_nonce'])), 'gma_upload_photo_' . $post_id)
            );

            if (!$nonce_ok) {
                $error = 'Your session expired. Please try uploading again.';

            } elseif (empty($_FILES['gma_photo']['name'])) {
                $error = 'Please choose a photo before clicking Upload.';

            } else {
                $file = $_FILES['gma_photo'];

                if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
                    switch ((int) $file['error']) {
                        case UPLOAD_ERR_INI_SIZE:
                        case UPLOAD_ERR_FORM_SIZE:
                            $error = 'Your photo is too large. Please resize it to under ' . $max_mb . ' MB and try again.';
                            break;
                        case UPLOAD_ERR_PARTIAL:
                            $error = 'The upload was interrupted. Please check your connection and try again.';
                            break;
                        case UPLOAD_ERR_NO_FILE:
                            $error = 'Please choose a photo before clicking Upload.';
                            break;
                        default:
                            $error = 'The upload failed (code ' . (int) $file['error'] . '). Please try again or contact support.';
                    }

                } elseif ((int) $file['size'] > $max_bytes) {
                    $mb    = round($file['size'] / 1048576, 1);
                    $error = 'Your photo is ' . $mb . ' MB, which is over the ' . $max_mb . ' MB limit. Please resize it and try again.';

                } else {
                    // Check the real file content, not just the extension.
                    $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
                    if (empty($check['type']) || !in_array($check['type'], $this->allowed, true)) {
                        $error = 'That file type is not supported. Please upload a ' . $this->label . ' image. '
                               . 'Tip: iPhone photos saved as HEIC need converting to JPG first, or set your iPhone camera format to "Most Compatible".';
                    } else {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                        require_once ABSPATH . 'wp-admin/includes/media.php';
                        require_once ABSPATH . 'wp-admin/includes/image.php';

                        $old_thumb_id  = get_post_thumbnail_id($post_id);
                        $attachment_id = media_handle_upload('gma_photo', 0);

                        if (is_wp_error($attachment_id)) {
                            $error = 'Upload failed: ' . $attachment_id->get_error_message() . ' Please try again or contact support.';
                        } else {
                            update_post_meta($attachment_id, self::MARKER_META, 1);
                            set_post_thumbnail($post_id, $attachment_id);

                            // Remove the old photo only if this plugin created it.
                            if (
                                $old_thumb_id
                                && (int) $old_thumb_id !== (int) $attachment_id
                                && get_post_meta($old_thumb_id, self::MARKER_META, true)
                            ) {
                                wp_delete_attachment($old_thumb_id, true);
                            }

                            // The link has done its job; retire it.
                            if ($via_token) {
                                GMA_T_Token::clear($post_id);
                            }

                            $url = esc_url(gma_t_opt('testimonials_url'));
                            return '<p><strong>Thank you! Your photo has been uploaded successfully.</strong><br><br>'
                                 . 'You can see your testimonial at &rarr; '
                                 . '<a href="' . $url . '" target="_blank" rel="noopener">Testimonials</a></p>';
                        }
                    }
                }
            }
        }

        ob_start();

        if ($error) {
            echo '<div style="margin-bottom:20px;padding:12px 16px;border-left:4px solid #d63638;background:#fcf0f1;">';
            echo '<p style="margin:0;"><strong>' . esc_html($error) . '</strong></p>';
            echo '</div>';
        }

        if ($via_token) {
            $who = get_post_meta($post_id, gma_t_opt('meta_name'), true);
            if (!$who) $who = get_the_title($post_id);
            if ($who) {
                $parts = explode(' ', trim($who));
                echo '<p>Hi ' . esc_html($parts[0]) . ', this will add your photo to your testimonial.</p>';
            }
        }

        if (has_post_thumbnail($post_id)) {
            echo '<div style="margin-bottom:20px;">';
            echo '<p><strong>Your testimonial already has this photo.</strong> Uploading a new one will replace it.</p>';
            echo get_the_post_thumbnail($post_id, 'medium');
            echo '</div>';
        }
        ?>
        <form method="post" enctype="multipart/form-data">
            <p><strong>Upload your photo</strong></p>
            <p style="color:#666;font-size:0.9em;">
                Accepted formats: <?php echo esc_html($this->label); ?> &middot;
                Maximum size: <?php echo (int) $max_mb; ?> MB
            </p>
            <input type="hidden" name="gma_upload_nonce" value="<?php echo esc_attr(wp_create_nonce('gma_upload_photo_' . $post_id)); ?>">
            <?php if ($via_token) : ?>
                <input type="hidden" name="gma_upload_token" value="<?php echo esc_attr($token); ?>">
            <?php endif; ?>
            <input type="file" name="gma_photo" accept="image/jpeg,image/png,image/gif,image/webp" required>
            <br><br>
            <button type="submit" name="gma_upload_submit">Upload Photo</button>
        </form>
        <?php
        return ob_get_clean();
    }
}

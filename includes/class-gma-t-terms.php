<?php
if (!defined('ABSPATH')) exit;

/**
 * Term assignment module.
 *
 * WPForms Post Submissions creates the testimonial post but does not assign
 * the Mentor and Service values to our custom taxonomies, so new testimonials
 * arrive with no terms: no badges on the card and invisible to the filter
 * dropdowns.
 *
 * This module closes that gap. On every submission of the configured form it
 * reads the two chosen values and assigns them as terms, creating the term if
 * it does not exist yet. It also provides a backfill tool that walks existing
 * testimonials and assigns terms from their stored entry data.
 */
class GMA_T_Terms {

    const BACKFILL_META = '_gma_t_terms_done';

    public function __construct() {
        // Runs after Post Submissions has created the post.
        add_action('wpforms_process_complete', [$this, 'assign_from_submission'], 30, 4);
        add_action('admin_menu', [$this, 'menu'], 25);
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    /**
     * Assign one value to one taxonomy, creating the term when needed.
     *
     * @param int    $post_id  Testimonial post ID.
     * @param string $taxonomy Taxonomy slug.
     * @param string $value    Submitted label, e.g. "Rakhee Gokhale".
     * @return bool True when a term was assigned.
     */
    private function set_term($post_id, $taxonomy, $value) {
        $value = trim((string) $value);
        if ($value === '' || !$post_id) return false;

        // Match an existing term by name first so we never create a duplicate
        // that differs only by case or slug.
        $term = get_term_by('name', $value, $taxonomy);

        if (!$term) {
            $created = wp_insert_term($value, $taxonomy);
            if (is_wp_error($created)) {
                // A race or an existing-term error still gives us the term ID.
                $existing = $created->get_error_data('term_exists');
                if (!$existing) return false;
                $term_id = (int) $existing;
            } else {
                $term_id = (int) $created['term_id'];
            }
        } else {
            $term_id = (int) $term->term_id;
        }

        $result = wp_set_object_terms($post_id, [$term_id], $taxonomy, false);
        return !is_wp_error($result);
    }

    /**
     * Find the testimonial created for a given email address.
     *
     * wpforms_process_complete does not hand over the post ID that Post
     * Submissions created, so we match on the email meta and take the newest,
     * which is the one just written.
     */
    private function find_testimonial_by_email($email) {
        if (!$email) return 0;

        $posts = get_posts([
            'post_type'        => GMA_T_PT,
            'post_status'      => ['publish', 'pending', 'draft', 'private'],
            'posts_per_page'   => 1,
            'orderby'          => 'ID',
            'order'            => 'DESC',
            'suppress_filters' => false,
            'meta_query'       => [[
                'key'     => gma_t_opt('meta_email'),
                'value'   => $email,
                'compare' => '=',
            ]],
        ]);

        return !empty($posts) ? (int) $posts[0]->ID : 0;
    }

    /** Pull a plain string value out of a WPForms processed field. */
    private function field_value($fields, $field_id) {
        $field_id = (int) $field_id;
        if (empty($fields[$field_id])) return '';

        $field = $fields[$field_id];
        if (isset($field['value']) && is_scalar($field['value'])) {
            return trim((string) $field['value']);
        }
        return '';
    }

    /** Find the email address in a submission, for locating the post. */
    private function submission_email($fields) {
        // Prefer a field explicitly typed as email.
        foreach ($fields as $field) {
            if (isset($field['type']) && $field['type'] === 'email' && !empty($field['value'])) {
                return sanitize_email($field['value']);
            }
        }
        // Otherwise take the first value that looks like one.
        foreach ($fields as $field) {
            if (!empty($field['value']) && is_scalar($field['value']) && is_email($field['value'])) {
                return sanitize_email($field['value']);
            }
        }
        return '';
    }

    /* ── Live assignment ─────────────────────────────────────────────────── */

    /**
     * @param array $fields    Sanitised field values.
     * @param array $entry     Raw $_POST.
     * @param array $form_data Form settings.
     * @param int   $entry_id  Stored entry ID.
     */
    public function assign_from_submission($fields, $entry, $form_data, $entry_id) {

        if (!gma_t_opt('terms_enabled')) return;

        if (absint($form_data['id']) !== (int) gma_t_opt('terms_form_id')) return;

        $mentor  = $this->field_value($fields, gma_t_opt('terms_mentor_field'));
        $service = $this->field_value($fields, gma_t_opt('terms_service_field'));

        if ($mentor === '' && $service === '') return;

        $email   = $this->submission_email($fields);
        $post_id = $this->find_testimonial_by_email($email);

        if (!$post_id) return;

        if ($mentor !== '')  $this->set_term($post_id, GMA_T_TAX_MENTOR,  $mentor);
        if ($service !== '') $this->set_term($post_id, GMA_T_TAX_SERVICE, $service);

        update_post_meta($post_id, self::BACKFILL_META, 1);
    }

    /* ── Backfill ────────────────────────────────────────────────────────── */

    /**
     * Walk stored WPForms entries and assign terms to the matching
     * testimonials. Safe to re-run: existing terms are simply overwritten with
     * the same values.
     *
     * @param bool $only_missing Skip testimonials that already have both terms.
     * @return array Counts for the admin notice.
     */
    public function backfill($only_missing = true) {

        $stats = ['scanned' => 0, 'updated' => 0, 'skipped' => 0, 'no_entry' => 0];

        if (!function_exists('wpforms')) return $stats;

        $form_id      = (int) gma_t_opt('terms_form_id');
        $mentor_field = (int) gma_t_opt('terms_mentor_field');
        $service_field= (int) gma_t_opt('terms_service_field');

        $posts = get_posts([
            'post_type'      => GMA_T_PT,
            'post_status'    => ['publish', 'pending', 'draft', 'private'],
            'posts_per_page' => -1,
        ]);

        foreach ($posts as $post) {

            $stats['scanned']++;

            $has_mentor  = !empty(get_the_terms($post->ID, GMA_T_TAX_MENTOR));
            $has_service = !empty(get_the_terms($post->ID, GMA_T_TAX_SERVICE));

            if ($only_missing && $has_mentor && $has_service) {
                $stats['skipped']++;
                continue;
            }

            $email = get_post_meta($post->ID, gma_t_opt('meta_email'), true);
            if (!$email) {
                $stats['no_entry']++;
                continue;
            }

            $entry = $this->find_entry_by_email($form_id, $email);
            if (!$entry) {
                $stats['no_entry']++;
                continue;
            }

            $mentor  = isset($entry[$mentor_field]['value'])  ? trim((string) $entry[$mentor_field]['value'])  : '';
            $service = isset($entry[$service_field]['value']) ? trim((string) $entry[$service_field]['value']) : '';

            $did = false;
            if ($mentor !== ''  && (!$has_mentor  || !$only_missing)) {
                $did = $this->set_term($post->ID, GMA_T_TAX_MENTOR, $mentor) || $did;
            }
            if ($service !== '' && (!$has_service || !$only_missing)) {
                $did = $this->set_term($post->ID, GMA_T_TAX_SERVICE, $service) || $did;
            }

            if ($did) {
                $stats['updated']++;
                update_post_meta($post->ID, self::BACKFILL_META, 1);
            } else {
                $stats['no_entry']++;
            }
        }

        return $stats;
    }

    /** Look up the newest stored entry for an email on the configured form. */
    private function find_entry_by_email($form_id, $email) {
        global $wpdb;

        $table = $wpdb->prefix . 'wpforms_entries';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return null;
        }

        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT fields FROM {$table}
              WHERE form_id = %d AND user_uuid IS NOT NULL AND fields LIKE %s
              ORDER BY entry_id DESC LIMIT 1",
            $form_id,
            '%' . $wpdb->esc_like($email) . '%'
        ));

        if (!$row) return null;

        $decoded = json_decode($row, true);
        return is_array($decoded) ? $decoded : null;
    }

    /* ── Admin page ──────────────────────────────────────────────────────── */

    public function menu() {
        add_submenu_page(
            'edit.php?post_type=' . GMA_T_PT,
            'Assign Terms',
            'Assign Terms',
            'manage_options',
            'gma-t-terms',
            [$this, 'page']
        );
    }

    public function page() {
        if (!current_user_can('manage_options')) return;

        $notice = '';

        if (!empty($_POST['gma_t_terms_action']) && check_admin_referer('gma_t_terms')) {
            $only_missing = (sanitize_key($_POST['gma_t_terms_action']) !== 'backfill_all');
            $stats = $this->backfill($only_missing);
            $notice = sprintf(
                'Scanned %d testimonials. Updated %d, already had terms %d, no matching entry data %d.',
                $stats['scanned'], $stats['updated'], $stats['skipped'], $stats['no_entry']
            );
        }

        // Current coverage
        $total = (int) wp_count_posts(GMA_T_PT)->publish;
        $with_mentor = 0;
        $posts = get_posts(['post_type' => GMA_T_PT, 'posts_per_page' => -1, 'fields' => 'ids']);
        foreach ($posts as $pid) {
            if (!empty(get_the_terms($pid, GMA_T_TAX_MENTOR))) $with_mentor++;
        }

        echo '<div class="wrap">';
        echo '<h1>Assign Terms <span style="font-size:0.6em;color:#666;">v' . esc_html(GMA_T_VERSION) . '</span></h1>';

        if ($notice) echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';

        echo '<p>New submissions are assigned Mentor and Service terms automatically. '
           . 'Use the tools below to fill in testimonials that pre-date that, or after changing the field mapping in '
           . '<a href="' . esc_url(admin_url('edit.php?post_type=' . GMA_T_PT . '&page=gma-t-settings')) . '">Settings</a>.</p>';

        echo '<table class="widefat striped" style="max-width:620px;margin-bottom:20px;"><tbody>';
        echo '<tr><td style="width:280px;"><strong>Published testimonials</strong></td><td>' . (int) $total . '</td></tr>';
        echo '<tr><td><strong>With a Mentor term</strong></td><td>' . (int) $with_mentor . '</td></tr>';
        echo '<tr><td><strong>Source form</strong></td><td>ID ' . (int) gma_t_opt('terms_form_id')
           . ' (mentor field ' . (int) gma_t_opt('terms_mentor_field')
           . ', service field ' . (int) gma_t_opt('terms_service_field') . ')</td></tr>';
        echo '</tbody></table>';

        echo '<form method="post" style="display:flex;gap:10px;flex-wrap:wrap;">';
        wp_nonce_field('gma_t_terms');
        echo '<button class="button button-primary" name="gma_t_terms_action" value="backfill_missing">Assign terms where missing</button>';
        echo '<button class="button" name="gma_t_terms_action" value="backfill_all" '
           . 'onclick="return confirm(\'Re-assign terms on every testimonial from its form entry? Existing terms will be overwritten.\');">'
           . 'Re-assign on all testimonials</button>';
        echo '</form>';

        echo '<p class="description" style="margin-top:16px;max-width:620px;">'
           . 'Terms are matched to the form entry by email address. Testimonials with no matching entry '
           . '(added by hand, or submitted before the mentor and service fields existed) are left alone &mdash; '
           . 'set those from the Testimonials list with Bulk edit.</p>';

        echo '</div>';
    }
}

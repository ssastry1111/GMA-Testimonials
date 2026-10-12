<?php
if (!defined('ABSPATH')) exit;

/**
 * One settings page for every module, under Testimonials.
 */
class GMA_T_Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'menu'], 15);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu() {
        add_submenu_page(
            'edit.php?post_type=' . GMA_T_PT,
            'Testimonials Settings',
            'Settings',
            'manage_options',
            'gma-t-settings',
            [$this, 'page']
        );
    }

    public function assets($hook) {
        if (strpos($hook, 'gma-t-settings') === false) return;
        wp_enqueue_media();
    }

    /** Sanitise the posted form into the settings array. */
    private function save() {
        $d   = gma_t_defaults();
        $in  = isset($_POST['gma_t']) && is_array($_POST['gma_t']) ? wp_unslash($_POST['gma_t']) : [];
        $out = [];

        $ints = ['columns' => [1, 4], 'per_page' => [1, 100], 'excerpt_words' => [5, 300],
                 'max' => [1, 20], 'upload_max_mb' => [1, 64], 'fallback_image' => [0, PHP_INT_MAX]];
        foreach ($ints as $key => $range) {
            $val = isset($in[$key]) ? (int) $in[$key] : (int) $d[$key];
            $out[$key] = max($range[0], min($range[1], $val));
        }

        $checkboxes = ['show_photo', 'show_date', 'show_rating', 'show_badges', 'equal_heights',
                       'enable_filters', 'reminder_enabled', 'uploader_enabled',
                       'show_pagination', 'show_count', 'terms_enabled', 'single_urls',
                       'tokens_enabled'];
        foreach ($checkboxes as $key) {
            $out[$key] = !empty($in[$key]) ? 1 : 0;
        }

        $out['order'] = in_array(isset($in['order']) ? $in['order'] : '', ['newest', 'oldest', 'random', 'menu'], true)
            ? $in['order'] : $d['order'];

        $out['pagination_pos'] = in_array(isset($in['pagination_pos']) ? $in['pagination_pos'] : '', ['after', 'both'], true)
            ? $in['pagination_pos'] : $d['pagination_pos'];

        $out['pagination_style'] = in_array(isset($in['pagination_style']) ? $in['pagination_style'] : '', ['numbered', 'prevnext', 'loadmore'], true)
            ? $in['pagination_style'] : $d['pagination_style'];

        $out['fallback_shape'] = in_array(isset($in['fallback_shape']) ? $in['fallback_shape'] : '', ['circle', 'square'], true)
            ? $in['fallback_shape'] : $d['fallback_shape'];

        $out['token_expiry_days'] = max(1, min(365, isset($in['token_expiry_days']) ? (int) $in['token_expiry_days'] : (int) $d['token_expiry_days']));

        foreach (['terms_form_id', 'terms_mentor_field', 'terms_service_field'] as $key) {
            $out[$key] = isset($in[$key]) ? max(0, (int) $in[$key]) : (int) $d[$key];
        }

        $out['photo_size'] = in_array(isset($in['photo_size']) ? $in['photo_size'] : '', ['thumbnail', 'medium', 'large', 'full'], true)
            ? $in['photo_size'] : $d['photo_size'];

        $texts = ['read_more_text', 'read_less_text', 'mentor_label', 'service_label', 'all_label',
                  'date_format', 'subject', 'from_name', 'time', 'count_template'];
        foreach ($texts as $key) {
            $out[$key] = isset($in[$key]) ? sanitize_text_field($in[$key]) : $d[$key];
        }

        $keys = ['meta_name', 'meta_email', 'meta_rating'];
        foreach ($keys as $key) {
            $out[$key] = isset($in[$key]) ? sanitize_key($in[$key]) : $d[$key];
        }

        $out['from_email'] = isset($in['from_email']) ? sanitize_email($in['from_email']) : $d['from_email'];
        $out['test_email'] = isset($in['test_email']) ? sanitize_email($in['test_email']) : $d['test_email'];
        $out['cc_emails']  = isset($in['cc_emails'])  ? sanitize_text_field($in['cc_emails'])  : $d['cc_emails'];
        $out['testimonials_url'] = isset($in['testimonials_url']) ? esc_url_raw($in['testimonials_url']) : $d['testimonials_url'];
        $out['upload_url']       = isset($in['upload_url'])       ? esc_url_raw($in['upload_url'])       : $d['upload_url'];
        $out['body'] = isset($in['body']) ? wp_kses_post($in['body']) : $d['body'];

        update_option(GMA_T_SETTINGS, $out);

        // Time or enabled state may have changed - re-queue the reminder.
        GMA_T_Reminder::schedule_next_static();

        return true;
    }

    private function checkbox($key, $label, $help = '') {
        $on = gma_t_opt($key) ? ' checked' : '';
        echo '<label style="display:block;margin:6px 0;">';
        echo '<input type="checkbox" name="gma_t[' . esc_attr($key) . ']" value="1"' . $on . '> ';
        echo esc_html($label);
        if ($help) echo ' <span style="color:#666;">&mdash; ' . esc_html($help) . '</span>';
        echo '</label>';
    }

    private function text($key, $label, $width = 320, $type = 'text') {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        echo '<input type="' . esc_attr($type) . '" name="gma_t[' . esc_attr($key) . ']" '
           . 'value="' . esc_attr(gma_t_opt($key)) . '" style="width:' . (int) $width . 'px;">';
        echo '</td></tr>';
    }

    private function number($key, $label, $min = 0, $max = 100) {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        echo '<input type="number" min="' . (int) $min . '" max="' . (int) $max . '" '
           . 'name="gma_t[' . esc_attr($key) . ']" value="' . esc_attr((int) gma_t_opt($key)) . '" style="width:90px;">';
        echo '</td></tr>';
    }

    private function select($key, $label, $choices) {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        echo '<select name="gma_t[' . esc_attr($key) . ']">';
        $current = gma_t_opt($key);
        foreach ($choices as $value => $text) {
            echo '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select></td></tr>';
    }

    public function page() {
        if (!current_user_can('manage_options')) return;

        $saved = false;
        if (!empty($_POST['gma_t_save']) && check_admin_referer('gma_t_settings')) {
            $saved = $this->save();
        }

        $meta_keys = GMA_T_CPT::available_meta_keys();
        $meta_choices = ['' => '(none)'];
        foreach ($meta_keys as $k) $meta_choices[$k] = $k;

        $fallback_id  = (int) gma_t_opt('fallback_image');
        $fallback_url = $fallback_id ? wp_get_attachment_image_url($fallback_id, 'thumbnail') : '';

        echo '<div class="wrap">';
        echo '<h1>Testimonials Settings <span style="font-size:0.6em;color:#666;">v' . esc_html(GMA_T_VERSION) . '</span></h1>';

        if ($saved) echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';

        echo '<p>Place <code>[gma_testimonials]</code> on any page for the grid. '
           . 'Attributes override these defaults, for example '
           . '<code>[gma_testimonials mentor="nitin-naik" columns="2"]</code> on a mentor profile page.</p>';

        echo '<form method="post">';
        wp_nonce_field('gma_t_settings');

        // ── Display ──────────────────────────────────────────────────────
        echo '<h2 class="title">Grid display</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->number('columns', 'Columns', 1, 4);
        $this->number('per_page', 'Testimonials per page', 1, 100);
        $this->select('order', 'Order', [
            'newest' => 'Newest first', 'oldest' => 'Oldest first',
            'random' => 'Random',       'menu'   => 'Menu order',
        ]);
        $this->number('excerpt_words', 'Excerpt length (words)', 5, 300);
        $this->text('read_more_text', 'Read more link text', 200);
        $this->text('read_less_text', 'Show less link text', 200);
        $this->text('date_format', 'Date format', 200);
        $this->select('photo_size', 'Photo size', [
            'thumbnail' => 'Thumbnail (150x150)', 'medium' => 'Medium', 'large' => 'Large', 'full' => 'Full',
        ]);

        echo '<tr><th scope="row">Default photo</th><td>';
        echo '<div id="gma-t-fallback-preview" style="margin-bottom:8px;">';
        if ($fallback_url) echo '<img src="' . esc_url($fallback_url) . '" style="max-width:120px;height:auto;border:1px solid #ddd;border-radius:4px;">';
        echo '</div>';
        echo '<input type="hidden" id="gma_t_fallback_image" name="gma_t[fallback_image]" value="' . esc_attr($fallback_id) . '">';
        echo '<button type="button" class="button" id="gma-t-pick-image">Choose image</button> ';
        echo '<button type="button" class="button" id="gma-t-clear-image">Remove</button>';
        echo '<p class="description">Shown when a testimonial has no photo of its own. '
           . 'This is display only &mdash; it is never saved as a featured image, so those testimonials still receive photo reminders.</p>';
        echo '</td></tr>';

        $this->select('fallback_shape', 'Default photo shape', [
            'circle' => 'Circle (matches real headshots)',
            'square' => 'Square (better for wide graphics)',
        ]);

        echo '<tr><th scope="row">Card options</th><td>';
        $this->checkbox('show_photo',    'Show photo');
        $this->checkbox('show_date',     'Show date');
        $this->checkbox('show_rating',   'Show star rating', 'requires a rating field below');
        $this->checkbox('show_badges',   'Show mentor and service badges');
        $this->checkbox('equal_heights', 'Equal card heights', 'stops one long testimonial stretching its row');
        echo '</td></tr>';
        echo '</tbody></table>';

        // ── Pagination ───────────────────────────────────────────────────
        echo '<h2 class="title">Pagination</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Paginate</th><td>';
        $this->checkbox('show_pagination', 'Split testimonials across pages', 'off shows every testimonial on one page');
        echo '</td></tr>';
        $this->select('pagination_style', 'Style', [
            'numbered' => 'Numbered pages (1 2 3 ...)',
            'prevnext' => 'Previous / Next only',
            'loadmore' => 'Load more button (appends)',
        ]);
        $this->select('pagination_pos', 'Position', [
            'after' => 'Below the grid only',
            'both'  => 'Above and below the grid',
        ]);
        echo '<tr><th scope="row">Result count</th><td>';
        $this->checkbox('show_count', 'Show a result count above the grid');
        echo '</td></tr>';
        $this->text('count_template', 'Count text', 420);
        echo '<p class="description" style="margin-left:0;">Placeholders: <code>{from}</code>, <code>{to}</code>, <code>{total}</code>. '
           . 'Load more always renders below the grid, whatever the position setting.</p>';
        echo '</tbody></table>';

        // ── Filters ──────────────────────────────────────────────────────
        echo '<h2 class="title">Visitor filters</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Filter bar</th><td>';
        $this->checkbox('enable_filters', 'Show mentor and service dropdowns above the grid');
        echo '<p class="description">Dropdowns only appear for taxonomies that have terms in use. '
           . 'Assign terms under <a href="' . esc_url(admin_url('edit-tags.php?taxonomy=' . GMA_T_TAX_MENTOR . '&post_type=' . GMA_T_PT)) . '">Mentors</a> and '
           . '<a href="' . esc_url(admin_url('edit-tags.php?taxonomy=' . GMA_T_TAX_SERVICE . '&post_type=' . GMA_T_PT)) . '">Services</a>.</p>';
        echo '</td></tr>';
        $this->text('mentor_label', 'Mentor dropdown label', 220);
        $this->text('service_label', 'Service dropdown label', 220);
        $this->text('all_label', '"All" option text', 220);
        echo '</tbody></table>';

        $this->link_builder();

        // ── Field mapping ────────────────────────────────────────────────
        echo '<h2 class="title">Field mapping</h2>';
        echo '<p class="description" style="margin-bottom:10px;">Detected from the meta keys actually present on your testimonials.</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->select('meta_name',   'Name field',        $meta_choices);
        $this->select('meta_email',  'Email field',       $meta_choices);
        $this->select('meta_rating', 'Star rating field', $meta_choices);
        echo '</tbody></table>';

        // ── Term assignment ──────────────────────────────────────────────
        echo '<h2 class="title">Mentor and service terms</h2>';
        echo '<p class="description" style="margin-bottom:10px;">WPForms creates the testimonial but does not assign these '
           . 'taxonomies itself, so the plugin does it. Run the '
           . '<a href="' . esc_url(admin_url('edit.php?post_type=' . GMA_T_PT . '&page=gma-t-terms')) . '">Assign Terms</a> '
           . 'tool to fill in older testimonials.</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Assignment</th><td>';
        $this->checkbox('terms_enabled', 'Assign Mentor and Service terms on each new submission');
        echo '</td></tr>';
        $this->number('terms_form_id', 'Feedback form ID', 0, 999999);
        $this->number('terms_mentor_field', 'Mentor field ID', 0, 9999);
        $this->number('terms_service_field', 'Service field ID', 0, 9999);
        echo '<tr><td colspan="2"><p class="description">Field IDs come from the WPForms builder &mdash; click a field and read the ID under its label.</p></td></tr>';
        echo '<tr><th scope="row">Single testimonial pages</th><td>';
        $this->checkbox('single_urls', 'Give each testimonial its own public URL', 'off is recommended; stops Yoast counting them as orphaned content');
        echo '<p class="description">Changing this needs permalinks flushing &mdash; visit Settings &rarr; Permalinks and click Save once.</p>';
        echo '</td></tr>';
        echo '</tbody></table>';

        // ── Reminder ─────────────────────────────────────────────────────
        echo '<h2 class="title">Photo reminder emails</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Module</th><td>';
        $this->checkbox('reminder_enabled', 'Send automatic photo reminders');
        echo '</td></tr>';
        $this->text('time', 'Daily send time (IST)', 120, 'time');
        $this->number('max', 'Maximum reminders per person', 1, 20);
        $this->text('from_name', 'From name', 320);
        $this->text('from_email', 'From email', 320, 'email');
        $this->text('cc_emails', 'Cc (comma separated)', 420);
        $this->text('test_email', 'Test email recipient', 320, 'email');
        $this->text('subject', 'Subject', 520);
        echo '<tr><th scope="row">Body</th><td>';
        echo '<textarea name="gma_t[body]" rows="10" style="width:100%;max-width:760px;">' . esc_textarea(gma_t_opt('body')) . '</textarea>';
        echo '<p class="description">HTML allowed. Placeholders: <code>{first_name}</code>, <code>{name}</code>, <code>{email}</code>, '
           . '<code>{upload_url}</code> (that recipient\'s own no-login upload link).</p>';
        echo '</td></tr>';
        echo '</tbody></table>';

        // ── Uploader ─────────────────────────────────────────────────────
        echo '<h2 class="title">Photo uploader</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Module</th><td>';
        $this->checkbox('uploader_enabled', 'Enable the [gma_testimonial_photo_upload] shortcode');
        echo '</td></tr>';
        $this->number('upload_max_mb', 'Maximum upload size (MB)', 1, 64);
        $this->text('upload_url', 'Upload page URL', 520, 'url');
        echo '<tr><td colspan="2"><p class="description">The page holding <code>[gma_testimonial_photo_upload]</code>. '
           . 'Reminder emails link here, so it must be reachable without a membership &mdash; '
           . 'remove any PMPro restriction from it, since the shortcode does its own access check.</p></td></tr>';
        $this->text('testimonials_url', 'Testimonials page URL', 520, 'url');
        echo '</tbody></table>';

        // ── Upload links ─────────────────────────────────────────────────
        echo '<h2 class="title">Upload links in reminder emails</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">No-login links</th><td>';
        $this->checkbox('tokens_enabled', 'Give each reminder a personal upload link', 'recipients add their photo without logging in');
        echo '<p class="description">Put <code>{upload_url}</code> in the email body above and each person receives a link to '
           . 'their own testimonial. The link authorises that one photo and nothing else &mdash; it is not a login &mdash; '
           . 'and it stops working once a photo is uploaded.<br>'
           . 'With this off, <code>{upload_url}</code> falls back to the plain page, which requires the member to log in.</p>';
        echo '</td></tr>';
        $this->number('token_expiry_days', 'Link valid for (days)', 1, 365);
        echo '</tbody></table>';

        submit_button('Save Settings', 'primary', 'gma_t_save');
        echo '</form>';
        ?>
        <script>
        jQuery(function ($) {
            var frame;
            $('#gma-t-pick-image').on('click', function (e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: 'Choose default photo', button: { text: 'Use this image' }, multiple: false });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    $('#gma_t_fallback_image').val(att.id);
                    var url = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                    $('#gma-t-fallback-preview').html('<img src="' + url + '" style="max-width:120px;height:auto;border:1px solid #ddd;border-radius:4px;">');
                });
                frame.open();
            });
            $('#gma-t-clear-image').on('click', function (e) {
                e.preventDefault();
                $('#gma_t_fallback_image').val('0');
                $('#gma-t-fallback-preview').empty();
            });
        });
        </script>
        <?php
        echo '</div>';
    }

    /**
     * Interactive builder for pre-filtered links.
     *
     * Everything happens in the browser - nothing here is saved. It exists so
     * that building a link for a mentor page never means looking up a slug by
     * hand or guessing the shortcode syntax.
     */
    private function link_builder() {

        $mentors  = get_terms(['taxonomy' => GMA_T_TAX_MENTOR,  'hide_empty' => false]);
        $services = get_terms(['taxonomy' => GMA_T_TAX_SERVICE, 'hide_empty' => false]);

        if (is_wp_error($mentors))  $mentors  = [];
        if (is_wp_error($services)) $services = [];

        $base = gma_t_opt('testimonials_url');

        echo '<h2 class="title">Link builder</h2>';
        echo '<p class="description" style="margin-bottom:12px;">Pick a mentor or service and copy the result onto that page. '
           . 'Nothing here is saved &mdash; it just writes the link for you.</p>';

        if (empty($mentors) && empty($services)) {
            echo '<p><em>No mentor or service terms exist yet. Add some under Testimonials &rarr; Mentors or Services first.</em></p>';
            return;
        }

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="gma-lb-mentor">Mentor</label></th><td>';
        echo '<select id="gma-lb-mentor"><option value="" data-name="">(none)</option>';
        foreach ($mentors as $t) {
            echo '<option value="' . esc_attr($t->slug) . '" data-name="' . esc_attr($t->name) . '">' . esc_html($t->name) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="gma-lb-service">Service</label></th><td>';
        echo '<select id="gma-lb-service"><option value="" data-name="">(none)</option>';
        foreach ($services as $t) {
            echo '<option value="' . esc_attr($t->slug) . '" data-name="' . esc_attr($t->name) . '">' . esc_html($t->name) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="gma-lb-text">Link text</label></th><td>';
        echo '<input type="text" id="gma-lb-text" value="Read his testimonials" style="width:320px;"></td></tr>';

        echo '<tr><th scope="row"><label for="gma-lb-style">Style</label></th><td>';
        echo '<select id="gma-lb-style">'
           . '<option value="outline">Outline button (default)</option>'
           . '<option value="solid">Solid button</option>'
           . '<option value="text">Bold text link</option>'
           . '</select>';
        echo ' <label style="margin-left:14px;"><input type="checkbox" id="gma-lb-arrow" checked> Show arrow</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Shortcode</th><td>';
        echo '<textarea id="gma-lb-shortcode" readonly rows="2" style="width:100%;max-width:620px;font-family:monospace;"></textarea>';
        echo '<br><button type="button" class="button gma-lb-copy" data-target="gma-lb-shortcode">Copy shortcode</button>';
        echo '<p class="description">Paste into a Shortcode block on the mentor page. A Custom HTML block will not run it.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Direct URL</th><td>';
        echo '<textarea id="gma-lb-url" readonly rows="2" style="width:100%;max-width:620px;font-family:monospace;"></textarea>';
        echo '<br><button type="button" class="button gma-lb-copy" data-target="gma-lb-url">Copy URL</button>';
        echo '<p class="description">For a theme or page-builder button, where you supply your own styling.</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        ?>
        <script>
        jQuery(function ($) {
            var base = <?php echo wp_json_encode($base); ?>;

            function build() {
                var mentorSel  = $('#gma-lb-mentor').find(':selected');
                var serviceSel = $('#gma-lb-service').find(':selected');
                var mentorSlug  = $('#gma-lb-mentor').val();
                var serviceSlug = $('#gma-lb-service').val();
                var text  = $('#gma-lb-text').val() || 'Read testimonials';
                var style = $('#gma-lb-style').val();
                var arrow = $('#gma-lb-arrow').is(':checked');

                if (!mentorSlug && !serviceSlug) {
                    $('#gma-lb-shortcode').val('Choose a mentor or a service above.');
                    $('#gma-lb-url').val('');
                    return;
                }

                // Shortcode uses term NAMES, so it survives a slug change.
                var sc = '[gma_testimonials_link';
                if (mentorSlug)  { sc += ' mentor="' + mentorSel.data('name') + '"'; }
                if (serviceSlug) { sc += ' service="' + serviceSel.data('name') + '"'; }
                sc += ' text="' + text + '"';
                if (style !== 'outline') { sc += ' style="' + style + '"'; }
                if (!arrow) { sc += ' arrow="no"'; }
                sc += ']';
                $('#gma-lb-shortcode').val(sc);

                // Direct URL uses slugs.
                var params = [];
                if (mentorSlug)  { params.push('mentor=' + encodeURIComponent(mentorSlug)); }
                if (serviceSlug) { params.push('service=' + encodeURIComponent(serviceSlug)); }
                var sep = (base.indexOf('?') === -1) ? '?' : '&';
                $('#gma-lb-url').val(base + sep + params.join('&'));
            }

            $('#gma-lb-mentor, #gma-lb-service, #gma-lb-text, #gma-lb-style, #gma-lb-arrow')
                .on('change keyup', build);

            $('.gma-lb-copy').on('click', function () {
                var el = document.getElementById($(this).data('target'));
                if (!el || !el.value) { return; }
                el.select();
                try { document.execCommand('copy'); } catch (e) {}
                var btn = $(this), original = btn.text();
                btn.text('Copied');
                setTimeout(function () { btn.text(original); }, 1400);
            });

            build();
        });
        </script>
        <?php
    }
}

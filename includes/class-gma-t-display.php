<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end grid, filter bar and AJAX paging.
 *
 * Shortcode: [gma_testimonials]
 * Attributes override saved settings, e.g.
 *   [gma_testimonials mentor="divya-sisodia" columns="2" per_page="6"]
 */
class GMA_T_Display {

    public function __construct() {
        add_shortcode('gma_testimonials', [$this, 'shortcode']);
        add_shortcode('gma_testimonials_link', [$this, 'link_shortcode']);
        add_action('wp_head', [$this, 'canonical_tag'], 1);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('wp_ajax_gma_t_filter',        [$this, 'ajax_filter']);
        add_action('wp_ajax_nopriv_gma_t_filter', [$this, 'ajax_filter']);
    }

    public function register_assets() {
        wp_register_style('gma-testimonials', GMA_T_URL . 'assets/gma-testimonials.css', [], GMA_T_VERSION);
        wp_register_script('gma-testimonials', GMA_T_URL . 'assets/gma-testimonials.js', [], GMA_T_VERSION, true);
        wp_localize_script('gma-testimonials', 'GMAT', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('gma_t_filter'),
        ]);
    }

    /** Resolve shortcode atts against saved settings. */
    private function resolve_atts($atts) {
        $atts = shortcode_atts([
            'columns'       => gma_t_opt('columns'),
            'per_page'      => gma_t_opt('per_page'),
            'order'         => gma_t_opt('order'),
            'excerpt_words' => gma_t_opt('excerpt_words'),
            'mentor'        => '',
            'service'       => '',
            'category'      => '',
            'search'        => '',
            'filters'       => gma_t_opt('enable_filters'),
            'show_search'   => gma_t_opt('enable_search'),
            'show_photo'    => gma_t_opt('show_photo'),
            'show_date'     => gma_t_opt('show_date'),
            'show_rating'   => gma_t_opt('show_rating'),
            'show_badges'   => gma_t_opt('show_badges'),
            'pagination'    => gma_t_opt('show_pagination'),
            'pagination_pos'=> gma_t_opt('pagination_pos'),
            'pagination_style' => gma_t_opt('pagination_style'),
            'show_count'    => gma_t_opt('show_count'),
        ], $atts, 'gma_testimonials');

        $atts['columns']       = max(1, min(4, (int) $atts['columns']));
        $atts['per_page']      = max(1, (int) $atts['per_page']);
        $atts['excerpt_words'] = max(5, (int) $atts['excerpt_words']);
        return $atts;
    }

    /**
     * Read a taxonomy filter from the query string and validate it against
     * real terms, so a bad or guessed slug just falls back to "All".
     *
     * @param string $key      Query variable, "mentor" or "service".
     * @param string $taxonomy Taxonomy to validate against.
     * @return string Valid term slug, or an empty string.
     */
    private function slug_from_url($key, $taxonomy) {
        if (empty($_GET[$key])) return '';

        $slug = sanitize_title(wp_unslash($_GET[$key]));
        if ($slug === '') return '';

        $term = get_term_by('slug', $slug, $taxonomy);
        return $term ? $term->slug : '';
    }

    /**
     * Read the name search from the query string.
     *
     * Free text rather than a slug, so it is only trimmed, stripped of tags
     * and length-capped here; the SQL below escapes it properly.
     */
    private function search_from_url() {
        if (empty($_GET['q'])) return '';
        return $this->clean_search(wp_unslash($_GET['q']));
    }

    /** Normalise a search term from any source. */
    private function clean_search($raw) {
        $term = sanitize_text_field((string) $raw);
        $term = preg_replace('/\s+/', ' ', trim($term));
        if (function_exists('mb_substr')) {
            return mb_substr($term, 0, 100);
        }
        return substr($term, 0, 100);
    }

    public function shortcode($atts) {
        wp_enqueue_style('gma-testimonials');
        wp_enqueue_script('gma-testimonials');

        $atts = (array) $atts;
        $a    = $this->resolve_atts($atts);

        // A value hardcoded in the shortcode pins the grid to that term and
        // hides the control for it. A value from the URL only preselects the
        // dropdown, so the visitor can still change or reset it.
        $locked_mentor  = ! empty($atts['mentor']);
        $locked_service = ! empty($atts['service']);
        $locked_search  = ! empty($atts['search']);

        $preselect = ['mentor' => '', 'service' => '', 'search' => ''];

        if (!$locked_mentor) {
            $from_url = $this->slug_from_url('mentor', GMA_T_TAX_MENTOR);
            if ($from_url !== '') {
                $a['mentor']            = $from_url;
                $preselect['mentor']    = $from_url;
            }
        }

        if (!$locked_service) {
            $from_url = $this->slug_from_url('service', GMA_T_TAX_SERVICE);
            if ($from_url !== '') {
                $a['service']         = $from_url;
                $preselect['service'] = $from_url;
            }
        }

        if ($locked_search) {
            $a['search'] = $this->clean_search($atts['search']);
        } else {
            $from_url = $this->search_from_url();
            if ($from_url !== '') {
                $a['search']         = $from_url;
                $preselect['search'] = $from_url;
            }
        }

        // The config travels to the AJAX handler, which takes the live
        // control values instead - so strip anything merely preselected from
        // it and keep only what the shortcode itself pinned.
        $config = $a;
        $config['mentor']  = $locked_mentor  ? $a['mentor']  : '';
        $config['service'] = $locked_service ? $a['service'] : '';
        $config['search']  = $locked_search  ? $a['search']  : '';

        ob_start();
        echo '<div class="gma-t-wrap" data-config="' . esc_attr(wp_json_encode($config)) . '">';

        if (!empty($a['filters']) && (!$locked_mentor || !$locked_service || !$locked_search)) {
            echo $this->render_filter_bar($preselect, $locked_mentor, $locked_service, $locked_search, $a);
        }

        echo '<div class="gma-t-results" aria-live="polite">';
        echo $this->render_results($a, 1);
        echo '</div>';
        echo '</div>';
        return ob_get_clean();
    }

    /**
     * Link to the testimonials page pre-filtered by mentor or service.
     *
     * [gma_testimonials_link mentor="Nitin Naik" text="Read his testimonials"]
     *
     * Accepts either a term name or a slug, so mentor pages can use the name
     * as displayed and stay correct if the slug changes.
     */
    public function link_shortcode($atts) {
        $atts = shortcode_atts([
            'mentor'  => '',
            'service' => '',
            'text'    => 'Read testimonials',
            'style'   => 'outline',   // outline | solid | text
            'class'   => '',
            'url'     => '',
            'arrow'   => 'yes',
        ], $atts, 'gma_testimonials_link');

        // The link usually sits on a mentor page where the grid shortcode
        // never runs, so the stylesheet has to be requested here too.
        wp_enqueue_style('gma-testimonials');

        $base = $atts['url'] !== '' ? $atts['url'] : gma_t_opt('testimonials_url');
        if (!$base) {
            return $this->link_problem('No testimonials page URL is set. Add one under Testimonials &rarr; Settings &rarr; Photo uploader, or pass url="..." on the shortcode.');
        }

        $args    = [];
        $missing = [];

        foreach ([
            'mentor'  => GMA_T_TAX_MENTOR,
            'service' => GMA_T_TAX_SERVICE,
        ] as $key => $taxonomy) {

            $value = trim((string) $atts[$key]);
            if ($value === '') continue;

            $term = $this->find_term($value, $taxonomy);

            if ($term) {
                $args[$key] = $term->slug;
            } else {
                $missing[] = $key . ' "' . $value . '"';
            }
        }

        if (empty($args)) {
            if (empty($missing)) {
                return $this->link_problem('No mentor or service was given. Example: [gma_testimonials_link mentor="Nitin Naik" text="Read his testimonials"]');
            }
            return $this->link_problem(
                'No matching term for ' . implode(' or ', $missing) . '. '
                . 'Check the exact spelling against Testimonials &rarr; Mentors or Services. '
                . 'If the name looks right, the quotes may have been converted to curly quotes by the editor - retype them.'
            );
        }

        $url = add_query_arg($args, $base);

        $style = in_array($atts['style'], ['outline', 'solid', 'text'], true) ? $atts['style'] : 'outline';

        $classes = ['gma-t-link', 'gma-t-link-' . $style];
        if ($atts['class'] !== '') {
            $classes[] = $atts['class'];
        }

        $arrow = ($atts['arrow'] !== 'no')
            ? '<span class="gma-t-link-arrow" aria-hidden="true">&rarr;</span>'
            : '';

        return '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($url) . '">'
             . esc_html($atts['text']) . $arrow . '</a>';
    }

    /**
     * Find a term by name or slug, tolerantly.
     *
     * Falls back to a case and whitespace insensitive scan, because a name
     * typed on a mentor page rarely matches the stored term byte for byte.
     */
    private function find_term($value, $taxonomy) {

        $term = get_term_by('name', $value, $taxonomy);
        if ($term) return $term;

        $term = get_term_by('slug', sanitize_title($value), $taxonomy);
        if ($term) return $term;

        $needle = strtolower(preg_replace('/\s+/', ' ', trim($value)));
        $terms  = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

        if (is_wp_error($terms)) return null;

        foreach ($terms as $t) {
            if (strtolower(preg_replace('/\s+/', ' ', trim($t->name))) === $needle) return $t;
            if (strtolower($t->slug) === $needle) return $t;
        }

        return null;
    }

    /**
     * Explain a failed link to administrators; render nothing for visitors, so
     * a mistake never shows up on the public page.
     */
    private function link_problem($message) {
        if (!current_user_can('manage_options')) return '';

        return '<span style="display:inline-block;padding:8px 12px;border-left:3px solid #d63638;'
             . 'background:#fcf0f1;font-size:0.9em;color:#3a3a32;">'
             . '<strong>Testimonials link (visible to admins only):</strong> ' . wp_kses_post($message)
             . '</span>';
    }

    /**
     * Filtered views are the same content in a different order, so point the
     * canonical at the clean page to keep search engines off the variants.
     */
    public function canonical_tag() {
        if (empty($_GET['mentor']) && empty($_GET['service'])) return;
        if (!is_singular()) return;

        $post = get_post();
        if (!$post || !has_shortcode($post->post_content, 'gma_testimonials')) return;

        echo '<link rel="canonical" href="' . esc_url(get_permalink($post)) . '" />' . "\n";
    }

    /**
     * Two dropdowns, only rendered for taxonomies that actually have terms.
     *
     * @param array $preselect       Slugs to mark selected, from the URL.
     * @param bool  $locked_mentor   Mentor pinned by the shortcode - hide it.
     * @param bool  $locked_service  Service pinned by the shortcode - hide it.
     */
    private function render_filter_bar($preselect = [], $locked_mentor = false, $locked_service = false, $locked_search = false, $a = []) {
        $mentors  = get_terms(['taxonomy' => GMA_T_TAX_MENTOR,  'hide_empty' => true]);
        $services = get_terms(['taxonomy' => GMA_T_TAX_SERVICE, 'hide_empty' => true]);

        $has_mentors  = (!is_wp_error($mentors)  && !empty($mentors))  && !$locked_mentor;
        $has_services = (!is_wp_error($services) && !empty($services)) && !$locked_service;

        $show_search = !$locked_search
            && (isset($a['show_search']) ? !empty($a['show_search']) : (bool) gma_t_opt('enable_search'));

        // The search box alone is reason enough to render the bar, so a site
        // with no mentor or service terms yet still gets it.
        if (!$has_mentors && !$has_services && !$show_search) return '';

        $sel_mentor  = isset($preselect['mentor'])  ? $preselect['mentor']  : '';
        $sel_service = isset($preselect['service']) ? $preselect['service'] : '';
        $sel_search  = isset($preselect['search'])  ? $preselect['search']  : '';

        $all = esc_html(gma_t_opt('all_label'));
        $out = '<div class="gma-t-filters">';

        if ($has_mentors) {
            $out .= '<label class="gma-t-filter">';
            $out .= '<span>' . esc_html(gma_t_opt('mentor_label')) . '</span>';
            $out .= '<select class="gma-t-select" data-tax="mentor">';
            $out .= '<option value="">' . $all . '</option>';
            foreach ($mentors as $t) {
                $out .= '<option value="' . esc_attr($t->slug) . '"' . selected($sel_mentor, $t->slug, false) . '>'
                      . esc_html($t->name) . '</option>';
            }
            $out .= '</select></label>';
        }

        if ($has_services) {
            $out .= '<label class="gma-t-filter">';
            $out .= '<span>' . esc_html(gma_t_opt('service_label')) . '</span>';
            $out .= '<select class="gma-t-select" data-tax="service">';
            $out .= '<option value="">' . $all . '</option>';
            foreach ($services as $t) {
                $out .= '<option value="' . esc_attr($t->slug) . '"' . selected($sel_service, $t->slug, false) . '>'
                      . esc_html($t->name) . '</option>';
            }
            $out .= '</select></label>';
        }

        if ($show_search) {
            $out .= '<label class="gma-t-filter gma-t-filter-search">';
            $out .= '<span>' . esc_html(gma_t_opt('search_label')) . '</span>';
            $out .= '<span class="gma-t-search-wrap">';
            $out .= '<input type="search" class="gma-t-search" autocomplete="off"'
                  . ' placeholder="' . esc_attr(gma_t_opt('search_placeholder')) . '"'
                  . ' aria-label="' . esc_attr(gma_t_opt('search_label')) . '"'
                  . ' value="' . esc_attr($sel_search) . '">';
            $out .= '<button type="button" class="gma-t-search-clear" aria-label="Clear search"'
                  . ($sel_search === '' ? ' hidden' : '') . '>&times;</button>';
            $out .= '</span></label>';
        }

        $out .= '<button type="button" class="gma-t-reset">Reset</button>';
        $out .= '</div>';
        return $out;
    }

    /**
     * Post IDs whose provider name matches the search term.
     *
     * Matches the post title and the configured name meta field only - not the
     * testimonial body, so searching "Nitin" finds testimonials written BY
     * Nitin rather than every testimonial that mentions him.
     *
     * Multiple words must all match, in any order, so both "narinder kaur" and
     * "kaur narinder" find the same person.
     *
     * @param string $term Cleaned search term.
     * @return int[] Matching IDs; an empty array means nothing matched.
     */
    private function search_post_ids($term) {
        global $wpdb;

        $tokens = preg_split('/\s+/', trim((string) $term), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) return [];

        // Cap the token count so a pathological query string cannot build an
        // enormous statement.
        $tokens = array_slice($tokens, 0, 6);

        $where  = [];
        $params = [gma_t_opt('meta_name'), GMA_T_PT];

        foreach ($tokens as $token) {
            $like     = '%' . $wpdb->esc_like($token) . '%';
            $where[]  = '(p.post_title LIKE %s OR pm.meta_value LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT DISTINCT p.ID
                  FROM {$wpdb->posts} p
                  LEFT JOIN {$wpdb->postmeta} pm
                         ON pm.post_id = p.ID AND pm.meta_key = %s
                 WHERE p.post_type = %s
                   AND p.post_status = 'publish'
                   AND " . implode(' AND ', $where);

        $ids = $wpdb->get_col($wpdb->prepare($sql, $params));

        return array_map('intval', (array) $ids);
    }

    /** Build the WP_Query args for a given config and page. */
    private function build_query_args($a, $paged) {
        $args = [
            'post_type'      => GMA_T_PT,
            'post_status'    => 'publish',
            'posts_per_page' => $a['per_page'],
            'paged'          => max(1, (int) $paged),
        ];

        switch ($a['order']) {
            case 'oldest': $args['orderby'] = 'date'; $args['order'] = 'ASC';  break;
            case 'random': $args['orderby'] = 'rand';                          break;
            case 'menu':   $args['orderby'] = 'menu_order'; $args['order'] = 'ASC'; break;
            default:       $args['orderby'] = 'date'; $args['order'] = 'DESC';
        }

        $tax_query = [];
        if (!empty($a['mentor'])) {
            $tax_query[] = ['taxonomy' => GMA_T_TAX_MENTOR, 'field' => 'slug', 'terms' => array_map('trim', explode(',', $a['mentor']))];
        }
        if (!empty($a['service'])) {
            $tax_query[] = ['taxonomy' => GMA_T_TAX_SERVICE, 'field' => 'slug', 'terms' => array_map('trim', explode(',', $a['service']))];
        }
        if (!empty($a['category'])) {
            $tax_query[] = ['taxonomy' => GMA_T_TAX_CAT, 'field' => 'slug', 'terms' => array_map('trim', explode(',', $a['category']))];
        }
        if (count($tax_query) > 1) $tax_query['relation'] = 'AND';
        if ($tax_query) $args['tax_query'] = $tax_query;

        // Name search narrows whatever the filters already selected.
        if (!empty($a['search'])) {
            $ids = $this->search_post_ids($a['search']);
            // post__in must never be empty or WP_Query ignores it and returns
            // everything, so an unmatched search gets a deliberately dead ID.
            $args['post__in'] = !empty($ids) ? $ids : [0];
        }

        return $args;
    }

    /**
     * Render one page of results.
     *
     * $mode 'full'  returns count + pagination + grid (initial load, filter change,
     *               numbered/prev-next paging).
     * $mode 'cards' returns only the card markup, for Load more to append.
     */
    public function render_results($a, $paged, $mode = 'full') {
        $q = new WP_Query($this->build_query_args($a, $paged));

        if (!$q->have_posts()) {
            wp_reset_postdata();
            if ($mode === 'cards') return '';

            $message = !empty($a['search'])
                ? 'No testimonials found for &ldquo;' . esc_html($a['search']) . '&rdquo;.'
                : 'No testimonials match your selection yet.';

            return '<p class="gma-t-empty">' . $message . '</p>';
        }

        $cards = '';
        while ($q->have_posts()) {
            $q->the_post();
            $cards .= $this->render_card(get_post(), $a);
        }

        if ($mode === 'cards') {
            wp_reset_postdata();
            return $cards;
        }

        $classes = 'gma-t-grid gma-t-cols-' . (int) $a['columns'];
        if (gma_t_opt('equal_heights')) $classes .= ' gma-t-equal';

        $style   = $a['pagination_style'];
        $enabled = !empty($a['pagination']) && $q->max_num_pages > 1;
        $pager   = $enabled ? $this->render_pagination($q->max_num_pages, $paged, $style) : '';

        // Load more never renders above the grid - it belongs under it.
        $before = ($enabled && $a['pagination_pos'] === 'both' && $style !== 'loadmore') ? $pager : '';

        $out  = '';
        if (!empty($a['show_count'])) {
            $out .= $this->render_count($q, $a, $paged);
        }
        $out .= $before;
        $out .= '<div class="' . esc_attr($classes) . '">' . $cards . '</div>';
        $out .= $pager;

        wp_reset_postdata();
        return $out;
    }

    /** "Showing 1-10 of 54" line. */
    private function render_count($q, $a, $paged) {
        $total = (int) $q->found_posts;
        if ($total < 1) return '';

        $per   = max(1, (int) $a['per_page']);
        $from  = (($paged - 1) * $per) + 1;
        $to    = min($total, $paged * $per);

        // With Load more the visible set grows from the top, so it always starts at 1.
        if ($a['pagination_style'] === 'loadmore') $from = 1;

        $text = str_replace(
            ['{from}', '{to}', '{total}'],
            [number_format_i18n($from), number_format_i18n($to), number_format_i18n($total)],
            (string) gma_t_opt('count_template')
        );
        return '<p class="gma-t-count" data-per-page="' . $per . '" data-total="' . $total . '">' . esc_html($text) . '</p>';
    }

    /** A single testimonial card. */
    private function render_card($post, $a) {
        $id      = $post->ID;
        $name    = get_the_title($id);
        $content = $post->post_content;

        // Fall back to the client_name meta if the post title is empty.
        if (trim($name) === '') {
            $meta_name = get_post_meta($id, gma_t_opt('meta_name'), true);
            if ($meta_name) $name = $meta_name;
        }

        $full  = trim(wp_strip_all_tags($content));
        $words = preg_split('/\s+/', $full, -1, PREG_SPLIT_NO_EMPTY);
        $limit = (int) $a['excerpt_words'];
        $needs_toggle = (count($words) > $limit);
        $short = $needs_toggle ? implode(' ', array_slice($words, 0, $limit)) : $full;

        $out = '<article class="gma-t-card">';

        $out .= '<h3 class="gma-t-name">' . esc_html($name) . '</h3>';

        $out .= '<div class="gma-t-body">';

        if (!empty($a['show_photo'])) {
            $img = '';
            if (has_post_thumbnail($id)) {
                $img = get_the_post_thumbnail($id, gma_t_opt('photo_size'), ['class' => 'gma-t-photo', 'loading' => 'lazy', 'alt' => esc_attr($name)]);
            } else {
                $fallback = (int) gma_t_opt('fallback_image');
                if ($fallback) {
                    // The fallback is usually a wide graphic rather than a
                    // headshot, so it can be shown square instead of cropped
                    // into a circle.
                    $shape = (gma_t_opt('fallback_shape') === 'square') ? ' gma-t-photo-square' : '';
                    $img = wp_get_attachment_image(
                        $fallback,
                        gma_t_opt('photo_size'),
                        false,
                        ['class' => 'gma-t-photo gma-t-photo-default' . $shape, 'loading' => 'lazy', 'alt' => '']
                    );
                }
            }
            if ($img) $out .= '<div class="gma-t-photo-wrap">' . $img . '</div>';
        }

        $out .= '<div class="gma-t-text">';
        if (!empty($a['show_rating'])) {
            $out .= $this->render_rating($id);
        }
        $out .= '<div class="gma-t-excerpt">' . esc_html($short);
        if ($needs_toggle) {
            $out .= '<span class="gma-t-more" hidden> ' . esc_html(substr($full, strlen($short))) . '</span>';
        }
        $out .= '</div>';

        if ($needs_toggle) {
            $out .= '<button type="button" class="gma-t-toggle" '
                  . 'data-more="' . esc_attr(gma_t_opt('read_more_text')) . '" '
                  . 'data-less="' . esc_attr(gma_t_opt('read_less_text')) . '">'
                  . esc_html(gma_t_opt('read_more_text')) . '</button>';
        }
        $out .= '</div>'; // .gma-t-text
        $out .= '</div>'; // .gma-t-body

        $out .= '<footer class="gma-t-foot">';
        if (!empty($a['show_badges'])) {
            $out .= $this->render_badges($id);
        }
        if (!empty($a['show_date'])) {
            $out .= '<time class="gma-t-date">' . esc_html(get_the_date(gma_t_opt('date_format'), $id)) . '</time>';
        }
        $out .= '</footer>';

        $out .= '</article>';
        return $out;
    }

    /** Star rating, if a rating meta key is configured and present. */
    private function render_rating($id) {
        $key = gma_t_opt('meta_rating');
        if (!$key) return '';
        $val = get_post_meta($id, $key, true);
        if ($val === '' || $val === null) return '';
        $stars = (int) round((float) $val);
        if ($stars < 1) return '';
        $stars = min(5, $stars);

        $out = '<div class="gma-t-rating" aria-label="' . esc_attr($stars . ' out of 5') . '">';
        for ($i = 1; $i <= 5; $i++) {
            $out .= '<span class="gma-t-star' . ($i <= $stars ? ' is-on' : '') . '">&#9733;</span>';
        }
        $out .= '</div>';
        return $out;
    }

    /** Mentor and service pills. */
    private function render_badges($id) {
        $out = '';
        foreach ([GMA_T_TAX_MENTOR => 'mentor', GMA_T_TAX_SERVICE => 'service'] as $tax => $cls) {
            $terms = get_the_terms($id, $tax);
            if (is_wp_error($terms) || empty($terms)) continue;
            foreach ($terms as $t) {
                $out .= '<span class="gma-t-badge gma-t-badge-' . esc_attr($cls) . '">' . esc_html($t->name) . '</span>';
            }
        }
        return $out ? '<div class="gma-t-badges">' . $out . '</div>' : '';
    }

    /** Pagination in one of three styles: numbered, prev/next only, or load more. */
    private function render_pagination($max_pages, $current, $style = 'numbered') {
        if ($max_pages < 2) return '';
        $current = max(1, min((int) $current, (int) $max_pages));

        if ($style === 'loadmore') {
            if ($current >= $max_pages) return '';
            return '<div class="gma-t-loadmore-wrap">'
                 . '<button type="button" class="gma-t-loadmore" data-page="' . ($current + 1) . '" '
                 . 'data-max="' . (int) $max_pages . '">Load more</button></div>';
        }

        $out  = '<nav class="gma-t-pagination" aria-label="Testimonials pages">';
        $out .= '<button type="button" class="gma-t-page-prev"' . ($current <= 1 ? ' disabled' : '')
              . ' data-page="' . ($current - 1) . '" aria-label="Previous page">&laquo;</button>';

        if ($style === 'numbered') {
            for ($i = 1; $i <= $max_pages; $i++) {
                $cls = 'gma-t-page' . ($i === $current ? ' is-current' : '');
                $out .= '<button type="button" class="' . $cls . '" data-page="' . $i . '">' . $i . '</button>';
            }
        } else {
            $out .= '<span class="gma-t-page-of">' . (int) $current . ' / ' . (int) $max_pages . '</span>';
        }

        $out .= '<button type="button" class="gma-t-page-next"' . ($current >= $max_pages ? ' disabled' : '')
              . ' data-page="' . ($current + 1) . '" aria-label="Next page">&raquo;</button>';
        $out .= '</nav>';
        return $out;
    }

    /** AJAX: return a fresh grid for the requested filters and page. */
    public function ajax_filter() {
        check_ajax_referer('gma_t_filter', 'nonce');

        $config = isset($_POST['config']) ? json_decode(wp_unslash($_POST['config']), true) : [];
        if (!is_array($config)) $config = [];

        $a = $this->resolve_atts([]);
        // Only accept the keys we own, from the config the shortcode emitted.
        foreach (['columns', 'per_page', 'order', 'excerpt_words', 'category', 'show_photo', 'show_date',
                  'show_rating', 'show_badges', 'pagination', 'pagination_pos', 'pagination_style', 'show_count'] as $k) {
            if (isset($config[$k])) $a[$k] = $config[$k];
        }
        $a['columns']       = max(1, min(4, (int) $a['columns']));
        $a['per_page']      = max(1, (int) $a['per_page']);
        $a['excerpt_words'] = max(5, (int) $a['excerpt_words']);

        $a['mentor']  = isset($_POST['mentor'])  ? sanitize_title(wp_unslash($_POST['mentor']))  : '';
        $a['service'] = isset($_POST['service']) ? sanitize_title(wp_unslash($_POST['service'])) : '';
        $a['search']  = isset($_POST['search'])  ? $this->clean_search(wp_unslash($_POST['search'])) : '';

        // A search pinned by the shortcode wins over anything posted.
        if (!empty($config['search'])) {
            $a['search'] = $this->clean_search($config['search']);
        }
        $paged        = isset($_POST['paged'])   ? max(1, (int) $_POST['paged']) : 1;
        $mode         = (isset($_POST['mode']) && $_POST['mode'] === 'cards') ? 'cards' : 'full';

        if ($mode === 'cards') {
            // Load more: append cards and tell the button whether to stay.
            $probe     = new WP_Query($this->build_query_args($a, $paged));
            $max_pages = (int) $probe->max_num_pages;
            wp_reset_postdata();

            wp_send_json_success([
                'html'      => $this->render_results($a, $paged, 'cards'),
                'has_more'  => ($paged < $max_pages),
                'next_page' => $paged + 1,
                'shown'     => min((int) $probe->found_posts, $paged * max(1, (int) $a['per_page'])),
                'total'     => (int) $probe->found_posts,
            ]);
        }

        wp_send_json_success(['html' => $this->render_results($a, $paged, 'full')]);
    }
}

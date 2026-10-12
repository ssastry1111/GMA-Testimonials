<?php
if (!defined('ABSPATH')) exit;

/**
 * Registers the testimonial post type and its taxonomies.
 *
 * The post type slug is the same one Strong Testimonials used, which is what
 * makes the switchover a no-migration operation: every existing row in
 * wp_posts, every featured image and every meta value is inherited as-is.
 */
class GMA_T_CPT {

    public function __construct() {
        add_action('init', [__CLASS__, 'register_types'], 5);
        add_filter('manage_' . GMA_T_PT . '_posts_columns',       [$this, 'columns']);
        add_action('manage_' . GMA_T_PT . '_posts_custom_column', [$this, 'column_content'], 10, 2);

        // Let WPForms Post Submissions target this post type. Strong Testimonials
        // used to supply this; without it, Post Submissions only offers "post".
        add_filter('wpforms_post_submissions_post_type', [$this, 'allow_post_submissions']);
    }

    public static function register_types() {

        if (function_exists('gma_t_strong_active') && gma_t_strong_active()) {
            return; // stand down while Strong Testimonials owns the type
        }

        $single_urls = function_exists('gma_t_opt') ? (bool) gma_t_opt('single_urls') : false;

        register_post_type(GMA_T_PT, [
            'labels' => [
                'name'               => 'Testimonials',
                'singular_name'      => 'Testimonial',
                'add_new'            => 'Add New',
                'add_new_item'       => 'Add New Testimonial',
                'edit_item'          => 'Edit Testimonial',
                'new_item'           => 'New Testimonial',
                'view_item'          => 'View Testimonial',
                'search_items'       => 'Search Testimonials',
                'not_found'          => 'No testimonials found',
                'not_found_in_trash' => 'No testimonials found in Trash',
                // Prefixed so the sidebar entry is identifiable as one of the
                // GMA plugins, alongside GMA Buddies, GMA ICA and the rest.
                'menu_name'          => 'GMA Testimonials',
            ],
            // Individual testimonial pages are off by default: nothing links to
            // them, they read as thin content to search engines, and Yoast
            // counts every one as "orphaned". The grid queries posts directly,
            // so it is unaffected either way.
            'public'              => $single_urls,
            'publicly_queryable'  => $single_urls,
            'exclude_from_search' => true,
            'show_ui'             => true,   // admin UI always available
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'menu_icon'           => 'dashicons-format-quote',
            'menu_position'       => 26,
            'supports'            => ['title', 'editor', 'thumbnail', 'custom-fields', 'revisions'],
            'has_archive'         => false,
            'rewrite'             => $single_urls ? ['slug' => 'testimonial'] : false,
            'capability_type'     => 'post',
        ]);

        // Existing Strong Testimonials category taxonomy, preserved so any
        // terms already assigned keep working.
        register_taxonomy(GMA_T_TAX_CAT, GMA_T_PT, [
            'labels' => [
                'name'          => 'Testimonial Categories',
                'singular_name' => 'Testimonial Category',
                'menu_name'     => 'Categories',
            ],
            'public'            => true,
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => false,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ]);

        // Two independent taxonomies are what make "mentor AND/OR service"
        // filtering possible without fighting a single mixed term list.
        register_taxonomy(GMA_T_TAX_MENTOR, GMA_T_PT, [
            'labels' => [
                'name'              => 'Mentors',
                'singular_name'     => 'Mentor',
                'menu_name'         => 'Mentors',
                'add_new_item'      => 'Add New Mentor',
                'all_items'         => 'All Mentors',
                'search_items'      => 'Search Mentors',
            ],
            'public'            => true,
            'hierarchical'      => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ]);

        register_taxonomy(GMA_T_TAX_SERVICE, GMA_T_PT, [
            'labels' => [
                'name'              => 'Services',
                'singular_name'     => 'Service',
                'menu_name'         => 'Services',
                'add_new_item'      => 'Add New Service',
                'all_items'         => 'All Services',
                'search_items'      => 'Search Services',
            ],
            'public'            => true,
            'hierarchical'      => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => false,
        ]);
    }

    /** Allow WPForms Post Submissions to create this post type. */
    public function allow_post_submissions($post_type) {
        return GMA_T_PT;
    }

    /** Add Photo and Email columns to the admin list table. */
    public function columns($cols) {
        $new = [];
        foreach ($cols as $key => $label) {
            if ($key === 'title') {
                $new['gma_photo'] = 'Photo';
            }
            $new[$key] = $label;
            if ($key === 'title') {
                $new['gma_email'] = 'Email';
            }
        }
        return $new;
    }

    public function column_content($col, $post_id) {
        if ($col === 'gma_photo') {
            if (has_post_thumbnail($post_id)) {
                echo get_the_post_thumbnail($post_id, [48, 48], ['style' => 'border-radius:4px;object-fit:cover;']);
            } else {
                echo '<span style="color:#d63638;" title="No photo uploaded">&#9679; none</span>';
            }
        }
        if ($col === 'gma_email') {
            $email = get_post_meta($post_id, gma_t_opt('meta_email'), true);
            echo $email ? esc_html($email) : '<span style="color:#999;">&mdash;</span>';
        }
    }

    /**
     * Distinct meta keys present on testimonials. Used by the settings page so
     * mapping fields (star rating, etc.) is a dropdown rather than guesswork.
     */
    public static function available_meta_keys() {
        global $wpdb;
        $keys = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_key
               FROM {$wpdb->postmeta} pm
               INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE p.post_type = %s
                AND pm.meta_key NOT LIKE %s
              ORDER BY pm.meta_key ASC",
            GMA_T_PT,
            '\_%'
        ));
        return is_array($keys) ? $keys : [];
    }
}

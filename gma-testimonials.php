<?php
/*
Plugin Name: GMA Testimonials
Plugin URI: https://globalmentoringacademy.com
Description: v1.4 - Complete testimonials system for GMA. Adds a link builder on the settings page that writes the shortcode and URL for any mentor or service. Registers the testimonial post type, adds Mentor and Service taxonomies with front-end filtering, renders a configurable responsive grid via shortcode, and bundles the Photo Reminder and Photo Uploader modules. Replaces Strong Testimonials.
Version: 1.7.2
Author: GMA
Text Domain: gma-testimonials
*/

if (!defined('ABSPATH')) exit;

define('GMA_T_VERSION',      '1.7.2');
define('GMA_T_FILE',         __FILE__);
define('GMA_T_DIR',          plugin_dir_path(__FILE__));
define('GMA_T_URL',          plugin_dir_url(__FILE__));

// Post type and taxonomies. The post type slug deliberately matches the one
// Strong Testimonials used, so ~50 existing testimonials, their featured
// images and all their meta carry over with no migration at all.
define('GMA_T_PT',           'wpm-testimonial');
define('GMA_T_TAX_CAT',      'wpm-testimonial-category');
define('GMA_T_TAX_MENTOR',   'gma_mentor');
define('GMA_T_TAX_SERVICE',  'gma_service');

define('GMA_T_SETTINGS',     'gma_t_settings');

require_once GMA_T_DIR . 'includes/class-gma-t-cpt.php';
require_once GMA_T_DIR . 'includes/class-gma-t-settings.php';
require_once GMA_T_DIR . 'includes/class-gma-t-display.php';
require_once GMA_T_DIR . 'includes/class-gma-t-reminder.php';
require_once GMA_T_DIR . 'includes/class-gma-t-token.php';
require_once GMA_T_DIR . 'includes/class-gma-t-uploader.php';
require_once GMA_T_DIR . 'includes/class-gma-t-terms.php';

/**
 * Is Strong Testimonials currently active?
 *
 * Two plugins registering the same post type is unpredictable, so when Strong
 * Testimonials is still running we stand down completely and say so in an
 * admin notice, rather than fighting it for the registration.
 */
function gma_t_strong_active() {
    if (defined('WPMTST_VERSION')) return true;
    if (class_exists('Strong_Testimonials')) return true;

    $active = (array) get_option('active_plugins', []);
    foreach ($active as $plugin) {
        if (strpos($plugin, 'strong-testimonials') === 0) return true;
    }
    return false;
}

/**
 * Default settings. These deliberately reproduce the existing Strong
 * Testimonials View so the page looks unchanged the moment the shortcode
 * is swapped over.
 */
function gma_t_defaults() {
    return [
        // Display
        'columns'         => 3,
        'per_page'        => 10,
        'show_pagination' => 1,
        'pagination_pos'  => 'both',      // after | both
        'pagination_style'=> 'numbered',  // numbered | prevnext | loadmore
        'show_count'      => 0,
        'count_template'  => 'Showing {from}-{to} of {total}',
        'order'           => 'newest',
        'excerpt_words'   => 30,
        'read_more_text'  => 'Read more',
        'read_less_text'  => 'Show less',
        'show_photo'      => 1,
        'show_date'       => 1,
        'show_rating'     => 1,
        'show_badges'     => 1,
        'equal_heights'   => 1,
        'photo_size'      => 'thumbnail',
        'fallback_image'  => 0,
        'date_format'     => 'F j, Y',
        // Filters
        'enable_filters'  => 1,
        'enable_search'   => 1,
        'search_label'    => 'Search by name',
        'search_placeholder' => 'First, last or part of a name',
        'filters_toggle_label' => 'Filter & search',
        'mentor_label'    => 'Mentor',
        'service_label'   => 'Service',
        'all_label'       => 'All',
        // Meta keys (auto-detected, overridable)
        'meta_name'       => 'client_name',
        'meta_email'      => 'email',
        'meta_rating'     => '',
        'fallback_shape'  => 'circle',   // circle | square
        // Term assignment from WPForms
        'terms_enabled'   => 1,
        'terms_form_id'   => 2153,
        'terms_mentor_field'  => 14,
        'terms_service_field' => 15,
        'single_urls'     => 0,           // individual testimonial pages off
        // Reminder module
        'reminder_enabled' => 1,
        'time'            => '10:00',
        'max'             => 2,
        'subject'         => 'Quick request to add your photo',
        'body'            => "Hi {first_name},<br><br>\n"
                           . "Thanks again for sharing your testimonial - we really appreciate it.<br><br>\n"
                           . "Your testimonial will have more impact with a photo of you alongside it. "
                           . "The link below opens straight to the upload page - no login needed:<br><br>\n"
                           . "<a href='{upload_url}' target='_blank'>Upload your photo</a><br><br>\n"
                           . "Thanks so much,<br>Global Mentoring Academy",
        'from_email'      => 'support@globalmentoringacademy.com',
        'from_name'       => 'Global Mentoring Academy',
        'cc_emails'       => 'ssastry1111@gmail.com, naik.nitin@gmail.com',
        'test_email'      => 'ssastry1111@gmail.com',
        // Uploader module
        'uploader_enabled' => 1,
        'upload_max_mb'   => 5,
        'upload_url'      => 'https://globalmentoringacademy.com/upload-testimonial-photo/',
        // Tokenised upload links
        'tokens_enabled'    => 1,
        'token_expiry_days' => 30,
        'testimonials_url' => 'https://globalmentoringacademy.com/client-testimonials/',
    ];
}

/** Read one setting with its default applied. */
function gma_t_opt($key, $fallback = null) {
    $opts = get_option(GMA_T_SETTINGS, []);
    if (!is_array($opts)) $opts = [];
    $defaults = gma_t_defaults();
    if (isset($opts[$key]) && $opts[$key] !== '') return $opts[$key];
    if (array_key_exists($key, $defaults)) return $defaults[$key];
    return $fallback;
}

/** Boot the modules. */
function gma_t_init() {
    new GMA_T_CPT();
    new GMA_T_Settings();
    new GMA_T_Display();
    new GMA_T_Reminder();
    new GMA_T_Uploader();
    new GMA_T_Terms();
}
add_action('plugins_loaded', 'gma_t_init');

/** Admin notice when Strong Testimonials is still holding the post type. */
function gma_t_conflict_notice() {
    if (!gma_t_strong_active() || !current_user_can('manage_options')) return;
    echo '<div class="notice notice-error"><p><strong>GMA Testimonials is standing by.</strong> '
       . 'Strong Testimonials is still active and owns the testimonial post type. '
       . 'Deactivate Strong Testimonials (do not delete it, so you can roll back) and this plugin will take over automatically.</p></div>';
}
add_action('admin_notices', 'gma_t_conflict_notice');

/** Activation: seed settings only if absent, register types, flush permalinks. */
function gma_t_activate() {
    if (!get_option(GMA_T_SETTINGS)) {
        update_option(GMA_T_SETTINGS, gma_t_defaults());
    }
    if (!gma_t_strong_active()) {
        GMA_T_CPT::register_types();
    }
    flush_rewrite_rules();

    if (gma_t_opt('reminder_enabled')) {
        GMA_T_Reminder::schedule_next_static();
    }
}
register_activation_hook(__FILE__, 'gma_t_activate');

/** Deactivation: clear our cron only. No data is touched. */
function gma_t_deactivate() {
    wp_clear_scheduled_hook('gma_t_reminder_event');
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'gma_t_deactivate');

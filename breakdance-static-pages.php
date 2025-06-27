<?php
/**
 * Plugin Name: Breakdance Static Pages
 * Plugin URI: https://yoursite.com
 * Description: Convert Breakdance pages with ACF fields into lightning-fast static HTML files for dramatically improved performance.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: breakdance-static-pages
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BSP_VERSION', '1.0.0');
define('BSP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BSP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BSP_PLUGIN_FILE', __FILE__);

/**
 * Main plugin class
 */
class Breakdance_Static_Pages {
    
    /**
     * Single instance of the plugin
     */
    private static $instance = null;
    
    /**
     * Get single instance of the plugin
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load required files
        $this->load_dependencies();
        
        // Initialize components
        $this->init_hooks();
        
        // Load text domain
        load_plugin_textdomain('breakdance-static-pages', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        require_once BSP_PLUGIN_DIR . 'includes/class-static-generator.php';
        require_once BSP_PLUGIN_DIR . 'includes/class-cache-manager.php';
        require_once BSP_PLUGIN_DIR . 'includes/class-admin-interface.php';
        require_once BSP_PLUGIN_DIR . 'includes/class-ajax-handler.php';
        require_once BSP_PLUGIN_DIR . 'includes/class-url-rewriter.php';
        require_once BSP_PLUGIN_DIR . 'includes/class-performance-monitor.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Initialize admin interface
        if (is_admin()) {
            new BSP_Admin_Interface();
        }
        
        // Initialize AJAX handler
        new BSP_Ajax_Handler();
        
        // Initialize URL rewriter for frontend
        if (!is_admin()) {
            new BSP_URL_Rewriter();
        }
        
        // Initialize cache manager
        new BSP_Cache_Manager();
        
        // Initialize performance monitor
        new BSP_Performance_Monitor();
        
        // Hook into ACF and Breakdance updates
        add_action('acf/save_post', array($this, 'handle_content_update'), 20);
        add_action('save_post', array($this, 'handle_post_save'), 20, 2);
        
        // Add admin bar menu
        add_action('admin_bar_menu', array($this, 'add_admin_bar_menu'), 100);
    }
    
    /**
     * Handle content updates (ACF fields)
     */
    public function handle_content_update($post_id) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        // Check if this page has static generation enabled
        $static_enabled = get_post_meta($post_id, '_bsp_static_enabled', true);
        
        if ($static_enabled) {
            // Schedule regeneration
            wp_schedule_single_event(time() + 30, 'bsp_regenerate_static_page', array($post_id));
        }
    }
    
    /**
     * Handle post save
     */
    public function handle_post_save($post_id, $post) {
        // Only handle pages and posts
        if (!in_array($post->post_type, array('page', 'post'))) {
            return;
        }
        
        $this->handle_content_update($post_id);
    }
    
    /**
     * Add admin bar menu
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        global $post;
        
        if (is_singular() && $post) {
            $static_enabled = get_post_meta($post->ID, '_bsp_static_enabled', true);
            
            $wp_admin_bar->add_menu(array(
                'id' => 'bsp-static-control',
                'title' => $static_enabled ? '⚡ Static Active' : '🐌 Dynamic',
                'href' => admin_url('tools.php?page=breakdance-static-pages'),
                'meta' => array(
                    'title' => $static_enabled ? 'This page is served as static HTML' : 'This page is served dynamically'
                )
            ));
            
            if ($static_enabled) {
                $wp_admin_bar->add_menu(array(
                    'parent' => 'bsp-static-control',
                    'id' => 'bsp-regenerate',
                    'title' => '🔄 Regenerate Static',
                    'href' => wp_nonce_url(
                        admin_url('admin-post.php?action=bsp_regenerate_page&post_id=' . $post->ID),
                        'bsp_regenerate_' . $post->ID
                    )
                ));
            }
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create static files directory
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages';
        
        if (!file_exists($static_dir)) {
            wp_mkdir_p($static_dir);
            wp_mkdir_p($static_dir . '/pages');
            wp_mkdir_p($static_dir . '/assets');
        }
        
        // Create .htaccess for static files
        $htaccess_content = "# Breakdance Static Pages\n";
        $htaccess_content .= "Options -Indexes\n";
        $htaccess_content .= "<Files *.html>\n";
        $htaccess_content .= "    Header set Cache-Control \"public, max-age=3600\"\n";
        $htaccess_content .= "</Files>\n";
        
        file_put_contents($static_dir . '/.htaccess', $htaccess_content);
        
        // Schedule cleanup cron
        if (!wp_next_scheduled('bsp_cleanup_old_static_files')) {
            wp_schedule_event(time(), 'daily', 'bsp_cleanup_old_static_files');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('bsp_cleanup_old_static_files');
        wp_clear_scheduled_hook('bsp_regenerate_static_page');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Get static file path for a post
     */
    public static function get_static_file_path($post_id) {
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages';
        
        return $static_dir . '/page-' . $post_id . '.html';
    }
    
    /**
     * Get static file URL for a post (admin-only access)
     */
    public static function get_static_file_url($post_id) {
        // Return admin-ajax URL for secure access
        return admin_url('admin-ajax.php?action=bsp_serve_static&file=pages/page-' . $post_id . '.html');
    }
    
    /**
     * Check if a page should be served statically
     */
    public static function should_serve_static($post_id) {
        // Check if static generation is enabled for this page
        $static_enabled = get_post_meta($post_id, '_bsp_static_enabled', true);
        
        if (!$static_enabled) {
            return false;
        }
        
        // Check if static file exists and is not expired
        $static_file = self::get_static_file_path($post_id);
        
        if (!file_exists($static_file)) {
            return false;
        }
        
        // Check if file is not too old (configurable, default 24 hours)
        $max_age = apply_filters('bsp_static_file_max_age', 24 * HOUR_IN_SECONDS);
        $file_age = time() - filemtime($static_file);
        
        if ($file_age > $max_age) {
            return false;
        }
        
        return true;
    }
}

// Initialize the plugin
Breakdance_Static_Pages::get_instance();

// Handle regeneration action
add_action('admin_post_bsp_regenerate_page', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $post_id = intval($_GET['post_id']);
    
    if (!wp_verify_nonce($_GET['_wpnonce'], 'bsp_regenerate_' . $post_id)) {
        wp_die('Security check failed');
    }
    
    // Regenerate static page
    $generator = new BSP_Static_Generator();
    $result = $generator->generate_static_page($post_id);
    
    if ($result) {
        wp_redirect(add_query_arg('bsp_regenerated', '1', get_permalink($post_id)));
    } else {
        wp_redirect(add_query_arg('bsp_error', '1', get_permalink($post_id)));
    }
    exit;
});

// Handle scheduled regeneration
add_action('bsp_regenerate_static_page', function($post_id) {
    $generator = new BSP_Static_Generator();
    $generator->generate_static_page($post_id);
});

// Handle cleanup
add_action('bsp_cleanup_old_static_files', function() {
    $cache_manager = new BSP_Cache_Manager();
    $cache_manager->cleanup_old_files();
});

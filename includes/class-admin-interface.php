<?php
/**
 * Admin Interface Class
 * Handles the WordPress admin interface for the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSP_Admin_Interface {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_box_data'));
        add_action('admin_notices', array($this, 'show_admin_notices'));
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_management_page(
            'Breakdance Static Pages',
            'Static Pages',
            'manage_options',
            'breakdance-static-pages',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'tools_page_breakdance-static-pages' && $hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        
        wp_enqueue_script(
            'bsp-admin-script',
            BSP_PLUGIN_URL . 'assets/admin-script.js',
            array('jquery'),
            BSP_VERSION,
            true
        );
        
        wp_enqueue_style(
            'bsp-admin-style',
            BSP_PLUGIN_URL . 'assets/admin-style.css',
            array(),
            BSP_VERSION
        );
        
        // Localize script
        wp_localize_script('bsp-admin-script', 'bsp_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bsp_nonce'),
            'strings' => array(
                'generating' => __('Generating static files...', 'breakdance-static-pages'),
                'success' => __('Static files generated successfully!', 'breakdance-static-pages'),
                'error' => __('Error generating static files.', 'breakdance-static-pages'),
                'confirm_delete' => __('Are you sure you want to delete the static file for this page?', 'breakdance-static-pages'),
                'confirm_bulk_generate' => __('Generate static files for selected pages?', 'breakdance-static-pages'),
                'confirm_bulk_delete' => __('Delete static files for selected pages?', 'breakdance-static-pages')
            )
        ));
    }
    
    /**
     * Main admin page
     */
    public function admin_page() {
        // Get all pages and posts
        $pages = $this->get_eligible_pages();
        $stats = $this->get_plugin_stats();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Breakdance Static Pages', 'breakdance-static-pages'); ?></h1>
            
            <div class="bsp-admin-header">
                <div class="bsp-stats-grid">
                    <div class="bsp-stat-card">
                        <h3><?php echo number_format($stats['total_pages']); ?></h3>
                        <p><?php _e('Total Pages', 'breakdance-static-pages'); ?></p>
                    </div>
                    <div class="bsp-stat-card">
                        <h3><?php echo number_format($stats['static_enabled']); ?></h3>
                        <p><?php _e('Static Enabled', 'breakdance-static-pages'); ?></p>
                    </div>
                    <div class="bsp-stat-card">
                        <h3><?php echo number_format($stats['static_generated']); ?></h3>
                        <p><?php _e('Files Generated', 'breakdance-static-pages'); ?></p>
                    </div>
                    <div class="bsp-stat-card">
                        <h3><?php echo size_format($stats['total_size']); ?></h3>
                        <p><?php _e('Total Size', 'breakdance-static-pages'); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bsp-admin-content">
                <div class="bsp-bulk-actions">
                    <button type="button" id="bsp-select-all" class="button"><?php _e('Select All', 'breakdance-static-pages'); ?></button>
                    <button type="button" id="bsp-select-none" class="button"><?php _e('Select None', 'breakdance-static-pages'); ?></button>
                    <button type="button" id="bsp-bulk-generate" class="button button-primary"><?php _e('Generate Selected', 'breakdance-static-pages'); ?></button>
                    <button type="button" id="bsp-bulk-delete" class="button button-secondary"><?php _e('Delete Selected', 'breakdance-static-pages'); ?></button>
                </div>
                
                <div id="bsp-progress" class="bsp-progress" style="display: none;">
                    <div class="bsp-progress-bar">
                        <div class="bsp-progress-fill"></div>
                    </div>
                    <div class="bsp-progress-text"><?php _e('Processing...', 'breakdance-static-pages'); ?></div>
                </div>
                
                <div id="bsp-results" class="bsp-results" style="display: none;"></div>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column">
                                <input type="checkbox" id="bsp-select-all-checkbox">
                            </td>
                            <th class="manage-column"><?php _e('Page', 'breakdance-static-pages'); ?></th>
                            <th class="manage-column"><?php _e('Type', 'breakdance-static-pages'); ?></th>
                            <th class="manage-column"><?php _e('Status', 'breakdance-static-pages'); ?></th>
                            <th class="manage-column"><?php _e('Last Generated', 'breakdance-static-pages'); ?></th>
                            <th class="manage-column"><?php _e('File Size', 'breakdance-static-pages'); ?></th>
                            <th class="manage-column"><?php _e('Actions', 'breakdance-static-pages'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $page): ?>
                            <?php
                            $static_enabled = get_post_meta($page->ID, '_bsp_static_enabled', true);
                            $static_generated = get_post_meta($page->ID, '_bsp_static_generated', true);
                            $file_size = get_post_meta($page->ID, '_bsp_static_file_size', true);
                            $static_file_exists = file_exists(Breakdance_Static_Pages::get_static_file_path($page->ID));
                            ?>
                            <tr data-post-id="<?php echo $page->ID; ?>">
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="page_ids[]" value="<?php echo $page->ID; ?>" class="bsp-page-checkbox">
                                </th>
                                <td>
                                    <strong>
                                        <a href="<?php echo get_permalink($page->ID); ?>" target="_blank">
                                            <?php echo esc_html($page->post_title); ?>
                                        </a>
                                    </strong>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="<?php echo get_edit_post_link($page->ID); ?>"><?php _e('Edit', 'breakdance-static-pages'); ?></a> |
                                        </span>
                                        <span class="view">
                                            <a href="<?php echo get_permalink($page->ID); ?>" target="_blank"><?php _e('View', 'breakdance-static-pages'); ?></a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo ucfirst($page->post_type); ?></td>
                                <td>
                                    <div class="bsp-status-toggle">
                                        <label class="bsp-switch">
                                            <input type="checkbox" 
                                                   class="bsp-static-toggle" 
                                                   data-post-id="<?php echo $page->ID; ?>"
                                                   <?php checked($static_enabled, '1'); ?>>
                                            <span class="bsp-slider"></span>
                                        </label>
                                        <span class="bsp-status-text">
                                            <?php if ($static_enabled): ?>
                                                <?php if ($static_file_exists): ?>
                                                    <span class="bsp-status-active">⚡ Static Active</span>
                                                <?php else: ?>
                                                    <span class="bsp-status-pending">⏳ Needs Generation</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="bsp-status-disabled">🐌 Dynamic</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($static_generated): ?>
                                        <?php echo human_time_diff(strtotime($static_generated), current_time('timestamp')) . ' ago'; ?>
                                    <?php else: ?>
                                        <span class="bsp-text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($file_size): ?>
                                        <?php echo size_format($file_size); ?>
                                    <?php else: ?>
                                        <span class="bsp-text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="bsp-actions">
                                        <?php if ($static_enabled): ?>
                                            <button type="button" 
                                                    class="button button-small bsp-generate-single" 
                                                    data-post-id="<?php echo $page->ID; ?>">
                                                <?php _e('Generate', 'breakdance-static-pages'); ?>
                                            </button>
                                            <?php if ($static_file_exists): ?>
                                                <button type="button" 
                                                        class="button button-small bsp-delete-single" 
                                                        data-post-id="<?php echo $page->ID; ?>">
                                                    <?php _e('Delete', 'breakdance-static-pages'); ?>
                                                </button>
                                                <a href="<?php echo Breakdance_Static_Pages::get_static_file_url($page->ID); ?>" 
                                                   target="_blank" 
                                                   class="button button-small">
                                                    <?php _e('View Static', 'breakdance-static-pages'); ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="description"><?php _e('Enable static generation first', 'breakdance-static-pages'); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if (empty($pages)): ?>
                    <div class="bsp-empty-state">
                        <h3><?php _e('No pages found', 'breakdance-static-pages'); ?></h3>
                        <p><?php _e('Create some pages or posts to get started with static generation.', 'breakdance-static-pages'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="bsp-admin-sidebar">
                <div class="bsp-info-card">
                    <h3><?php _e('How It Works', 'breakdance-static-pages'); ?></h3>
                    <ul>
                        <li><?php _e('Toggle static generation for individual pages', 'breakdance-static-pages'); ?></li>
                        <li><?php _e('Generate static HTML files for faster loading', 'breakdance-static-pages'); ?></li>
                        <li><?php _e('Auto-regeneration when content changes', 'breakdance-static-pages'); ?></li>
                        <li><?php _e('Preserve all Breakdance functionality', 'breakdance-static-pages'); ?></li>
                    </ul>
                </div>
                
                <div class="bsp-info-card">
                    <h3><?php _e('Performance Tips', 'breakdance-static-pages'); ?></h3>
                    <ul>
                        <li><?php _e('Enable static generation for high-traffic pages', 'breakdance-static-pages'); ?></li>
                        <li><?php _e('Pages with heavy ACF usage benefit most', 'breakdance-static-pages'); ?></li>
                        <li><?php _e('Static files are automatically updated when you edit content', 'breakdance-static-pages'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get eligible pages for static generation
     */
    private function get_eligible_pages() {
        $args = array(
            'post_type' => array('page', 'post'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        );
        
        return get_posts($args);
    }
    
    /**
     * Get plugin statistics
     */
    private function get_plugin_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Total pages
        $stats['total_pages'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('page', 'post') AND post_status = 'publish'"
        );
        
        // Static enabled
        $stats['static_enabled'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bsp_static_enabled' AND meta_value = '1'"
        );
        
        // Static generated
        $stats['static_generated'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bsp_static_generated'"
        );
        
        // Total size
        $file_sizes = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_bsp_static_file_size'"
        );
        
        $stats['total_size'] = array_sum(array_map('intval', $file_sizes));
        
        return $stats;
    }
    
    /**
     * Add meta boxes to post edit screens
     */
    public function add_meta_boxes() {
        $post_types = array('page', 'post');
        
        foreach ($post_types as $post_type) {
            add_meta_box(
                'bsp-static-control',
                __('Static Page Generation', 'breakdance-static-pages'),
                array($this, 'meta_box_callback'),
                $post_type,
                'side',
                'high'
            );
        }
    }
    
    /**
     * Meta box callback
     */
    public function meta_box_callback($post) {
        wp_nonce_field('bsp_meta_box', 'bsp_meta_box_nonce');
        
        $static_enabled = get_post_meta($post->ID, '_bsp_static_enabled', true);
        $static_generated = get_post_meta($post->ID, '_bsp_static_generated', true);
        $file_size = get_post_meta($post->ID, '_bsp_static_file_size', true);
        $static_file_exists = file_exists(Breakdance_Static_Pages::get_static_file_path($post->ID));
        
        ?>
        <div class="bsp-meta-box">
            <p>
                <label>
                    <input type="checkbox" 
                           name="bsp_static_enabled" 
                           value="1" 
                           <?php checked($static_enabled, '1'); ?>>
                    <?php _e('Enable static generation for this page', 'breakdance-static-pages'); ?>
                </label>
            </p>
            
            <?php if ($static_enabled): ?>
                <div class="bsp-meta-info">
                    <?php if ($static_file_exists): ?>
                        <p class="bsp-status-active">
                            ⚡ <?php _e('Static file active', 'breakdance-static-pages'); ?>
                        </p>
                    <?php else: ?>
                        <p class="bsp-status-pending">
                            ⏳ <?php _e('Static file needs generation', 'breakdance-static-pages'); ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if ($static_generated): ?>
                        <p>
                            <strong><?php _e('Last generated:', 'breakdance-static-pages'); ?></strong><br>
                            <?php echo human_time_diff(strtotime($static_generated), current_time('timestamp')) . ' ago'; ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if ($file_size): ?>
                        <p>
                            <strong><?php _e('File size:', 'breakdance-static-pages'); ?></strong><br>
                            <?php echo size_format($file_size); ?>
                        </p>
                    <?php endif; ?>
                    
                    <p>
                        <button type="button" 
                                class="button button-small bsp-generate-single" 
                                data-post-id="<?php echo $post->ID; ?>">
                            <?php _e('Generate Now', 'breakdance-static-pages'); ?>
                        </button>
                        
                        <?php if ($static_file_exists): ?>
                            <a href="<?php echo Breakdance_Static_Pages::get_static_file_url($post->ID); ?>" 
                               target="_blank" 
                               class="button button-small">
                                <?php _e('View Static', 'breakdance-static-pages'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
            
            <p class="description">
                <?php _e('Static generation creates a fast-loading HTML version of this page while preserving all Breakdance functionality.', 'breakdance-static-pages'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Save meta box data
     */
    public function save_meta_box_data($post_id) {
        // Check nonce
        if (!isset($_POST['bsp_meta_box_nonce']) || !wp_verify_nonce($_POST['bsp_meta_box_nonce'], 'bsp_meta_box')) {
            return;
        }
        
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Save static enabled setting
        $static_enabled = isset($_POST['bsp_static_enabled']) ? '1' : '';
        update_post_meta($post_id, '_bsp_static_enabled', $static_enabled);
        
        // If static generation was disabled, delete the static file
        if (!$static_enabled) {
            $generator = new BSP_Static_Generator();
            $generator->delete_static_page($post_id);
        }
    }
    
    /**
     * Show admin notices
     */
    public function show_admin_notices() {
        if (isset($_GET['bsp_regenerated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 __('Static page regenerated successfully!', 'breakdance-static-pages') . 
                 '</p></div>';
        }
        
        if (isset($_GET['bsp_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . 
                 __('Error regenerating static page. Please check the error logs.', 'breakdance-static-pages') . 
                 '</p></div>';
        }
    }
}

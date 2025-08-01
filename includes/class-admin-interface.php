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
        add_action('admin_init', array($this, 'save_settings'));
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
        // Only load on plugin admin page
        if ($hook === 'tools_page_breakdance-static-pages') {
            // Load all scripts for plugin admin page
        } 
        // Only load on post edit screens for supported post types
        elseif (($hook === 'post.php' || $hook === 'post-new.php')) {
            global $post;
            // Only load for pages and posts, not other post types
            if (!$post || !in_array($post->post_type, array('page', 'post'), true)) {
                return;
            }
        }
        // Only load on dashboard if widget is enabled and user wants it
        elseif ($hook === 'index.php') {
            // Check if dashboard widget is disabled
            if (get_option('bsp_disable_dashboard_widget', false)) {
                return;
            }
            // Only enqueue minimal scripts needed for dashboard widget
            wp_enqueue_script(
                'bsp-dashboard-widget',
                BSP_PLUGIN_URL . 'assets/dashboard-widget.js',
                array('jquery'),
                BSP_VERSION,
                true
            );
            wp_localize_script('bsp-dashboard-widget', 'bsp_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('bsp_nonce')
            ));
            return;
        }
        else {
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
        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'pages';
        
        ?>
        <div class="wrap">
            <h1><?php _e('Breakdance Static Pages', 'breakdance-static-pages'); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=breakdance-static-pages&tab=pages" class="nav-tab <?php echo $current_tab === 'pages' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Pages', 'breakdance-static-pages'); ?>
                </a>
                <a href="?page=breakdance-static-pages&tab=health" class="nav-tab <?php echo $current_tab === 'health' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Health Check', 'breakdance-static-pages'); ?>
                </a>
                <a href="?page=breakdance-static-pages&tab=errors" class="nav-tab <?php echo $current_tab === 'errors' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Errors', 'breakdance-static-pages'); ?>
                    <?php
                    $error_handler = BSP_Error_Handler::get_instance();
                    $error_stats = $error_handler->get_error_stats();
                    if ($error_stats['total'] > 0) {
                        echo '<span class="update-plugins count-' . $error_stats['total'] . '"><span class="plugin-count">' . $error_stats['total'] . '</span></span>';
                    }
                    ?>
                </a>
                <a href="?page=breakdance-static-pages&tab=queue" class="nav-tab <?php echo $current_tab === 'queue' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Queue', 'breakdance-static-pages'); ?>
                    <?php
                    $queue_manager = BSP_Queue_Manager::get_instance();
                    $queue_status = $queue_manager->get_queue_status();
                    if ($queue_status['pending'] > 0) {
                        echo '<span class="update-plugins count-' . $queue_status['pending'] . '"><span class="plugin-count">' . $queue_status['pending'] . '</span></span>';
                    }
                    ?>
                </a>
                <a href="?page=breakdance-static-pages&tab=seo" class="nav-tab <?php echo $current_tab === 'seo' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('SEO Protection', 'breakdance-static-pages'); ?>
                </a>
                <a href="?page=breakdance-static-pages&tab=repair" class="nav-tab <?php echo $current_tab === 'repair' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Data Repair', 'breakdance-static-pages'); ?>
                    <?php
                    // Check if repair is needed
                    $analysis = BSP_Data_Repair::analyze_data_state();
                    if (!empty($analysis['recommendations'])) {
                        echo ' <span class="dashicons dashicons-warning" style="color: #d63638;" title="' . esc_attr__('Issues detected', 'breakdance-static-pages') . '"></span>';
                    }
                    ?>
                </a>
                <a href="?page=breakdance-static-pages&tab=settings" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Settings', 'breakdance-static-pages'); ?>
                </a>
            </nav>
            
            <div class="tab-content">
                <?php
                switch ($current_tab) {
                    case 'health':
                        $this->render_health_tab();
                        break;
                    case 'errors':
                        $this->render_errors_tab();
                        break;
                    case 'queue':
                        $this->render_queue_tab();
                        break;
                    case 'seo':
                        $this->render_seo_tab();
                        break;
                    case 'repair':
                        $this->render_repair_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'pages':
                    default:
                        $this->render_pages_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render pages tab content
     */
    private function render_pages_tab() {
        // Get all pages and posts
        $pages = $this->get_eligible_pages();
        // Use cached stats for better performance
        $stats = BSP_Stats_Cache::get_stats();
        
        ?>
            
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
        <?php
    }
    
    /**
     * Render health check tab
     */
    private function render_health_tab() {
        // Get health check data
        $health_data = get_transient('bsp_health_check_results');
        
        if (!$health_data) {
            // Run health check if no cached data
            $health_check = new BSP_Health_Check();
            $health_data = $health_check->run_health_check();
        }
        
        $status_class = 'notice-success';
        $status_icon = '✅';
        
        if ($health_data['status'] === 'warning') {
            $status_class = 'notice-warning';
            $status_icon = '⚠️';
        } elseif ($health_data['status'] === 'critical') {
            $status_class = 'notice-error';
            $status_icon = '❌';
        }
        
        ?>
        <div class="bsp-health-check-container">
            <div class="notice <?php echo esc_attr($status_class); ?> inline">
                <h2>
                    <?php echo $status_icon; ?> 
                    <?php printf(__('System Status: %s', 'breakdance-static-pages'), ucfirst($health_data['status'])); ?>
                </h2>
                <p><?php printf(
                    __('%d checks passed, %d warnings, %d critical issues', 'breakdance-static-pages'),
                    $health_data['summary']['passed'],
                    $health_data['summary']['warnings'],
                    $health_data['summary']['critical']
                ); ?></p>
            </div>
            
            <div class="bsp-health-actions">
                <button type="button" class="button button-primary" id="bsp-run-health-check">
                    <?php _e('Run Health Check', 'breakdance-static-pages'); ?>
                </button>
                <span class="spinner"></span>
            </div>
            
            <div class="bsp-health-results">
                <h3><?php _e('Health Check Results', 'breakdance-static-pages'); ?></h3>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Check', 'breakdance-static-pages'); ?></th>
                            <th><?php _e('Status', 'breakdance-static-pages'); ?></th>
                            <th><?php _e('Details', 'breakdance-static-pages'); ?></th>
                            <th><?php _e('Recommendation', 'breakdance-static-pages'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($health_data['checks'] as $check_name => $check_data): ?>
                            <tr>
                                <td><strong><?php echo esc_html(ucwords(str_replace('_', ' ', $check_name))); ?></strong></td>
                                <td>
                                    <?php
                                    $check_icon = '✅';
                                    $check_color = '#46b450';
                                    
                                    if ($check_data['status'] === 'warning') {
                                        $check_icon = '⚠️';
                                        $check_color = '#ffb900';
                                    } elseif ($check_data['status'] === 'critical') {
                                        $check_icon = '❌';
                                        $check_color = '#dc3232';
                                    }
                                    ?>
                                    <span style="color: <?php echo $check_color; ?>"><?php echo $check_icon; ?> <?php echo ucfirst($check_data['status']); ?></span>
                                </td>
                                <td><?php echo esc_html($check_data['message']); ?></td>
                                <td><?php echo isset($check_data['recommendation']) ? esc_html($check_data['recommendation']) : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if (!empty($health_data['recommendations'])): ?>
                    <h3><?php _e('Recommendations', 'breakdance-static-pages'); ?></h3>
                    <ul class="bsp-recommendations">
                        <?php foreach ($health_data['recommendations'] as $recommendation): ?>
                            <li><?php echo esc_html($recommendation); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render errors tab
     */
    private function render_errors_tab() {
        $error_handler = BSP_Error_Handler::get_instance();
        $errors = $error_handler->get_recent_errors(null, null, 100);
        $error_stats = $error_handler->get_error_stats();
        
        ?>
        <div class="bsp-errors-container">
            <div class="bsp-errors-header">
                <h2><?php _e('Error Log', 'breakdance-static-pages'); ?></h2>
                
                <div class="bsp-error-stats">
                    <div class="stat-card">
                        <span class="stat-number"><?php echo $error_stats['total']; ?></span>
                        <span class="stat-label"><?php _e('Total Errors', 'breakdance-static-pages'); ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-number"><?php echo $error_stats['last_24h']; ?></span>
                        <span class="stat-label"><?php _e('Last 24h', 'breakdance-static-pages'); ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-number"><?php echo $error_stats['last_7d']; ?></span>
                        <span class="stat-label"><?php _e('Last 7 days', 'breakdance-static-pages'); ?></span>
                    </div>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div class="bsp-error-actions">
                        <button type="button" class="button" id="bsp-clear-errors">
                            <?php _e('Clear All Errors', 'breakdance-static-pages'); ?>
                        </button>
                        <button type="button" class="button" id="bsp-export-errors">
                            <?php _e('Export Errors', 'breakdance-static-pages'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (empty($errors)): ?>
                <div class="notice notice-success inline">
                    <p><?php _e('No errors recorded. Great job!', 'breakdance-static-pages'); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="150"><?php _e('Time', 'breakdance-static-pages'); ?></th>
                            <th width="100"><?php _e('Severity', 'breakdance-static-pages'); ?></th>
                            <th width="150"><?php _e('Context', 'breakdance-static-pages'); ?></th>
                            <th><?php _e('Message', 'breakdance-static-pages'); ?></th>
                            <th width="100"><?php _e('Actions', 'breakdance-static-pages'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $error): ?>
                            <?php
                            $severity_class = 'notice-info';
                            if ($error['severity'] === 'warning') {
                                $severity_class = 'notice-warning';
                            } elseif ($error['severity'] === 'error' || $error['severity'] === 'critical') {
                                $severity_class = 'notice-error';
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html($error['time']); ?></td>
                                <td>
                                    <span class="error-severity <?php echo esc_attr($severity_class); ?>">
                                        <?php echo esc_html(ucfirst($error['severity'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($error['context']); ?></td>
                                <td>
                                    <?php echo esc_html($error['message']); ?>
                                    <?php if (!empty($error['data'])): ?>
                                        <details>
                                            <summary><?php _e('View details', 'breakdance-static-pages'); ?></summary>
                                            <pre><?php echo esc_html(print_r($error['data'], true)); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small view-error-details" data-error-id="<?php echo esc_attr($error['id']); ?>">
                                        <?php _e('Details', 'breakdance-static-pages'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render queue tab
     */
    private function render_queue_tab() {
        $queue_manager = BSP_Queue_Manager::get_instance();
        $queue_status = $queue_manager->get_queue_status();
        $queue_items = $queue_manager->get_queue_items(array('limit' => 50));
        $processing_stats = $queue_manager->get_processing_stats();
        $progress_tracker = BSP_Progress_Tracker::get_instance();
        $active_sessions = $progress_tracker->get_all_active_sessions();
        
        ?>
        <div class="bsp-queue-container">
            <h2><?php _e('Background Queue', 'breakdance-static-pages'); ?></h2>
            
            <!-- Queue Status -->
            <div class="bsp-queue-stats">
                <div class="stat-card">
                    <span class="stat-number"><?php echo $queue_status['pending']; ?></span>
                    <span class="stat-label"><?php _e('Pending', 'breakdance-static-pages'); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo $queue_status['processing']; ?></span>
                    <span class="stat-label"><?php _e('Processing', 'breakdance-static-pages'); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo $queue_status['completed']; ?></span>
                    <span class="stat-label"><?php _e('Completed', 'breakdance-static-pages'); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-number"><?php echo $queue_status['failed']; ?></span>
                    <span class="stat-label"><?php _e('Failed', 'breakdance-static-pages'); ?></span>
                </div>
            </div>
            
            <!-- Processing Stats -->
            <div class="bsp-processing-stats">
                <h3><?php _e('Processing Statistics', 'breakdance-static-pages'); ?></h3>
                <ul>
                    <li><?php printf(__('Average processing time: %s seconds', 'breakdance-static-pages'), $processing_stats['avg_processing_time']); ?></li>
                    <li><?php printf(__('Success rate: %s%%', 'breakdance-static-pages'), $processing_stats['success_rate']); ?></li>
                    <li><?php printf(__('Total processed: %d', 'breakdance-static-pages'), $processing_stats['total_processed']); ?></li>
                    <?php if ($queue_status['next_run']): ?>
                        <li><?php printf(__('Next run: %s', 'breakdance-static-pages'), human_time_diff(time(), $queue_status['next_run'])); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <!-- Active Progress Sessions -->
            <?php if (!empty($active_sessions)): ?>
                <div class="bsp-active-progress">
                    <h3><?php _e('Active Operations', 'breakdance-static-pages'); ?></h3>
                    <?php foreach ($active_sessions as $session): ?>
                        <?php echo BSP_Progress_Tracker::render_progress_bar($session); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Queue Actions -->
            <div class="bsp-queue-actions">
                <button type="button" class="button" id="bsp-retry-failed">
                    <?php _e('Retry Failed Items', 'breakdance-static-pages'); ?>
                </button>
                <button type="button" class="button" id="bsp-clear-completed">
                    <?php _e('Clear Completed', 'breakdance-static-pages'); ?>
                </button>
                <button type="button" class="button button-link-delete" id="bsp-clear-queue">
                    <?php _e('Clear All Queue', 'breakdance-static-pages'); ?>
                </button>
            </div>
            
            <!-- Queue Items -->
            <?php if (!empty($queue_items)): ?>
                <h3><?php _e('Queue Items', 'breakdance-static-pages'); ?></h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="50"><?php _e('ID', 'breakdance-static-pages'); ?></th>
                            <th><?php _e('Item', 'breakdance-static-pages'); ?></th>
                            <th width="100"><?php _e('Action', 'breakdance-static-pages'); ?></th>
                            <th width="100"><?php _e('Status', 'breakdance-static-pages'); ?></th>
                            <th width="80"><?php _e('Priority', 'breakdance-static-pages'); ?></th>
                            <th width="80"><?php _e('Attempts', 'breakdance-static-pages'); ?></th>
                            <th width="150"><?php _e('Created', 'breakdance-static-pages'); ?></th>
                            <th><?php _e('Error', 'breakdance-static-pages'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queue_items as $item): ?>
                            <tr>
                                <td><?php echo esc_html($item->id); ?></td>
                                <td>
                                    <?php 
                                    if ($item->item_type === 'post') {
                                        $post = get_post($item->item_id);
                                        if ($post) {
                                            echo esc_html($post->post_title);
                                        } else {
                                            echo sprintf(__('Post #%d (deleted)', 'breakdance-static-pages'), $item->item_id);
                                        }
                                    } else {
                                        echo esc_html($item->item_type . ' #' . $item->item_id);
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($item->action); ?></td>
                                <td>
                                    <span class="queue-status status-<?php echo esc_attr($item->status); ?>">
                                        <?php echo esc_html($item->status); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($item->priority); ?></td>
                                <td><?php echo esc_html($item->attempts . '/' . $item->max_attempts); ?></td>
                                <td><?php echo esc_html($item->created_at); ?></td>
                                <td>
                                    <?php if ($item->error_message): ?>
                                        <span class="error-message" title="<?php echo esc_attr($item->error_message); ?>">
                                            <?php echo esc_html(substr($item->error_message, 0, 50) . (strlen($item->error_message) > 50 ? '...' : '')); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php _e('No items in queue.', 'breakdance-static-pages'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render settings tab
     */
    private function render_settings_tab() {
        ?>
        <div class="bsp-settings-container">
            <h2><?php _e('Plugin Settings', 'breakdance-static-pages'); ?></h2>
            <p><?php _e('Configure Breakdance Static Pages plugin settings.', 'breakdance-static-pages'); ?></p>
            
            <form method="post" action="">
                <?php wp_nonce_field('bsp_settings', 'bsp_settings_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label><?php _e('Dashboard Widget', 'breakdance-static-pages'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="bsp_disable_dashboard_widget" value="1" <?php checked(get_option('bsp_disable_dashboard_widget', false)); ?> />
                                <?php _e('Disable performance dashboard widget', 'breakdance-static-pages'); ?>
                            </label>
                            <p class="description"><?php _e('Disable the dashboard widget to improve admin performance', 'breakdance-static-pages'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php _e('Batch Size', 'breakdance-static-pages'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="bsp_batch_size" value="<?php echo esc_attr(get_option('bsp_batch_size', 3)); ?>" min="1" max="10" />
                            <p class="description"><?php _e('Number of pages to process at once during bulk operations (lower = more reliable on shared hosting)', 'breakdance-static-pages'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php _e('Cache Duration', 'breakdance-static-pages'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="bsp_cache_duration" value="3600" min="60" max="86400" />
                            <p class="description"><?php _e('How long to cache static files (in seconds)', 'breakdance-static-pages'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php _e('Auto-regeneration', 'breakdance-static-pages'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="bsp_auto_regenerate" value="1" checked />
                                <?php _e('Automatically regenerate static files when content changes', 'breakdance-static-pages'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label><?php _e('File Retention', 'breakdance-static-pages'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="bsp_file_retention" value="30" min="1" max="365" />
                            <p class="description"><?php _e('Days to keep old static files before cleanup', 'breakdance-static-pages'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php _e('Save Settings', 'breakdance-static-pages'); ?></button>
                </p>
            </form>
            
            <hr />
            
            <h3><?php _e('Maintenance Actions', 'breakdance-static-pages'); ?></h3>
            
            <div class="bsp-maintenance-actions">
                <p>
                    <button type="button" class="button" id="bsp-cleanup-orphaned">
                        <?php _e('Clean Orphaned Files', 'breakdance-static-pages'); ?>
                    </button>
                    <span class="description"><?php _e('Remove static files for deleted pages', 'breakdance-static-pages'); ?></span>
                </p>
                
                <p>
                    <button type="button" class="button" id="bsp-clear-all-locks">
                        <?php _e('Clear All Locks', 'breakdance-static-pages'); ?>
                    </button>
                    <span class="description"><?php _e('Force release all file locks (use with caution)', 'breakdance-static-pages'); ?></span>
                </p>
                
                <p>
                    <button type="button" class="button button-link-delete" id="bsp-delete-all-static" onclick="return confirm('<?php esc_attr_e('Are you sure? This will delete all static files!', 'breakdance-static-pages'); ?>');">
                        <?php _e('Delete All Static Files', 'breakdance-static-pages'); ?>
                    </button>
                    <span class="description"><?php _e('Remove all generated static files', 'breakdance-static-pages'); ?></span>
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render data repair tab
     */
    private function render_repair_tab() {
        // Analyze current data state
        $analysis = BSP_Data_Repair::analyze_data_state();
        $summary = $analysis['summary'];
        
        ?>
        <div class="bsp-admin-section">
            <h2><?php _e('Data Analysis', 'breakdance-static-pages'); ?></h2>
            
            <div class="bsp-stats-grid">
                <div class="bsp-stat-card">
                    <h3><?php echo esc_html($summary['total_posts']); ?></h3>
                    <p><?php _e('Total Posts/Pages', 'breakdance-static-pages'); ?></p>
                </div>
                <div class="bsp-stat-card">
                    <h3><?php echo esc_html($summary['enabled_with_1']); ?></h3>
                    <p><?php _e('Static Enabled', 'breakdance-static-pages'); ?></p>
                </div>
                <div class="bsp-stat-card">
                    <h3><?php echo esc_html($summary['has_generated']); ?></h3>
                    <p><?php _e('Has Generated Meta', 'breakdance-static-pages'); ?></p>
                </div>
                <div class="bsp-stat-card">
                    <h3><?php echo esc_html($summary['actual_files']); ?></h3>
                    <p><?php _e('Actual Static Files', 'breakdance-static-pages'); ?></p>
                </div>
            </div>
            
            <?php if (!empty($analysis['recommendations'])): ?>
                <div class="notice notice-warning">
                    <p><strong><?php _e('Issues Detected:', 'breakdance-static-pages'); ?></strong></p>
                    <ul>
                        <?php foreach ($analysis['recommendations'] as $recommendation): ?>
                            <li><?php echo esc_html($recommendation); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                <div class="notice notice-success">
                    <p><?php _e('No data inconsistencies detected. Your static pages data is healthy!', 'breakdance-static-pages'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="bsp-admin-section">
            <h2><?php _e('Data Repair Actions', 'breakdance-static-pages'); ?></h2>
            
            <div class="bsp-action-cards">
                <div class="bsp-action-card">
                    <h3><?php _e('Quick Repair', 'breakdance-static-pages'); ?></h3>
                    <p><?php _e('Run all repair operations to fix data inconsistencies, sync files with database, and clean orphaned data.', 'breakdance-static-pages'); ?></p>
                    <button type="button" class="button button-primary" id="bsp-run-repair">
                        <?php _e('Run All Repairs', 'breakdance-static-pages'); ?>
                    </button>
                </div>
                
                <div class="bsp-action-card">
                    <h3><?php _e('Repair Missing Metadata', 'breakdance-static-pages'); ?></h3>
                    <p><?php _e('Find static files without metadata and repair the database records.', 'breakdance-static-pages'); ?></p>
                    <button type="button" class="button" id="bsp-repair-metadata">
                        <?php _e('Repair Metadata', 'breakdance-static-pages'); ?>
                    </button>
                </div>
                
                <div class="bsp-action-card">
                    <h3><?php _e('Fix Meta Values', 'breakdance-static-pages'); ?></h3>
                    <p><?php _e('Standardize inconsistent meta values and remove invalid entries.', 'breakdance-static-pages'); ?></p>
                    <button type="button" class="button" id="bsp-fix-meta-values">
                        <?php _e('Fix Meta Values', 'breakdance-static-pages'); ?>
                    </button>
                </div>
                
                <div class="bsp-action-card">
                    <h3><?php _e('Sync Files with Database', 'breakdance-static-pages'); ?></h3>
                    <p><?php _e('Ensure database records match actual static files on disk.', 'breakdance-static-pages'); ?></p>
                    <button type="button" class="button" id="bsp-sync-files">
                        <?php _e('Sync Files', 'breakdance-static-pages'); ?>
                    </button>
                </div>
                
                <div class="bsp-action-card">
                    <h3><?php _e('Clean Orphaned Data', 'breakdance-static-pages'); ?></h3>
                    <p><?php _e('Remove metadata for deleted posts and files without corresponding posts.', 'breakdance-static-pages'); ?></p>
                    <button type="button" class="button" id="bsp-clean-orphaned">
                        <?php _e('Clean Orphaned Data', 'breakdance-static-pages'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="bsp-admin-section">
            <h2><?php _e('Additional Information', 'breakdance-static-pages'); ?></h2>
            
            <div class="bsp-info-section">
                <h3><?php _e('Meta Value States', 'breakdance-static-pages'); ?></h3>
                <ul>
                    <li><?php printf(__('Posts with _bsp_static_enabled = "1": %d', 'breakdance-static-pages'), $summary['enabled_with_1']); ?></li>
                    <li><?php printf(__('Posts with _bsp_static_enabled = "" (empty): %d', 'breakdance-static-pages'), $summary['enabled_empty']); ?></li>
                    <li><?php printf(__('Posts without _bsp_static_enabled: %d', 'breakdance-static-pages'), $summary['enabled_null']); ?></li>
                    <li><?php printf(__('Posts with file size metadata: %d', 'breakdance-static-pages'), $summary['has_file_size']); ?></li>
                </ul>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Run all repairs
            $('#bsp-run-repair').on('click', function() {
                if (!confirm('<?php _e('This will run all repair operations. Continue?', 'breakdance-static-pages'); ?>')) {
                    return;
                }
                
                var $button = $(this);
                $button.prop('disabled', true).text('<?php _e('Running repairs...', 'breakdance-static-pages'); ?>');
                
                $.ajax({
                    url: bsp_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'bsp_run_data_repair',
                        repair_type: 'all',
                        nonce: bsp_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            window.location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php _e('Run All Repairs', 'breakdance-static-pages'); ?>');
                    }
                });
            });
            
            // Individual repair actions
            $('#bsp-repair-metadata').on('click', function() { runRepair('metadata', this); });
            $('#bsp-fix-meta-values').on('click', function() { runRepair('meta_values', this); });
            $('#bsp-sync-files').on('click', function() { runRepair('file_sync', this); });
            $('#bsp-clean-orphaned').on('click', function() { runRepair('orphaned', this); });
            
            function runRepair(type, button) {
                var $button = $(button);
                $button.prop('disabled', true);
                
                $.ajax({
                    url: bsp_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'bsp_run_data_repair',
                        repair_type: type,
                        nonce: bsp_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            window.location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                    }
                });
            }
        });
        </script>
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
     * Render SEO tab content
     */
    private function render_seo_tab() {
        $seo_recommendations = BSP_SEO_Protection::get_seo_recommendations();
        $seo_validation = BSP_SEO_Protection::validate_seo_config();
        ?>
        <div class="bsp-seo-tab">
            <h2><?php _e('SEO Protection Status', 'breakdance-static-pages'); ?></h2>
            
            <div class="notice notice-info">
                <p><strong><?php _e('SEO Protection Overview:', 'breakdance-static-pages'); ?></strong></p>
                <p><?php _e('This plugin automatically protects your site from duplicate content issues by adding proper SEO tags to static files. Static files include canonical URLs pointing to your original dynamic pages and noindex meta tags to prevent search engines from indexing the static versions.', 'breakdance-static-pages'); ?></p>
            </div>

            <h3><?php _e('Protection Features', 'breakdance-static-pages'); ?></h3>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 200px;"><?php _e('Feature', 'breakdance-static-pages'); ?></th>
                        <th style="width: 80px;"><?php _e('Status', 'breakdance-static-pages'); ?></th>
                        <th><?php _e('Description', 'breakdance-static-pages'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($seo_recommendations as $key => $recommendation): ?>
                    <tr>
                        <td><strong><?php echo esc_html($recommendation['title']); ?></strong></td>
                        <td>
                            <?php if ($recommendation['status'] === 'good'): ?>
                                <span class="dashicons dashicons-yes" style="color: #46b450;"></span>
                            <?php elseif ($recommendation['status'] === 'warning'): ?>
                                <span class="dashicons dashicons-warning" style="color: #ffb900;"></span>
                            <?php else: ?>
                                <span class="dashicons dashicons-no" style="color: #dc3232;"></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($recommendation['description']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3><?php _e('Configuration Validation', 'breakdance-static-pages'); ?></h3>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 200px;"><?php _e('Check', 'breakdance-static-pages'); ?></th>
                        <th style="width: 80px;"><?php _e('Status', 'breakdance-static-pages'); ?></th>
                        <th><?php _e('Details', 'breakdance-static-pages'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($seo_validation as $key => $validation): ?>
                    <tr>
                        <td><strong><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?></strong></td>
                        <td>
                            <?php if ($validation['status'] === 'good'): ?>
                                <span class="dashicons dashicons-yes" style="color: #46b450;"></span>
                            <?php elseif ($validation['status'] === 'warning'): ?>
                                <span class="dashicons dashicons-warning" style="color: #ffb900;"></span>
                            <?php elseif ($validation['status'] === 'info'): ?>
                                <span class="dashicons dashicons-info" style="color: #72aee6;"></span>
                            <?php else: ?>
                                <span class="dashicons dashicons-no" style="color: #dc3232;"></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($validation['message']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3><?php _e('Technical Implementation', 'breakdance-static-pages'); ?></h3>
            <div class="bsp-seo-details">
                <h4><?php _e('Meta Tags Added to Static Files:', 'breakdance-static-pages'); ?></h4>
                <ul>
                    <li><code>&lt;link rel="canonical" href="original-page-url"&gt;</code> - Points search engines to the original dynamic page</li>
                    <li><code>&lt;meta name="robots" content="noindex, nofollow"&gt;</code> - Prevents indexing of static versions</li>
                    <li><code>&lt;meta name="googlebot" content="noindex, nofollow"&gt;</code> - Specific Google bot instructions</li>
                    <li><code>&lt;meta name="bingbot" content="noindex, nofollow"&gt;</code> - Specific Bing bot instructions</li>
                </ul>

                <h4><?php _e('HTTP Headers Added:', 'breakdance-static-pages'); ?></h4>
                <ul>
                    <li><code>X-Robots-Tag: noindex, nofollow</code> - Server-level robots directive</li>
                    <li><code>X-Robots-Tag: noarchive, nosnippet</code> - Additional protection</li>
                    <li><code>Link: &lt;original-url&gt;; rel="canonical"</code> - HTTP canonical header</li>
                </ul>

                <h4><?php _e('Robots.txt Rules:', 'breakdance-static-pages'); ?></h4>
                <pre style="background: #f0f0f0; padding: 10px; border-radius: 4px;">
# Breakdance Static Pages - Prevent indexing of static files
User-agent: *
Disallow: /wp-content/uploads/breakdance-static-pages/
Disallow: /wp-content/uploads/breakdance-static-pages/pages/
Disallow: /wp-content/uploads/breakdance-static-pages/assets/
                </pre>
            </div>

            <div class="notice notice-warning">
                <p><strong><?php _e('Important:', 'breakdance-static-pages'); ?></strong></p>
                <ul>
                    <li><?php _e('Static files are designed for performance improvement, not direct user access', 'breakdance-static-pages'); ?></li>
                    <li><?php _e('Only the original dynamic URLs should appear in search results', 'breakdance-static-pages'); ?></li>
                    <li><?php _e('Users will automatically get static versions served on the original URLs for better performance', 'breakdance-static-pages'); ?></li>
                    <li><?php _e('All SEO benefits (meta tags, structured data, etc.) are preserved from the original dynamic pages', 'breakdance-static-pages'); ?></li>
                </ul>
            </div>

            <h3><?php _e('Customization Options', 'breakdance-static-pages'); ?></h3>
            <p><?php _e('Advanced users can customize SEO protection using these filters:', 'breakdance-static-pages'); ?></p>
            <pre style="background: #f0f0f0; padding: 10px; border-radius: 4px;">
// Customize robots meta content for static files
add_filter('bsp_static_robots_meta', function($content, $post_id) {
    return 'noindex, nofollow, noarchive';
}, 10, 2);

// Modify cache age for static files  
add_filter('bsp_static_cache_max_age', function($age) {
    return 7200; // 2 hours
});
            </pre>
        </div>
        <?php
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
        
        if (isset($_GET['bsp_settings_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 __('Settings saved successfully!', 'breakdance-static-pages') . 
                 '</p></div>';
        }
    }
    
    /**
     * Save plugin settings
     */
    public function save_settings() {
        if (!isset($_POST['bsp_settings_nonce']) || !wp_verify_nonce($_POST['bsp_settings_nonce'], 'bsp_settings')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Save dashboard widget setting
        $disable_widget = isset($_POST['bsp_disable_dashboard_widget']) ? 1 : 0;
        update_option('bsp_disable_dashboard_widget', $disable_widget);
        
        // Save batch size setting
        if (isset($_POST['bsp_batch_size'])) {
            $batch_size = intval($_POST['bsp_batch_size']);
            $batch_size = max(1, min(10, $batch_size)); // Ensure between 1-10
            update_option('bsp_batch_size', $batch_size);
        }
        
        // Redirect to avoid resubmission
        wp_safe_redirect(add_query_arg('bsp_settings_saved', '1', wp_get_referer()));
        exit;
    }
}

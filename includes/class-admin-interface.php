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

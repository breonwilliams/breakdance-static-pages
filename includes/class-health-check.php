<?php
/**
 * Health Check System
 *
 * Monitors plugin health and provides diagnostic information.
 *
 * @package Breakdance_Static_Pages
 * @since 1.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_Health_Check
 *
 * Provides health monitoring and diagnostic capabilities for the plugin.
 */
class BSP_Health_Check {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add AJAX handler for health checks
        add_action('wp_ajax_bsp_health_check', array($this, 'handle_health_check'));
        
        // Add admin notice if critical issues detected
        add_action('admin_notices', array($this, 'display_health_notices'));
        
        // Add to admin bar for quick status
        add_action('admin_bar_menu', array($this, 'add_health_status_to_admin_bar'), 999);
        
        // Schedule periodic health checks
        add_action('init', array($this, 'schedule_health_checks'));
        add_action('bsp_scheduled_health_check', array($this, 'run_scheduled_health_check'));
    }
    
    /**
     * Handle AJAX health check request
     */
    public function handle_health_check() {
        // Verify permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Run health check
        $health_data = $this->run_health_check();
        
        // Store results for display
        set_transient('bsp_health_check_results', $health_data, HOUR_IN_SECONDS);
        
        wp_send_json_success($health_data);
    }
    
    /**
     * Run comprehensive health check
     *
     * @return array Health check results
     */
    public function run_health_check() {
        $health = array(
            'status' => 'healthy',
            'timestamp' => current_time('timestamp'),
            'checks' => array(),
            'issues' => array(),
            'recommendations' => array()
        );
        
        // Check 1: Write permissions
        $health['checks']['write_permissions'] = $this->check_write_permissions();
        
        // Check 2: Database integrity
        $health['checks']['database'] = $this->check_database_integrity();
        
        // Check 3: Memory usage
        $health['checks']['memory'] = $this->check_memory_usage();
        
        // Check 4: Disk space
        $health['checks']['disk_space'] = $this->check_disk_space();
        
        // Check 5: Lock system
        $health['checks']['locks'] = $this->check_lock_system();
        
        // Check 6: Cron jobs
        $health['checks']['cron'] = $this->check_cron_jobs();
        
        // Check 7: PHP configuration
        $health['checks']['php_config'] = $this->check_php_configuration();
        
        // Check 8: Plugin conflicts
        $health['checks']['conflicts'] = $this->check_plugin_conflicts();
        
        // Check 9: Static files integrity
        $health['checks']['static_files'] = $this->check_static_files_integrity();
        
        // Check 10: Performance metrics
        $health['checks']['performance'] = $this->check_performance_metrics();
        
        // Determine overall status
        $critical_issues = 0;
        $warnings = 0;
        
        foreach ($health['checks'] as $check => $result) {
            if ($result['status'] === 'critical') {
                $critical_issues++;
                $health['issues'][] = $result['message'];
            } elseif ($result['status'] === 'warning') {
                $warnings++;
                $health['issues'][] = $result['message'];
            }
            
            if (!empty($result['recommendation'])) {
                $health['recommendations'][] = $result['recommendation'];
            }
        }
        
        // Set overall status
        if ($critical_issues > 0) {
            $health['status'] = 'critical';
        } elseif ($warnings > 0) {
            $health['status'] = 'warning';
        }
        
        $health['summary'] = array(
            'total_checks' => count($health['checks']),
            'passed' => count(array_filter($health['checks'], function($check) {
                return $check['status'] === 'ok';
            })),
            'warnings' => $warnings,
            'critical' => $critical_issues
        );
        
        return $health;
    }
    
    /**
     * Check write permissions
     */
    private function check_write_permissions() {
        $result = array(
            'status' => 'ok',
            'message' => 'Write permissions are correctly configured',
            'details' => array()
        );
        
        $upload_dir = wp_upload_dir();
        $directories = array(
            'static_dir' => $upload_dir['basedir'] . '/breakdance-static-pages/',
            'pages_dir' => $upload_dir['basedir'] . '/breakdance-static-pages/pages/',
            'lock_dir' => $upload_dir['basedir'] . '/bsp-locks/'
        );
        
        foreach ($directories as $key => $dir) {
            if (!file_exists($dir)) {
                // Try to create it
                if (!wp_mkdir_p($dir)) {
                    $result['status'] = 'critical';
                    $result['message'] = sprintf('Cannot create directory: %s', $dir);
                    $result['recommendation'] = 'Check file permissions on wp-content/uploads/';
                    $result['details'][$key] = 'missing';
                    continue;
                }
            }
            
            if (!is_writable($dir)) {
                $result['status'] = 'critical';
                $result['message'] = sprintf('Directory not writable: %s', $dir);
                $result['recommendation'] = sprintf('Run: chmod 755 %s', $dir);
                $result['details'][$key] = 'not_writable';
            } else {
                $result['details'][$key] = 'ok';
            }
        }
        
        return $result;
    }
    
    /**
     * Check database integrity
     */
    private function check_database_integrity() {
        global $wpdb;
        
        $result = array(
            'status' => 'ok',
            'message' => 'Database connection is healthy',
            'details' => array()
        );
        
        // Test database connection
        try {
            $test = $wpdb->get_var("SELECT 1");
            if ($test !== '1') {
                $result['status'] = 'critical';
                $result['message'] = 'Database connection test failed';
            }
        } catch (Exception $e) {
            $result['status'] = 'critical';
            $result['message'] = 'Database error: ' . $e->getMessage();
        }
        
        // Check for orphaned meta
        $orphaned = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE p.ID IS NULL
            AND pm.meta_key LIKE '_bsp_%'
        ");
        
        if ($orphaned > 0) {
            $result['status'] = 'warning';
            $result['message'] = sprintf('%d orphaned meta entries found', $orphaned);
            $result['recommendation'] = 'Run database cleanup from plugin settings';
            $result['details']['orphaned_meta'] = $orphaned;
        }
        
        return $result;
    }
    
    /**
     * Check memory usage
     */
    private function check_memory_usage() {
        $result = array(
            'status' => 'ok',
            'message' => 'Memory usage is within limits',
            'details' => array()
        );
        
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        
        $usage_percent = ($memory_usage / $memory_limit) * 100;
        $peak_percent = ($memory_peak / $memory_limit) * 100;
        
        $result['details'] = array(
            'current' => size_format($memory_usage),
            'peak' => size_format($memory_peak),
            'limit' => size_format($memory_limit),
            'usage_percent' => round($usage_percent, 2),
            'peak_percent' => round($peak_percent, 2)
        );
        
        if ($usage_percent > 80) {
            $result['status'] = 'critical';
            $result['message'] = sprintf('Memory usage critical: %d%% of limit', round($usage_percent));
            $result['recommendation'] = 'Increase PHP memory_limit in wp-config.php';
        } elseif ($usage_percent > 60) {
            $result['status'] = 'warning';
            $result['message'] = sprintf('Memory usage high: %d%% of limit', round($usage_percent));
        }
        
        return $result;
    }
    
    /**
     * Check disk space
     */
    private function check_disk_space() {
        $result = array(
            'status' => 'ok',
            'message' => 'Sufficient disk space available',
            'details' => array()
        );
        
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        
        // Get disk space info
        $free_space = disk_free_space($base_dir);
        $total_space = disk_total_space($base_dir);
        $used_space = $total_space - $free_space;
        $used_percent = ($used_space / $total_space) * 100;
        
        $result['details'] = array(
            'free' => size_format($free_space),
            'total' => size_format($total_space),
            'used' => size_format($used_space),
            'used_percent' => round($used_percent, 2)
        );
        
        // Check static files size
        $static_dir = $base_dir . '/breakdance-static-pages/';
        if (is_dir($static_dir)) {
            $static_size = $this->get_directory_size($static_dir);
            $result['details']['static_files_size'] = size_format($static_size);
        }
        
        if ($free_space < 100 * MB_IN_BYTES) {
            $result['status'] = 'critical';
            $result['message'] = 'Very low disk space: ' . size_format($free_space);
            $result['recommendation'] = 'Free up disk space immediately';
        } elseif ($free_space < 500 * MB_IN_BYTES) {
            $result['status'] = 'warning';
            $result['message'] = 'Low disk space: ' . size_format($free_space);
            $result['recommendation'] = 'Consider freeing up disk space';
        }
        
        return $result;
    }
    
    /**
     * Check lock system
     */
    private function check_lock_system() {
        $result = array(
            'status' => 'ok',
            'message' => 'Lock system is functioning properly',
            'details' => array()
        );
        
        $lock_manager = BSP_File_Lock_Manager::get_instance();
        $active_locks = $lock_manager->get_active_locks();
        
        $result['details']['active_locks'] = count($active_locks);
        
        // Check for stuck locks
        $stuck_locks = array_filter($active_locks, function($lock) {
            return (time() - $lock['timestamp']) > 3600; // 1 hour
        });
        
        if (count($stuck_locks) > 0) {
            $result['status'] = 'warning';
            $result['message'] = sprintf('%d stuck locks detected', count($stuck_locks));
            $result['recommendation'] = 'Run lock cleanup or restart generation process';
            $result['details']['stuck_locks'] = count($stuck_locks);
        }
        
        // Test lock functionality
        $test_post_id = 999999; // Non-existent post for testing
        if ($lock_manager->acquire_lock($test_post_id, 1)) {
            $lock_manager->release_lock($test_post_id);
            $result['details']['lock_test'] = 'passed';
        } else {
            $result['status'] = 'critical';
            $result['message'] = 'Lock system test failed';
            $result['details']['lock_test'] = 'failed';
        }
        
        return $result;
    }
    
    /**
     * Check cron jobs
     */
    private function check_cron_jobs() {
        $result = array(
            'status' => 'ok',
            'message' => 'All scheduled tasks are properly configured',
            'details' => array()
        );
        
        $required_crons = array(
            'bsp_cleanup_old_static_files' => 'daily',
            'bsp_cleanup_locks' => 'hourly'
        );
        
        $missing_crons = array();
        
        foreach ($required_crons as $hook => $schedule) {
            $next_run = wp_next_scheduled($hook);
            if (!$next_run) {
                $missing_crons[] = $hook;
                $result['details'][$hook] = 'not_scheduled';
            } else {
                $result['details'][$hook] = array(
                    'next_run' => date('Y-m-d H:i:s', $next_run),
                    'in' => human_time_diff(time(), $next_run)
                );
            }
        }
        
        if (count($missing_crons) > 0) {
            $result['status'] = 'warning';
            $result['message'] = 'Some scheduled tasks are not configured';
            $result['recommendation'] = 'Deactivate and reactivate the plugin';
            $result['details']['missing'] = $missing_crons;
        }
        
        // Check if WP-Cron is disabled
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $result['status'] = 'warning';
            $result['message'] = 'WP-Cron is disabled';
            $result['recommendation'] = 'Ensure system cron is configured to run wp-cron.php';
            $result['details']['wp_cron_disabled'] = true;
        }
        
        return $result;
    }
    
    /**
     * Check PHP configuration
     */
    private function check_php_configuration() {
        $result = array(
            'status' => 'ok',
            'message' => 'PHP configuration is optimal',
            'details' => array()
        );
        
        $warnings = array();
        
        // Check PHP version
        $result['details']['php_version'] = PHP_VERSION;
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $warnings[] = 'PHP version is below recommended 7.4';
        }
        
        // Check max execution time
        $max_execution = ini_get('max_execution_time');
        $result['details']['max_execution_time'] = $max_execution;
        if ($max_execution < 30 && $max_execution != 0) {
            $warnings[] = 'max_execution_time is too low';
        }
        
        // Check important functions
        $required_functions = array('curl_init', 'fopen', 'file_get_contents');
        foreach ($required_functions as $func) {
            if (!function_exists($func)) {
                $warnings[] = sprintf('Required function %s is disabled', $func);
                $result['details']['disabled_functions'][] = $func;
            }
        }
        
        if (count($warnings) > 0) {
            $result['status'] = 'warning';
            $result['message'] = implode('; ', $warnings);
            $result['recommendation'] = 'Contact your hosting provider to adjust PHP configuration';
        }
        
        return $result;
    }
    
    /**
     * Check for plugin conflicts
     */
    private function check_plugin_conflicts() {
        $result = array(
            'status' => 'ok',
            'message' => 'No known conflicts detected',
            'details' => array()
        );
        
        // Check for known conflicting plugins
        $known_conflicts = array(
            'wp-super-cache/wp-cache.php' => 'WP Super Cache may interfere with static file serving',
            'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache may conflict with static generation',
            'wp-rocket/wp-rocket.php' => 'WP Rocket may cache static file responses'
        );
        
        $active_plugins = get_option('active_plugins', array());
        $conflicts_found = array();
        
        foreach ($known_conflicts as $plugin => $message) {
            if (in_array($plugin, $active_plugins)) {
                $conflicts_found[$plugin] = $message;
            }
        }
        
        if (count($conflicts_found) > 0) {
            $result['status'] = 'warning';
            $result['message'] = 'Potential plugin conflicts detected';
            $result['details']['conflicts'] = $conflicts_found;
            $result['recommendation'] = 'Configure caching plugins to exclude static pages';
        }
        
        return $result;
    }
    
    /**
     * Check static files integrity
     */
    private function check_static_files_integrity() {
        global $wpdb;
        
        $result = array(
            'status' => 'ok',
            'message' => 'Static files are intact',
            'details' => array()
        );
        
        // Get enabled pages
        $enabled_pages = $wpdb->get_col("
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_bsp_static_enabled' 
            AND meta_value = '1'
        ");
        
        $missing_files = 0;
        $corrupted_files = 0;
        
        foreach ($enabled_pages as $post_id) {
            $file_path = Breakdance_Static_Pages::get_static_file_path($post_id);
            
            if (!file_exists($file_path)) {
                $missing_files++;
            } elseif (filesize($file_path) === 0) {
                $corrupted_files++;
            }
        }
        
        $result['details'] = array(
            'total_enabled' => count($enabled_pages),
            'missing_files' => $missing_files,
            'corrupted_files' => $corrupted_files
        );
        
        if ($missing_files > 0 || $corrupted_files > 0) {
            $result['status'] = 'warning';
            $result['message'] = sprintf(
                '%d missing and %d corrupted files detected',
                $missing_files,
                $corrupted_files
            );
            $result['recommendation'] = 'Regenerate affected static files';
        }
        
        return $result;
    }
    
    /**
     * Check performance metrics
     */
    private function check_performance_metrics() {
        $result = array(
            'status' => 'ok',
            'message' => 'Performance metrics are normal',
            'details' => array()
        );
        
        // Get average generation time from recent operations
        $recent_generations = get_transient('bsp_recent_generation_times');
        if ($recent_generations && is_array($recent_generations)) {
            $avg_time = array_sum($recent_generations) / count($recent_generations);
            $result['details']['avg_generation_time'] = round($avg_time, 2) . 's';
            
            if ($avg_time > 10) {
                $result['status'] = 'warning';
                $result['message'] = 'Generation times are high';
                $result['recommendation'] = 'Consider optimizing page content or server resources';
            }
        }
        
        return $result;
    }
    
    /**
     * Display admin notices for critical issues
     */
    public function display_health_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Only show on plugin pages or dashboard
        $screen = get_current_screen();
        $show_on_screens = array('dashboard', 'tools_page_breakdance-static-pages');
        
        if (!in_array($screen->id, $show_on_screens)) {
            return;
        }
        
        // Get cached health check results
        $health_data = get_transient('bsp_health_check_results');
        
        if (!$health_data || $health_data['status'] === 'healthy') {
            return;
        }
        
        $class = $health_data['status'] === 'critical' ? 'notice-error' : 'notice-warning';
        
        ?>
        <div class="notice <?php echo esc_attr($class); ?> is-dismissible">
            <p>
                <strong><?php _e('Breakdance Static Pages Health Check:', 'breakdance-static-pages'); ?></strong>
                <?php
                if ($health_data['status'] === 'critical') {
                    printf(
                        __('%d critical issue(s) detected. ', 'breakdance-static-pages'),
                        $health_data['summary']['critical']
                    );
                } else {
                    printf(
                        __('%d warning(s) detected. ', 'breakdance-static-pages'),
                        $health_data['summary']['warnings']
                    );
                }
                ?>
                <a href="<?php echo admin_url('tools.php?page=breakdance-static-pages&tab=health'); ?>">
                    <?php _e('View details', 'breakdance-static-pages'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Add health status to admin bar
     */
    public function add_health_status_to_admin_bar($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $health_data = get_transient('bsp_health_check_results');
        
        if (!$health_data) {
            return;
        }
        
        $icon = '✅';
        $color = '#46b450';
        
        if ($health_data['status'] === 'warning') {
            $icon = '⚠️';
            $color = '#ffb900';
        } elseif ($health_data['status'] === 'critical') {
            $icon = '❌';
            $color = '#dc3232';
        }
        
        $wp_admin_bar->add_node(array(
            'id' => 'bsp-health-status',
            'title' => sprintf(
                '<span style="color: %s">%s BSP Health</span>',
                $color,
                $icon
            ),
            'href' => admin_url('tools.php?page=breakdance-static-pages&tab=health'),
            'meta' => array(
                'title' => sprintf(
                    'Breakdance Static Pages: %s',
                    ucfirst($health_data['status'])
                )
            )
        ));
    }
    
    /**
     * Schedule periodic health checks
     */
    public function schedule_health_checks() {
        if (!wp_next_scheduled('bsp_scheduled_health_check')) {
            wp_schedule_event(time(), 'twicedaily', 'bsp_scheduled_health_check');
        }
    }
    
    /**
     * Run scheduled health check
     */
    public function run_scheduled_health_check() {
        $health_data = $this->run_health_check();
        
        // Log critical issues
        if ($health_data['status'] === 'critical') {
            error_log(sprintf(
                'BSP Health Check: Critical issues detected - %s',
                implode('; ', $health_data['issues'])
            ));
            
            // Optionally send email notification to admin
            if (apply_filters('bsp_notify_critical_health_issues', true)) {
                $this->send_critical_health_notification($health_data);
            }
        }
    }
    
    /**
     * Send email notification for critical health issues
     */
    private function send_critical_health_notification($health_data) {
        $to = get_option('admin_email');
        $subject = sprintf(
            '[%s] Breakdance Static Pages: Critical Health Issues',
            get_bloginfo('name')
        );
        
        $message = "Critical issues have been detected with Breakdance Static Pages:\n\n";
        
        foreach ($health_data['issues'] as $issue) {
            $message .= "• " . $issue . "\n";
        }
        
        $message .= "\nRecommendations:\n";
        foreach ($health_data['recommendations'] as $rec) {
            $message .= "• " . $rec . "\n";
        }
        
        $message .= "\n" . sprintf(
            "View details: %s",
            admin_url('tools.php?page=breakdance-static-pages&tab=health')
        );
        
        wp_mail($to, $subject, $message);
    }
    
    /**
     * Get directory size recursively
     */
    private function get_directory_size($dir) {
        $size = 0;
        
        if (!is_dir($dir)) {
            return $size;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }
}
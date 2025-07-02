<?php
/**
 * Recovery Manager Class
 *
 * Provides self-healing and recovery capabilities for the plugin.
 *
 * @package Breakdance_Static_Pages
 * @since 1.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_Recovery_Manager
 *
 * Manages automatic recovery and healing of plugin issues.
 */
class BSP_Recovery_Manager {
    
    /**
     * Singleton instance
     *
     * @var BSP_Recovery_Manager|null
     */
    private static $instance = null;
    
    /**
     * Recovery status
     *
     * @var array
     */
    private $recovery_status = array();
    
    /**
     * Constructor
     */
    private function __construct() {
        // Schedule recovery tasks
        add_action('init', array($this, 'schedule_recovery_tasks'));
        
        // Hook into recovery events
        add_action('bsp_hourly_recovery', array($this, 'run_hourly_recovery'));
        add_action('bsp_daily_recovery', array($this, 'run_daily_recovery'));
        
        // Hook into error events for immediate recovery
        add_action('bsp_error_logged', array($this, 'handle_error_recovery'), 10, 1);
    }
    
    /**
     * Get singleton instance
     *
     * @return BSP_Recovery_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Schedule recovery tasks
     */
    public function schedule_recovery_tasks() {
        // Hourly recovery
        if (!wp_next_scheduled('bsp_hourly_recovery')) {
            wp_schedule_event(time(), 'hourly', 'bsp_hourly_recovery');
        }
        
        // Daily recovery
        if (!wp_next_scheduled('bsp_daily_recovery')) {
            wp_schedule_event(time(), 'daily', 'bsp_daily_recovery');
        }
    }
    
    /**
     * Run hourly recovery tasks
     */
    public function run_hourly_recovery() {
        $this->recovery_status = array(
            'start_time' => current_time('mysql'),
            'tasks' => array()
        );
        
        // Fix stuck locks
        $this->fix_stuck_locks();
        
        // Recover failed generations
        $this->recover_failed_generations();
        
        // Verify file integrity
        $this->verify_recent_files();
        
        // Clean up temporary files
        $this->cleanup_temp_files();
        
        // Log recovery status
        $this->log_recovery_status('hourly');
    }
    
    /**
     * Run daily recovery tasks
     */
    public function run_daily_recovery() {
        $this->recovery_status = array(
            'start_time' => current_time('mysql'),
            'tasks' => array()
        );
        
        // Fix orphaned files
        $this->fix_orphaned_files();
        
        // Repair corrupted metadata
        $this->repair_corrupted_metadata();
        
        // Verify all file integrity
        $this->verify_all_files();
        
        // Optimize database
        $this->optimize_database();
        
        // Clean up old data
        $this->cleanup_old_data();
        
        // Log recovery status
        $this->log_recovery_status('daily');
    }
    
    /**
     * Fix stuck locks
     */
    private function fix_stuck_locks() {
        $error_handler = BSP_Error_Handler::get_instance();
        $lock_manager = BSP_File_Lock_Manager::get_instance();
        
        try {
            $start = microtime(true);
            $cleaned = $lock_manager->cleanup_expired_locks(300); // 5 minute timeout
            
            $this->recovery_status['tasks']['stuck_locks'] = array(
                'status' => 'success',
                'cleaned' => $cleaned,
                'duration' => microtime(true) - $start
            );
            
            if ($cleaned > 0) {
                $error_handler->log_error(
                    'recovery_manager',
                    sprintf('Cleaned %d stuck locks', $cleaned),
                    'info'
                );
            }
            
        } catch (Exception $e) {
            $this->recovery_status['tasks']['stuck_locks'] = array(
                'status' => 'failed',
                'error' => $e->getMessage()
            );
            
            $error_handler->log_error(
                'recovery_manager',
                'Failed to clean stuck locks: ' . $e->getMessage(),
                'error',
                array(),
                $e
            );
        }
    }
    
    /**
     * Recover failed generations
     */
    private function recover_failed_generations() {
        global $wpdb;
        $error_handler = BSP_Error_Handler::get_instance();
        
        try {
            // Find pages that are enabled but have no generated file
            $missing_files = $wpdb->get_col("
                SELECT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id
                LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bsp_static_generated'
                WHERE pm1.meta_key = '_bsp_static_enabled'
                AND pm1.meta_value = '1'
                AND (pm2.meta_value IS NULL OR pm2.meta_value < DATE_SUB(NOW(), INTERVAL 1 HOUR))
                AND p.post_status = 'publish'
                LIMIT 10
            ");
            
            $recovered = 0;
            $failed = 0;
            
            foreach ($missing_files as $post_id) {
                // Check if file actually exists
                $file_path = Breakdance_Static_Pages::get_static_file_path($post_id);
                
                if (!file_exists($file_path)) {
                    // Try to regenerate with retry
                    $result = BSP_Retry_Manager::retry(
                        function() use ($post_id) {
                            return BSP_Atomic_Operations::generate_with_rollback($post_id);
                        },
                        array('max_attempts' => 2, 'initial_delay' => 1000)
                    );
                    
                    if ($result['success']) {
                        $recovered++;
                    } else {
                        $failed++;
                        
                        // Disable static generation if it keeps failing
                        $failure_count = get_post_meta($post_id, '_bsp_generation_failures', true);
                        $failure_count = $failure_count ? intval($failure_count) + 1 : 1;
                        update_post_meta($post_id, '_bsp_generation_failures', $failure_count);
                        
                        if ($failure_count >= 3) {
                            update_post_meta($post_id, '_bsp_static_enabled', '0');
                            $error_handler->log_error(
                                'recovery_manager',
                                sprintf('Disabled static generation for post %d after 3 failures', $post_id),
                                'warning'
                            );
                        }
                    }
                }
            }
            
            $this->recovery_status['tasks']['failed_generations'] = array(
                'status' => 'success',
                'recovered' => $recovered,
                'failed' => $failed,
                'total' => count($missing_files)
            );
            
        } catch (Exception $e) {
            $this->recovery_status['tasks']['failed_generations'] = array(
                'status' => 'failed',
                'error' => $e->getMessage()
            );
            
            $error_handler->log_error(
                'recovery_manager',
                'Failed generation recovery error: ' . $e->getMessage(),
                'error',
                array(),
                $e
            );
        }
    }
    
    /**
     * Verify recent file integrity
     */
    private function verify_recent_files() {
        global $wpdb;
        $error_handler = BSP_Error_Handler::get_instance();
        
        try {
            // Get recently generated files
            $recent_files = $wpdb->get_results("
                SELECT post_id, meta_value as generated_time
                FROM {$wpdb->postmeta}
                WHERE meta_key = '_bsp_static_generated'
                AND meta_value > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ORDER BY meta_value DESC
                LIMIT 20
            ");
            
            $verified = 0;
            $corrupted = 0;
            
            foreach ($recent_files as $file_data) {
                $post_id = $file_data->post_id;
                $file_path = Breakdance_Static_Pages::get_static_file_path($post_id);
                
                if (file_exists($file_path)) {
                    // Verify file is not empty
                    if (filesize($file_path) === 0) {
                        $corrupted++;
                        
                        // Regenerate corrupted file
                        BSP_Atomic_Operations::generate_with_rollback($post_id);
                        
                        $error_handler->log_error(
                            'recovery_manager',
                            sprintf('Regenerated corrupted file for post %d', $post_id),
                            'warning'
                        );
                    } else {
                        $verified++;
                    }
                } else {
                    $corrupted++;
                    
                    // Clean up metadata for missing file
                    delete_post_meta($post_id, '_bsp_static_generated');
                    delete_post_meta($post_id, '_bsp_static_file_size');
                    delete_post_meta($post_id, '_bsp_static_etag');
                }
            }
            
            $this->recovery_status['tasks']['file_verification'] = array(
                'status' => 'success',
                'verified' => $verified,
                'corrupted' => $corrupted
            );
            
        } catch (Exception $e) {
            $this->recovery_status['tasks']['file_verification'] = array(
                'status' => 'failed',
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Clean up temporary files
     */
    private function cleanup_temp_files() {
        $error_handler = BSP_Error_Handler::get_instance();
        
        try {
            $upload_dir = wp_upload_dir();
            $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages/';
            
            $cleaned = 0;
            
            // Clean up atomic temp files
            $temp_files = glob($static_dir . '*.atomic.*');
            if ($temp_files) {
                foreach ($temp_files as $temp_file) {
                    // Only delete if older than 1 hour
                    if (filemtime($temp_file) < (time() - 3600)) {
                        if (unlink($temp_file)) {
                            $cleaned++;
                        }
                    }
                }
            }
            
            // Clean up backup files
            $backup_files = glob($static_dir . '*.backup.*');
            if ($backup_files) {
                foreach ($backup_files as $backup_file) {
                    // Only delete if older than 24 hours
                    if (filemtime($backup_file) < (time() - 86400)) {
                        if (unlink($backup_file)) {
                            $cleaned++;
                        }
                    }
                }
            }
            
            $this->recovery_status['tasks']['temp_cleanup'] = array(
                'status' => 'success',
                'cleaned' => $cleaned
            );
            
        } catch (Exception $e) {
            $this->recovery_status['tasks']['temp_cleanup'] = array(
                'status' => 'failed',
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Fix orphaned files
     */
    private function fix_orphaned_files() {
        $cache_manager = new BSP_Cache_Manager();
        
        try {
            $cleaned = $cache_manager->cleanup_orphaned_files();
            
            $this->recovery_status['tasks']['orphaned_files'] = array(
                'status' => 'success',
                'cleaned' => $cleaned
            );
            
        } catch (Exception $e) {
            $this->recovery_status['tasks']['orphaned_files'] = array(
                'status' => 'failed',
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Repair corrupted metadata
     */
    private function repair_corrupted_metadata() {
        global $wpdb;
        $error_handler = BSP_Error_Handler::get_instance();
        
        try {
            $repaired = 0;
            
            // Find posts with file size metadata but no generated timestamp
            $corrupted = $wpdb->get_col("
                SELECT DISTINCT pm1.post_id
                FROM {$wpdb->postmeta} pm1
                LEFT JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id 
                    AND pm2.meta_key = '_bsp_static_generated'
                WHERE pm1.meta_key = '_bsp_static_file_size'
                AND pm2.meta_value IS NULL
            ");
            
            foreach ($corrupted as $post_id) {
                $file_path = Breakdance_Static_Pages::get_static_file_path($post_id);
                
                if (file_exists($file_path)) {
                    // Restore generated timestamp from file
                    update_post_meta($post_id, '_bsp_static_generated', 
                        date('Y-m-d H:i:s', filemtime($file_path))
                    );
                    $repaired++;
                } else {
                    // Clean up all metadata
                    delete_post_meta($post_id, '_bsp_static_file_size');
                    delete_post_meta($post_id, '_bsp_static_etag');
                    delete_post_meta($post_id, '_bsp_static_etag_time');
                    $repaired++;
                }
            }
            
            $this->recovery_status['tasks']['metadata_repair'] = array(
                'status' => 'success',
                'repaired' => $repaired
            );
            
        } catch (Exception $e) {
            $this->recovery_status['tasks']['metadata_repair'] = array(
                'status' => 'failed',
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Verify all file integrity
     */
    private function verify_all_files() {
        // This is handled by the cache manager's validate_all_files method
        try {
            $cache_manager = new BSP_Cache_Manager();
            $method = new ReflectionMethod($cache_manager, 'validate_all_static_files');
            $method->setAccessible(true);
            $result = $method->invoke($cache_manager);
            
            $this->recovery_status['tasks']['full_verification'] = array(
                'status' => 'success',
                'result' => $result
            );
            
        } catch (Exception $e) {
            $this->recovery_status['tasks']['full_verification'] = array(
                'status' => 'failed',
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Optimize database
     */
    private function optimize_database() {
        global $wpdb;
        
        try {
            // Clean up old performance data
            $deleted = $wpdb->query("
                DELETE FROM {$wpdb->options}
                WHERE option_name LIKE 'bsp_performance_%'
                AND option_name < 'bsp_performance_" . date('Y-m-d', strtotime('-7 days')) . "'
            ");
            
            // Clean up old transients
            $wpdb->query("
                DELETE FROM {$wpdb->options}
                WHERE option_name LIKE '_transient_bsp_%'
                AND option_name NOT IN (
                    SELECT CONCAT('_transient_', option_name)
                    FROM (
                        SELECT option_name 
                        FROM {$wpdb->options} 
                        WHERE option_name LIKE '_transient_timeout_bsp_%'
                        AND option_value > UNIX_TIMESTAMP()
                    ) AS t
                )
            ");
            
            $this->recovery_status['tasks']['database_optimization'] = array(
                'status' => 'success',
                'cleaned_records' => $deleted
            );
            
        } catch (Exception $e) {
            $this->recovery_status['tasks']['database_optimization'] = array(
                'status' => 'failed',
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Clean up old data
     */
    private function cleanup_old_data() {
        try {
            // Clean up old error logs
            BSP_Error_Handler::get_instance()->cleanup_old_errors();
            
            // Clean up old generation failure counts
            global $wpdb;
            $wpdb->query("
                DELETE FROM {$wpdb->postmeta}
                WHERE meta_key = '_bsp_generation_failures'
                AND meta_value < '3'
            ");
            
            $this->recovery_status['tasks']['old_data_cleanup'] = array(
                'status' => 'success'
            );
            
        } catch (Exception $e) {
            $this->recovery_status['tasks']['old_data_cleanup'] = array(
                'status' => 'failed',
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Handle immediate recovery for specific errors
     *
     * @param array $error Error data
     */
    public function handle_error_recovery($error) {
        // Only handle critical errors
        if (!isset($error['severity']) || $error['severity'] !== 'critical') {
            return;
        }
        
        // Attempt immediate recovery based on error context
        switch ($error['context']) {
            case 'static_generation':
                if (isset($error['data']['post_id'])) {
                    $this->schedule_regeneration($error['data']['post_id']);
                }
                break;
                
            case 'file_lock':
                $this->fix_stuck_locks();
                break;
                
            case 'database':
                $this->repair_corrupted_metadata();
                break;
        }
    }
    
    /**
     * Schedule regeneration for a failed post
     *
     * @param int $post_id Post ID
     */
    private function schedule_regeneration($post_id) {
        // Schedule regeneration in 5 minutes
        wp_schedule_single_event(
            time() + 300,
            'bsp_regenerate_static_page',
            array($post_id)
        );
        
        BSP_Error_Handler::get_instance()->log_error(
            'recovery_manager',
            sprintf('Scheduled regeneration for post %d', $post_id),
            'info'
        );
    }
    
    /**
     * Log recovery status
     *
     * @param string $type Recovery type (hourly/daily)
     */
    private function log_recovery_status($type) {
        $this->recovery_status['end_time'] = current_time('mysql');
        $this->recovery_status['type'] = $type;
        
        // Store recovery status
        update_option('bsp_last_recovery_' . $type, $this->recovery_status, false);
        
        // Log summary
        $summary = array(
            'successful_tasks' => 0,
            'failed_tasks' => 0
        );
        
        foreach ($this->recovery_status['tasks'] as $task => $result) {
            if ($result['status'] === 'success') {
                $summary['successful_tasks']++;
            } else {
                $summary['failed_tasks']++;
            }
        }
        
        BSP_Error_Handler::get_instance()->log_error(
            'recovery_manager',
            sprintf(
                '%s recovery completed: %d successful, %d failed tasks',
                ucfirst($type),
                $summary['successful_tasks'],
                $summary['failed_tasks']
            ),
            $summary['failed_tasks'] > 0 ? 'warning' : 'info',
            $this->recovery_status
        );
    }
    
    /**
     * Get recovery status
     *
     * @param string $type Recovery type (hourly/daily)
     * @return array Recovery status
     */
    public function get_recovery_status($type = 'hourly') {
        return get_option('bsp_last_recovery_' . $type, array());
    }
}
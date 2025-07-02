# Breakdance Static Pages - Phased Improvement Plan

## Overview
This document outlines a comprehensive 7-phase improvement plan for the Breakdance Static Pages plugin. Each phase is designed to be implemented independently while maintaining backward compatibility and ensuring system stability.

## Phase 1: Foundation - Safety Mechanisms & Infrastructure (Week 1-2)

### Objectives
- Implement file locking to prevent race conditions
- Add database version tracking for future migrations
- Create uninstall cleanup mechanism
- Add basic health check system

### Implementation Steps

#### 1.1 File Locking System
Create a new class to handle file locking:

```php
// includes/class-file-lock-manager.php
class BSP_File_Lock_Manager {
    private static $instance = null;
    private $lock_dir;
    
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->lock_dir = $upload_dir['basedir'] . '/bsp-locks/';
        wp_mkdir_p($this->lock_dir);
    }
    
    public function acquire_lock($post_id, $timeout = 300) {
        $lock_file = $this->lock_dir . 'post-' . $post_id . '.lock';
        
        // Check if lock exists and is still valid
        if (file_exists($lock_file)) {
            $lock_time = filemtime($lock_file);
            if (time() - $lock_time < $timeout) {
                return false; // Lock is still active
            }
            // Lock expired, remove it
            unlink($lock_file);
        }
        
        // Create lock file
        return file_put_contents($lock_file, time()) !== false;
    }
    
    public function release_lock($post_id) {
        $lock_file = $this->lock_dir . 'post-' . $post_id . '.lock';
        if (file_exists($lock_file)) {
            unlink($lock_file);
        }
    }
    
    public function cleanup_expired_locks($timeout = 300) {
        $locks = glob($this->lock_dir . '*.lock');
        foreach ($locks as $lock) {
            if (time() - filemtime($lock) > $timeout) {
                unlink($lock);
            }
        }
    }
}
```

#### 1.2 Database Version Tracking
Add to plugin activation:

```php
// In breakdance-static-pages.php
register_activation_hook(__FILE__, array($this, 'activate'));

public function activate() {
    // Existing activation code...
    
    // Add database version
    add_option('bsp_db_version', '1.1.0');
    
    // Schedule lock cleanup
    if (!wp_next_scheduled('bsp_cleanup_locks')) {
        wp_schedule_event(time(), 'hourly', 'bsp_cleanup_locks');
    }
}
```

#### 1.3 Uninstall Cleanup
Create uninstall.php:

```php
// uninstall.php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove all plugin options
$options = array(
    'bsp_db_version',
    'bsp_settings'
);

foreach ($options as $option) {
    delete_option($option);
}

// Remove all performance data options
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bsp_%'");

// Remove all post meta
delete_post_meta_by_key('_bsp_static_enabled');
delete_post_meta_by_key('_bsp_static_generated');
delete_post_meta_by_key('_bsp_static_file_size');
delete_post_meta_by_key('_bsp_static_etag');

// Remove static files directory
$upload_dir = wp_upload_dir();
$static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/';
if (is_dir($static_dir)) {
    BSP_Uninstall::remove_directory($static_dir);
}

// Remove scheduled events
wp_clear_scheduled_hook('bsp_daily_cleanup');
wp_clear_scheduled_hook('bsp_cleanup_locks');
```

#### 1.4 Health Check System
Add health check endpoint:

```php
// includes/class-health-check.php
class BSP_Health_Check {
    public function __construct() {
        add_action('wp_ajax_bsp_health_check', array($this, 'handle_health_check'));
    }
    
    public function handle_health_check() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $health = array(
            'status' => 'healthy',
            'checks' => array()
        );
        
        // Check write permissions
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/';
        $health['checks']['write_permissions'] = is_writable($static_dir);
        
        // Check database
        global $wpdb;
        $health['checks']['database'] = $wpdb->get_var("SELECT 1") === '1';
        
        // Check memory
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_usage = memory_get_usage(true);
        $health['checks']['memory'] = ($memory_usage / $memory_limit) < 0.8;
        
        // Check locks
        $lock_manager = BSP_File_Lock_Manager::get_instance();
        $health['checks']['locks'] = true; // Implement lock count check
        
        // Overall status
        if (in_array(false, $health['checks'], true)) {
            $health['status'] = 'unhealthy';
        }
        
        wp_send_json_success($health);
    }
}
```

### Testing Phase 1
1. Test file locking with concurrent requests
2. Verify database version is properly stored
3. Test uninstall cleanup thoroughly
4. Validate health check endpoint

## Phase 2: Performance Optimization (Week 3-4)

### Objectives
- Implement ETag caching
- Add streaming support for large files
- Optimize database queries
- Implement memory-efficient file operations

### Implementation Steps

#### 2.1 ETag Caching System
Modify class-static-generator.php:

```php
// Add to generate_static_page method after file creation
$etag = md5_file($file_path);
update_post_meta($post_id, '_bsp_static_etag', $etag);
update_post_meta($post_id, '_bsp_static_etag_time', time());
```

Modify class-url-rewriter.php:

```php
private function get_etag($file_path, $post_id) {
    // Try to get cached ETag first
    $cached_etag = get_post_meta($post_id, '_bsp_static_etag', true);
    $etag_time = get_post_meta($post_id, '_bsp_static_etag_time', true);
    $file_mtime = filemtime($file_path);
    
    // If ETag is cached and file hasn't been modified externally
    if ($cached_etag && $etag_time && $etag_time >= $file_mtime) {
        return $cached_etag;
    }
    
    // Generate new ETag
    $etag = md5_file($file_path);
    update_post_meta($post_id, '_bsp_static_etag', $etag);
    update_post_meta($post_id, '_bsp_static_etag_time', time());
    
    return $etag;
}
```

#### 2.2 Streaming Support
Replace file_get_contents with streaming:

```php
// In class-url-rewriter.php serve_static_file method
private function stream_file($file_path) {
    $handle = fopen($file_path, 'rb');
    if ($handle === false) {
        return false;
    }
    
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Stream file in chunks
    while (!feof($handle)) {
        echo fread($handle, 8192); // 8KB chunks
        flush();
    }
    
    fclose($handle);
    return true;
}
```

#### 2.3 Database Query Optimization
Add caching layer for statistics:

```php
// includes/class-stats-cache.php
class BSP_Stats_Cache {
    private static $cache_key = 'bsp_stats_cache';
    private static $cache_expiry = 300; // 5 minutes
    
    public static function get_stats() {
        $cached = get_transient(self::$cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $stats = self::calculate_stats();
        set_transient(self::$cache_key, $stats, self::$cache_expiry);
        
        return $stats;
    }
    
    public static function invalidate() {
        delete_transient(self::$cache_key);
    }
    
    private static function calculate_stats() {
        global $wpdb;
        
        // Use single query with conditional counting
        $results = $wpdb->get_row("
            SELECT 
                COUNT(CASE WHEN meta_value = '1' THEN 1 END) as enabled_count,
                COUNT(*) as total_count,
                SUM(CASE WHEN meta_value = '1' THEN 1 ELSE 0 END) as enabled_sum
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_bsp_static_enabled'
        ");
        
        return array(
            'enabled_pages' => $results->enabled_count,
            'total_pages' => $results->total_count
        );
    }
}
```

#### 2.4 Memory-Efficient Operations
Implement chunked HTML processing:

```php
// In class-static-generator.php
private function optimize_html_chunked($input_file, $output_file) {
    $input = fopen($input_file, 'r');
    $output = fopen($output_file, 'w');
    
    if (!$input || !$output) {
        return false;
    }
    
    $buffer = '';
    $in_admin_bar = false;
    
    while (!feof($input)) {
        $chunk = fread($input, 8192);
        $buffer .= $chunk;
        
        // Process complete tags only
        while (preg_match('/<[^>]+>/', $buffer, $matches, PREG_OFFSET_CAPTURE)) {
            $tag = $matches[0][0];
            $pos = $matches[0][1];
            
            // Write everything before the tag
            fwrite($output, substr($buffer, 0, $pos));
            
            // Process the tag
            if (strpos($tag, 'id="wpadminbar"') !== false) {
                $in_admin_bar = true;
            } elseif ($in_admin_bar && strpos($tag, '</div>') !== false) {
                $in_admin_bar = false;
                $buffer = substr($buffer, $pos + strlen($tag));
                continue;
            }
            
            if (!$in_admin_bar) {
                fwrite($output, $tag);
            }
            
            $buffer = substr($buffer, $pos + strlen($tag));
        }
    }
    
    // Write remaining buffer
    fwrite($output, $buffer);
    
    fclose($input);
    fclose($output);
    
    return true;
}
```

### Testing Phase 2
1. Benchmark ETag caching performance improvement
2. Test streaming with large files (>10MB)
3. Verify query optimization reduces database load
4. Test memory usage with chunked processing

## Phase 3: Reliability & Error Recovery (Week 5-6)

### Objectives
- Implement comprehensive error handling
- Add retry mechanisms
- Create transaction-like operations
- Implement automatic recovery

### Implementation Steps

#### 3.1 Enhanced Error Handling
Create error handler class:

```php
// includes/class-error-handler.php
class BSP_Error_Handler {
    private static $instance = null;
    private $errors = array();
    
    public function log_error($context, $message, $severity = 'error', $data = array()) {
        $error = array(
            'time' => current_time('mysql'),
            'context' => $context,
            'message' => $message,
            'severity' => $severity,
            'data' => $data,
            'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        );
        
        // Store in transient for admin display
        $this->errors[] = $error;
        set_transient('bsp_recent_errors', $this->errors, DAY_IN_SECONDS);
        
        // Log to error log if debug is enabled
        if (WP_DEBUG) {
            error_log(sprintf(
                'BSP Error [%s] in %s: %s',
                $severity,
                $context,
                $message
            ));
        }
        
        // Send email for critical errors
        if ($severity === 'critical') {
            $this->notify_admin($error);
        }
    }
    
    private function notify_admin($error) {
        $admin_email = get_option('admin_email');
        $subject = sprintf(
            '[%s] Critical Error in Breakdance Static Pages',
            get_bloginfo('name')
        );
        
        $message = sprintf(
            "A critical error occurred:\n\n" .
            "Context: %s\n" .
            "Message: %s\n" .
            "Time: %s\n" .
            "Data: %s",
            $error['context'],
            $error['message'],
            $error['time'],
            print_r($error['data'], true)
        );
        
        wp_mail($admin_email, $subject, $message);
    }
}
```

#### 3.2 Retry Mechanism
Implement exponential backoff:

```php
// includes/class-retry-manager.php
class BSP_Retry_Manager {
    public static function retry_with_backoff($callable, $max_attempts = 3, $initial_delay = 1000) {
        $attempt = 0;
        $delay = $initial_delay;
        $last_exception = null;
        
        while ($attempt < $max_attempts) {
            try {
                return call_user_func($callable);
            } catch (Exception $e) {
                $last_exception = $e;
                $attempt++;
                
                if ($attempt < $max_attempts) {
                    usleep($delay * 1000); // Convert to microseconds
                    $delay *= 2; // Exponential backoff
                }
                
                BSP_Error_Handler::get_instance()->log_error(
                    'retry_manager',
                    sprintf('Attempt %d failed: %s', $attempt, $e->getMessage()),
                    'warning'
                );
            }
        }
        
        throw new Exception(
            sprintf('Failed after %d attempts. Last error: %s', 
                $max_attempts, 
                $last_exception->getMessage()
            )
        );
    }
}
```

#### 3.3 Transaction-like Operations
Implement atomic operations:

```php
// includes/class-atomic-operations.php
class BSP_Atomic_Operations {
    public static function generate_with_rollback($post_id) {
        $rollback_data = array();
        $temp_file = null;
        
        try {
            // Store original meta for rollback
            $rollback_data['meta'] = array(
                '_bsp_static_generated' => get_post_meta($post_id, '_bsp_static_generated', true),
                '_bsp_static_file_size' => get_post_meta($post_id, '_bsp_static_file_size', true),
                '_bsp_static_etag' => get_post_meta($post_id, '_bsp_static_etag', true)
            );
            
            // Get file paths
            $generator = new BSP_Static_Generator();
            $file_path = $generator->get_static_file_path($post_id);
            $temp_file = $file_path . '.tmp';
            
            // Store original file if exists
            if (file_exists($file_path)) {
                $rollback_data['file'] = $file_path . '.backup';
                copy($file_path, $rollback_data['file']);
            }
            
            // Generate to temp file first
            $result = $generator->generate_to_temp($post_id, $temp_file);
            
            if (!$result['success']) {
                throw new Exception($result['error']);
            }
            
            // Atomic move
            if (!rename($temp_file, $file_path)) {
                throw new Exception('Failed to move temporary file');
            }
            
            // Update meta
            update_post_meta($post_id, '_bsp_static_generated', current_time('timestamp'));
            update_post_meta($post_id, '_bsp_static_file_size', filesize($file_path));
            update_post_meta($post_id, '_bsp_static_etag', md5_file($file_path));
            
            // Clean up backup
            if (isset($rollback_data['file'])) {
                unlink($rollback_data['file']);
            }
            
            return array('success' => true);
            
        } catch (Exception $e) {
            // Rollback
            self::rollback($rollback_data, $temp_file);
            
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    private static function rollback($rollback_data, $temp_file) {
        // Remove temp file
        if ($temp_file && file_exists($temp_file)) {
            unlink($temp_file);
        }
        
        // Restore original file
        if (isset($rollback_data['file']) && file_exists($rollback_data['file'])) {
            $original_path = str_replace('.backup', '', $rollback_data['file']);
            rename($rollback_data['file'], $original_path);
        }
        
        // Restore meta
        if (isset($rollback_data['meta'])) {
            foreach ($rollback_data['meta'] as $key => $value) {
                if ($value) {
                    update_post_meta($post_id, $key, $value);
                } else {
                    delete_post_meta($post_id, $key);
                }
            }
        }
    }
}
```

#### 3.4 Automatic Recovery
Add self-healing capabilities:

```php
// includes/class-recovery-manager.php
class BSP_Recovery_Manager {
    public function __construct() {
        add_action('bsp_hourly_maintenance', array($this, 'run_recovery'));
    }
    
    public function run_recovery() {
        $this->fix_orphaned_files();
        $this->verify_file_integrity();
        $this->cleanup_failed_generations();
        $this->repair_corrupted_meta();
    }
    
    private function fix_orphaned_files() {
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages/';
        $files = glob($static_dir . '*.html');
        
        foreach ($files as $file) {
            if (preg_match('/page-(\d+)\.html$/', $file, $matches)) {
                $post_id = $matches[1];
                $post = get_post($post_id);
                
                if (!$post || $post->post_status !== 'publish') {
                    unlink($file);
                    BSP_Error_Handler::get_instance()->log_error(
                        'recovery',
                        sprintf('Removed orphaned file for post %d', $post_id),
                        'info'
                    );
                }
            }
        }
    }
    
    private function verify_file_integrity() {
        global $wpdb;
        
        $posts_with_static = $wpdb->get_col("
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_bsp_static_enabled' 
            AND meta_value = '1'
        ");
        
        foreach ($posts_with_static as $post_id) {
            $generator = new BSP_Static_Generator();
            $file_path = $generator->get_static_file_path($post_id);
            
            if (!file_exists($file_path)) {
                // File missing, regenerate
                BSP_Retry_Manager::retry_with_backoff(function() use ($post_id) {
                    $generator = new BSP_Static_Generator();
                    return $generator->generate_static_page($post_id);
                });
            }
        }
    }
}
```

### Testing Phase 3
1. Test error logging and notification system
2. Verify retry mechanism with simulated failures
3. Test atomic operations rollback
4. Validate automatic recovery processes

## Phase 4: Scalability - Background Processing (Week 7-8)

### Objectives
- Implement proper queue system
- Add progress tracking
- Enable parallel processing
- Implement rate limiting

### Implementation Steps

#### 4.1 Install Action Scheduler
Add to composer.json or include manually:

```json
{
    "require": {
        "woocommerce/action-scheduler": "^3.6"
    }
}
```

#### 4.2 Queue Manager
Create queue management system:

```php
// includes/class-queue-manager.php
class BSP_Queue_Manager {
    private static $instance = null;
    
    public function __construct() {
        add_action('init', array($this, 'init_scheduler'));
        
        // Register async actions
        add_action('bsp_process_single', array($this, 'process_single_item'), 10, 2);
        add_action('bsp_process_bulk', array($this, 'process_bulk_items'), 10, 2);
    }
    
    public function init_scheduler() {
        if (function_exists('as_enqueue_async_action')) {
            // Action Scheduler is available
            $this->setup_tables();
        }
    }
    
    public function queue_single_generation($post_id, $priority = 10) {
        if (!function_exists('as_enqueue_async_action')) {
            // Fallback to direct processing
            $generator = new BSP_Static_Generator();
            return $generator->generate_static_page($post_id);
        }
        
        // Check if already queued
        $existing = as_get_scheduled_actions(array(
            'hook' => 'bsp_process_single',
            'args' => array($post_id),
            'status' => ActionScheduler_Store::STATUS_PENDING
        ));
        
        if (empty($existing)) {
            $action_id = as_enqueue_async_action(
                'bsp_process_single',
                array($post_id, $priority),
                'breakdance-static-pages'
            );
            
            // Store action ID for tracking
            set_transient('bsp_action_' . $post_id, $action_id, HOUR_IN_SECONDS);
            
            return array(
                'success' => true,
                'action_id' => $action_id,
                'message' => 'Queued for processing'
            );
        }
        
        return array(
            'success' => false,
            'message' => 'Already queued'
        );
    }
    
    public function queue_bulk_generation($post_ids, $batch_size = 10) {
        $batches = array_chunk($post_ids, $batch_size);
        $batch_ids = array();
        
        foreach ($batches as $index => $batch) {
            $action_id = as_enqueue_async_action(
                'bsp_process_bulk',
                array($batch, $index),
                'breakdance-static-pages'
            );
            
            $batch_ids[] = $action_id;
        }
        
        // Store batch operation data
        set_transient('bsp_bulk_operation', array(
            'total' => count($post_ids),
            'batches' => count($batches),
            'batch_ids' => $batch_ids,
            'started' => time()
        ), HOUR_IN_SECONDS);
        
        return array(
            'success' => true,
            'batches' => count($batches),
            'total' => count($post_ids)
        );
    }
    
    public function process_single_item($post_id, $priority = 10) {
        try {
            // Acquire lock
            $lock_manager = BSP_File_Lock_Manager::get_instance();
            if (!$lock_manager->acquire_lock($post_id)) {
                // Reschedule for later
                as_schedule_single_action(
                    time() + 60,
                    'bsp_process_single',
                    array($post_id, $priority),
                    'breakdance-static-pages'
                );
                return;
            }
            
            // Process with atomic operations
            $result = BSP_Atomic_Operations::generate_with_rollback($post_id);
            
            // Release lock
            $lock_manager->release_lock($post_id);
            
            // Update progress
            $this->update_progress('single', $post_id, $result['success']);
            
            // Clear cache
            BSP_Stats_Cache::invalidate();
            
        } catch (Exception $e) {
            BSP_Error_Handler::get_instance()->log_error(
                'queue_processing',
                $e->getMessage(),
                'error',
                array('post_id' => $post_id)
            );
            
            // Release lock on error
            $lock_manager->release_lock($post_id);
        }
    }
    
    public function process_bulk_items($post_ids, $batch_index) {
        $results = array('success' => 0, 'failed' => 0);
        
        foreach ($post_ids as $post_id) {
            $result = $this->process_single_item($post_id, 5); // Lower priority for bulk
            
            if ($result) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            
            // Rate limiting
            usleep(100000); // 100ms between items
        }
        
        // Update bulk progress
        $this->update_bulk_progress($batch_index, $results);
    }
    
    private function update_progress($type, $identifier, $success) {
        $progress_key = 'bsp_progress_' . $type . '_' . $identifier;
        $progress = get_transient($progress_key) ?: array();
        
        $progress['completed'] = time();
        $progress['success'] = $success;
        
        set_transient($progress_key, $progress, HOUR_IN_SECONDS);
        
        // Trigger progress update
        do_action('bsp_progress_updated', $type, $identifier, $progress);
    }
    
    public function get_queue_status() {
        if (!function_exists('as_get_scheduled_actions')) {
            return array('error' => 'Action Scheduler not available');
        }
        
        $pending = as_get_scheduled_actions(array(
            'hook' => 'bsp_process_single',
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => -1
        ));
        
        $running = as_get_scheduled_actions(array(
            'hook' => 'bsp_process_single',
            'status' => ActionScheduler_Store::STATUS_RUNNING,
            'per_page' => -1
        ));
        
        return array(
            'pending' => count($pending),
            'running' => count($running),
            'can_process' => count($running) < 3 // Limit concurrent processes
        );
    }
}
```

#### 4.3 Progress Tracking
Implement real-time progress updates:

```php
// includes/class-progress-tracker.php
class BSP_Progress_Tracker {
    public function __construct() {
        add_action('wp_ajax_bsp_get_progress', array($this, 'get_progress'));
        add_action('bsp_progress_updated', array($this, 'broadcast_progress'), 10, 3);
    }
    
    public function get_progress() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $type = sanitize_text_field($_POST['type']);
        $identifier = sanitize_text_field($_POST['identifier']);
        
        if ($type === 'bulk') {
            $progress = $this->get_bulk_progress();
        } else {
            $progress = $this->get_single_progress($identifier);
        }
        
        wp_send_json_success($progress);
    }
    
    private function get_bulk_progress() {
        $operation = get_transient('bsp_bulk_operation');
        if (!$operation) {
            return array('status' => 'not_found');
        }
        
        $completed = 0;
        $failed = 0;
        
        // Check each batch
        foreach ($operation['batch_ids'] as $action_id) {
            $action = ActionScheduler::store()->fetch_action($action_id);
            if ($action) {
                $status = $action->get_status();
                if ($status === ActionScheduler_Store::STATUS_COMPLETE) {
                    $completed++;
                } elseif ($status === ActionScheduler_Store::STATUS_FAILED) {
                    $failed++;
                }
            }
        }
        
        $progress = ($completed + $failed) / count($operation['batch_ids']) * 100;
        
        return array(
            'status' => 'processing',
            'progress' => $progress,
            'completed' => $completed,
            'failed' => $failed,
            'total' => $operation['total'],
            'elapsed' => time() - $operation['started']
        );
    }
    
    public function broadcast_progress($type, $identifier, $progress) {
        // Store for SSE or WebSocket broadcasting
        set_transient('bsp_live_progress_' . $type . '_' . $identifier, $progress, 60);
    }
}
```

#### 4.4 Rate Limiting
Implement rate limiting for API protection:

```php
// includes/class-rate-limiter.php
class BSP_Rate_Limiter {
    private static $instance = null;
    
    public function check_rate_limit($action, $identifier = null) {
        $key = 'bsp_rate_' . $action;
        if ($identifier) {
            $key .= '_' . $identifier;
        }
        
        $attempts = get_transient($key) ?: 0;
        $max_attempts = $this->get_max_attempts($action);
        $window = $this->get_time_window($action);
        
        if ($attempts >= $max_attempts) {
            return false;
        }
        
        set_transient($key, $attempts + 1, $window);
        return true;
    }
    
    private function get_max_attempts($action) {
        $limits = array(
            'generate_single' => 10,
            'generate_bulk' => 2,
            'api_request' => 100
        );
        
        return isset($limits[$action]) ? $limits[$action] : 10;
    }
    
    private function get_time_window($action) {
        $windows = array(
            'generate_single' => MINUTE_IN_SECONDS,
            'generate_bulk' => HOUR_IN_SECONDS,
            'api_request' => MINUTE_IN_SECONDS
        );
        
        return isset($windows[$action]) ? $windows[$action] : MINUTE_IN_SECONDS;
    }
}
```

### Testing Phase 4
1. Test queue processing with 100+ pages
2. Verify progress tracking accuracy
3. Test rate limiting under load
4. Validate concurrent processing limits

## Phase 5: Code Quality Refactoring (Week 9)

### Objectives
- Implement WordPress Filesystem API
- Fix coding standards violations
- Add proper SSL verification
- Implement PSR-4 autoloading

### Implementation Steps

#### 5.1 WordPress Filesystem API
Replace direct file operations:

```php
// includes/class-filesystem-handler.php
class BSP_Filesystem_Handler {
    private $wp_filesystem;
    
    public function __construct() {
        $this->init_filesystem();
    }
    
    private function init_filesystem() {
        global $wp_filesystem;
        
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        WP_Filesystem();
        $this->wp_filesystem = $wp_filesystem;
    }
    
    public function put_contents($file, $contents) {
        return $this->wp_filesystem->put_contents($file, $contents, FS_CHMOD_FILE);
    }
    
    public function get_contents($file) {
        return $this->wp_filesystem->get_contents($file);
    }
    
    public function exists($file) {
        return $this->wp_filesystem->exists($file);
    }
    
    public function delete($file) {
        return $this->wp_filesystem->delete($file);
    }
    
    public function mkdir($path) {
        return $this->wp_filesystem->mkdir($path);
    }
    
    public function copy($source, $destination) {
        return $this->wp_filesystem->copy($source, $destination);
    }
    
    public function move($source, $destination) {
        return $this->wp_filesystem->move($source, $destination);
    }
}
```

#### 5.2 Fix SSL Verification
Update cURL implementation:

```php
// In class-static-generator.php
private function capture_via_curl($url) {
    $ch = curl_init();
    
    // Get SSL bundle path
    $ssl_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
    
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CAINFO => $ssl_bundle,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array(
            'X-BSP-Generation-Request: 1',
            'User-Agent: Breakdance Static Pages Generator'
        ),
        CURLOPT_COOKIE => $this->get_auth_cookies()
    ));
    
    // Allow filtering for development environments
    $curl_options = apply_filters('bsp_curl_options', curl_getopt($ch));
    curl_setopt_array($ch, $curl_options);
    
    $html = curl_exec($ch);
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('cURL Error: ' . $error);
    }
    
    if ($http_code !== 200) {
        throw new Exception('HTTP Error: ' . $http_code);
    }
    
    return $html;
}
```

#### 5.3 Implement Autoloading
Add composer.json:

```json
{
    "name": "breakdance/static-pages",
    "description": "Convert Breakdance pages to static HTML",
    "type": "wordpress-plugin",
    "require": {
        "php": ">=7.4",
        "woocommerce/action-scheduler": "^3.6"
    },
    "autoload": {
        "psr-4": {
            "BreakdanceStaticPages\\": "includes/"
        }
    }
}
```

Update class files with namespaces:

```php
// includes/StaticGenerator.php
namespace BreakdanceStaticPages;

class StaticGenerator {
    // Renamed from BSP_Static_Generator
}
```

#### 5.4 Coding Standards Fixes
Update array syntax and spacing:

```php
// Before
$args = array('post_type' => 'page', 'posts_per_page' => -1);
if (!defined('ABSPATH')) exit;

// After
$args = [
    'post_type' => 'page',
    'posts_per_page' => -1,
];

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

### Testing Phase 5
1. Test all file operations with new filesystem handler
2. Verify SSL connections work properly
3. Test autoloading functionality
4. Run PHP CodeSniffer for standards compliance

## Phase 6: Testing Suite (Week 10)

### Objectives
- Add unit tests
- Implement integration tests
- Add performance benchmarks
- Create testing documentation

### Implementation Steps

#### 6.1 PHPUnit Setup
Create phpunit.xml:

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
>
    <testsuites>
        <testsuite name="unit">
            <directory suffix="Test.php">./tests/unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory suffix="Test.php">./tests/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

#### 6.2 Unit Tests
Example test class:

```php
// tests/unit/StaticGeneratorTest.php
use PHPUnit\Framework\TestCase;
use BreakdanceStaticPages\StaticGenerator;

class StaticGeneratorTest extends TestCase {
    private $generator;
    
    protected function setUp(): void {
        parent::setUp();
        $this->generator = new StaticGenerator();
    }
    
    public function test_file_path_generation() {
        $path = $this->generator->get_static_file_path(123);
        $this->assertStringContainsString('page-123.html', $path);
    }
    
    public function test_html_optimization() {
        $html = '<div id="wpadminbar">Admin</div><div>Content</div>';
        $optimized = $this->generator->optimize_html($html);
        $this->assertStringNotContainsString('wpadminbar', $optimized);
    }
    
    public function test_etag_generation() {
        $content = 'Test content';
        $etag = $this->generator->generate_etag($content);
        $this->assertEquals(32, strlen($etag)); // MD5 length
    }
}
```

#### 6.3 Integration Tests
Test full workflows:

```php
// tests/integration/GenerationWorkflowTest.php
class GenerationWorkflowTest extends WP_UnitTestCase {
    public function test_full_generation_workflow() {
        // Create test post
        $post_id = $this->factory->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_content' => 'Test content'
        ]);
        
        // Enable static generation
        update_post_meta($post_id, '_bsp_static_enabled', '1');
        
        // Generate static file
        $generator = new StaticGenerator();
        $result = $generator->generate_static_page($post_id);
        
        // Assert success
        $this->assertTrue($result['success']);
        $this->assertFileExists($result['file_path']);
        
        // Verify meta data
        $this->assertNotEmpty(get_post_meta($post_id, '_bsp_static_generated', true));
        $this->assertNotEmpty(get_post_meta($post_id, '_bsp_static_file_size', true));
        
        // Cleanup
        wp_delete_post($post_id, true);
    }
}
```

### Testing Phase 6
1. Run full test suite
2. Check code coverage (aim for >80%)
3. Test edge cases
4. Performance regression tests

## Phase 7: Documentation & Migration (Week 11)

### Objectives
- Create comprehensive documentation
- Build migration tools
- Add inline help
- Create video tutorials

### Implementation Steps

#### 7.1 Migration Script
Create upgrade handler:

```php
// includes/class-upgrade-handler.php
class BSP_Upgrade_Handler {
    public function __construct() {
        add_action('admin_init', array($this, 'check_version'));
    }
    
    public function check_version() {
        $current_version = get_option('bsp_db_version', '1.0.0');
        
        if (version_compare($current_version, BSP_VERSION, '<')) {
            $this->run_upgrades($current_version);
        }
    }
    
    private function run_upgrades($from_version) {
        // 1.0.0 to 1.1.0
        if (version_compare($from_version, '1.1.0', '<')) {
            $this->upgrade_to_1_1_0();
        }
        
        // Update version
        update_option('bsp_db_version', BSP_VERSION);
    }
    
    private function upgrade_to_1_1_0() {
        // Add new meta keys
        global $wpdb;
        
        // Add ETag meta for existing static files
        $posts = $wpdb->get_col("
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_bsp_static_enabled' 
            AND meta_value = '1'
        ");
        
        foreach ($posts as $post_id) {
            $generator = new BSP_Static_Generator();
            $file_path = $generator->get_static_file_path($post_id);
            
            if (file_exists($file_path)) {
                $etag = md5_file($file_path);
                update_post_meta($post_id, '_bsp_static_etag', $etag);
                update_post_meta($post_id, '_bsp_static_etag_time', time());
            }
        }
        
        // Create lock directory
        $upload_dir = wp_upload_dir();
        wp_mkdir_p($upload_dir['basedir'] . '/bsp-locks/');
    }
}
```

#### 7.2 User Documentation
Create comprehensive help:

```php
// includes/class-help-system.php
class BSP_Help_System {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_help_tabs'));
    }
    
    public function add_help_tabs() {
        $screen = get_current_screen();
        
        if (strpos($screen->id, 'breakdance-static-pages') !== false) {
            $screen->add_help_tab(array(
                'id' => 'bsp_overview',
                'title' => __('Overview', 'breakdance-static-pages'),
                'content' => $this->get_overview_content()
            ));
            
            $screen->add_help_tab(array(
                'id' => 'bsp_quick_start',
                'title' => __('Quick Start', 'breakdance-static-pages'),
                'content' => $this->get_quick_start_content()
            ));
            
            $screen->add_help_tab(array(
                'id' => 'bsp_troubleshooting',
                'title' => __('Troubleshooting', 'breakdance-static-pages'),
                'content' => $this->get_troubleshooting_content()
            ));
            
            $screen->set_help_sidebar($this->get_help_sidebar());
        }
    }
}
```

### Testing Phase 7
1. Test migration on various versions
2. Verify documentation accuracy
3. Test help system
4. Validate upgrade paths

## Final Testing & Deployment Checklist

### Pre-deployment Testing
- [ ] All unit tests passing
- [ ] Integration tests complete
- [ ] Performance benchmarks acceptable
- [ ] Security audit passed
- [ ] Code standards compliance
- [ ] Documentation complete

### Deployment Steps
1. Create release branch
2. Update version numbers
3. Generate changelog
4. Create release package
5. Test on staging environment
6. Deploy to production
7. Monitor error logs
8. Gather user feedback

### Post-deployment Monitoring
- Monitor error logs for 48 hours
- Check performance metrics
- Verify queue processing
- Review user feedback
- Plan hotfixes if needed

## Rollback Plan

If critical issues arise:

1. **Immediate Actions**
   - Disable plugin via database if needed
   - Restore previous version files
   - Clear all caches

2. **Database Rollback**
   ```sql
   -- Remove new meta keys
   DELETE FROM wp_postmeta WHERE meta_key IN ('_bsp_static_etag', '_bsp_static_etag_time');
   
   -- Reset version
   UPDATE wp_options SET option_value = '1.0.0' WHERE option_name = 'bsp_db_version';
   ```

3. **File Cleanup**
   - Remove new directories (locks, etc.)
   - Clear generated static files if corrupted

## Success Metrics

Track these KPIs post-deployment:

1. **Performance**
   - Page load time improvement: Target 50%+ reduction
   - Server resource usage: Target 30%+ reduction
   - Cache hit rate: Target 90%+

2. **Reliability**
   - Error rate: <0.1%
   - Successful generation rate: >99%
   - Recovery success rate: >95%

3. **User Satisfaction**
   - Support tickets: Reduce by 40%
   - User adoption: 80% of eligible pages
   - Feature requests: Track and prioritize

This phased approach ensures each improvement is thoroughly tested before moving to the next phase, maintaining stability throughout the upgrade process.
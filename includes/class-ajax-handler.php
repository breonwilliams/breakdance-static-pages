<?php
/**
 * AJAX Handler Class
 * Handles all AJAX requests for the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSP_Ajax_Handler {
    
    public function __construct() {
        // AJAX actions for logged-in users
        add_action('wp_ajax_bsp_generate_single', array($this, 'handle_generate_single'));
        add_action('wp_ajax_bsp_generate_multiple', array($this, 'handle_generate_multiple'));
        add_action('wp_ajax_bsp_delete_single', array($this, 'handle_delete_single'));
        add_action('wp_ajax_bsp_delete_multiple', array($this, 'handle_delete_multiple'));
        add_action('wp_ajax_bsp_toggle_static', array($this, 'handle_toggle_static'));
        add_action('wp_ajax_bsp_get_stats', array($this, 'handle_get_stats'));
        add_action('wp_ajax_bsp_serve_static', array($this, 'serve_static_file'));
        add_action('wp_ajax_nopriv_bsp_serve_static', array($this, 'serve_static_file_nopriv'));
        
        // Maintenance actions
        add_action('wp_ajax_bsp_cleanup_orphaned', array($this, 'handle_cleanup_orphaned'));
        add_action('wp_ajax_bsp_clear_all_locks', array($this, 'handle_clear_all_locks'));
        add_action('wp_ajax_bsp_delete_all_static', array($this, 'handle_delete_all_static'));
        
        // Error handling actions
        add_action('wp_ajax_bsp_clear_errors', array($this, 'handle_clear_errors'));
        add_action('wp_ajax_bsp_export_errors', array($this, 'handle_export_errors'));
        
        // Queue management actions
        add_action('wp_ajax_bsp_retry_failed_queue', array($this, 'handle_retry_failed_queue'));
        add_action('wp_ajax_bsp_clear_completed_queue', array($this, 'handle_clear_completed_queue'));
        add_action('wp_ajax_bsp_clear_all_queue', array($this, 'handle_clear_all_queue'));
    }
    
    /**
     * Handle single page generation
     */
    public function handle_generate_single() {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        $post_id = intval($_POST['post_id']);
        
        if (!$post_id) {
            wp_send_json_error(array(
                'message' => __('Invalid post ID', 'breakdance-static-pages')
            ));
        }
        
        try {
            // Use atomic operations for single page generation
            $result = BSP_Atomic_Operations::generate_with_rollback($post_id);
            
            if ($result['success']) {
                // Invalidate stats cache after successful generation
                BSP_Stats_Cache::invalidate();
                
                $file_size = get_post_meta($post_id, '_bsp_static_file_size', true);
                $generated_time = get_post_meta($post_id, '_bsp_static_generated', true);
                
                wp_send_json_success(array(
                    'message' => __('Static file generated successfully!', 'breakdance-static-pages'),
                    'post_id' => $post_id,
                    'file_size' => $file_size ? size_format($file_size) : '',
                    'generated_time' => $generated_time ? human_time_diff(strtotime($generated_time), current_time('timestamp')) . ' ago' : '',
                    'static_url' => Breakdance_Static_Pages::get_static_file_url($post_id)
                ));
            } else {
                wp_send_json_error(array(
                    'message' => __('Failed to generate static file: ', 'breakdance-static-pages') . $result['error']
                ));
            }
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('Error: ', 'breakdance-static-pages') . $e->getMessage()
            ));
        }
    }
    
    /**
     * Handle multiple page generation
     */
    public function handle_generate_multiple() {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        $post_ids = array_map('intval', $_POST['post_ids']);
        
        if (empty($post_ids)) {
            wp_send_json_error(array(
                'message' => __('No pages selected', 'breakdance-static-pages')
            ));
        }
        
        try {
            // Increase time limit for bulk operations
            set_time_limit(0);
            
            // Use atomic bulk operations
            $result = BSP_Atomic_Operations::bulk_operation_atomic($post_ids, 'generate');
            
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Bulk generation completed. %d successful, %d errors.', 'breakdance-static-pages'),
                    $result['success_count'],
                    $result['failure_count']
                ),
                'results' => $result['completed'],
                'failed' => $result['failed'],
                'success_count' => $result['success_count'],
                'error_count' => $result['failure_count']
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('Error: ', 'breakdance-static-pages') . $e->getMessage()
            ));
        }
    }
    
    /**
     * Handle single page deletion
     */
    public function handle_delete_single() {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        $post_id = intval($_POST['post_id']);
        
        if (!$post_id) {
            wp_send_json_error(array(
                'message' => __('Invalid post ID', 'breakdance-static-pages')
            ));
        }
        
        try {
            // Use atomic operations for single page deletion
            $result = BSP_Atomic_Operations::delete_with_rollback($post_id);
            
            if ($result['success']) {
                wp_send_json_success(array(
                    'message' => __('Static file deleted successfully!', 'breakdance-static-pages'),
                    'post_id' => $post_id
                ));
            } else {
                wp_send_json_error(array(
                    'message' => __('Failed to delete static file: ', 'breakdance-static-pages') . $result['error']
                ));
            }
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('Error: ', 'breakdance-static-pages') . $e->getMessage()
            ));
        }
    }
    
    /**
     * Handle multiple page deletion
     */
    public function handle_delete_multiple() {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        $post_ids = array_map('intval', $_POST['post_ids']);
        
        if (empty($post_ids)) {
            wp_send_json_error(array(
                'message' => __('No pages selected', 'breakdance-static-pages')
            ));
        }
        
        try {
            // Use atomic bulk operations for deletion
            $result = BSP_Atomic_Operations::bulk_operation_atomic($post_ids, 'delete');
            
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Bulk deletion completed. %d successful, %d errors.', 'breakdance-static-pages'),
                    $result['success_count'],
                    $result['failure_count']
                ),
                'success_count' => $result['success_count'],
                'error_count' => $result['failure_count']
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('Error: ', 'breakdance-static-pages') . $e->getMessage()
            ));
        }
    }
    
    /**
     * Handle toggle static generation for a page
     */
    public function handle_toggle_static() {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        $post_id = intval($_POST['post_id']);
        $enabled = $_POST['enabled'] === 'true';
        
        if (!$post_id) {
            wp_send_json_error(array(
                'message' => __('Invalid post ID', 'breakdance-static-pages')
            ));
        }
        
        try {
            // Update the meta value
            update_post_meta($post_id, '_bsp_static_enabled', $enabled ? '1' : '');
            
            // If disabled, delete the static file
            if (!$enabled) {
                BSP_Atomic_Operations::delete_with_rollback($post_id);
            }
            
            wp_send_json_success(array(
                'message' => $enabled ? 
                    __('Static generation enabled for this page', 'breakdance-static-pages') : 
                    __('Static generation disabled for this page', 'breakdance-static-pages'),
                'post_id' => $post_id,
                'enabled' => $enabled
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('Error: ', 'breakdance-static-pages') . $e->getMessage()
            ));
        }
    }
    
    /**
     * Get plugin statistics
     */
    public function handle_get_stats() {
        // Verify nonce and permissions
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        try {
            // Use cached stats for better performance
            $stats = BSP_Stats_Cache::get_detailed_stats();
            
            wp_send_json_success($stats);
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => __('Error: ', 'breakdance-static-pages') . $e->getMessage()
            ));
        }
    }
    
    /**
     * Serve static file to logged-in admins only
     */
    public function serve_static_file() {
        // Check if user is logged in and has admin capabilities
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_die(__('Access denied. Static files are only accessible to administrators.', 'breakdance-static-pages'), 'Access Denied', array('response' => 403));
        }
        
        $this->serve_static_file_content();
    }
    
    /**
     * Handle non-privileged requests (deny access)
     */
    public function serve_static_file_nopriv() {
        wp_die(__('Access denied. Static files are only accessible to administrators to prevent SEO duplicate content issues.', 'breakdance-static-pages'), 'Access Denied', array('response' => 403));
    }
    
    /**
     * Actually serve the static file content
     */
    private function serve_static_file_content() {
        $file = sanitize_text_field($_GET['file']);
        
        if (empty($file)) {
            wp_die(__('No file specified.', 'breakdance-static-pages'), 'Bad Request', array('response' => 400));
        }
        
        // Security: Only allow files from the pages directory
        if (strpos($file, '..') !== false || strpos($file, 'pages/') !== 0) {
            wp_die(__('Invalid file path.', 'breakdance-static-pages'), 'Bad Request', array('response' => 400));
        }
        
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/breakdance-static-pages/' . $file;
        
        // Check if file exists and is readable
        if (!file_exists($file_path) || !is_readable($file_path)) {
            wp_die(__('File not found.', 'breakdance-static-pages'), 'Not Found', array('response' => 404));
        }
        
        // Security: Ensure it's an HTML file
        if (pathinfo($file_path, PATHINFO_EXTENSION) !== 'html') {
            wp_die(__('Invalid file type.', 'breakdance-static-pages'), 'Bad Request', array('response' => 400));
        }
        
        // Set appropriate headers
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Robots-Tag: noindex, nofollow'); // Prevent search engine indexing
        
        // Add admin notice to the HTML
        $content = file_get_contents($file_path);
        $admin_notice = '
        <div style="position: fixed; top: 0; left: 0; right: 0; background: #d63638; color: white; padding: 10px; text-align: center; z-index: 999999; font-family: Arial, sans-serif; font-size: 14px;">
            <strong>ADMIN PREVIEW:</strong> This is a static file preview only accessible to administrators. Public users see the original dynamic page to prevent SEO issues.
        </div>
        <style>body { margin-top: 50px !important; }</style>';
        
        $content = str_replace('<body', $admin_notice . '<body', $content);
        
        echo $content;
        exit;
    }
    
    /**
     * Handle cleanup orphaned files
     */
    public function handle_cleanup_orphaned() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $cache_manager = new BSP_Cache_Manager();
        $cleaned = $cache_manager->cleanup_orphaned_files();
        
        wp_send_json_success(array(
            'message' => sprintf(__('Cleaned up %d orphaned files', 'breakdance-static-pages'), $cleaned)
        ));
    }
    
    /**
     * Handle clear all locks
     */
    public function handle_clear_all_locks() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $lock_manager = BSP_File_Lock_Manager::get_instance();
        $cleared = $lock_manager->force_release_all_locks();
        
        wp_send_json_success(array(
            'message' => sprintf(__('Cleared %d locks', 'breakdance-static-pages'), $cleared)
        ));
    }
    
    /**
     * Handle delete all static files
     */
    public function handle_delete_all_static() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        global $wpdb;
        
        // Get all posts with static files
        $posts = $wpdb->get_col("
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_bsp_static_generated'
        ");
        
        $deleted = 0;
        
        foreach ($posts as $post_id) {
            $file_path = Breakdance_Static_Pages::get_static_file_path($post_id);
            
            if (file_exists($file_path) && unlink($file_path)) {
                $deleted++;
                
                // Clean up metadata
                delete_post_meta($post_id, '_bsp_static_generated');
                delete_post_meta($post_id, '_bsp_static_file_size');
                delete_post_meta($post_id, '_bsp_static_etag');
                delete_post_meta($post_id, '_bsp_static_etag_time');
            }
        }
        
        wp_send_json_success(array(
            'message' => sprintf(__('Deleted %d static files', 'breakdance-static-pages'), $deleted)
        ));
    }
    
    /**
     * Handle clear errors
     */
    public function handle_clear_errors() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $error_handler = BSP_Error_Handler::get_instance();
        $error_handler->clear_errors();
        
        wp_send_json_success(array(
            'message' => __('All errors cleared successfully', 'breakdance-static-pages')
        ));
    }
    
    /**
     * Handle export errors
     */
    public function handle_export_errors() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $error_handler = BSP_Error_Handler::get_instance();
        $export_data = $error_handler->export_errors();
        
        wp_send_json_success(array(
            'message' => __('Errors exported successfully', 'breakdance-static-pages'),
            'data' => $export_data,
            'filename' => 'bsp-errors-' . date('Y-m-d-His') . '.json'
        ));
    }
    
    /**
     * Handle retry failed queue items
     */
    public function handle_retry_failed_queue() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $queue_manager = BSP_Queue_Manager::get_instance();
        $retried = $queue_manager->retry_failed_items();
        
        wp_send_json_success(array(
            'message' => sprintf(__('%d failed items set to retry', 'breakdance-static-pages'), $retried)
        ));
    }
    
    /**
     * Handle clear completed queue items
     */
    public function handle_clear_completed_queue() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $queue_manager = BSP_Queue_Manager::get_instance();
        $cleared = $queue_manager->clear_queue('completed');
        
        wp_send_json_success(array(
            'message' => sprintf(__('%d completed items cleared', 'breakdance-static-pages'), $cleared)
        ));
    }
    
    /**
     * Handle clear all queue items
     */
    public function handle_clear_all_queue() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $queue_manager = BSP_Queue_Manager::get_instance();
        $cleared = $queue_manager->clear_queue();
        
        wp_send_json_success(array(
            'message' => __('Queue cleared successfully', 'breakdance-static-pages')
        ));
    }
}

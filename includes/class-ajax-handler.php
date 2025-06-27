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
            $generator = new BSP_Static_Generator();
            $result = $generator->generate_static_page($post_id);
            
            if ($result) {
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
                    'message' => __('Failed to generate static file. Check error logs for details.', 'breakdance-static-pages')
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
            
            $generator = new BSP_Static_Generator();
            $results = array();
            $success_count = 0;
            $error_count = 0;
            
            foreach ($post_ids as $post_id) {
                $result = $generator->generate_static_page($post_id);
                $results[$post_id] = $result;
                
                if ($result) {
                    $success_count++;
                } else {
                    $error_count++;
                }
                
                // Send progress update
                $progress = array(
                    'current' => count($results),
                    'total' => count($post_ids),
                    'success' => $success_count,
                    'errors' => $error_count,
                    'percentage' => round((count($results) / count($post_ids)) * 100)
                );
                
                // For real-time updates, you could implement Server-Sent Events here
                // For now, we'll just continue processing
            }
            
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Bulk generation completed. %d successful, %d errors.', 'breakdance-static-pages'),
                    $success_count,
                    $error_count
                ),
                'results' => $results,
                'success_count' => $success_count,
                'error_count' => $error_count
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
            $generator = new BSP_Static_Generator();
            $result = $generator->delete_static_page($post_id);
            
            if ($result) {
                wp_send_json_success(array(
                    'message' => __('Static file deleted successfully!', 'breakdance-static-pages'),
                    'post_id' => $post_id
                ));
            } else {
                wp_send_json_error(array(
                    'message' => __('Failed to delete static file.', 'breakdance-static-pages')
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
            $generator = new BSP_Static_Generator();
            $success_count = 0;
            $error_count = 0;
            
            foreach ($post_ids as $post_id) {
                $result = $generator->delete_static_page($post_id);
                
                if ($result) {
                    $success_count++;
                } else {
                    $error_count++;
                }
            }
            
            wp_send_json_success(array(
                'message' => sprintf(
                    __('Bulk deletion completed. %d successful, %d errors.', 'breakdance-static-pages'),
                    $success_count,
                    $error_count
                ),
                'success_count' => $success_count,
                'error_count' => $error_count
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
                $generator = new BSP_Static_Generator();
                $generator->delete_static_page($post_id);
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
            $generator = new BSP_Static_Generator();
            $stats = $generator->get_generation_stats();
            
            // Add additional stats
            global $wpdb;
            
            $stats['total_pages'] = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('page', 'post') AND post_status = 'publish'"
            );
            
            // Calculate performance metrics
            $upload_dir = wp_upload_dir();
            $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages';
            
            if (file_exists($static_dir)) {
                $files = glob($static_dir . '/*.html');
                $stats['static_files_count'] = count($files);
                
                $total_size = 0;
                foreach ($files as $file) {
                    $total_size += filesize($file);
                }
                $stats['total_disk_usage'] = $total_size;
            } else {
                $stats['static_files_count'] = 0;
                $stats['total_disk_usage'] = 0;
            }
            
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
}

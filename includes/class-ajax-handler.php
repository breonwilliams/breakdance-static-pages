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
}

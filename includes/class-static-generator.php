<?php
/**
 * Static Generator Class
 * Handles the generation of static HTML files from dynamic pages
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSP_Static_Generator {
    
    /**
     * Generate static HTML for a specific page
     */
    public function generate_static_page($post_id) {
        // Get instances
        $lock_manager = BSP_File_Lock_Manager::get_instance();
        $error_handler = BSP_Error_Handler::get_instance();
        
        // Try to acquire lock
        if (!$lock_manager->acquire_lock($post_id)) {
            $error_handler->log_error(
                'static_generation',
                'Could not acquire lock for post ID: ' . $post_id . ' - generation already in progress',
                'warning',
                array('post_id' => $post_id)
            );
            return array(
                'success' => false,
                'error' => 'Generation already in progress for this page'
            );
        }
        
        try {
            // Track generation start time
            $start_time = microtime(true);
            
            // Check memory before starting
            if (!$this->check_memory_usage()) {
                $lock_manager->release_lock($post_id);
                $error_handler->log_error(
                    'static_generation',
                    'Insufficient memory available for static generation',
                    'error',
                    array('post_id' => $post_id, 'memory_limit' => ini_get('memory_limit'))
                );
                return array(
                    'success' => false,
                    'error' => 'Insufficient memory available for static generation'
                );
            }
            
            $error_handler->log_error(
                'static_generation',
                'Starting static generation for post ID: ' . $post_id,
                'info',
                array('post_id' => $post_id)
            );
            
            // Get the post
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                $error_handler->log_error(
                    'static_generation',
                    'Post not found or not published: ' . $post_id,
                    'error',
                    array('post_id' => $post_id)
                );
                $lock_manager->release_lock($post_id);
                return false;
            }
            
            // Get the page URL
            $page_url = get_permalink($post_id);
            if (!$page_url) {
                $error_handler->log_error(
                    'static_generation',
                    'Could not get permalink for post: ' . $post_id,
                    'error',
                    array('post_id' => $post_id)
                );
                $lock_manager->release_lock($post_id);
                return false;
            }
            
            // Capture the page HTML
            $html_content = $this->capture_page_html($page_url, $post_id);
            
            if (!$html_content) {
                $error_handler->log_error(
                    'static_generation',
                    'Failed to capture HTML for post: ' . $post_id,
                    'error',
                    array('post_id' => $post_id, 'url' => $page_url)
                );
                $lock_manager->release_lock($post_id);
                return false;
            }
            
            // Process and optimize the HTML
            $optimized_html = $this->optimize_html($html_content, $post_id);
            
            // Save the static file
            $static_file_path = Breakdance_Static_Pages::get_static_file_path($post_id);
            $result = $this->save_static_file($static_file_path, $optimized_html);
            
            if ($result) {
                // Update metadata
                update_post_meta($post_id, '_bsp_static_generated', current_time('mysql'));
                update_post_meta($post_id, '_bsp_static_file_size', filesize($static_file_path));
                
                // Calculate and store ETag for caching
                $etag = md5_file($static_file_path);
                update_post_meta($post_id, '_bsp_static_etag', $etag);
                update_post_meta($post_id, '_bsp_static_etag_time', time());
                
                // Track generation time
                $generation_time = microtime(true) - $start_time;
                BSP_Stats_Cache::track_generation_time($generation_time);
                
                $error_handler->log_error(
                    'static_generation',
                    sprintf('Static file generated successfully for post %d in %.2f seconds', $post_id, $generation_time),
                    'info',
                    array(
                        'post_id' => $post_id,
                        'generation_time' => $generation_time,
                        'file_size' => filesize($static_file_path)
                    )
                );
                
                // Fire action for other plugins to hook into
                do_action('bsp_static_page_generated', $post_id, $static_file_path);
                
                // Release lock on success
                $lock_manager->release_lock($post_id);
                
                return true;
            }
            
            // Release lock on failure
            $lock_manager->release_lock($post_id);
            return false;
            
        } catch (Exception $e) {
            $error_handler->log_error(
                'static_generation',
                'Exception during static generation: ' . $e->getMessage(),
                'critical',
                array('post_id' => $post_id),
                $e
            );
            // Always release lock in case of exception
            $lock_manager->release_lock($post_id);
            return false;
        }
    }
    
    /**
     * Capture HTML content from a page URL
     */
    private function capture_page_html($url, $post_id) {
        // Method 1: Use WordPress internal request (preferred)
        $html = $this->capture_via_internal_request($url, $post_id);
        
        if ($html) {
            return $html;
        }
        
        // Method 2: Fallback to cURL if internal request fails
        return $this->capture_via_curl($url);
    }
    
    /**
     * Capture HTML using WordPress internal request
     */
    private function capture_via_internal_request($url, $post_id) {
        // Temporarily disable static serving to avoid recursion
        add_filter('bsp_disable_static_serving', '__return_true');
        
        // Set up the request
        $args = array(
            'timeout' => 30,
            'user-agent' => 'BSP Static Generator',
            'headers' => array(
                'X-BSP-Static-Generation' => '1'
            )
        );
        
        // Make the request
        $response = wp_remote_get($url, $args);
        
        // Re-enable static serving
        remove_filter('bsp_disable_static_serving', '__return_true');
        
        if (is_wp_error($response)) {
            BSP_Error_Handler::get_instance()->log_error(
                'static_generation',
                'WordPress request failed: ' . $response->get_error_message(),
                'error',
                array('url' => $url, 'post_id' => $post_id)
            );
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            BSP_Error_Handler::get_instance()->log_error(
                'static_generation',
                'HTTP error ' . $response_code . ' for URL: ' . $url,
                'error',
                array('url' => $url, 'post_id' => $post_id, 'response_code' => $response_code)
            );
            return false;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        if (empty($html)) {
            BSP_Error_Handler::get_instance()->log_error(
                'static_generation',
                'Empty response body for URL: ' . $url,
                'error',
                array('url' => $url, 'post_id' => $post_id)
            );
            return false;
        }
        
        return $html;
    }
    
    /**
     * Capture HTML using cURL as fallback
     */
    private function capture_via_curl($url) {
        if (!function_exists('curl_init')) {
            error_log('BSP: cURL not available');
            return false;
        }
        
        $ch = curl_init();
        
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'BSP Static Generator',
            CURLOPT_HTTPHEADER => array(
                'X-BSP-Static-Generation: 1'
            ),
            CURLOPT_SSL_VERIFYPEER => false
        ));
        
        $html = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            error_log('BSP: cURL error: ' . $error);
            return false;
        }
        
        if ($http_code !== 200) {
            error_log('BSP: HTTP error ' . $http_code . ' for URL: ' . $url);
            return false;
        }
        
        return $html;
    }
    
    /**
     * Optimize HTML for static serving
     */
    private function optimize_html($html, $post_id) {
        // Remove WordPress admin bar if present
        $html = preg_replace('/<div[^>]*id="wpadminbar"[^>]*>.*?<\/div>/s', '', $html);
        
        // Remove edit links and admin elements
        $html = preg_replace('/<span[^>]*class="[^"]*edit-link[^"]*"[^>]*>.*?<\/span>/s', '', $html);
        
        // Add static generation comment
        $comment = "\n<!-- Generated by Breakdance Static Pages on " . current_time('mysql') . " -->\n";
        $html = str_replace('</head>', $comment . '</head>', $html);
        
        // Optimize CSS and JS (basic optimization)
        $html = $this->optimize_assets($html, $post_id);
        
        // Add cache headers meta tag
        $cache_meta = '<meta name="bsp-static-cache" content="' . time() . '">' . "\n";
        $html = str_replace('</head>', $cache_meta . '</head>', $html);
        
        return $html;
    }
    
    /**
     * Optimize CSS and JS assets
     */
    private function optimize_assets($html, $post_id) {
        // For now, just ensure all URLs are absolute
        $site_url = home_url();
        
        // Convert relative URLs to absolute
        $html = preg_replace('/href="\/([^"]*)"/', 'href="' . $site_url . '/$1"', $html);
        $html = preg_replace('/src="\/([^"]*)"/', 'src="' . $site_url . '/$1"', $html);
        
        // Future: Could implement CSS/JS minification here
        
        return $html;
    }
    
    /**
     * Save static HTML file with atomic operation
     */
    private function save_static_file($file_path, $html_content) {
        // Ensure directory exists
        $dir = dirname($file_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        
        // Use temporary file for atomic write
        $temp_file = $file_path . '.tmp.' . uniqid();
        
        // Save to temporary file first
        $result = file_put_contents($temp_file, $html_content, LOCK_EX);
        
        if ($result === false) {
            error_log('BSP: Failed to save temporary file: ' . $temp_file);
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }
            return false;
        }
        
        // Set appropriate permissions on temp file
        @chmod($temp_file, 0644);
        
        // Atomically move temp file to final location
        if (!@rename($temp_file, $file_path)) {
            error_log('BSP: Failed to move temp file to final location: ' . $file_path);
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }
            return false;
        }
        
        return true;
    }
    
    /**
     * Generate static files for multiple pages
     */
    public function generate_multiple_pages($post_ids) {
        $results = array();
        
        foreach ($post_ids as $post_id) {
            $results[$post_id] = $this->generate_static_page($post_id);
            
            // Small delay to prevent server overload
            usleep(100000); // 0.1 seconds
        }
        
        return $results;
    }
    
    /**
     * Delete static file for a page
     */
    public function delete_static_page($post_id) {
        $static_file_path = Breakdance_Static_Pages::get_static_file_path($post_id);
        
        if (file_exists($static_file_path)) {
            $result = unlink($static_file_path);
            
            if ($result) {
                delete_post_meta($post_id, '_bsp_static_generated');
                delete_post_meta($post_id, '_bsp_static_file_size');
                
                do_action('bsp_static_page_deleted', $post_id);
                
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if page content has changed since last generation
     */
    public function has_content_changed($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return true;
        }
        
        $last_generated = get_post_meta($post_id, '_bsp_static_generated', true);
        if (!$last_generated) {
            return true;
        }
        
        $last_generated_time = strtotime($last_generated);
        $post_modified_time = strtotime($post->post_modified);
        
        // Check if post was modified after static generation
        if ($post_modified_time > $last_generated_time) {
            return true;
        }
        
        // Check if ACF fields were modified (if ACF is active)
        if (function_exists('get_fields')) {
            // This is a simplified check - in practice, you might want to store
            // a hash of ACF field values and compare
            return true;
        }
        
        return false;
    }
    
    /**
     * Check memory usage before operation
     */
    private function check_memory_usage() {
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_usage = memory_get_usage(true);
        $memory_available = $memory_limit - $memory_usage;
        
        // Require at least 50MB free memory
        $required_memory = 50 * MB_IN_BYTES;
        
        if ($memory_available < $required_memory) {
            error_log(sprintf(
                'BSP: Low memory warning - Available: %s, Required: %s',
                size_format($memory_available),
                size_format($required_memory)
            ));
            return false;
        }
        
        return true;
    }
    
    /**
     * Get generation statistics
     */
    public function get_generation_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Count total pages with static generation enabled
        $stats['enabled_pages'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bsp_static_enabled' AND meta_value = '1'"
        );
        
        // Count pages with generated static files
        $stats['generated_pages'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_bsp_static_generated'"
        );
        
        // Calculate total size of static files
        $file_sizes = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_bsp_static_file_size'"
        );
        
        $stats['total_size'] = array_sum($file_sizes);
        $stats['average_size'] = count($file_sizes) > 0 ? $stats['total_size'] / count($file_sizes) : 0;
        
        return $stats;
    }
}

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
        try {
            error_log('BSP: Starting static generation for post ID: ' . $post_id);
            
            // Get the post
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                error_log('BSP: Post not found or not published: ' . $post_id);
                return false;
            }
            
            // Get the page URL
            $page_url = get_permalink($post_id);
            if (!$page_url) {
                error_log('BSP: Could not get permalink for post: ' . $post_id);
                return false;
            }
            
            // Capture the page HTML
            $html_content = $this->capture_page_html($page_url, $post_id);
            
            if (!$html_content) {
                error_log('BSP: Failed to capture HTML for post: ' . $post_id);
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
                
                error_log('BSP: Static file generated successfully for post: ' . $post_id);
                
                // Fire action for other plugins to hook into
                do_action('bsp_static_page_generated', $post_id, $static_file_path);
                
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log('BSP: Exception during static generation: ' . $e->getMessage());
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
            error_log('BSP: WordPress request failed: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('BSP: HTTP error ' . $response_code . ' for URL: ' . $url);
            return false;
        }
        
        $html = wp_remote_retrieve_body($response);
        
        if (empty($html)) {
            error_log('BSP: Empty response body for URL: ' . $url);
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
     * Save static HTML file
     */
    private function save_static_file($file_path, $html_content) {
        // Ensure directory exists
        $dir = dirname($file_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        
        // Save the file
        $result = file_put_contents($file_path, $html_content);
        
        if ($result === false) {
            error_log('BSP: Failed to save static file: ' . $file_path);
            return false;
        }
        
        // Set appropriate permissions
        chmod($file_path, 0644);
        
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

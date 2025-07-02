<?php
/**
 * URL Rewriter Class
 * Handles serving static files instead of dynamic pages
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSP_URL_Rewriter {
    
    public function __construct() {
        add_action('template_redirect', array($this, 'maybe_serve_static'), 1);
        add_filter('the_content', array($this, 'add_static_indicator'), 999);
    }
    
    /**
     * Check if we should serve a static version of the current page
     */
    public function maybe_serve_static() {
        // Don't serve static files in admin or for AJAX requests
        if (is_admin() || wp_doing_ajax()) {
            return;
        }
        
        // Don't serve static files during static generation
        if (apply_filters('bsp_disable_static_serving', false)) {
            return;
        }
        
        // Don't serve static files if this is a static generation request
        if (isset($_SERVER['HTTP_X_BSP_STATIC_GENERATION'])) {
            return;
        }
        
        // Only handle singular pages and posts
        if (!is_singular()) {
            return;
        }
        
        global $post;
        
        if (!$post) {
            return;
        }
        
        // Check if this page should be served statically
        if (!Breakdance_Static_Pages::should_serve_static($post->ID)) {
            return;
        }
        
        // Get the static file path
        $static_file_path = Breakdance_Static_Pages::get_static_file_path($post->ID);
        
        if (!file_exists($static_file_path)) {
            return;
        }
        
        // Serve the static file
        $this->serve_static_file($static_file_path, $post->ID);
    }
    
    /**
     * Serve the static HTML file
     */
    private function serve_static_file($file_path, $post_id) {
        // Set appropriate headers
        $this->set_static_headers($file_path, $post_id);
        
        // Stream the file for better memory efficiency
        if (!$this->stream_static_file($file_path, $post_id)) {
            // Log the error for debugging
            error_log('BSP: Failed to stream static file: ' . $file_path);
            return; // Fall back to dynamic rendering
        }
        
        exit;
    }
    
    /**
     * Set appropriate headers for static files
     */
    private function set_static_headers($file_path, $post_id) {
        // Set content type
        header('Content-Type: text/html; charset=UTF-8');
        
        // Add SEO protection headers to prevent duplicate content
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Robots-Tag: noarchive, nosnippet');
        
        // Add canonical URL header pointing to original dynamic page
        $canonical_url = get_permalink($post_id);
        if ($canonical_url) {
            header('Link: <' . $canonical_url . '>; rel="canonical"');
        }
        
        // Set cache headers
        $max_age = apply_filters('bsp_static_cache_max_age', 3600); // 1 hour default
        header('Cache-Control: public, max-age=' . $max_age);
        
        // Set last modified header
        $last_modified = filemtime($file_path);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $last_modified) . ' GMT');
        
        // Get ETag - try cached version first for performance
        $etag = $this->get_cached_etag($file_path, $post_id);
        header('ETag: "' . $etag . '"');
        
        // Check if client has cached version
        $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? 
            strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : 0;
        $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? 
            trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') : '';
        
        if ($if_modified_since >= $last_modified || $if_none_match === $etag) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }
        
        // Add custom headers to indicate static serving
        header('X-BSP-Static-Served: true');
        header('X-BSP-Generated: ' . gmdate('D, d M Y H:i:s', $last_modified) . ' GMT');
        header('X-BSP-Original-URL: ' . $canonical_url);
    }
    
    /**
     * Stream static file with chunked reading for memory efficiency
     */
    private function stream_static_file($file_path, $post_id) {
        $handle = @fopen($file_path, 'rb');
        if ($handle === false) {
            return false;
        }
        
        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // For admin users, we need to add debug indicator
        $add_indicator = current_user_can('manage_options');
        $indicator_added = false;
        $chunk_size = 8192; // 8KB chunks
        
        while (!feof($handle)) {
            $chunk = fread($handle, $chunk_size);
            
            // Add indicator to first chunk containing <body> tag if admin
            if ($add_indicator && !$indicator_added && stripos($chunk, '<body') !== false) {
                $chunk = $this->add_admin_indicator_to_chunk($chunk, $post_id);
                $indicator_added = true;
            }
            
            echo $chunk;
            
            // Flush output to browser
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
        
        fclose($handle);
        return true;
    }
    
    /**
     * Add admin indicator to chunk containing body tag
     */
    private function add_admin_indicator_to_chunk($chunk, $post_id) {
        $indicator = '<div style="position:fixed;top:0;right:0;background:#0073aa;color:white;padding:10px;z-index:99999;font-family:sans-serif;">
            ⚡ Static Version (Admin View Only)
        </div>';
        
        // Find body tag and insert after it
        $chunk = preg_replace('/(<body[^>]*>)/i', '$1' . $indicator, $chunk);
        
        return $chunk;
    }
    
    /**
     * Get cached ETag or calculate if needed
     */
    private function get_cached_etag($file_path, $post_id) {
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
        
        // Cache it for next time
        update_post_meta($post_id, '_bsp_static_etag', $etag);
        update_post_meta($post_id, '_bsp_static_etag_time', time());
        
        return $etag;
    }
    
    /**
     * Add static indicator to content (for debugging)
     */
    public function add_static_indicator($content) {
        // Only add indicator if we're viewing a static-enabled page
        if (!is_singular()) {
            return $content;
        }
        
        global $post;
        
        if (!$post) {
            return $content;
        }
        
        $static_enabled = get_post_meta($post->ID, '_bsp_static_enabled', true);
        
        if (!$static_enabled) {
            return $content;
        }
        
        // Check if user can see indicators (admin users only)
        if (!current_user_can('manage_options')) {
            return $content;
        }
        
        $static_file_exists = file_exists(Breakdance_Static_Pages::get_static_file_path($post->ID));
        $generated_time = get_post_meta($post->ID, '_bsp_static_generated', true);
        
        $indicator = '<div class="bsp-static-indicator" style="
            position: fixed;
            top: 32px;
            right: 20px;
            background: #0073aa;
            color: white;
            padding: 10px 15px;
            border-radius: 4px;
            font-size: 12px;
            z-index: 99999;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        ">';
        
        if ($static_file_exists) {
            $indicator .= '⚡ Static Active';
            if ($generated_time) {
                $indicator .= '<br><small>Generated: ' . 
                    human_time_diff(strtotime($generated_time), current_time('timestamp')) . ' ago</small>';
            }
        } else {
            $indicator .= '⏳ Static Enabled (Not Generated)';
        }
        
        $indicator .= '</div>';
        
        // Add some JavaScript to make it dismissible
        $indicator .= '<script>
            document.addEventListener("DOMContentLoaded", function() {
                var indicator = document.querySelector(".bsp-static-indicator");
                if (indicator) {
                    indicator.style.cursor = "pointer";
                    indicator.title = "Click to dismiss";
                    indicator.addEventListener("click", function() {
                        this.style.display = "none";
                    });
                }
            });
        </script>';
        
        return $content . $indicator;
    }
}

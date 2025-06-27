<?php
/**
 * Cache Manager Class
 * Handles cache management and cleanup operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSP_Cache_Manager {
    
    public function __construct() {
        add_action('bsp_cleanup_old_static_files', array($this, 'cleanup_old_files'));
        add_action('wp_trash_post', array($this, 'handle_post_trash'));
        add_action('delete_post', array($this, 'handle_post_delete'));
    }
    
    /**
     * Clean up old static files
     */
    public function cleanup_old_files() {
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages';
        
        if (!file_exists($static_dir)) {
            return;
        }
        
        $files = glob($static_dir . '/*.html');
        $cleaned_count = 0;
        $max_age = apply_filters('bsp_cleanup_max_age', 30 * DAY_IN_SECONDS); // 30 days default
        
        foreach ($files as $file) {
            $file_age = time() - filemtime($file);
            
            // Extract post ID from filename (format: page-{ID}.html)
            $filename = basename($file, '.html');
            $post_id = str_replace('page-', '', $filename);
            
            if (!is_numeric($post_id)) {
                continue;
            }
            
            $post = get_post($post_id);
            
            // Delete file if post doesn't exist or is not published
            if (!$post || $post->post_status !== 'publish') {
                if (unlink($file)) {
                    $cleaned_count++;
                    delete_post_meta($post_id, '_bsp_static_generated');
                    delete_post_meta($post_id, '_bsp_static_file_size');
                }
                continue;
            }
            
            // Delete file if it's too old and static generation is disabled
            $static_enabled = get_post_meta($post_id, '_bsp_static_enabled', true);
            if (!$static_enabled && $file_age > $max_age) {
                if (unlink($file)) {
                    $cleaned_count++;
                    delete_post_meta($post_id, '_bsp_static_generated');
                    delete_post_meta($post_id, '_bsp_static_file_size');
                }
            }
        }
        
        error_log('BSP: Cleaned up ' . $cleaned_count . ' old static files');
        
        return $cleaned_count;
    }
    
    /**
     * Handle post being moved to trash
     */
    public function handle_post_trash($post_id) {
        $this->delete_static_file($post_id);
    }
    
    /**
     * Handle post being permanently deleted
     */
    public function handle_post_delete($post_id) {
        $this->delete_static_file($post_id);
    }
    
    /**
     * Delete static file for a specific post
     */
    private function delete_static_file($post_id) {
        $static_file_path = Breakdance_Static_Pages::get_static_file_path($post_id);
        
        if (file_exists($static_file_path)) {
            unlink($static_file_path);
        }
        
        delete_post_meta($post_id, '_bsp_static_generated');
        delete_post_meta($post_id, '_bsp_static_file_size');
        delete_post_meta($post_id, '_bsp_static_enabled');
    }
    
    /**
     * Clear all static files
     */
    public function clear_all_static_files() {
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages';
        
        if (!file_exists($static_dir)) {
            return 0;
        }
        
        $files = glob($static_dir . '/*.html');
        $deleted_count = 0;
        
        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted_count++;
            }
        }
        
        // Clear all metadata
        global $wpdb;
        $wpdb->delete($wpdb->postmeta, array('meta_key' => '_bsp_static_generated'));
        $wpdb->delete($wpdb->postmeta, array('meta_key' => '_bsp_static_file_size'));
        
        return $deleted_count;
    }
    
    /**
     * Get cache statistics
     */
    public function get_cache_stats() {
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages';
        
        $stats = array(
            'total_files' => 0,
            'total_size' => 0,
            'oldest_file' => null,
            'newest_file' => null,
            'average_size' => 0
        );
        
        if (!file_exists($static_dir)) {
            return $stats;
        }
        
        $files = glob($static_dir . '/*.html');
        $stats['total_files'] = count($files);
        
        if (empty($files)) {
            return $stats;
        }
        
        $file_times = array();
        $file_sizes = array();
        
        foreach ($files as $file) {
            $size = filesize($file);
            $time = filemtime($file);
            
            $stats['total_size'] += $size;
            $file_sizes[] = $size;
            $file_times[] = $time;
        }
        
        $stats['average_size'] = $stats['total_size'] / count($files);
        $stats['oldest_file'] = min($file_times);
        $stats['newest_file'] = max($file_times);
        
        return $stats;
    }
    
    /**
     * Validate static files integrity
     */
    public function validate_static_files() {
        global $wpdb;
        
        $results = array(
            'valid' => 0,
            'invalid' => 0,
            'missing' => 0,
            'orphaned' => 0,
            'issues' => array()
        );
        
        // Get all posts with static generation enabled
        $static_posts = $wpdb->get_results(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bsp_static_enabled' AND meta_value = '1'"
        );
        
        foreach ($static_posts as $row) {
            $post_id = $row->post_id;
            $post = get_post($post_id);
            
            if (!$post || $post->post_status !== 'publish') {
                $results['invalid']++;
                $results['issues'][] = "Post {$post_id} is not published but has static generation enabled";
                continue;
            }
            
            $static_file_path = Breakdance_Static_Pages::get_static_file_path($post_id);
            
            if (!file_exists($static_file_path)) {
                $results['missing']++;
                $results['issues'][] = "Static file missing for post {$post_id}: {$post->post_title}";
                continue;
            }
            
            // Validate file content
            $content = file_get_contents($static_file_path);
            if (empty($content) || strlen($content) < 100) {
                $results['invalid']++;
                $results['issues'][] = "Static file appears corrupted for post {$post_id}: {$post->post_title}";
                continue;
            }
            
            $results['valid']++;
        }
        
        // Check for orphaned files
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages';
        
        if (file_exists($static_dir)) {
            $files = glob($static_dir . '/*.html');
            
            foreach ($files as $file) {
                $filename = basename($file, '.html');
                $post_id = str_replace('page-', '', $filename);
                
                if (!is_numeric($post_id)) {
                    continue;
                }
                
                $post = get_post($post_id);
                $static_enabled = get_post_meta($post_id, '_bsp_static_enabled', true);
                
                if (!$post || $post->post_status !== 'publish' || !$static_enabled) {
                    $results['orphaned']++;
                    $results['issues'][] = "Orphaned static file found: {$filename}.html";
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Fix static files issues
     */
    public function fix_static_files_issues() {
        $validation = $this->validate_static_files();
        $fixed_count = 0;
        
        // Remove orphaned files
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages';
        
        if (file_exists($static_dir)) {
            $files = glob($static_dir . '/*.html');
            
            foreach ($files as $file) {
                $filename = basename($file, '.html');
                $post_id = str_replace('page-', '', $filename);
                
                if (!is_numeric($post_id)) {
                    continue;
                }
                
                $post = get_post($post_id);
                $static_enabled = get_post_meta($post_id, '_bsp_static_enabled', true);
                
                if (!$post || $post->post_status !== 'publish' || !$static_enabled) {
                    if (unlink($file)) {
                        $fixed_count++;
                        delete_post_meta($post_id, '_bsp_static_generated');
                        delete_post_meta($post_id, '_bsp_static_file_size');
                    }
                }
            }
        }
        
        // Clean up metadata for non-existent posts
        global $wpdb;
        
        $orphaned_meta = $wpdb->get_results(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm 
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE pm.meta_key IN ('_bsp_static_enabled', '_bsp_static_generated', '_bsp_static_file_size') 
             AND p.ID IS NULL"
        );
        
        foreach ($orphaned_meta as $row) {
            delete_post_meta($row->post_id, '_bsp_static_enabled');
            delete_post_meta($row->post_id, '_bsp_static_generated');
            delete_post_meta($row->post_id, '_bsp_static_file_size');
            $fixed_count++;
        }
        
        return $fixed_count;
    }
}

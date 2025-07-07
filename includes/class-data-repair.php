<?php
/**
 * Data Repair Class
 *
 * Fixes data inconsistencies and repairs database issues
 *
 * @package Breakdance_Static_Pages
 * @since 1.3.3
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_Data_Repair
 *
 * Handles data repair and migration tasks
 */
class BSP_Data_Repair {
    
    /**
     * Run all repair tasks
     *
     * @return array Results of repair operations
     */
    public static function run_all_repairs() {
        $results = array();
        
        // Repair missing metadata
        $results['metadata'] = self::repair_missing_metadata();
        
        // Fix inconsistent meta values
        $results['meta_values'] = self::fix_inconsistent_meta_values();
        
        // Sync file existence with database
        $results['file_sync'] = self::sync_files_with_database();
        
        // Clean orphaned data
        $results['orphaned'] = self::clean_orphaned_data();
        
        // Recalculate stats
        BSP_Stats_Cache::invalidate();
        
        return $results;
    }
    
    /**
     * Repair missing metadata
     *
     * @return array Results
     */
    public static function repair_missing_metadata() {
        global $wpdb;
        $results = array(
            'checked' => 0,
            'repaired' => 0,
            'issues' => array()
        );
        
        // Find static files that exist
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages';
        
        if (!file_exists($static_dir)) {
            return $results;
        }
        
        $files = glob($static_dir . '/*.html');
        
        foreach ($files as $file) {
            $results['checked']++;
            
            $filename = basename($file, '.html');
            $post_id = str_replace('page-', '', $filename);
            
            if (!is_numeric($post_id)) {
                continue;
            }
            
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }
            
            // Check if metadata exists
            $static_enabled = get_post_meta($post_id, '_bsp_static_enabled', true);
            $static_generated = get_post_meta($post_id, '_bsp_static_generated', true);
            
            // If file exists but metadata is missing, repair it
            if (empty($static_enabled)) {
                update_post_meta($post_id, '_bsp_static_enabled', '1');
                $results['repaired']++;
                $results['issues'][] = "Added missing _bsp_static_enabled for post {$post_id}";
            }
            
            if (empty($static_generated)) {
                $file_time = filemtime($file);
                $generated_time = date('Y-m-d H:i:s', $file_time);
                update_post_meta($post_id, '_bsp_static_generated', $generated_time);
                $results['repaired']++;
                $results['issues'][] = "Added missing _bsp_static_generated for post {$post_id}";
            }
            
            // Update file size if missing
            $file_size = get_post_meta($post_id, '_bsp_static_file_size', true);
            if (empty($file_size)) {
                $size = filesize($file);
                update_post_meta($post_id, '_bsp_static_file_size', $size);
                $results['repaired']++;
                $results['issues'][] = "Added missing _bsp_static_file_size for post {$post_id}";
            }
        }
        
        return $results;
    }
    
    /**
     * Fix inconsistent meta values
     *
     * @return array Results
     */
    public static function fix_inconsistent_meta_values() {
        global $wpdb;
        $results = array(
            'checked' => 0,
            'fixed' => 0,
            'issues' => array()
        );
        
        // Find posts with empty string meta values and convert to proper values
        $empty_enabled = $wpdb->get_results(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_bsp_static_enabled' 
             AND meta_value = ''"
        );
        
        foreach ($empty_enabled as $row) {
            $results['checked']++;
            // Delete empty meta values - absence means disabled
            delete_post_meta($row->post_id, '_bsp_static_enabled');
            $results['fixed']++;
            $results['issues'][] = "Removed empty _bsp_static_enabled for post {$row->post_id}";
        }
        
        // Find posts with values other than '1' and standardize
        $non_standard = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
             WHERE meta_key = '_bsp_static_enabled' 
             AND meta_value NOT IN ('', '1')"
        );
        
        foreach ($non_standard as $row) {
            $results['checked']++;
            // Anything truthy becomes '1', anything else gets deleted
            if ($row->meta_value && $row->meta_value !== '0' && $row->meta_value !== 'false') {
                update_post_meta($row->post_id, '_bsp_static_enabled', '1');
                $results['fixed']++;
                $results['issues'][] = "Standardized _bsp_static_enabled from '{$row->meta_value}' to '1' for post {$row->post_id}";
            } else {
                delete_post_meta($row->post_id, '_bsp_static_enabled');
                $results['fixed']++;
                $results['issues'][] = "Removed invalid _bsp_static_enabled value '{$row->meta_value}' for post {$row->post_id}";
            }
        }
        
        return $results;
    }
    
    /**
     * Sync files with database
     *
     * @return array Results
     */
    public static function sync_files_with_database() {
        global $wpdb;
        $results = array(
            'files_checked' => 0,
            'db_checked' => 0,
            'synced' => 0,
            'issues' => array()
        );
        
        // Check database records against actual files
        $posts_with_static = $wpdb->get_results(
            "SELECT DISTINCT p.ID, pm1.meta_value as enabled, pm2.meta_value as generated
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_bsp_static_enabled'
             LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bsp_static_generated'
             WHERE p.post_status = 'publish'
             AND p.post_type IN ('page', 'post')
             AND (pm1.meta_value = '1' OR pm2.meta_value IS NOT NULL)"
        );
        
        foreach ($posts_with_static as $post) {
            $results['db_checked']++;
            
            $static_file_path = Breakdance_Static_Pages::get_static_file_path($post->ID);
            $file_exists = file_exists($static_file_path);
            
            // If marked as generated but file doesn't exist
            if ($post->generated && !$file_exists) {
                delete_post_meta($post->ID, '_bsp_static_generated');
                delete_post_meta($post->ID, '_bsp_static_file_size');
                delete_post_meta($post->ID, '_bsp_static_etag');
                delete_post_meta($post->ID, '_bsp_static_etag_time');
                $results['synced']++;
                $results['issues'][] = "Cleared generated metadata for missing file: post {$post->ID}";
            }
            
            // If enabled but not marked as generated and file exists
            if ($post->enabled === '1' && !$post->generated && $file_exists) {
                $file_time = filemtime($static_file_path);
                $file_size = filesize($static_file_path);
                update_post_meta($post->ID, '_bsp_static_generated', date('Y-m-d H:i:s', $file_time));
                update_post_meta($post->ID, '_bsp_static_file_size', $file_size);
                $results['synced']++;
                $results['issues'][] = "Added missing generated metadata for existing file: post {$post->ID}";
            }
        }
        
        return $results;
    }
    
    /**
     * Clean orphaned data
     *
     * @return array Results
     */
    public static function clean_orphaned_data() {
        global $wpdb;
        $results = array(
            'metadata_cleaned' => 0,
            'files_cleaned' => 0,
            'issues' => array()
        );
        
        // Clean metadata for non-existent posts
        $orphaned_meta = $wpdb->get_results(
            "SELECT DISTINCT pm.post_id 
             FROM {$wpdb->postmeta} pm 
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
             WHERE pm.meta_key IN ('_bsp_static_enabled', '_bsp_static_generated', '_bsp_static_file_size', '_bsp_static_etag', '_bsp_static_etag_time')
             AND p.ID IS NULL"
        );
        
        foreach ($orphaned_meta as $row) {
            delete_post_meta($row->post_id, '_bsp_static_enabled');
            delete_post_meta($row->post_id, '_bsp_static_generated');
            delete_post_meta($row->post_id, '_bsp_static_file_size');
            delete_post_meta($row->post_id, '_bsp_static_etag');
            delete_post_meta($row->post_id, '_bsp_static_etag_time');
            $results['metadata_cleaned']++;
            $results['issues'][] = "Cleaned orphaned metadata for non-existent post {$row->post_id}";
        }
        
        // Clean files for non-existent or unpublished posts
        $cache_manager = new BSP_Cache_Manager();
        $results['files_cleaned'] = $cache_manager->cleanup_orphaned_files();
        
        return $results;
    }
    
    /**
     * Analyze current data state
     *
     * @return array Analysis results
     */
    public static function analyze_data_state() {
        global $wpdb;
        
        $analysis = array(
            'summary' => array(),
            'details' => array(),
            'recommendations' => array()
        );
        
        // Count posts by status
        $post_counts = $wpdb->get_row(
            "SELECT 
                COUNT(DISTINCT p.ID) as total_posts,
                COUNT(DISTINCT CASE WHEN pm1.meta_value = '1' THEN p.ID END) as enabled_with_1,
                COUNT(DISTINCT CASE WHEN pm1.meta_value = '' THEN p.ID END) as enabled_empty,
                COUNT(DISTINCT CASE WHEN pm1.meta_value IS NULL THEN p.ID END) as enabled_null,
                COUNT(DISTINCT CASE WHEN pm2.meta_value IS NOT NULL THEN p.ID END) as has_generated,
                COUNT(DISTINCT CASE WHEN pm3.meta_value IS NOT NULL THEN p.ID END) as has_file_size
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_bsp_static_enabled'
             LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bsp_static_generated'
             LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_bsp_static_file_size'
             WHERE p.post_type IN ('page', 'post') 
             AND p.post_status = 'publish'",
            OBJECT
        );
        
        $analysis['summary'] = (array) $post_counts;
        
        // Count actual files
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages';
        $file_count = 0;
        
        if (file_exists($static_dir)) {
            $files = glob($static_dir . '/*.html');
            $file_count = count($files);
        }
        
        $analysis['summary']['actual_files'] = $file_count;
        
        // Identify issues
        if ($file_count > 0 && $analysis['summary']['enabled_with_1'] == 0) {
            $analysis['recommendations'][] = "Found {$file_count} static files but no posts marked as enabled. Run data repair to fix metadata.";
        }
        
        if ($analysis['summary']['enabled_empty'] > 0) {
            $analysis['recommendations'][] = "Found {$analysis['summary']['enabled_empty']} posts with empty enabled values. Run data repair to standardize.";
        }
        
        $mismatch = abs($file_count - $analysis['summary']['has_generated']);
        if ($mismatch > 0) {
            $analysis['recommendations'][] = "File count ({$file_count}) doesn't match generated metadata count ({$analysis['summary']['has_generated']}). Run sync to fix.";
        }
        
        return $analysis;
    }
}
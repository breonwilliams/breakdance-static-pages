<?php
/**
 * Breakdance Static Pages Uninstaller
 * 
 * This file is executed when the plugin is uninstalled.
 * It removes all plugin data including options, post meta, and files.
 *
 * @package Breakdance_Static_Pages
 * @since 1.1.0
 */

// Exit if uninstall not called from WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Helper class for uninstall operations
 */
class BSP_Uninstall {
    
    /**
     * Execute uninstall process
     */
    public static function uninstall() {
        self::remove_options();
        self::remove_post_meta();
        self::remove_scheduled_events();
        self::remove_files();
        self::cleanup_database();
    }
    
    /**
     * Remove all plugin options
     */
    private static function remove_options() {
        // Remove specific options
        $options = array(
            'bsp_db_version',
            'bsp_settings',
            'bsp_activation_time',
            'bsp_first_run',
            'bsp_critical_errors',
            'bsp_last_recovery_hourly',
            'bsp_last_recovery_daily'
        );
        
        foreach ($options as $option) {
            delete_option($option);
        }
        
        // Remove all performance and stats options
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bsp_performance_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bsp_generation_stats_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bsp_detailed_performance_%'");
        
        // Remove transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bsp_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_bsp_%'");
        
        // Log removal
        error_log('BSP Uninstall: Removed all plugin options');
    }
    
    /**
     * Remove all post meta created by the plugin
     */
    private static function remove_post_meta() {
        global $wpdb;
        
        // Define meta keys to remove
        $meta_keys = array(
            '_bsp_static_enabled',
            '_bsp_static_generated',
            '_bsp_static_file_size',
            '_bsp_static_etag',
            '_bsp_static_etag_time',
            '_bsp_generation_time',
            '_bsp_last_error',
            '_bsp_generation_failures'
        );
        
        // Remove each meta key
        foreach ($meta_keys as $meta_key) {
            $wpdb->delete(
                $wpdb->postmeta,
                array('meta_key' => $meta_key),
                array('%s')
            );
        }
        
        error_log('BSP Uninstall: Removed all post meta');
    }
    
    /**
     * Clear all scheduled events
     */
    private static function remove_scheduled_events() {
        // Clear scheduled hooks
        $hooks = array(
            'bsp_cleanup_old_static_files',
            'bsp_regenerate_static_page',
            'bsp_cleanup_locks',
            'bsp_hourly_maintenance',
            'bsp_daily_cleanup',
            'bsp_cleanup_error_logs',
            'bsp_hourly_recovery',
            'bsp_daily_recovery'
        );
        
        foreach ($hooks as $hook) {
            wp_clear_scheduled_hook($hook);
            
            // Also clear any single scheduled events
            $crons = _get_cron_array();
            foreach ($crons as $timestamp => $cron) {
                if (isset($cron[$hook])) {
                    unset($crons[$timestamp][$hook]);
                    if (empty($crons[$timestamp])) {
                        unset($crons[$timestamp]);
                    }
                }
            }
            _set_cron_array($crons);
        }
        
        error_log('BSP Uninstall: Cleared all scheduled events');
    }
    
    /**
     * Remove all files created by the plugin
     */
    private static function remove_files() {
        $upload_dir = wp_upload_dir();
        
        // Remove main plugin directory
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/';
        if (is_dir($static_dir)) {
            self::remove_directory($static_dir);
            error_log('BSP Uninstall: Removed static files directory');
        }
        
        // Remove lock directory
        $lock_dir = $upload_dir['basedir'] . '/bsp-locks/';
        if (is_dir($lock_dir)) {
            self::remove_directory($lock_dir);
            error_log('BSP Uninstall: Removed lock directory');
        }
    }
    
    /**
     * Additional database cleanup
     */
    private static function cleanup_database() {
        global $wpdb;
        
        // Drop queue table
        $table_name = $wpdb->prefix . 'bsp_queue';
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
        
        // Remove queue database version option
        delete_option('bsp_queue_db_version');
        
        // Remove API key if exists
        delete_option('bsp_api_key');
        
        // Clean up any orphaned data
        // This could include cleaning up any custom tables if they exist in future versions
        
        // Clear any remaining cache
        wp_cache_flush();
        
        error_log('BSP Uninstall: Database cleanup completed');
    }
    
    /**
     * Recursively remove a directory
     *
     * @param string $dir Directory path
     * @return bool Success
     */
    public static function remove_directory($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                self::remove_directory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
    
    /**
     * Get uninstall confirmation
     * Note: This is not used in automatic uninstall but can be called separately
     *
     * @return array Statistics about what will be removed
     */
    public static function get_uninstall_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Count options
        $stats['options'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'bsp_%'"
        );
        
        // Count post meta
        $stats['post_meta'] = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} 
             WHERE meta_key LIKE '_bsp_%'"
        );
        
        // Count static files
        $upload_dir = wp_upload_dir();
        $static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages/';
        
        if (is_dir($static_dir)) {
            $files = glob($static_dir . '*.html');
            $stats['static_files'] = $files ? count($files) : 0;
        } else {
            $stats['static_files'] = 0;
        }
        
        // Calculate disk space
        $stats['disk_space'] = 0;
        if (is_dir($upload_dir['basedir'] . '/breakdance-static-pages/')) {
            $stats['disk_space'] = self::get_directory_size(
                $upload_dir['basedir'] . '/breakdance-static-pages/'
            );
        }
        
        return $stats;
    }
    
    /**
     * Get directory size
     *
     * @param string $dir Directory path
     * @return int Size in bytes
     */
    private static function get_directory_size($dir) {
        $size = 0;
        
        if (!is_dir($dir)) {
            return $size;
        }
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }
}

// Execute uninstall
try {
    BSP_Uninstall::uninstall();
    error_log('Breakdance Static Pages: Uninstall completed successfully');
} catch (Exception $e) {
    error_log('Breakdance Static Pages: Uninstall error - ' . $e->getMessage());
}
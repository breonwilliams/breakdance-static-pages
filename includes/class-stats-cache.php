<?php
/**
 * Stats Cache Class
 *
 * Provides caching layer for plugin statistics to reduce database queries.
 *
 * @package Breakdance_Static_Pages
 * @since 1.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_Stats_Cache
 *
 * Caches frequently accessed statistics to improve performance.
 */
class BSP_Stats_Cache {
    
    /**
     * Cache key prefix
     *
     * @var string
     */
    private static $cache_prefix = 'bsp_stats_';
    
    /**
     * Default cache expiry in seconds
     *
     * @var int
     */
    private static $default_expiry = 30; // 30 seconds for more real-time updates
    
    /**
     * Get cached stats or calculate if expired
     *
     * @param bool $force_refresh Force recalculation
     * @return array Statistics array
     */
    public static function get_stats($force_refresh = false) {
        $cache_key = self::$cache_prefix . 'main';
        
        // Try to get from cache first
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Calculate fresh stats
        $stats = self::calculate_stats();
        
        // Cache the results
        set_transient($cache_key, $stats, self::$default_expiry);
        
        return $stats;
    }
    
    /**
     * Calculate fresh statistics
     *
     * @return array Statistics
     */
    private static function calculate_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Use single optimized query for basic counts
        $results = $wpdb->get_row("
            SELECT 
                COUNT(DISTINCT p.ID) as total_pages,
                COUNT(DISTINCT CASE WHEN pm1.meta_value = '1' THEN pm1.post_id END) as static_enabled,
                COUNT(DISTINCT pm2.post_id) as static_generated
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_bsp_static_enabled'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bsp_static_generated'
            WHERE p.post_type IN ('page', 'post') 
            AND p.post_status = 'publish'
        ");
        
        $stats['total_pages'] = intval($results->total_pages);
        $stats['static_enabled'] = intval($results->static_enabled);
        $stats['static_generated'] = intval($results->static_generated);
        
        // Calculate total file size
        $stats['total_size'] = self::calculate_total_size();
        
        // Add performance metrics if available
        $stats['performance'] = self::get_performance_metrics();
        
        // Add generation success rate
        if ($stats['static_enabled'] > 0) {
            $stats['success_rate'] = round(($stats['static_generated'] / $stats['static_enabled']) * 100, 1);
        } else {
            $stats['success_rate'] = 0;
        }
        
        // Add last update time
        $stats['last_updated'] = current_time('timestamp');
        
        return $stats;
    }
    
    /**
     * Calculate total size of static files
     *
     * @return int Total size in bytes
     */
    private static function calculate_total_size() {
        global $wpdb;
        
        // Get from cached meta if available
        $cache_key = self::$cache_prefix . 'total_size';
        $cached_size = get_transient($cache_key);
        
        if ($cached_size !== false) {
            return $cached_size;
        }
        
        // Calculate from database
        $total_size = $wpdb->get_var("
            SELECT SUM(CAST(meta_value AS UNSIGNED))
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bsp_static_file_size'
        ");
        
        $total_size = intval($total_size);
        
        // Cache for longer as file sizes don't change often
        set_transient($cache_key, $total_size, self::$default_expiry * 2);
        
        return $total_size;
    }
    
    /**
     * Get performance metrics
     *
     * @return array Performance data
     */
    private static function get_performance_metrics() {
        $cache_key = self::$cache_prefix . 'performance';
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $metrics = array(
            'avg_generation_time' => 0,
            'avg_file_size' => 0,
            'cache_hit_rate' => 0
        );
        
        // Get average generation time from recent operations
        $recent_times = get_option('bsp_recent_generation_times', array());
        if (!empty($recent_times)) {
            $metrics['avg_generation_time'] = round(array_sum($recent_times) / count($recent_times), 2);
        }
        
        // Calculate average file size
        global $wpdb;
        $avg_size = $wpdb->get_var("
            SELECT AVG(CAST(meta_value AS UNSIGNED))
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_bsp_static_file_size'
            AND meta_value > 0
        ");
        
        if ($avg_size) {
            $metrics['avg_file_size'] = intval($avg_size);
        }
        
        // Calculate cache hit rate (if tracking)
        $cache_stats = get_option('bsp_cache_stats', array('hits' => 0, 'misses' => 0));
        if ($cache_stats['hits'] + $cache_stats['misses'] > 0) {
            $metrics['cache_hit_rate'] = round(
                ($cache_stats['hits'] / ($cache_stats['hits'] + $cache_stats['misses'])) * 100, 
                1
            );
        }
        
        set_transient($cache_key, $metrics, self::$default_expiry);
        
        return $metrics;
    }
    
    /**
     * Invalidate all stats caches
     */
    public static function invalidate() {
        // Delete all stats transients
        delete_transient(self::$cache_prefix . 'main');
        delete_transient(self::$cache_prefix . 'total_size');
        delete_transient(self::$cache_prefix . 'performance');
        delete_transient(self::$cache_prefix . 'detailed');
        
        // Trigger recalculation
        do_action('bsp_stats_cache_invalidated');
    }
    
    /**
     * Invalidate specific cache
     *
     * @param string $cache_name Cache to invalidate
     */
    public static function invalidate_specific($cache_name) {
        delete_transient(self::$cache_prefix . $cache_name);
    }
    
    /**
     * Get stats for a specific page
     *
     * @param int $post_id Post ID
     * @return array Page statistics
     */
    public static function get_page_stats($post_id) {
        $cache_key = self::$cache_prefix . 'page_' . $post_id;
        
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $stats = array(
            'enabled' => get_post_meta($post_id, '_bsp_static_enabled', true) === '1',
            'generated' => get_post_meta($post_id, '_bsp_static_generated', true),
            'file_size' => intval(get_post_meta($post_id, '_bsp_static_file_size', true)),
            'etag' => get_post_meta($post_id, '_bsp_static_etag', true),
            'file_exists' => file_exists(Breakdance_Static_Pages::get_static_file_path($post_id))
        );
        
        // Add human-readable values
        if ($stats['generated']) {
            $stats['generated_ago'] = human_time_diff(strtotime($stats['generated']), current_time('timestamp'));
        }
        
        if ($stats['file_size'] > 0) {
            $stats['file_size_formatted'] = size_format($stats['file_size']);
        }
        
        // Cache for shorter time as page stats change more frequently
        set_transient($cache_key, $stats, 60); // 1 minute
        
        return $stats;
    }
    
    /**
     * Update generation time tracking
     *
     * @param float $generation_time Time taken to generate
     */
    public static function track_generation_time($generation_time) {
        $times = get_option('bsp_recent_generation_times', array());
        
        // Keep last 100 generation times
        $times[] = $generation_time;
        if (count($times) > 100) {
            array_shift($times);
        }
        
        update_option('bsp_recent_generation_times', $times, false);
        
        // Invalidate performance cache
        self::invalidate_specific('performance');
    }
    
    /**
     * Track cache hit/miss
     *
     * @param bool $hit Whether it was a cache hit
     */
    public static function track_cache_access($hit = true) {
        $stats = get_option('bsp_cache_stats', array('hits' => 0, 'misses' => 0));
        
        if ($hit) {
            $stats['hits']++;
        } else {
            $stats['misses']++;
        }
        
        // Reset counters if they get too large
        if ($stats['hits'] + $stats['misses'] > 10000) {
            $stats = array('hits' => 0, 'misses' => 0);
        }
        
        update_option('bsp_cache_stats', $stats, false);
    }
    
    /**
     * Get detailed stats for admin dashboard
     *
     * @return array Detailed statistics
     */
    public static function get_detailed_stats() {
        $cache_key = self::$cache_prefix . 'detailed';
        
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        // Get basic stats first
        $stats = self::get_stats();
        
        // Add more detailed information
        global $wpdb;
        
        // Pages by status
        $stats['by_status'] = $wpdb->get_results("
            SELECT 
                CASE 
                    WHEN pm1.meta_value = '1' AND pm2.post_id IS NOT NULL THEN 'generated'
                    WHEN pm1.meta_value = '1' AND pm2.post_id IS NULL THEN 'enabled_not_generated'
                    ELSE 'not_enabled'
                END as status,
                COUNT(*) as count
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_bsp_static_enabled'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bsp_static_generated'
            WHERE p.post_type IN ('page', 'post') 
            AND p.post_status = 'publish'
            GROUP BY status
        ", ARRAY_A);
        
        // Recent activity
        $stats['recent_generated'] = $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_value as generated_time
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_bsp_static_generated'
            ORDER BY pm.meta_value DESC
            LIMIT 10
        ");
        
        // Cache for standard duration
        set_transient($cache_key, $stats, self::$default_expiry);
        
        return $stats;
    }
}
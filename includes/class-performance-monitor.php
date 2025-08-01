<?php
/**
 * Performance Monitor Class
 * Tracks and reports performance metrics for static pages
 */

if (!defined('ABSPATH')) {
    exit;
}

class BSP_Performance_Monitor {
    
    public function __construct() {
        add_action('wp_footer', array($this, 'track_page_load'), 999);
        add_action('bsp_static_page_generated', array($this, 'track_generation'), 10, 2);
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
    }
    
    /**
     * Track page load performance
     */
    public function track_page_load() {
        // Only track on frontend singular pages
        if (is_admin() || !is_singular()) {
            return;
        }
        
        global $post;
        
        if (!$post) {
            return;
        }
        
        $static_enabled = get_post_meta($post->ID, '_bsp_static_enabled', true);
        
        if (!$static_enabled) {
            return;
        }
        
        // Check if this was served statically
        $served_statically = headers_sent() ? false : $this->was_served_statically();
        
        // Track the performance data
        $this->record_page_view($post->ID, $served_statically);
        
        // Add performance tracking script for admin users
        if (current_user_can('manage_options')) {
            $this->add_performance_tracking_script($post->ID, $served_statically);
        }
    }
    
    /**
     * Check if page was served statically
     */
    private function was_served_statically() {
        $headers = headers_list();
        
        foreach ($headers as $header) {
            if (strpos($header, 'X-BSP-Static-Served: true') !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Record page view for performance tracking
     */
    private function record_page_view($post_id, $served_statically) {
        $today = date('Y-m-d');
        $option_key = 'bsp_performance_' . $today;
        
        $data = get_option($option_key, array());
        
        if (!isset($data[$post_id])) {
            $data[$post_id] = array(
                'static_views' => 0,
                'dynamic_views' => 0,
                'total_views' => 0
            );
        }
        
        if ($served_statically) {
            $data[$post_id]['static_views']++;
        } else {
            $data[$post_id]['dynamic_views']++;
        }
        
        $data[$post_id]['total_views']++;
        
        update_option($option_key, $data);
    }
    
    /**
     * Add performance tracking script
     */
    private function add_performance_tracking_script($post_id, $served_statically) {
        ?>
        <script>
        (function() {
            if (typeof performance !== 'undefined' && performance.timing) {
                var timing = performance.timing;
                var loadTime = timing.loadEventEnd - timing.navigationStart;
                var domReady = timing.domContentLoadedEventEnd - timing.navigationStart;
                
                // Send performance data via AJAX
                if (typeof jQuery !== 'undefined') {
                    jQuery(document).ready(function($) {
                        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'bsp_track_performance',
                            post_id: <?php echo $post_id; ?>,
                            served_statically: <?php echo $served_statically ? 'true' : 'false'; ?>,
                            load_time: loadTime,
                            dom_ready: domReady,
                            nonce: '<?php echo wp_create_nonce('bsp_performance_nonce'); ?>'
                        });
                    });
                }
            }
        })();
        </script>
        <?php
    }
    
    /**
     * Track static page generation performance
     */
    public function track_generation($post_id, $static_file_path) {
        $generation_time = microtime(true) - (defined('BSP_GENERATION_START') ? BSP_GENERATION_START : microtime(true));
        $file_size = file_exists($static_file_path) ? filesize($static_file_path) : 0;
        
        $today = date('Y-m-d');
        $option_key = 'bsp_generation_stats_' . $today;
        
        $data = get_option($option_key, array(
            'total_generations' => 0,
            'total_time' => 0,
            'total_size' => 0,
            'average_time' => 0,
            'average_size' => 0
        ));
        
        $data['total_generations']++;
        $data['total_time'] += $generation_time;
        $data['total_size'] += $file_size;
        $data['average_time'] = $data['total_time'] / $data['total_generations'];
        $data['average_size'] = $data['total_size'] / $data['total_generations'];
        
        update_option($option_key, $data);
    }
    
    /**
     * Get performance statistics
     */
    public function get_performance_stats($days = 7) {
        $stats = array(
            'total_views' => 0,
            'static_views' => 0,
            'dynamic_views' => 0,
            'static_percentage' => 0,
            'average_load_time_static' => 0,
            'average_load_time_dynamic' => 0,
            'performance_improvement' => 0,
            'daily_stats' => array()
        );
        
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $daily_data = get_option('bsp_performance_' . $date, array());
            
            $daily_stats = array(
                'date' => $date,
                'static_views' => 0,
                'dynamic_views' => 0,
                'total_views' => 0
            );
            
            foreach ($daily_data as $post_data) {
                $daily_stats['static_views'] += $post_data['static_views'];
                $daily_stats['dynamic_views'] += $post_data['dynamic_views'];
                $daily_stats['total_views'] += $post_data['total_views'];
            }
            
            $stats['daily_stats'][] = $daily_stats;
            $stats['total_views'] += $daily_stats['total_views'];
            $stats['static_views'] += $daily_stats['static_views'];
            $stats['dynamic_views'] += $daily_stats['dynamic_views'];
        }
        
        if ($stats['total_views'] > 0) {
            $stats['static_percentage'] = round(($stats['static_views'] / $stats['total_views']) * 100, 2);
        }
        
        return $stats;
    }
    
    /**
     * Get generation statistics
     */
    public function get_generation_stats($days = 7) {
        $stats = array(
            'total_generations' => 0,
            'total_time' => 0,
            'total_size' => 0,
            'average_time' => 0,
            'average_size' => 0,
            'daily_stats' => array()
        );
        
        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $daily_data = get_option('bsp_generation_stats_' . $date, array());
            
            if (!empty($daily_data)) {
                $stats['daily_stats'][] = array_merge($daily_data, array('date' => $date));
                $stats['total_generations'] += $daily_data['total_generations'];
                $stats['total_time'] += $daily_data['total_time'];
                $stats['total_size'] += $daily_data['total_size'];
            }
        }
        
        if ($stats['total_generations'] > 0) {
            $stats['average_time'] = $stats['total_time'] / $stats['total_generations'];
            $stats['average_size'] = $stats['total_size'] / $stats['total_generations'];
        }
        
        return $stats;
    }
    
    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        // Check if dashboard widget is disabled
        if (get_option('bsp_disable_dashboard_widget', false)) {
            return;
        }
        
        if (current_user_can('manage_options')) {
            wp_add_dashboard_widget(
                'bsp_performance_widget',
                'Static Pages Performance',
                array($this, 'dashboard_widget_content')
            );
        }
    }
    
    /**
     * Dashboard widget content
     */
    public function dashboard_widget_content() {
        // Get main stats instead of performance stats
        $stats = BSP_Stats_Cache::get_stats();
        
        ?>
        <div class="bsp-dashboard-widget" id="bsp_performance_widget">
            <div class="bsp-widget-stats">
                <div class="bsp-stat-item">
                    <span class="bsp-stat-number bsp-stat-enabled"><?php echo number_format($stats['static_enabled']); ?></span>
                    <span class="bsp-stat-label">Static Enabled</span>
                </div>
                <div class="bsp-stat-item">
                    <span class="bsp-stat-number bsp-stat-generated"><?php echo number_format($stats['static_generated']); ?></span>
                    <span class="bsp-stat-label">Files Generated</span>
                </div>
                <div class="bsp-stat-item">
                    <span class="bsp-stat-number bsp-stat-size"><?php echo size_format($stats['total_size']); ?></span>
                    <span class="bsp-stat-label">Total Size</span>
                </div>
            </div>
            
            <?php if ($stats['performance']['avg_generation_time'] > 0): ?>
                <div class="bsp-widget-details">
                    <p><strong>Average Generation Time:</strong> <?php echo round($stats['performance']['avg_generation_time'], 2); ?>s</p>
                    <p><strong>Success Rate:</strong> <span class="bsp-stat-success-rate"><?php echo $stats['success_rate']; ?>%</span></p>
                </div>
            <?php endif; ?>
            
            <div class="bsp-widget-actions">
                <a href="<?php echo admin_url('tools.php?page=breakdance-static-pages'); ?>" class="button button-primary">
                    Manage Static Pages
                </a>
            </div>
        </div>
        
        <style>
        .bsp-dashboard-widget .bsp-widget-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
        }
        .bsp-stat-item {
            text-align: center;
            flex: 1;
        }
        .bsp-stat-number {
            display: block;
            font-size: 24px;
            font-weight: bold;
            color: #0073aa;
        }
        .bsp-stat-label {
            display: block;
            font-size: 12px;
            color: #666;
        }
        .bsp-widget-details {
            margin-bottom: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        .bsp-widget-details p {
            margin: 5px 0;
            font-size: 13px;
        }
        </style>
        <?php
    }
    
    /**
     * Clean up old performance data
     */
    public function cleanup_old_performance_data($days = 30) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d', strtotime("-{$days} days"));
        
        // Get all performance options
        $options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} 
                 WHERE option_name LIKE 'bsp_performance_%' 
                 OR option_name LIKE 'bsp_generation_stats_%'"
            )
        );
        
        $deleted_count = 0;
        
        foreach ($options as $option) {
            // Extract date from option name
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', $option->option_name, $matches)) {
                $option_date = $matches[1];
                
                if ($option_date < $cutoff_date) {
                    delete_option($option->option_name);
                    $deleted_count++;
                }
            }
        }
        
        return $deleted_count;
    }
}

// Handle AJAX performance tracking
add_action('wp_ajax_bsp_track_performance', function() {
    if (!wp_verify_nonce($_POST['nonce'], 'bsp_performance_nonce')) {
        wp_die('Security check failed');
    }
    
    $post_id = intval($_POST['post_id']);
    $served_statically = $_POST['served_statically'] === 'true';
    $load_time = floatval($_POST['load_time']);
    $dom_ready = floatval($_POST['dom_ready']);
    
    $today = date('Y-m-d');
    $option_key = 'bsp_detailed_performance_' . $today;
    
    $data = get_option($option_key, array());
    
    if (!isset($data[$post_id])) {
        $data[$post_id] = array(
            'static_times' => array(),
            'dynamic_times' => array()
        );
    }
    
    if ($served_statically) {
        $data[$post_id]['static_times'][] = $load_time;
    } else {
        $data[$post_id]['dynamic_times'][] = $load_time;
    }
    
    update_option($option_key, $data);
    
    wp_send_json_success();
});

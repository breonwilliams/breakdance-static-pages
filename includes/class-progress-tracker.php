<?php
/**
 * Progress Tracker Class
 *
 * Tracks and reports progress for long-running operations.
 *
 * @package Breakdance_Static_Pages
 * @since 1.2.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_Progress_Tracker
 *
 * Provides real-time progress tracking for operations.
 */
class BSP_Progress_Tracker {
    
    /**
     * Singleton instance
     *
     * @var BSP_Progress_Tracker|null
     */
    private static $instance = null;
    
    /**
     * Active progress sessions
     *
     * @var array
     */
    private $sessions = array();
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('wp_ajax_bsp_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_bsp_get_all_progress', array($this, 'ajax_get_all_progress'));
        
        // Cleanup old sessions periodically
        add_action('bsp_cleanup_progress_sessions', array($this, 'cleanup_old_sessions'));
        
        if (!wp_next_scheduled('bsp_cleanup_progress_sessions')) {
            wp_schedule_event(time(), 'hourly', 'bsp_cleanup_progress_sessions');
        }
    }
    
    /**
     * Get singleton instance
     *
     * @return BSP_Progress_Tracker
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Start a new progress session
     *
     * @param string $operation Operation name
     * @param int $total Total items to process
     * @param array $metadata Additional metadata
     * @return string Session ID
     */
    public function start_progress($operation, $total, $metadata = array()) {
        $session_id = uniqid('progress_');
        
        $session_data = array(
            'id' => $session_id,
            'operation' => $operation,
            'total' => $total,
            'current' => 0,
            'percentage' => 0,
            'status' => 'running',
            'started_at' => time(),
            'updated_at' => time(),
            'completed_at' => null,
            'estimated_completion' => null,
            'current_item' => '',
            'messages' => array(),
            'errors' => array(),
            'metadata' => $metadata,
            'performance' => array(
                'items_per_second' => 0,
                'average_item_time' => 0,
                'elapsed_time' => 0,
                'remaining_time' => 0
            )
        );
        
        // Store in transient
        set_transient('bsp_progress_' . $session_id, $session_data, HOUR_IN_SECONDS);
        
        // Log session start
        BSP_Error_Handler::get_instance()->log_error(
            'progress_tracker',
            sprintf('Started progress session %s for %s with %d items', 
                $session_id, $operation, $total),
            'info',
            array('session_data' => $session_data)
        );
        
        return $session_id;
    }
    
    /**
     * Update progress
     *
     * @param string $session_id Session ID
     * @param int $current Current progress
     * @param string $current_item Current item being processed
     * @param string $message Optional message
     * @return bool Success
     */
    public function update_progress($session_id, $current, $current_item = '', $message = '') {
        $session_data = get_transient('bsp_progress_' . $session_id);
        
        if (!$session_data) {
            return false;
        }
        
        $now = time();
        $elapsed = $now - $session_data['started_at'];
        
        // Update basic progress
        $session_data['current'] = $current;
        $session_data['percentage'] = $session_data['total'] > 0 ? 
            round(($current / $session_data['total']) * 100, 2) : 0;
        $session_data['updated_at'] = $now;
        $session_data['current_item'] = $current_item;
        
        // Add message if provided
        if (!empty($message)) {
            $session_data['messages'][] = array(
                'time' => $now,
                'message' => $message
            );
            
            // Keep only last 50 messages
            if (count($session_data['messages']) > 50) {
                array_shift($session_data['messages']);
            }
        }
        
        // Calculate performance metrics
        if ($current > 0 && $elapsed > 0) {
            $items_per_second = $current / $elapsed;
            $average_item_time = $elapsed / $current;
            $remaining_items = $session_data['total'] - $current;
            $remaining_time = $remaining_items * $average_item_time;
            
            $session_data['performance'] = array(
                'items_per_second' => round($items_per_second, 2),
                'average_item_time' => round($average_item_time, 2),
                'elapsed_time' => $elapsed,
                'remaining_time' => round($remaining_time)
            );
            
            $session_data['estimated_completion'] = $now + $remaining_time;
        }
        
        // Check if completed
        if ($current >= $session_data['total']) {
            $session_data['status'] = 'completed';
            $session_data['completed_at'] = $now;
            $session_data['percentage'] = 100;
        }
        
        // Update transient
        set_transient('bsp_progress_' . $session_id, $session_data, HOUR_IN_SECONDS);
        
        return true;
    }
    
    /**
     * Add error to progress session
     *
     * @param string $session_id Session ID
     * @param string $error Error message
     * @param array $context Error context
     * @return bool Success
     */
    public function add_error($session_id, $error, $context = array()) {
        $session_data = get_transient('bsp_progress_' . $session_id);
        
        if (!$session_data) {
            return false;
        }
        
        $session_data['errors'][] = array(
            'time' => time(),
            'message' => $error,
            'context' => $context
        );
        
        // Keep only last 100 errors
        if (count($session_data['errors']) > 100) {
            array_shift($session_data['errors']);
        }
        
        set_transient('bsp_progress_' . $session_id, $session_data, HOUR_IN_SECONDS);
        
        return true;
    }
    
    /**
     * Complete progress session
     *
     * @param string $session_id Session ID
     * @param string $status Final status (completed, failed, cancelled)
     * @param string $message Final message
     * @return bool Success
     */
    public function complete_progress($session_id, $status = 'completed', $message = '') {
        $session_data = get_transient('bsp_progress_' . $session_id);
        
        if (!$session_data) {
            return false;
        }
        
        $session_data['status'] = $status;
        $session_data['completed_at'] = time();
        
        if (!empty($message)) {
            $session_data['messages'][] = array(
                'time' => time(),
                'message' => $message
            );
        }
        
        // Calculate final metrics
        $total_time = $session_data['completed_at'] - $session_data['started_at'];
        $session_data['performance']['total_time'] = $total_time;
        
        // Log completion
        BSP_Error_Handler::get_instance()->log_error(
            'progress_tracker',
            sprintf('Completed progress session %s with status %s', $session_id, $status),
            'info',
            array(
                'session_id' => $session_id,
                'total_items' => $session_data['total'],
                'processed_items' => $session_data['current'],
                'total_time' => $total_time,
                'errors' => count($session_data['errors'])
            )
        );
        
        // Keep completed sessions for 1 hour
        set_transient('bsp_progress_' . $session_id, $session_data, HOUR_IN_SECONDS);
        
        return true;
    }
    
    /**
     * Get progress data
     *
     * @param string $session_id Session ID
     * @return array|false Progress data or false
     */
    public function get_progress($session_id) {
        $session_data = get_transient('bsp_progress_' . $session_id);
        
        if (!$session_data) {
            return false;
        }
        
        // Add human-readable times
        $session_data['started_at_human'] = human_time_diff($session_data['started_at']);
        
        if ($session_data['estimated_completion'] && $session_data['status'] === 'running') {
            $session_data['estimated_completion_human'] = human_time_diff(
                time(), 
                $session_data['estimated_completion']
            );
        }
        
        if ($session_data['completed_at']) {
            $session_data['duration_human'] = human_time_diff(
                $session_data['started_at'],
                $session_data['completed_at']
            );
        }
        
        return $session_data;
    }
    
    /**
     * Get all active progress sessions
     *
     * @return array Active sessions
     */
    public function get_all_active_sessions() {
        global $wpdb;
        
        $sessions = array();
        
        // Get all progress transients
        $transients = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_bsp_progress_%'"
        );
        
        foreach ($transients as $transient) {
            $session_id = str_replace('_transient_bsp_progress_', '', $transient);
            $session_data = $this->get_progress($session_id);
            
            if ($session_data && $session_data['status'] === 'running') {
                $sessions[] = $session_data;
            }
        }
        
        return $sessions;
    }
    
    /**
     * Cancel progress session
     *
     * @param string $session_id Session ID
     * @return bool Success
     */
    public function cancel_progress($session_id) {
        return $this->complete_progress($session_id, 'cancelled', 'Operation cancelled by user');
    }
    
    /**
     * AJAX handler to get progress
     */
    public function ajax_get_progress() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        
        if (empty($session_id)) {
            wp_send_json_error('No session ID provided');
        }
        
        $progress = $this->get_progress($session_id);
        
        if ($progress) {
            wp_send_json_success($progress);
        } else {
            wp_send_json_error('Session not found');
        }
    }
    
    /**
     * AJAX handler to get all active sessions
     */
    public function ajax_get_all_progress() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $sessions = $this->get_all_active_sessions();
        
        wp_send_json_success(array(
            'sessions' => $sessions,
            'count' => count($sessions)
        ));
    }
    
    /**
     * Clean up old progress sessions
     */
    public function cleanup_old_sessions() {
        global $wpdb;
        
        $cleaned = 0;
        
        // Get all progress transients
        $transients = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_bsp_progress_%'"
        );
        
        foreach ($transients as $transient) {
            $session_data = maybe_unserialize($transient->option_value);
            
            if (is_array($session_data)) {
                // Remove if older than 24 hours
                if (isset($session_data['started_at']) && 
                    (time() - $session_data['started_at']) > DAY_IN_SECONDS) {
                    delete_transient(str_replace('_transient_', '', $transient->option_name));
                    $cleaned++;
                }
                
                // Remove if completed and older than 2 hours
                if (isset($session_data['status']) && 
                    $session_data['status'] !== 'running' &&
                    isset($session_data['completed_at']) &&
                    (time() - $session_data['completed_at']) > 2 * HOUR_IN_SECONDS) {
                    delete_transient(str_replace('_transient_', '', $transient->option_name));
                    $cleaned++;
                }
            }
        }
        
        if ($cleaned > 0) {
            BSP_Error_Handler::get_instance()->log_error(
                'progress_tracker',
                sprintf('Cleaned up %d old progress sessions', $cleaned),
                'info'
            );
        }
        
        return $cleaned;
    }
    
    /**
     * Create progress bar HTML
     *
     * @param array $progress Progress data
     * @return string HTML
     */
    public static function render_progress_bar($progress) {
        if (!$progress) {
            return '';
        }
        
        $percentage = isset($progress['percentage']) ? $progress['percentage'] : 0;
        $status_class = 'progress-bar-info';
        
        if ($progress['status'] === 'completed') {
            $status_class = 'progress-bar-success';
        } elseif ($progress['status'] === 'failed') {
            $status_class = 'progress-bar-danger';
        } elseif ($progress['status'] === 'cancelled') {
            $status_class = 'progress-bar-warning';
        }
        
        ob_start();
        ?>
        <div class="bsp-progress-container" data-session-id="<?php echo esc_attr($progress['id']); ?>">
            <div class="progress-header">
                <span class="progress-title"><?php echo esc_html($progress['operation']); ?></span>
                <span class="progress-percentage"><?php echo esc_html($percentage); ?>%</span>
            </div>
            
            <div class="progress">
                <div class="progress-bar <?php echo esc_attr($status_class); ?>" 
                     role="progressbar" 
                     aria-valuenow="<?php echo esc_attr($percentage); ?>" 
                     aria-valuemin="0" 
                     aria-valuemax="100" 
                     style="width: <?php echo esc_attr($percentage); ?>%">
                </div>
            </div>
            
            <div class="progress-details">
                <span class="progress-current">
                    <?php printf(
                        __('%d of %d items', 'breakdance-static-pages'),
                        $progress['current'],
                        $progress['total']
                    ); ?>
                </span>
                
                <?php if ($progress['status'] === 'running' && isset($progress['estimated_completion_human'])): ?>
                    <span class="progress-eta">
                        <?php printf(
                            __('About %s remaining', 'breakdance-static-pages'),
                            $progress['estimated_completion_human']
                        ); ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($progress['current_item'])): ?>
                <div class="progress-current-item">
                    <?php echo esc_html($progress['current_item']); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        
        return ob_get_clean();
    }
}
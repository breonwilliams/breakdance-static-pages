<?php
/**
 * Queue Manager Class
 *
 * Handles background processing of static page generation tasks.
 *
 * @package Breakdance_Static_Pages
 * @since 1.2.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_Queue_Manager
 *
 * Manages a queue system for processing static page generation in the background.
 */
class BSP_Queue_Manager {
    
    /**
     * Singleton instance
     *
     * @var BSP_Queue_Manager|null
     */
    private static $instance = null;
    
    /**
     * Queue table name
     *
     * @var string
     */
    private $table_name;
    
    /**
     * Maximum items to process per batch
     *
     * @var int
     */
    private $batch_size = 5;
    
    /**
     * Maximum execution time for a batch (seconds)
     *
     * @var int
     */
    private $time_limit = 20;
    
    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'bsp_queue';
        
        // Initialize hooks
        add_action('init', array($this, 'init'));
        add_action('bsp_process_queue', array($this, 'process_queue'));
        add_action('bsp_queue_cleanup', array($this, 'cleanup_old_items'));
    }
    
    /**
     * Get singleton instance
     *
     * @return BSP_Queue_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize queue manager
     */
    public function init() {
        // Schedule queue processing
        if (!wp_next_scheduled('bsp_process_queue')) {
            wp_schedule_event(time(), 'bsp_queue_interval', 'bsp_process_queue');
        }
        
        // Schedule cleanup
        if (!wp_next_scheduled('bsp_queue_cleanup')) {
            wp_schedule_event(time(), 'daily', 'bsp_queue_cleanup');
        }
        
        // Add custom cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
    }
    
    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['bsp_queue_interval'] = array(
            'interval' => 60, // 1 minute
            'display' => __('Every Minute (BSP Queue)', 'breakdance-static-pages')
        );
        return $schedules;
    }
    
    /**
     * Create queue table
     */
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            item_id bigint(20) NOT NULL,
            item_type varchar(50) NOT NULL DEFAULT 'post',
            action varchar(50) NOT NULL DEFAULT 'generate',
            priority int(11) NOT NULL DEFAULT 10,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int(11) NOT NULL DEFAULT 0,
            max_attempts int(11) NOT NULL DEFAULT 3,
            data longtext,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            error_message text,
            PRIMARY KEY (id),
            KEY item_id (item_id),
            KEY status (status),
            KEY priority_status (priority, status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Store db version
        update_option('bsp_queue_db_version', '1.0');
    }
    
    /**
     * Add item to queue
     *
     * @param int $item_id Item ID (usually post ID)
     * @param string $action Action to perform
     * @param array $args Additional arguments
     * @return int|false Queue item ID or false on failure
     */
    public function add_to_queue($item_id, $action = 'generate', $args = array()) {
        global $wpdb;
        
        $defaults = array(
            'item_type' => 'post',
            'priority' => 10,
            'max_attempts' => 3,
            'data' => array()
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Check if item already exists in queue
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} 
             WHERE item_id = %d 
             AND action = %s 
             AND status IN ('pending', 'processing')",
            $item_id,
            $action
        ));
        
        if ($existing) {
            // Update priority if needed
            if ($args['priority'] < 10) {
                $wpdb->update(
                    $this->table_name,
                    array('priority' => $args['priority']),
                    array('id' => $existing),
                    array('%d'),
                    array('%d')
                );
            }
            return $existing;
        }
        
        // Insert new queue item
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'item_id' => $item_id,
                'item_type' => $args['item_type'],
                'action' => $action,
                'priority' => $args['priority'],
                'status' => 'pending',
                'max_attempts' => $args['max_attempts'],
                'data' => json_encode($args['data'])
            ),
            array('%d', '%s', '%s', '%d', '%s', '%d', '%s')
        );
        
        if ($result) {
            $queue_id = $wpdb->insert_id;
            
            // Log the addition
            BSP_Error_Handler::get_instance()->log_error(
                'queue_manager',
                sprintf('Added item %d to queue (action: %s)', $item_id, $action),
                'info',
                array('queue_id' => $queue_id, 'args' => $args)
            );
            
            // Trigger immediate processing if high priority
            if ($args['priority'] <= 5) {
                wp_schedule_single_event(time(), 'bsp_process_queue');
            }
            
            return $queue_id;
        }
        
        return false;
    }
    
    /**
     * Add multiple items to queue
     *
     * @param array $items Array of items to add
     * @param string $action Action to perform
     * @param array $args Additional arguments
     * @return array Results
     */
    public function bulk_add_to_queue($items, $action = 'generate', $args = array()) {
        $results = array(
            'added' => 0,
            'skipped' => 0,
            'failed' => 0
        );
        
        foreach ($items as $item_id) {
            $result = $this->add_to_queue($item_id, $action, $args);
            
            if ($result) {
                $results['added']++;
            } else {
                $results['failed']++;
            }
        }
        
        return $results;
    }
    
    /**
     * Process queue
     */
    public function process_queue() {
        global $wpdb;
        
        // Check if already processing
        if (get_transient('bsp_queue_processing')) {
            return;
        }
        
        // Set processing flag
        set_transient('bsp_queue_processing', true, $this->time_limit);
        
        $start_time = time();
        $processed = 0;
        $error_handler = BSP_Error_Handler::get_instance();
        
        try {
            // Get pending items
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name} 
                 WHERE status = 'pending' 
                 AND attempts < max_attempts
                 ORDER BY priority ASC, created_at ASC 
                 LIMIT %d",
                $this->batch_size
            ));
            
            foreach ($items as $item) {
                // Check time limit
                if ((time() - $start_time) >= $this->time_limit) {
                    break;
                }
                
                // Mark as processing
                $wpdb->update(
                    $this->table_name,
                    array(
                        'status' => 'processing',
                        'started_at' => current_time('mysql'),
                        'attempts' => $item->attempts + 1
                    ),
                    array('id' => $item->id),
                    array('%s', '%s', '%d'),
                    array('%d')
                );
                
                // Process the item
                $result = $this->process_item($item);
                
                if ($result['success']) {
                    // Mark as completed
                    $wpdb->update(
                        $this->table_name,
                        array(
                            'status' => 'completed',
                            'completed_at' => current_time('mysql')
                        ),
                        array('id' => $item->id),
                        array('%s', '%s'),
                        array('%d')
                    );
                    
                    $processed++;
                } else {
                    // Check if we should retry
                    if ($item->attempts + 1 >= $item->max_attempts) {
                        // Mark as failed
                        $wpdb->update(
                            $this->table_name,
                            array(
                                'status' => 'failed',
                                'error_message' => $result['error']
                            ),
                            array('id' => $item->id),
                            array('%s', '%s'),
                            array('%d')
                        );
                        
                        $error_handler->log_error(
                            'queue_manager',
                            sprintf('Queue item %d failed after %d attempts: %s', 
                                $item->id, $item->attempts + 1, $result['error']),
                            'error',
                            array('item' => $item)
                        );
                    } else {
                        // Mark as pending for retry
                        $wpdb->update(
                            $this->table_name,
                            array(
                                'status' => 'pending',
                                'error_message' => $result['error']
                            ),
                            array('id' => $item->id),
                            array('%s', '%s'),
                            array('%d')
                        );
                    }
                }
            }
            
            if ($processed > 0) {
                $error_handler->log_error(
                    'queue_manager',
                    sprintf('Processed %d queue items in %d seconds', 
                        $processed, time() - $start_time),
                    'info'
                );
            }
            
        } catch (Exception $e) {
            $error_handler->log_error(
                'queue_manager',
                'Queue processing error: ' . $e->getMessage(),
                'critical',
                array(),
                $e
            );
        } finally {
            // Clear processing flag
            delete_transient('bsp_queue_processing');
        }
    }
    
    /**
     * Process a single queue item
     *
     * @param object $item Queue item
     * @return array Result
     */
    private function process_item($item) {
        $data = json_decode($item->data, true);
        
        try {
            switch ($item->action) {
                case 'generate':
                    $result = BSP_Atomic_Operations::generate_with_rollback($item->item_id);
                    break;
                    
                case 'regenerate':
                    // Delete first, then generate
                    BSP_Atomic_Operations::delete_with_rollback($item->item_id);
                    $result = BSP_Atomic_Operations::generate_with_rollback($item->item_id);
                    break;
                    
                case 'delete':
                    $result = BSP_Atomic_Operations::delete_with_rollback($item->item_id);
                    break;
                    
                default:
                    // Allow custom actions via filter
                    $result = apply_filters(
                        'bsp_queue_process_custom_action', 
                        array('success' => false, 'error' => 'Unknown action'),
                        $item->action,
                        $item->item_id,
                        $data
                    );
                    break;
            }
            
            return $result;
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Get queue status
     *
     * @return array Queue statistics
     */
    public function get_queue_status() {
        global $wpdb;
        
        $status = array(
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'next_run' => wp_next_scheduled('bsp_process_queue')
        );
        
        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count 
             FROM {$this->table_name} 
             GROUP BY status"
        );
        
        foreach ($results as $row) {
            $status[$row->status] = intval($row->count);
            $status['total'] += intval($row->count);
        }
        
        return $status;
    }
    
    /**
     * Get queue items
     *
     * @param array $args Query arguments
     * @return array Queue items
     */
    public function get_queue_items($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'status' => '',
            'limit' => 50,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array('1=1');
        
        if (!empty($args['status'])) {
            $where[] = $wpdb->prepare('status = %s', $args['status']);
        }
        
        $where_clause = implode(' AND ', $where);
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE {$where_clause}
             ORDER BY {$args['orderby']} {$args['order']}
             LIMIT %d OFFSET %d",
            $args['limit'],
            $args['offset']
        ));
    }
    
    /**
     * Clear queue
     *
     * @param string $status Status to clear (empty for all)
     * @return int Number of items cleared
     */
    public function clear_queue($status = '') {
        global $wpdb;
        
        if (empty($status)) {
            return $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        } else {
            return $wpdb->delete(
                $this->table_name,
                array('status' => $status),
                array('%s')
            );
        }
    }
    
    /**
     * Cleanup old completed/failed items
     */
    public function cleanup_old_items() {
        global $wpdb;
        
        // Keep items for 7 days
        $days_to_keep = apply_filters('bsp_queue_retention_days', 7);
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE status IN ('completed', 'failed') 
             AND created_at < %s",
            $cutoff_date
        ));
        
        if ($deleted > 0) {
            BSP_Error_Handler::get_instance()->log_error(
                'queue_manager',
                sprintf('Cleaned up %d old queue items', $deleted),
                'info'
            );
        }
    }
    
    /**
     * Retry failed items
     *
     * @return int Number of items retried
     */
    public function retry_failed_items() {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            array(
                'status' => 'pending',
                'attempts' => 0,
                'error_message' => null
            ),
            array('status' => 'failed'),
            array('%s', '%d', '%s'),
            array('%s')
        );
    }
    
    /**
     * Get processing statistics
     *
     * @return array Processing stats
     */
    public function get_processing_stats() {
        global $wpdb;
        
        // Get average processing time
        $avg_time = $wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) 
             FROM {$this->table_name} 
             WHERE status = 'completed' 
             AND started_at IS NOT NULL 
             AND completed_at IS NOT NULL"
        );
        
        // Get success rate
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE status IN ('completed', 'failed')"
        );
        
        $successful = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE status = 'completed'"
        );
        
        $success_rate = $total > 0 ? ($successful / $total) * 100 : 0;
        
        return array(
            'avg_processing_time' => round($avg_time, 2),
            'success_rate' => round($success_rate, 2),
            'total_processed' => $total,
            'successful' => $successful
        );
    }
}
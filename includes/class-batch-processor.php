<?php
/**
 * Batch Processor Class
 *
 * Handles efficient batch processing of multiple operations.
 *
 * @package Breakdance_Static_Pages
 * @since 1.2.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_Batch_Processor
 *
 * Processes large batches of operations efficiently with progress tracking.
 */
class BSP_Batch_Processor {
    
    /**
     * Singleton instance
     *
     * @var BSP_Batch_Processor|null
     */
    private static $instance = null;
    
    /**
     * Current batch ID
     *
     * @var string
     */
    private $batch_id;
    
    /**
     * Batch data storage
     *
     * @var array
     */
    private $batch_data = array();
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('wp_ajax_bsp_start_batch', array($this, 'ajax_start_batch'));
        add_action('wp_ajax_bsp_process_batch_chunk', array($this, 'ajax_process_chunk'));
        add_action('wp_ajax_bsp_get_batch_status', array($this, 'ajax_get_status'));
        add_action('wp_ajax_bsp_cancel_batch', array($this, 'ajax_cancel_batch'));
    }
    
    /**
     * Get singleton instance
     *
     * @return BSP_Batch_Processor
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Start a new batch process
     *
     * @param array $items Items to process
     * @param string $operation Operation to perform
     * @param array $args Additional arguments
     * @return string Batch ID
     */
    public function start_batch($items, $operation, $args = array()) {
        $batch_id = uniqid('batch_');
        
        $batch_data = array(
            'id' => $batch_id,
            'operation' => $operation,
            'items' => $items,
            'total' => count($items),
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => array(),
            'status' => 'pending',
            'started_at' => current_time('mysql'),
            'completed_at' => null,
            'chunk_size' => isset($args['chunk_size']) ? $args['chunk_size'] : intval(get_option('bsp_batch_size', 3)),
            'current_chunk' => 0,
            'args' => $args
        );
        
        // Store batch data
        set_transient('bsp_batch_' . $batch_id, $batch_data, HOUR_IN_SECONDS);
        
        // Log batch start
        BSP_Error_Handler::get_instance()->log_error(
            'batch_processor',
            sprintf('Started batch %s for %s operation with %d items', 
                $batch_id, $operation, count($items)),
            'info',
            array('batch_data' => $batch_data)
        );
        
        return $batch_id;
    }
    
    /**
     * Process a chunk of the batch
     *
     * @param string $batch_id Batch ID
     * @return array Processing result
     */
    public function process_chunk($batch_id) {
        $batch_data = get_transient('bsp_batch_' . $batch_id);
        
        if (!$batch_data) {
            return array(
                'success' => false,
                'error' => 'Batch not found'
            );
        }
        
        if ($batch_data['status'] === 'cancelled') {
            return array(
                'success' => false,
                'error' => 'Batch was cancelled'
            );
        }
        
        // Update status to processing
        if ($batch_data['status'] === 'pending') {
            $batch_data['status'] = 'processing';
        }
        
        // Calculate chunk boundaries
        $chunk_size = $batch_data['chunk_size'];
        $start_index = $batch_data['current_chunk'] * $chunk_size;
        $end_index = min($start_index + $chunk_size, $batch_data['total']);
        
        // Get items for this chunk
        $chunk_items = array_slice($batch_data['items'], $start_index, $chunk_size);
        
        $chunk_results = array(
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        // Process each item in the chunk
        foreach ($chunk_items as $index => $item) {
            $item_index = $start_index + $index;
            
            try {
                $result = $this->process_item($item, $batch_data['operation'], $batch_data['args']);
                
                if ($result['success']) {
                    $chunk_results['successful']++;
                } else {
                    $chunk_results['failed']++;
                    $chunk_results['errors'][$item_index] = $result['error'];
                }
                
                $chunk_results['processed']++;
                
            } catch (Exception $e) {
                $chunk_results['failed']++;
                $chunk_results['errors'][$item_index] = $e->getMessage();
                $chunk_results['processed']++;
                
                BSP_Error_Handler::get_instance()->log_error(
                    'batch_processor',
                    sprintf('Error processing item %d in batch %s: %s', 
                        $item_index, $batch_id, $e->getMessage()),
                    'error',
                    array('item' => $item, 'batch_id' => $batch_id),
                    $e
                );
            }
        }
        
        // Update batch data
        $batch_data['processed'] += $chunk_results['processed'];
        $batch_data['successful'] += $chunk_results['successful'];
        $batch_data['failed'] += $chunk_results['failed'];
        $batch_data['errors'] = array_merge($batch_data['errors'], $chunk_results['errors']);
        $batch_data['current_chunk']++;
        
        // Check if batch is complete
        if ($batch_data['processed'] >= $batch_data['total']) {
            $batch_data['status'] = 'completed';
            $batch_data['completed_at'] = current_time('mysql');
            
            BSP_Error_Handler::get_instance()->log_error(
                'batch_processor',
                sprintf('Completed batch %s: %d successful, %d failed', 
                    $batch_id, $batch_data['successful'], $batch_data['failed']),
                'info',
                array('batch_data' => $batch_data)
            );
        }
        
        // Update transient
        set_transient('bsp_batch_' . $batch_id, $batch_data, HOUR_IN_SECONDS);
        
        return array(
            'success' => true,
            'batch_id' => $batch_id,
            'chunk' => $batch_data['current_chunk'] - 1,
            'processed' => $batch_data['processed'],
            'total' => $batch_data['total'],
            'progress' => $batch_data['total'] > 0 ? 
                round(($batch_data['processed'] / $batch_data['total']) * 100, 2) : 0,
            'status' => $batch_data['status'],
            'results' => $chunk_results
        );
    }
    
    /**
     * Process a single item
     *
     * @param mixed $item Item to process
     * @param string $operation Operation to perform
     * @param array $args Additional arguments
     * @return array Result
     */
    private function process_item($item, $operation, $args = array()) {
        switch ($operation) {
            case 'generate':
                if (is_numeric($item)) {
                    return BSP_Atomic_Operations::generate_with_rollback($item);
                } elseif (is_array($item) && isset($item['post_id'])) {
                    return BSP_Atomic_Operations::generate_with_rollback($item['post_id']);
                }
                break;
                
            case 'delete':
                if (is_numeric($item)) {
                    return BSP_Atomic_Operations::delete_with_rollback($item);
                } elseif (is_array($item) && isset($item['post_id'])) {
                    return BSP_Atomic_Operations::delete_with_rollback($item['post_id']);
                }
                break;
                
            case 'queue':
                // Add to background queue instead of processing immediately
                $queue_manager = BSP_Queue_Manager::get_instance();
                $queue_id = $queue_manager->add_to_queue(
                    $item,
                    isset($args['queue_action']) ? $args['queue_action'] : 'generate',
                    $args
                );
                return array('success' => (bool)$queue_id);
                
            default:
                // Allow custom operations via filter
                return apply_filters(
                    'bsp_batch_process_custom_operation',
                    array('success' => false, 'error' => 'Unknown operation'),
                    $operation,
                    $item,
                    $args
                );
        }
        
        return array('success' => false, 'error' => 'Invalid item format');
    }
    
    /**
     * Get batch status
     *
     * @param string $batch_id Batch ID
     * @return array|false Batch data or false
     */
    public function get_batch_status($batch_id) {
        return get_transient('bsp_batch_' . $batch_id);
    }
    
    /**
     * Cancel a batch
     *
     * @param string $batch_id Batch ID
     * @return bool Success
     */
    public function cancel_batch($batch_id) {
        $batch_data = get_transient('bsp_batch_' . $batch_id);
        
        if (!$batch_data) {
            return false;
        }
        
        $batch_data['status'] = 'cancelled';
        $batch_data['completed_at'] = current_time('mysql');
        
        set_transient('bsp_batch_' . $batch_id, $batch_data, HOUR_IN_SECONDS);
        
        BSP_Error_Handler::get_instance()->log_error(
            'batch_processor',
            sprintf('Cancelled batch %s', $batch_id),
            'info',
            array('batch_data' => $batch_data)
        );
        
        return true;
    }
    
    /**
     * AJAX handler to start batch
     */
    public function ajax_start_batch() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $items = isset($_POST['items']) ? array_map('intval', $_POST['items']) : array();
        $operation = isset($_POST['operation']) ? sanitize_text_field($_POST['operation']) : 'generate';
        
        if (empty($items)) {
            wp_send_json_error('No items provided');
        }
        
        $batch_id = $this->start_batch($items, $operation, array(
            'chunk_size' => isset($_POST['chunk_size']) ? intval($_POST['chunk_size']) : 10
        ));
        
        wp_send_json_success(array(
            'batch_id' => $batch_id,
            'total' => count($items),
            'message' => sprintf(__('Batch started with %d items', 'breakdance-static-pages'), count($items))
        ));
    }
    
    /**
     * AJAX handler to process chunk
     */
    public function ajax_process_chunk() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';
        
        if (empty($batch_id)) {
            wp_send_json_error('No batch ID provided');
        }
        
        $result = $this->process_chunk($batch_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error']);
        }
    }
    
    /**
     * AJAX handler to get batch status
     */
    public function ajax_get_status() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';
        
        if (empty($batch_id)) {
            wp_send_json_error('No batch ID provided');
        }
        
        $status = $this->get_batch_status($batch_id);
        
        if ($status) {
            wp_send_json_success($status);
        } else {
            wp_send_json_error('Batch not found');
        }
    }
    
    /**
     * AJAX handler to cancel batch
     */
    public function ajax_cancel_batch() {
        if (!wp_verify_nonce($_POST['nonce'], 'bsp_nonce') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $batch_id = isset($_POST['batch_id']) ? sanitize_text_field($_POST['batch_id']) : '';
        
        if (empty($batch_id)) {
            wp_send_json_error('No batch ID provided');
        }
        
        if ($this->cancel_batch($batch_id)) {
            wp_send_json_success(array(
                'message' => __('Batch cancelled', 'breakdance-static-pages')
            ));
        } else {
            wp_send_json_error('Failed to cancel batch');
        }
    }
    
    /**
     * Clean up old batch data
     */
    public static function cleanup_old_batches() {
        global $wpdb;
        
        // Get all batch transients older than 24 hours
        $expired_batches = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_bsp_batch_%' 
             AND option_value LIKE '%\"started_at\"%'
             AND TIMESTAMPDIFF(HOUR, 
                 STR_TO_DATE(
                     SUBSTRING_INDEX(SUBSTRING_INDEX(option_value, '\"started_at\":\"', -1), '\"', 1),
                     '%Y-%m-%d %H:%i:%s'
                 ), 
                 NOW()
             ) > 24"
        );
        
        foreach ($expired_batches as $option_name) {
            delete_transient(str_replace('_transient_', '', $option_name));
        }
        
        return count($expired_batches);
    }
}
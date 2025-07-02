<?php
/**
 * REST API Class
 *
 * Provides REST API endpoints for external integrations.
 *
 * @package Breakdance_Static_Pages
 * @since 1.2.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_REST_API
 *
 * Handles REST API endpoints for the plugin.
 */
class BSP_REST_API {
    
    /**
     * API namespace
     *
     * @var string
     */
    private $namespace = 'bsp/v1';
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Static page endpoints
        register_rest_route($this->namespace, '/pages', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_static_pages'),
                'permission_callback' => array($this, 'permissions_check'),
                'args' => array(
                    'per_page' => array(
                        'default' => 10,
                        'sanitize_callback' => 'absint',
                    ),
                    'page' => array(
                        'default' => 1,
                        'sanitize_callback' => 'absint',
                    ),
                    'status' => array(
                        'default' => 'all',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            ),
        ));
        
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_static_page'),
                'permission_callback' => array($this, 'permissions_check'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric($param);
                        }
                    ),
                ),
            ),
        ));
        
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/generate', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'generate_static_page'),
                'permission_callback' => array($this, 'permissions_check_write'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric($param);
                        }
                    ),
                    'async' => array(
                        'default' => false,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ),
                ),
            ),
        ));
        
        register_rest_route($this->namespace, '/pages/(?P<id>\d+)/delete', array(
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_static_page'),
                'permission_callback' => array($this, 'permissions_check_write'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric($param);
                        }
                    ),
                ),
            ),
        ));
        
        // Bulk operations
        register_rest_route($this->namespace, '/bulk/generate', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'bulk_generate'),
                'permission_callback' => array($this, 'permissions_check_write'),
                'args' => array(
                    'ids' => array(
                        'required' => true,
                        'validate_callback' => function($param, $request, $key) {
                            return is_array($param) && !empty($param);
                        }
                    ),
                    'async' => array(
                        'default' => true,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ),
                ),
            ),
        ));
        
        // Queue endpoints
        register_rest_route($this->namespace, '/queue', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_queue_status'),
                'permission_callback' => array($this, 'permissions_check'),
            ),
        ));
        
        register_rest_route($this->namespace, '/queue/items', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_queue_items'),
                'permission_callback' => array($this, 'permissions_check'),
                'args' => array(
                    'status' => array(
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'limit' => array(
                        'default' => 50,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            ),
        ));
        
        // Progress endpoints
        register_rest_route($this->namespace, '/progress/(?P<session_id>[a-zA-Z0-9_]+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_progress'),
                'permission_callback' => array($this, 'permissions_check'),
                'args' => array(
                    'session_id' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return preg_match('/^[a-zA-Z0-9_]+$/', $param);
                        }
                    ),
                ),
            ),
        ));
        
        // Stats endpoints
        register_rest_route($this->namespace, '/stats', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_stats'),
                'permission_callback' => array($this, 'permissions_check'),
            ),
        ));
        
        // Health check endpoint
        register_rest_route($this->namespace, '/health', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'health_check'),
                'permission_callback' => array($this, 'permissions_check'),
            ),
        ));
    }
    
    /**
     * Check read permissions
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function permissions_check($request) {
        // Check for API key in header
        $api_key = $request->get_header('X-BSP-API-Key');
        
        if ($api_key) {
            $valid_key = get_option('bsp_api_key');
            if ($api_key === $valid_key) {
                return true;
            }
        }
        
        // Fall back to capability check
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to access this endpoint.', 'breakdance-static-pages'),
                array('status' => 403)
            );
        }
        
        return true;
    }
    
    /**
     * Check write permissions
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error
     */
    public function permissions_check_write($request) {
        // Write operations require explicit permission
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to perform this action.', 'breakdance-static-pages'),
                array('status' => 403)
            );
        }
        
        return true;
    }
    
    /**
     * Get static pages
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_static_pages($request) {
        global $wpdb;
        
        $per_page = $request->get_param('per_page');
        $page = $request->get_param('page');
        $status = $request->get_param('status');
        $offset = ($page - 1) * $per_page;
        
        // Build query
        $where = array("p.post_type IN ('page', 'post')", "p.post_status = 'publish'");
        
        if ($status === 'enabled') {
            $where[] = "pm1.meta_value = '1'";
        } elseif ($status === 'generated') {
            $where[] = "pm2.meta_value IS NOT NULL";
        }
        
        $where_clause = implode(' AND ', $where);
        
        // Get pages
        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_name, p.post_modified,
                    pm1.meta_value as static_enabled,
                    pm2.meta_value as static_generated,
                    pm3.meta_value as file_size
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_bsp_static_enabled'
             LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bsp_static_generated'
             LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_bsp_static_file_size'
             WHERE {$where_clause}
             ORDER BY p.post_modified DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        // Format response
        $data = array();
        foreach ($pages as $page) {
            $data[] = array(
                'id' => $page->ID,
                'title' => $page->post_title,
                'slug' => $page->post_name,
                'modified' => $page->post_modified,
                'static_enabled' => (bool)$page->static_enabled,
                'static_generated' => $page->static_generated,
                'file_size' => $page->file_size ? intval($page->file_size) : null,
                'url' => get_permalink($page->ID),
                'static_url' => $page->static_generated ? 
                    Breakdance_Static_Pages::get_static_file_url($page->ID) : null
            );
        }
        
        // Get total count
        $total = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_bsp_static_enabled'
             LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bsp_static_generated'
             WHERE {$where_clause}"
        );
        
        $response = rest_ensure_response($data);
        $response->header('X-WP-Total', $total);
        $response->header('X-WP-TotalPages', ceil($total / $per_page));
        
        return $response;
    }
    
    /**
     * Get single static page info
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_static_page($request) {
        $post_id = $request->get_param('id');
        $post = get_post($post_id);
        
        if (!$post) {
            return new WP_Error(
                'rest_post_invalid_id',
                __('Invalid post ID.', 'breakdance-static-pages'),
                array('status' => 404)
            );
        }
        
        $static_enabled = get_post_meta($post_id, '_bsp_static_enabled', true);
        $static_generated = get_post_meta($post_id, '_bsp_static_generated', true);
        $file_size = get_post_meta($post_id, '_bsp_static_file_size', true);
        $etag = get_post_meta($post_id, '_bsp_static_etag', true);
        
        $data = array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'status' => $post->post_status,
            'modified' => $post->post_modified,
            'static_enabled' => (bool)$static_enabled,
            'static_generated' => $static_generated,
            'file_size' => $file_size ? intval($file_size) : null,
            'etag' => $etag,
            'url' => get_permalink($post->ID),
            'static_url' => $static_generated ? 
                Breakdance_Static_Pages::get_static_file_url($post->ID) : null,
            'file_exists' => file_exists(Breakdance_Static_Pages::get_static_file_path($post->ID))
        );
        
        return rest_ensure_response($data);
    }
    
    /**
     * Generate static page
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function generate_static_page($request) {
        $post_id = $request->get_param('id');
        $async = $request->get_param('async');
        
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error(
                'rest_post_invalid_id',
                __('Invalid post ID.', 'breakdance-static-pages'),
                array('status' => 404)
            );
        }
        
        if ($async) {
            // Add to queue for background processing
            $queue_manager = BSP_Queue_Manager::get_instance();
            $queue_id = $queue_manager->add_to_queue($post_id, 'generate', array(
                'priority' => 5
            ));
            
            if ($queue_id) {
                return rest_ensure_response(array(
                    'success' => true,
                    'message' => __('Page queued for static generation', 'breakdance-static-pages'),
                    'queue_id' => $queue_id
                ));
            } else {
                return new WP_Error(
                    'rest_queue_failed',
                    __('Failed to queue page for generation', 'breakdance-static-pages'),
                    array('status' => 500)
                );
            }
        } else {
            // Generate immediately
            $result = BSP_Atomic_Operations::generate_with_rollback($post_id);
            
            if ($result['success']) {
                return rest_ensure_response(array(
                    'success' => true,
                    'message' => __('Static page generated successfully', 'breakdance-static-pages'),
                    'file_path' => $result['file_path'],
                    'file_size' => $result['file_size']
                ));
            } else {
                return new WP_Error(
                    'rest_generation_failed',
                    $result['error'],
                    array('status' => 500)
                );
            }
        }
    }
    
    /**
     * Delete static page
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function delete_static_page($request) {
        $post_id = $request->get_param('id');
        
        $result = BSP_Atomic_Operations::delete_with_rollback($post_id);
        
        if ($result['success']) {
            return rest_ensure_response(array(
                'success' => true,
                'message' => __('Static page deleted successfully', 'breakdance-static-pages')
            ));
        } else {
            return new WP_Error(
                'rest_deletion_failed',
                $result['error'],
                array('status' => 500)
            );
        }
    }
    
    /**
     * Bulk generate static pages
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function bulk_generate($request) {
        $ids = $request->get_param('ids');
        $async = $request->get_param('async');
        
        // Validate IDs
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids);
        
        if (empty($ids)) {
            return new WP_Error(
                'rest_invalid_param',
                __('No valid IDs provided', 'breakdance-static-pages'),
                array('status' => 400)
            );
        }
        
        if ($async) {
            // Start batch process
            $batch_processor = BSP_Batch_Processor::get_instance();
            $batch_id = $batch_processor->start_batch($ids, 'queue', array(
                'queue_action' => 'generate',
                'chunk_size' => 20
            ));
            
            // Start progress tracking
            $progress_tracker = BSP_Progress_Tracker::get_instance();
            $session_id = $progress_tracker->start_progress(
                'Bulk Generate Static Pages',
                count($ids),
                array('batch_id' => $batch_id)
            );
            
            return rest_ensure_response(array(
                'success' => true,
                'message' => sprintf(
                    __('Started bulk generation for %d pages', 'breakdance-static-pages'),
                    count($ids)
                ),
                'batch_id' => $batch_id,
                'progress_session_id' => $session_id,
                'total' => count($ids)
            ));
        } else {
            // Process immediately (not recommended for large batches)
            $result = BSP_Atomic_Operations::bulk_operation_atomic($ids, 'generate');
            
            return rest_ensure_response(array(
                'success' => $result['success'],
                'message' => sprintf(
                    __('Processed %d pages: %d successful, %d failed', 'breakdance-static-pages'),
                    $result['total'],
                    $result['success_count'],
                    $result['failure_count']
                ),
                'results' => $result
            ));
        }
    }
    
    /**
     * Get queue status
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_queue_status($request) {
        $queue_manager = BSP_Queue_Manager::get_instance();
        $status = $queue_manager->get_queue_status();
        
        return rest_ensure_response($status);
    }
    
    /**
     * Get queue items
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_queue_items($request) {
        $queue_manager = BSP_Queue_Manager::get_instance();
        
        $items = $queue_manager->get_queue_items(array(
            'status' => $request->get_param('status'),
            'limit' => $request->get_param('limit')
        ));
        
        return rest_ensure_response($items);
    }
    
    /**
     * Get progress
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function get_progress($request) {
        $session_id = $request->get_param('session_id');
        
        $progress_tracker = BSP_Progress_Tracker::get_instance();
        $progress = $progress_tracker->get_progress($session_id);
        
        if ($progress) {
            return rest_ensure_response($progress);
        } else {
            return new WP_Error(
                'rest_session_not_found',
                __('Progress session not found', 'breakdance-static-pages'),
                array('status' => 404)
            );
        }
    }
    
    /**
     * Get plugin statistics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function get_stats($request) {
        $stats = BSP_Stats_Cache::get_stats();
        
        // Add queue stats
        $queue_manager = BSP_Queue_Manager::get_instance();
        $stats['queue'] = $queue_manager->get_queue_status();
        
        // Add processing stats
        $stats['processing'] = $queue_manager->get_processing_stats();
        
        return rest_ensure_response($stats);
    }
    
    /**
     * Health check endpoint
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function health_check($request) {
        $health_check = new BSP_Health_Check();
        $health_data = $health_check->run_health_check();
        
        $response = rest_ensure_response($health_data);
        
        // Set appropriate status code based on health
        if ($health_data['status'] === 'critical') {
            $response->set_status(503); // Service Unavailable
        } elseif ($health_data['status'] === 'warning') {
            $response->set_status(200); // OK but with warnings
        }
        
        return $response;
    }
}
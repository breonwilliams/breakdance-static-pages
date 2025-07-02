<?php
/**
 * Atomic Operations Class
 *
 * Provides transaction-like atomic operations with rollback capability.
 *
 * @package Breakdance_Static_Pages
 * @since 1.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_Atomic_Operations
 *
 * Ensures operations complete fully or roll back to original state.
 */
class BSP_Atomic_Operations {
    
    /**
     * Generate static page with rollback capability
     *
     * @param int $post_id Post ID
     * @return array Operation result
     */
    public static function generate_with_rollback($post_id) {
        $rollback_data = array();
        $temp_file = null;
        $lock_manager = BSP_File_Lock_Manager::get_instance();
        $error_handler = BSP_Error_Handler::get_instance();
        
        try {
            // Step 1: Acquire lock
            if (!$lock_manager->acquire_lock($post_id)) {
                return array(
                    'success' => false,
                    'error' => 'Could not acquire lock - generation already in progress'
                );
            }
            
            // Store lock in rollback data
            $rollback_data['lock_acquired'] = true;
            $rollback_data['post_id'] = $post_id;
            
            // Step 2: Backup existing metadata
            $rollback_data['meta'] = array(
                '_bsp_static_generated' => get_post_meta($post_id, '_bsp_static_generated', true),
                '_bsp_static_file_size' => get_post_meta($post_id, '_bsp_static_file_size', true),
                '_bsp_static_etag' => get_post_meta($post_id, '_bsp_static_etag', true),
                '_bsp_static_etag_time' => get_post_meta($post_id, '_bsp_static_etag_time', true)
            );
            
            // Step 3: Get file paths
            $static_file_path = Breakdance_Static_Pages::get_static_file_path($post_id);
            $temp_file = $static_file_path . '.atomic.' . uniqid();
            $rollback_data['static_file_path'] = $static_file_path;
            $rollback_data['temp_file'] = $temp_file;
            
            // Step 4: Backup existing file if it exists
            if (file_exists($static_file_path)) {
                $backup_file = $static_file_path . '.backup.' . uniqid();
                if (!copy($static_file_path, $backup_file)) {
                    throw new Exception('Failed to create backup of existing file');
                }
                $rollback_data['backup_file'] = $backup_file;
            }
            
            // Step 5: Generate new static file
            $generator = new BSP_Static_Generator();
            
            // Use temporary post meta to avoid conflicts
            $temp_meta_key = '_bsp_atomic_temp_' . uniqid();
            update_post_meta($post_id, $temp_meta_key, 'generating');
            $rollback_data['temp_meta_key'] = $temp_meta_key;
            
            // Generate to temporary location
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                throw new Exception('Post not found or not published');
            }
            
            $page_url = get_permalink($post_id);
            if (!$page_url) {
                throw new Exception('Could not get permalink for post');
            }
            
            // Capture HTML with retry
            $html_content = BSP_Retry_Manager::retry(
                function() use ($generator, $page_url, $post_id) {
                    $method = new ReflectionMethod($generator, 'capture_page_html');
                    $method->setAccessible(true);
                    return $method->invoke($generator, $page_url, $post_id);
                },
                array('max_attempts' => 2, 'initial_delay' => 500)
            );
            
            if (!$html_content) {
                throw new Exception('Failed to capture page HTML');
            }
            
            // Optimize HTML
            $method = new ReflectionMethod($generator, 'optimize_html');
            $method->setAccessible(true);
            $optimized_html = $method->invoke($generator, $html_content, $post_id);
            
            // Step 6: Write to temporary file
            if (file_put_contents($temp_file, $optimized_html, LOCK_EX) === false) {
                throw new Exception('Failed to write temporary file');
            }
            
            // Step 7: Validate generated file
            if (!file_exists($temp_file) || filesize($temp_file) === 0) {
                throw new Exception('Generated file is invalid');
            }
            
            // Step 8: Atomically move temp file to final location
            if (!rename($temp_file, $static_file_path)) {
                throw new Exception('Failed to move temporary file to final location');
            }
            
            // Step 9: Update metadata atomically
            $new_meta = array(
                '_bsp_static_generated' => current_time('mysql'),
                '_bsp_static_file_size' => filesize($static_file_path),
                '_bsp_static_etag' => md5_file($static_file_path),
                '_bsp_static_etag_time' => time()
            );
            
            foreach ($new_meta as $key => $value) {
                update_post_meta($post_id, $key, $value);
            }
            
            // Step 10: Clean up
            if (isset($rollback_data['backup_file']) && file_exists($rollback_data['backup_file'])) {
                unlink($rollback_data['backup_file']);
            }
            
            delete_post_meta($post_id, $temp_meta_key);
            
            // Fire success action
            do_action('bsp_atomic_generation_success', $post_id, $static_file_path);
            
            // Release lock
            $lock_manager->release_lock($post_id);
            
            // Invalidate caches
            BSP_Stats_Cache::invalidate();
            
            $error_handler->log_error(
                'atomic_operations',
                sprintf('Successfully generated static page for post %d', $post_id),
                'info'
            );
            
            return array(
                'success' => true,
                'file_path' => $static_file_path,
                'file_size' => filesize($static_file_path)
            );
            
        } catch (Exception $e) {
            // Rollback on any error
            $error_handler->log_error(
                'atomic_operations',
                sprintf('Atomic generation failed for post %d: %s', $post_id, $e->getMessage()),
                'error',
                array('rollback_data' => $rollback_data),
                $e
            );
            
            self::rollback($rollback_data);
            
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Delete static page with rollback capability
     *
     * @param int $post_id Post ID
     * @return array Operation result
     */
    public static function delete_with_rollback($post_id) {
        $rollback_data = array();
        $error_handler = BSP_Error_Handler::get_instance();
        
        try {
            // Backup metadata
            $rollback_data['meta'] = array(
                '_bsp_static_generated' => get_post_meta($post_id, '_bsp_static_generated', true),
                '_bsp_static_file_size' => get_post_meta($post_id, '_bsp_static_file_size', true),
                '_bsp_static_etag' => get_post_meta($post_id, '_bsp_static_etag', true),
                '_bsp_static_etag_time' => get_post_meta($post_id, '_bsp_static_etag_time', true)
            );
            $rollback_data['post_id'] = $post_id;
            
            // Backup file if it exists
            $static_file_path = Breakdance_Static_Pages::get_static_file_path($post_id);
            
            if (file_exists($static_file_path)) {
                $backup_file = $static_file_path . '.delete_backup.' . uniqid();
                if (!copy($static_file_path, $backup_file)) {
                    throw new Exception('Failed to create backup before deletion');
                }
                $rollback_data['backup_file'] = $backup_file;
                $rollback_data['original_path'] = $static_file_path;
                
                // Delete the file
                if (!unlink($static_file_path)) {
                    throw new Exception('Failed to delete static file');
                }
            }
            
            // Delete metadata
            delete_post_meta($post_id, '_bsp_static_generated');
            delete_post_meta($post_id, '_bsp_static_file_size');
            delete_post_meta($post_id, '_bsp_static_etag');
            delete_post_meta($post_id, '_bsp_static_etag_time');
            
            // Clean up backup on success
            if (isset($rollback_data['backup_file']) && file_exists($rollback_data['backup_file'])) {
                unlink($rollback_data['backup_file']);
            }
            
            // Invalidate caches
            BSP_Stats_Cache::invalidate();
            
            $error_handler->log_error(
                'atomic_operations',
                sprintf('Successfully deleted static page for post %d', $post_id),
                'info'
            );
            
            return array(
                'success' => true,
                'message' => 'Static file deleted successfully'
            );
            
        } catch (Exception $e) {
            $error_handler->log_error(
                'atomic_operations',
                sprintf('Atomic deletion failed for post %d: %s', $post_id, $e->getMessage()),
                'error',
                array('rollback_data' => $rollback_data),
                $e
            );
            
            self::rollback_delete($rollback_data);
            
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Bulk operation with transaction-like behavior
     *
     * @param array $post_ids Array of post IDs
     * @param string $operation Operation type ('generate' or 'delete')
     * @return array Operation results
     */
    public static function bulk_operation_atomic($post_ids, $operation = 'generate') {
        $completed = array();
        $failed = array();
        $rollback_all = array();
        
        try {
            foreach ($post_ids as $post_id) {
                if ($operation === 'generate') {
                    $result = self::generate_with_rollback($post_id);
                } else {
                    $result = self::delete_with_rollback($post_id);
                }
                
                if ($result['success']) {
                    $completed[$post_id] = $result;
                    $rollback_all[$post_id] = array(
                        'operation' => $operation,
                        'data' => $result
                    );
                } else {
                    $failed[$post_id] = $result['error'];
                    
                    // Option to continue or rollback all on failure
                    if (apply_filters('bsp_atomic_bulk_stop_on_failure', false)) {
                        throw new Exception(
                            sprintf('Bulk operation failed at post %d: %s', $post_id, $result['error'])
                        );
                    }
                }
            }
            
            return array(
                'success' => true,
                'completed' => $completed,
                'failed' => $failed,
                'total' => count($post_ids),
                'success_count' => count($completed),
                'failure_count' => count($failed)
            );
            
        } catch (Exception $e) {
            // Rollback all completed operations if configured
            if (apply_filters('bsp_atomic_bulk_rollback_on_failure', false)) {
                foreach ($rollback_all as $post_id => $rollback_info) {
                    if ($rollback_info['operation'] === 'generate') {
                        // Delete generated files
                        self::delete_with_rollback($post_id);
                    } else {
                        // Regenerate deleted files
                        self::generate_with_rollback($post_id);
                    }
                }
            }
            
            return array(
                'success' => false,
                'error' => $e->getMessage(),
                'completed' => $completed,
                'failed' => $failed,
                'rolled_back' => apply_filters('bsp_atomic_bulk_rollback_on_failure', false)
            );
        }
    }
    
    /**
     * Rollback changes
     *
     * @param array $rollback_data Data needed for rollback
     */
    private static function rollback($rollback_data) {
        $error_handler = BSP_Error_Handler::get_instance();
        
        try {
            // Remove temporary file if exists
            if (isset($rollback_data['temp_file']) && file_exists($rollback_data['temp_file'])) {
                @unlink($rollback_data['temp_file']);
            }
            
            // Restore original file from backup
            if (isset($rollback_data['backup_file']) && file_exists($rollback_data['backup_file'])) {
                if (isset($rollback_data['static_file_path'])) {
                    @rename($rollback_data['backup_file'], $rollback_data['static_file_path']);
                }
            }
            
            // Restore metadata
            if (isset($rollback_data['post_id']) && isset($rollback_data['meta'])) {
                foreach ($rollback_data['meta'] as $key => $value) {
                    if ($value !== false) {
                        update_post_meta($rollback_data['post_id'], $key, $value);
                    } else {
                        delete_post_meta($rollback_data['post_id'], $key);
                    }
                }
            }
            
            // Clean up temporary meta
            if (isset($rollback_data['temp_meta_key']) && isset($rollback_data['post_id'])) {
                delete_post_meta($rollback_data['post_id'], $rollback_data['temp_meta_key']);
            }
            
            // Release lock if acquired
            if (isset($rollback_data['lock_acquired']) && $rollback_data['lock_acquired'] && isset($rollback_data['post_id'])) {
                BSP_File_Lock_Manager::get_instance()->release_lock($rollback_data['post_id']);
            }
            
            $error_handler->log_error(
                'atomic_operations',
                'Rollback completed successfully',
                'info',
                array('rollback_data' => $rollback_data)
            );
            
        } catch (Exception $e) {
            $error_handler->log_error(
                'atomic_operations',
                'Rollback failed: ' . $e->getMessage(),
                'critical',
                array('rollback_data' => $rollback_data),
                $e
            );
        }
    }
    
    /**
     * Rollback delete operation
     *
     * @param array $rollback_data Data needed for rollback
     */
    private static function rollback_delete($rollback_data) {
        try {
            // Restore file from backup
            if (isset($rollback_data['backup_file']) && 
                file_exists($rollback_data['backup_file']) && 
                isset($rollback_data['original_path'])) {
                @rename($rollback_data['backup_file'], $rollback_data['original_path']);
            }
            
            // Restore metadata
            if (isset($rollback_data['post_id']) && isset($rollback_data['meta'])) {
                foreach ($rollback_data['meta'] as $key => $value) {
                    if ($value !== false) {
                        update_post_meta($rollback_data['post_id'], $key, $value);
                    }
                }
            }
            
        } catch (Exception $e) {
            BSP_Error_Handler::get_instance()->log_error(
                'atomic_operations',
                'Delete rollback failed: ' . $e->getMessage(),
                'critical',
                array('rollback_data' => $rollback_data),
                $e
            );
        }
    }
}
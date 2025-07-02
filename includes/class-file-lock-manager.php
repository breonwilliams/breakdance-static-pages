<?php
/**
 * File Lock Manager
 *
 * Handles file locking to prevent race conditions during concurrent static file generation.
 *
 * @package Breakdance_Static_Pages
 * @since 1.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_File_Lock_Manager
 *
 * Manages file locks to prevent concurrent generation of the same static file.
 */
class BSP_File_Lock_Manager {
    /**
     * Singleton instance
     *
     * @var BSP_File_Lock_Manager|null
     */
    private static $instance = null;

    /**
     * Lock directory path
     *
     * @var string
     */
    private $lock_dir;

    /**
     * Default lock timeout in seconds
     *
     * @var int
     */
    private $default_timeout = 300; // 5 minutes

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_lock_directory();
    }

    /**
     * Get singleton instance
     *
     * @return BSP_File_Lock_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize lock directory
     *
     * @return void
     */
    private function init_lock_directory() {
        $upload_dir = wp_upload_dir();
        $this->lock_dir = $upload_dir['basedir'] . '/bsp-locks/';
        
        // Create directory if it doesn't exist
        if (!file_exists($this->lock_dir)) {
            wp_mkdir_p($this->lock_dir);
            
            // Add .htaccess to prevent direct access
            $htaccess_content = "Order Deny,Allow\nDeny from all";
            file_put_contents($this->lock_dir . '.htaccess', $htaccess_content);
        }
    }

    /**
     * Acquire a lock for a specific post
     *
     * @param int $post_id Post ID to lock
     * @param int $timeout Lock timeout in seconds
     * @return bool True if lock acquired, false if already locked
     */
    public function acquire_lock($post_id, $timeout = null) {
        if (null === $timeout) {
            $timeout = $this->default_timeout;
        }

        $lock_file = $this->get_lock_file_path($post_id);
        
        // Check if lock exists and is still valid
        if (file_exists($lock_file)) {
            $lock_data = $this->read_lock_file($lock_file);
            
            if ($lock_data && $this->is_lock_valid($lock_data, $timeout)) {
                // Lock is still active
                error_log(sprintf(
                    'BSP: Lock still active for post %d (locked by process %d at %s)',
                    $post_id,
                    $lock_data['pid'],
                    date('Y-m-d H:i:s', $lock_data['timestamp'])
                ));
                return false;
            }
            
            // Lock expired or invalid, remove it
            $this->release_lock($post_id);
        }
        
        // Create lock file with process info
        $lock_data = array(
            'timestamp' => time(),
            'pid' => getmypid(),
            'post_id' => $post_id,
            'user_id' => get_current_user_id(),
            'timeout' => $timeout
        );
        
        // Atomic write using temp file and rename
        $temp_file = $lock_file . '.tmp';
        if (file_put_contents($temp_file, json_encode($lock_data)) !== false) {
            // Use rename for atomic operation
            if (rename($temp_file, $lock_file)) {
                error_log(sprintf('BSP: Lock acquired for post %d', $post_id));
                return true;
            }
        }
        
        // Cleanup temp file if rename failed
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        
        return false;
    }

    /**
     * Release a lock for a specific post
     *
     * @param int $post_id Post ID to unlock
     * @return bool True if lock released, false on error
     */
    public function release_lock($post_id) {
        $lock_file = $this->get_lock_file_path($post_id);
        
        if (file_exists($lock_file)) {
            $result = unlink($lock_file);
            if ($result) {
                error_log(sprintf('BSP: Lock released for post %d', $post_id));
            }
            return $result;
        }
        
        return true; // No lock to release
    }

    /**
     * Check if a post is currently locked
     *
     * @param int $post_id Post ID to check
     * @return bool True if locked, false otherwise
     */
    public function is_locked($post_id) {
        $lock_file = $this->get_lock_file_path($post_id);
        
        if (!file_exists($lock_file)) {
            return false;
        }
        
        $lock_data = $this->read_lock_file($lock_file);
        return $lock_data && $this->is_lock_valid($lock_data);
    }

    /**
     * Get lock information for a post
     *
     * @param int $post_id Post ID
     * @return array|false Lock data or false if not locked
     */
    public function get_lock_info($post_id) {
        $lock_file = $this->get_lock_file_path($post_id);
        
        if (!file_exists($lock_file)) {
            return false;
        }
        
        $lock_data = $this->read_lock_file($lock_file);
        
        if ($lock_data && $this->is_lock_valid($lock_data)) {
            // Add human-readable info
            $lock_data['locked_since'] = human_time_diff($lock_data['timestamp']);
            $lock_data['expires_in'] = human_time_diff(
                time(),
                $lock_data['timestamp'] + $lock_data['timeout']
            );
            
            return $lock_data;
        }
        
        return false;
    }

    /**
     * Clean up expired locks
     *
     * @param int|null $timeout Override timeout for cleanup
     * @return int Number of locks cleaned
     */
    public function cleanup_expired_locks($timeout = null) {
        if (null === $timeout) {
            $timeout = $this->default_timeout;
        }

        $cleaned = 0;
        $locks = glob($this->lock_dir . '*.lock');
        
        if (!$locks) {
            return 0;
        }
        
        foreach ($locks as $lock_file) {
            $lock_data = $this->read_lock_file($lock_file);
            
            if (!$lock_data || !$this->is_lock_valid($lock_data, $timeout)) {
                if (unlink($lock_file)) {
                    $cleaned++;
                    error_log(sprintf('BSP: Cleaned expired lock file: %s', basename($lock_file)));
                }
            }
        }
        
        if ($cleaned > 0) {
            error_log(sprintf('BSP: Cleaned %d expired lock(s)', $cleaned));
        }
        
        return $cleaned;
    }

    /**
     * Get all active locks
     *
     * @return array Array of active lock data
     */
    public function get_active_locks() {
        $active_locks = array();
        $locks = glob($this->lock_dir . '*.lock');
        
        if (!$locks) {
            return $active_locks;
        }
        
        foreach ($locks as $lock_file) {
            $lock_data = $this->read_lock_file($lock_file);
            
            if ($lock_data && $this->is_lock_valid($lock_data)) {
                $lock_data['file'] = basename($lock_file);
                $lock_data['locked_since'] = human_time_diff($lock_data['timestamp']);
                $active_locks[] = $lock_data;
            }
        }
        
        return $active_locks;
    }

    /**
     * Force release all locks (use with caution)
     *
     * @return int Number of locks released
     */
    public function force_release_all_locks() {
        $released = 0;
        $locks = glob($this->lock_dir . '*.lock');
        
        if (!$locks) {
            return 0;
        }
        
        foreach ($locks as $lock_file) {
            if (unlink($lock_file)) {
                $released++;
            }
        }
        
        error_log(sprintf('BSP: Force released %d lock(s)', $released));
        return $released;
    }

    /**
     * Get lock file path for a post
     *
     * @param int $post_id Post ID
     * @return string Lock file path
     */
    private function get_lock_file_path($post_id) {
        return $this->lock_dir . 'post-' . intval($post_id) . '.lock';
    }

    /**
     * Read lock file data
     *
     * @param string $lock_file Lock file path
     * @return array|false Lock data or false on error
     */
    private function read_lock_file($lock_file) {
        $content = file_get_contents($lock_file);
        
        if ($content === false) {
            return false;
        }
        
        $data = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log(sprintf('BSP: Invalid lock file data in %s', basename($lock_file)));
            return false;
        }
        
        return $data;
    }

    /**
     * Check if a lock is still valid
     *
     * @param array $lock_data Lock data
     * @param int|null $timeout Timeout to check against
     * @return bool True if valid, false if expired
     */
    private function is_lock_valid($lock_data, $timeout = null) {
        if (!isset($lock_data['timestamp'])) {
            return false;
        }
        
        // Use lock's own timeout if available, otherwise use provided or default
        if (isset($lock_data['timeout'])) {
            $timeout = $lock_data['timeout'];
        } elseif (null === $timeout) {
            $timeout = $this->default_timeout;
        }
        
        return (time() - $lock_data['timestamp']) < $timeout;
    }

    /**
     * Get lock directory path
     *
     * @return string Lock directory path
     */
    public function get_lock_directory() {
        return $this->lock_dir;
    }
}
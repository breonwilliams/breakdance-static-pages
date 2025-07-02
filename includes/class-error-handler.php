<?php
/**
 * Error Handler Class
 *
 * Provides comprehensive error handling and logging for the plugin.
 *
 * @package Breakdance_Static_Pages
 * @since 1.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_Error_Handler
 *
 * Manages error logging, notifications, and recovery strategies.
 */
class BSP_Error_Handler {
    
    /**
     * Singleton instance
     *
     * @var BSP_Error_Handler|null
     */
    private static $instance = null;
    
    /**
     * Error log storage
     *
     * @var array
     */
    private $errors = array();
    
    /**
     * Maximum errors to keep in memory
     *
     * @var int
     */
    private $max_errors = 100;
    
    /**
     * Error severity levels
     *
     * @var array
     */
    private $severity_levels = array(
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5
    );
    
    /**
     * Constructor
     */
    private function __construct() {
        // Set custom error handler for plugin operations
        $this->setup_error_handlers();
        
        // Load recent errors from transient
        $this->errors = get_transient('bsp_recent_errors') ?: array();
        
        // Schedule cleanup of old errors
        add_action('init', array($this, 'schedule_error_cleanup'));
        add_action('bsp_cleanup_error_logs', array($this, 'cleanup_old_errors'));
    }
    
    /**
     * Get singleton instance
     *
     * @return BSP_Error_Handler
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Setup custom error handlers
     */
    private function setup_error_handlers() {
        // Register shutdown function to catch fatal errors
        register_shutdown_function(array($this, 'handle_shutdown'));
    }
    
    /**
     * Log an error
     *
     * @param string $context Where the error occurred
     * @param string $message Error message
     * @param string $severity Error severity
     * @param array $data Additional data
     * @param Exception|null $exception Exception object if available
     * @return void
     */
    public function log_error($context, $message, $severity = 'error', $data = array(), $exception = null) {
        $error = array(
            'id' => uniqid('bsp_error_'),
            'time' => current_time('mysql'),
            'timestamp' => current_time('timestamp'),
            'context' => $context,
            'message' => $message,
            'severity' => $severity,
            'data' => $data,
            'user_id' => get_current_user_id(),
            'url' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
            'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : ''
        );
        
        // Add exception details if provided
        if ($exception instanceof Exception) {
            $error['exception'] = array(
                'class' => get_class($exception),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->format_stack_trace($exception->getTrace())
            );
        }
        
        // Add to errors array
        array_unshift($this->errors, $error);
        
        // Limit errors in memory
        if (count($this->errors) > $this->max_errors) {
            array_splice($this->errors, $this->max_errors);
        }
        
        // Save to transient
        set_transient('bsp_recent_errors', $this->errors, DAY_IN_SECONDS);
        
        // Log to WordPress debug log if enabled
        if (WP_DEBUG && WP_DEBUG_LOG) {
            error_log(sprintf(
                '[BSP %s] %s: %s | Context: %s | Data: %s',
                strtoupper($severity),
                current_time('Y-m-d H:i:s'),
                $message,
                $context,
                json_encode($data)
            ));
        }
        
        // Store in database for critical errors
        if ($severity === 'critical') {
            $this->store_critical_error($error);
        }
        
        // Send notifications for critical errors
        if ($severity === 'critical' && apply_filters('bsp_notify_critical_errors', true)) {
            $this->notify_admin($error);
        }
        
        // Fire action for other plugins to hook into
        do_action('bsp_error_logged', $error);
    }
    
    /**
     * Format stack trace for logging
     *
     * @param array $trace Stack trace
     * @return array Formatted trace
     */
    private function format_stack_trace($trace) {
        $formatted = array();
        
        foreach ($trace as $i => $call) {
            $formatted[] = sprintf(
                '#%d %s%s%s(%s) in %s:%d',
                $i,
                isset($call['class']) ? $call['class'] : '',
                isset($call['type']) ? $call['type'] : '',
                isset($call['function']) ? $call['function'] : '',
                isset($call['args']) ? $this->format_args($call['args']) : '',
                isset($call['file']) ? $call['file'] : 'unknown',
                isset($call['line']) ? $call['line'] : 0
            );
        }
        
        return $formatted;
    }
    
    /**
     * Format function arguments for trace
     *
     * @param array $args Arguments
     * @return string Formatted arguments
     */
    private function format_args($args) {
        $formatted = array();
        
        foreach ($args as $arg) {
            if (is_string($arg)) {
                $formatted[] = "'" . (strlen($arg) > 50 ? substr($arg, 0, 50) . '...' : $arg) . "'";
            } elseif (is_numeric($arg)) {
                $formatted[] = $arg;
            } elseif (is_bool($arg)) {
                $formatted[] = $arg ? 'true' : 'false';
            } elseif (is_array($arg)) {
                $formatted[] = 'Array(' . count($arg) . ')';
            } elseif (is_object($arg)) {
                $formatted[] = get_class($arg);
            } else {
                $formatted[] = gettype($arg);
            }
        }
        
        return implode(', ', $formatted);
    }
    
    /**
     * Store critical error in database
     *
     * @param array $error Error data
     */
    private function store_critical_error($error) {
        $critical_errors = get_option('bsp_critical_errors', array());
        
        // Add new error
        array_unshift($critical_errors, $error);
        
        // Keep only last 50 critical errors
        if (count($critical_errors) > 50) {
            array_splice($critical_errors, 50);
        }
        
        update_option('bsp_critical_errors', $critical_errors, false);
    }
    
    /**
     * Send email notification for critical errors
     *
     * @param array $error Error data
     */
    private function notify_admin($error) {
        $to = get_option('admin_email');
        $subject = sprintf(
            '[%s] Critical Error in Breakdance Static Pages',
            get_bloginfo('name')
        );
        
        $message = "A critical error has occurred:\n\n";
        $message .= "Time: " . $error['time'] . "\n";
        $message .= "Context: " . $error['context'] . "\n";
        $message .= "Message: " . $error['message'] . "\n";
        $message .= "URL: " . $error['url'] . "\n\n";
        
        if (!empty($error['data'])) {
            $message .= "Additional Data:\n" . print_r($error['data'], true) . "\n\n";
        }
        
        if (isset($error['exception'])) {
            $message .= "Exception Details:\n";
            $message .= "Class: " . $error['exception']['class'] . "\n";
            $message .= "File: " . $error['exception']['file'] . "\n";
            $message .= "Line: " . $error['exception']['line'] . "\n\n";
            $message .= "Stack Trace:\n" . implode("\n", $error['exception']['trace']) . "\n";
        }
        
        $message .= "\nView all errors: " . admin_url('tools.php?page=breakdance-static-pages&tab=errors');
        
        // Throttle emails to prevent spam
        $last_email = get_transient('bsp_last_error_email');
        if (!$last_email || (time() - $last_email) > 300) { // 5 minutes
            wp_mail($to, $subject, $message);
            set_transient('bsp_last_error_email', time(), HOUR_IN_SECONDS);
        }
    }
    
    /**
     * Handle shutdown to catch fatal errors
     */
    public function handle_shutdown() {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
            // Check if error is related to our plugin
            if (strpos($error['file'], 'breakdance-static-pages') !== false) {
                $this->log_error(
                    'fatal_error',
                    $error['message'],
                    'critical',
                    array(
                        'type' => $error['type'],
                        'file' => $error['file'],
                        'line' => $error['line']
                    )
                );
            }
        }
    }
    
    /**
     * Get recent errors
     *
     * @param string|null $severity Filter by severity
     * @param string|null $context Filter by context
     * @param int $limit Number of errors to return
     * @return array Recent errors
     */
    public function get_recent_errors($severity = null, $context = null, $limit = 50) {
        $errors = $this->errors;
        
        // Filter by severity
        if ($severity !== null) {
            $errors = array_filter($errors, function($error) use ($severity) {
                return $error['severity'] === $severity;
            });
        }
        
        // Filter by context
        if ($context !== null) {
            $errors = array_filter($errors, function($error) use ($context) {
                return $error['context'] === $context;
            });
        }
        
        // Limit results
        return array_slice($errors, 0, $limit);
    }
    
    /**
     * Get error statistics
     *
     * @return array Error stats
     */
    public function get_error_stats() {
        $stats = array(
            'total' => count($this->errors),
            'by_severity' => array(),
            'by_context' => array(),
            'last_24h' => 0,
            'last_7d' => 0
        );
        
        $now = current_time('timestamp');
        $day_ago = $now - DAY_IN_SECONDS;
        $week_ago = $now - WEEK_IN_SECONDS;
        
        foreach ($this->errors as $error) {
            // Count by severity
            if (!isset($stats['by_severity'][$error['severity']])) {
                $stats['by_severity'][$error['severity']] = 0;
            }
            $stats['by_severity'][$error['severity']]++;
            
            // Count by context
            if (!isset($stats['by_context'][$error['context']])) {
                $stats['by_context'][$error['context']] = 0;
            }
            $stats['by_context'][$error['context']]++;
            
            // Count recent errors
            if ($error['timestamp'] > $day_ago) {
                $stats['last_24h']++;
            }
            if ($error['timestamp'] > $week_ago) {
                $stats['last_7d']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Clear all error logs
     */
    public function clear_errors() {
        $this->errors = array();
        delete_transient('bsp_recent_errors');
        delete_option('bsp_critical_errors');
        
        do_action('bsp_errors_cleared');
    }
    
    /**
     * Schedule error log cleanup
     */
    public function schedule_error_cleanup() {
        if (!wp_next_scheduled('bsp_cleanup_error_logs')) {
            wp_schedule_event(time(), 'daily', 'bsp_cleanup_error_logs');
        }
    }
    
    /**
     * Clean up old error logs
     */
    public function cleanup_old_errors() {
        $week_ago = current_time('timestamp') - WEEK_IN_SECONDS;
        
        // Remove errors older than a week
        $this->errors = array_filter($this->errors, function($error) use ($week_ago) {
            return $error['timestamp'] > $week_ago;
        });
        
        // Update transient
        set_transient('bsp_recent_errors', $this->errors, DAY_IN_SECONDS);
        
        // Clean up critical errors
        $critical_errors = get_option('bsp_critical_errors', array());
        $critical_errors = array_filter($critical_errors, function($error) use ($week_ago) {
            return $error['timestamp'] > $week_ago;
        });
        update_option('bsp_critical_errors', $critical_errors, false);
    }
    
    /**
     * Export errors for debugging
     *
     * @return string JSON export of errors
     */
    public function export_errors() {
        $export = array(
            'plugin_version' => BSP_VERSION,
            'export_time' => current_time('mysql'),
            'site_url' => site_url(),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'errors' => $this->errors,
            'critical_errors' => get_option('bsp_critical_errors', array()),
            'stats' => $this->get_error_stats()
        );
        
        return json_encode($export, JSON_PRETTY_PRINT);
    }
}
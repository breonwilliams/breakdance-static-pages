<?php
/**
 * Retry Manager Class
 *
 * Handles retry logic with exponential backoff for failed operations.
 *
 * @package Breakdance_Static_Pages
 * @since 1.1.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class BSP_Retry_Manager
 *
 * Manages retry operations with configurable strategies.
 */
class BSP_Retry_Manager {
    
    /**
     * Default retry configuration
     *
     * @var array
     */
    private static $default_config = array(
        'max_attempts' => 3,
        'initial_delay' => 1000, // milliseconds
        'max_delay' => 30000, // milliseconds
        'multiplier' => 2,
        'jitter' => true,
        'retryable_exceptions' => array()
    );
    
    /**
     * Retry an operation with exponential backoff
     *
     * @param callable $operation The operation to retry
     * @param array $config Configuration options
     * @return mixed Result of the operation
     * @throws Exception If operation fails after all retries
     */
    public static function retry($operation, $config = array()) {
        $config = wp_parse_args($config, self::$default_config);
        
        $attempt = 0;
        $delay = $config['initial_delay'];
        $last_exception = null;
        $error_handler = BSP_Error_Handler::get_instance();
        
        while ($attempt < $config['max_attempts']) {
            $attempt++;
            
            try {
                // Log attempt
                if ($attempt > 1) {
                    $error_handler->log_error(
                        'retry_manager',
                        sprintf('Retry attempt %d/%d', $attempt, $config['max_attempts']),
                        'info',
                        array('delay' => $delay)
                    );
                }
                
                // Execute operation
                $result = call_user_func($operation);
                
                // Success - log if it was a retry
                if ($attempt > 1) {
                    $error_handler->log_error(
                        'retry_manager',
                        sprintf('Operation succeeded on attempt %d', $attempt),
                        'info'
                    );
                }
                
                return $result;
                
            } catch (Exception $e) {
                $last_exception = $e;
                
                // Check if exception is retryable
                if (!self::is_retryable_exception($e, $config['retryable_exceptions'])) {
                    throw $e;
                }
                
                // Log the failure
                $error_handler->log_error(
                    'retry_manager',
                    sprintf('Attempt %d failed: %s', $attempt, $e->getMessage()),
                    'warning',
                    array(
                        'exception_class' => get_class($e),
                        'exception_code' => $e->getCode()
                    ),
                    $e
                );
                
                // If not last attempt, wait before retrying
                if ($attempt < $config['max_attempts']) {
                    // Apply jitter if enabled
                    if ($config['jitter']) {
                        $jitter = rand(-$delay * 0.1, $delay * 0.1);
                        $delay += $jitter;
                    }
                    
                    // Sleep for the delay
                    usleep($delay * 1000); // Convert to microseconds
                    
                    // Calculate next delay with exponential backoff
                    $delay = min($delay * $config['multiplier'], $config['max_delay']);
                }
            }
        }
        
        // All attempts failed
        $error_handler->log_error(
            'retry_manager',
            sprintf('All %d retry attempts failed', $config['max_attempts']),
            'error',
            array('last_error' => $last_exception->getMessage())
        );
        
        throw new Exception(
            sprintf(
                'Operation failed after %d attempts. Last error: %s',
                $config['max_attempts'],
                $last_exception->getMessage()
            ),
            0,
            $last_exception
        );
    }
    
    /**
     * Retry an operation with custom retry condition
     *
     * @param callable $operation The operation to retry
     * @param callable $should_retry Function to determine if should retry
     * @param array $config Configuration options
     * @return mixed Result of the operation
     * @throws Exception If operation fails after all retries
     */
    public static function retry_with_condition($operation, $should_retry, $config = array()) {
        $config = wp_parse_args($config, self::$default_config);
        
        $attempt = 0;
        $delay = $config['initial_delay'];
        $last_result = null;
        $error_handler = BSP_Error_Handler::get_instance();
        
        while ($attempt < $config['max_attempts']) {
            $attempt++;
            
            try {
                $result = call_user_func($operation);
                
                // Check if we should retry based on result
                if (!call_user_func($should_retry, $result, $attempt)) {
                    return $result;
                }
                
                $last_result = $result;
                
                // Log retry reason
                $error_handler->log_error(
                    'retry_manager',
                    sprintf('Retrying based on condition (attempt %d/%d)', $attempt, $config['max_attempts']),
                    'info'
                );
                
                // Wait before retrying
                if ($attempt < $config['max_attempts']) {
                    usleep($delay * 1000);
                    $delay = min($delay * $config['multiplier'], $config['max_delay']);
                }
                
            } catch (Exception $e) {
                // Let regular retry handle exceptions
                return self::retry($operation, $config);
            }
        }
        
        throw new Exception(
            sprintf('Operation did not meet success condition after %d attempts', $config['max_attempts'])
        );
    }
    
    /**
     * Retry multiple operations in sequence
     *
     * @param array $operations Array of callables
     * @param array $config Configuration options
     * @return array Results of all operations
     * @throws Exception If any operation fails after retries
     */
    public static function retry_sequence($operations, $config = array()) {
        $results = array();
        
        foreach ($operations as $key => $operation) {
            try {
                $results[$key] = self::retry($operation, $config);
            } catch (Exception $e) {
                // Log which operation in sequence failed
                BSP_Error_Handler::get_instance()->log_error(
                    'retry_manager',
                    sprintf('Sequence failed at operation %s', $key),
                    'error',
                    array('completed' => array_keys($results))
                );
                throw $e;
            }
        }
        
        return $results;
    }
    
    /**
     * Check if an exception is retryable
     *
     * @param Exception $exception The exception to check
     * @param array $retryable_exceptions List of retryable exception classes
     * @return bool Whether the exception is retryable
     */
    private static function is_retryable_exception($exception, $retryable_exceptions) {
        // If no specific exceptions configured, retry all
        if (empty($retryable_exceptions)) {
            return true;
        }
        
        // Check if exception class is in retryable list
        foreach ($retryable_exceptions as $retryable_class) {
            if ($exception instanceof $retryable_class) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Create a retry wrapper for a function
     *
     * @param callable $function Function to wrap
     * @param array $config Retry configuration
     * @return callable Wrapped function
     */
    public static function wrap($function, $config = array()) {
        return function() use ($function, $config) {
            $args = func_get_args();
            return self::retry(
                function() use ($function, $args) {
                    return call_user_func_array($function, $args);
                },
                $config
            );
        };
    }
    
    /**
     * Retry an HTTP request
     *
     * @param string $url URL to request
     * @param array $args WordPress HTTP API arguments
     * @param array $config Retry configuration
     * @return array|WP_Error Response array or WP_Error
     */
    public static function retry_http_request($url, $args = array(), $config = array()) {
        // Configure retryable HTTP errors
        $config = wp_parse_args($config, array(
            'max_attempts' => 3,
            'initial_delay' => 1000,
            'retryable_http_codes' => array(408, 429, 500, 502, 503, 504)
        ));
        
        return self::retry_with_condition(
            function() use ($url, $args) {
                return wp_remote_request($url, $args);
            },
            function($response, $attempt) use ($config) {
                // Retry on WP_Error
                if (is_wp_error($response)) {
                    return $attempt < $config['max_attempts'];
                }
                
                // Retry on specific HTTP codes
                $code = wp_remote_retrieve_response_code($response);
                return in_array($code, $config['retryable_http_codes']);
            },
            $config
        );
    }
    
    /**
     * Retry a database operation
     *
     * @param callable $operation Database operation
     * @param array $config Retry configuration
     * @return mixed Result of the operation
     */
    public static function retry_database_operation($operation, $config = array()) {
        // Configure for database-specific retries
        $config = wp_parse_args($config, array(
            'max_attempts' => 3,
            'initial_delay' => 100,
            'max_delay' => 1000,
            'retryable_exceptions' => array('wpdb_error')
        ));
        
        return self::retry($operation, $config);
    }
    
    /**
     * Get retry statistics
     *
     * @return array Retry statistics
     */
    public static function get_retry_stats() {
        $error_handler = BSP_Error_Handler::get_instance();
        $errors = $error_handler->get_recent_errors(null, 'retry_manager', 1000);
        
        $stats = array(
            'total_retries' => 0,
            'successful_retries' => 0,
            'failed_operations' => 0,
            'retry_reasons' => array()
        );
        
        foreach ($errors as $error) {
            if (strpos($error['message'], 'Retry attempt') !== false) {
                $stats['total_retries']++;
            } elseif (strpos($error['message'], 'succeeded on attempt') !== false) {
                $stats['successful_retries']++;
            } elseif (strpos($error['message'], 'All') !== false && strpos($error['message'], 'attempts failed') !== false) {
                $stats['failed_operations']++;
            }
            
            // Track retry reasons
            if (isset($error['data']['exception_class'])) {
                $class = $error['data']['exception_class'];
                if (!isset($stats['retry_reasons'][$class])) {
                    $stats['retry_reasons'][$class] = 0;
                }
                $stats['retry_reasons'][$class]++;
            }
        }
        
        if ($stats['total_retries'] > 0) {
            $stats['success_rate'] = round(
                ($stats['successful_retries'] / $stats['total_retries']) * 100,
                2
            );
        } else {
            $stats['success_rate'] = 0;
        }
        
        return $stats;
    }
}
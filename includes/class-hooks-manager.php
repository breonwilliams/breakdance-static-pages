<?php
/**
 * Hooks Manager Class
 *
 * Centralizes all plugin hooks and filters for better extensibility.
 *
 * @package    Breakdance_Static_Pages
 * @subpackage Breakdance_Static_Pages/includes
 * @author     Your Name <email@example.com>
 * @since      1.3.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks Manager Class
 *
 * Provides centralized hook and filter management with documentation
 * of all available extension points.
 *
 * @since      1.3.0
 * @package    Breakdance_Static_Pages
 * @subpackage Breakdance_Static_Pages/includes
 */
class BSP_Hooks_Manager {

	/**
	 * Initialize all hooks and filters
	 *
	 * @since 1.3.0
	 */
	public static function init() {
		// Add default filters.
		self::add_default_filters();
	}

	/**
	 * Add default filters
	 *
	 * @since 1.3.0
	 */
	private static function add_default_filters() {
		// File generation filters.
		add_filter( 'bsp_should_generate_static', array( __CLASS__, 'default_should_generate' ), 10, 2 );
		add_filter( 'bsp_static_file_content', array( __CLASS__, 'default_file_content' ), 10, 3 );
		add_filter( 'bsp_static_file_path', array( __CLASS__, 'default_file_path' ), 10, 2 );

		// Performance filters.
		add_filter( 'bsp_memory_limit', array( __CLASS__, 'default_memory_limit' ) );
		add_filter( 'bsp_time_limit', array( __CLASS__, 'default_time_limit' ) );

		// Queue filters.
		add_filter( 'bsp_queue_batch_size', array( __CLASS__, 'default_batch_size' ) );
		add_filter( 'bsp_queue_priority', array( __CLASS__, 'default_queue_priority' ), 10, 2 );

		// Cache filters.
		add_filter( 'bsp_cache_duration', array( __CLASS__, 'default_cache_duration' ), 10, 2 );
		add_filter( 'bsp_should_cache', array( __CLASS__, 'default_should_cache' ), 10, 2 );
	}

	/**
	 * Default filter: Should generate static file
	 *
	 * @since  1.3.0
	 * @param  bool   $should_generate Whether to generate.
	 * @param  int    $post_id        Post ID.
	 * @return bool
	 */
	public static function default_should_generate( $should_generate, $post_id ) {
		// Don't generate for password protected posts.
		if ( post_password_required( $post_id ) ) {
			return false;
		}

		// Don't generate for private posts.
		$post = get_post( $post_id );
		if ( $post && 'private' === $post->post_status ) {
			return false;
		}

		return $should_generate;
	}

	/**
	 * Default filter: Static file content
	 *
	 * @since  1.3.0
	 * @param  string $content HTML content.
	 * @param  int    $post_id Post ID.
	 * @param  string $url     Page URL.
	 * @return string
	 */
	public static function default_file_content( $content, $post_id, $url ) {
		// Add generation meta tag.
		$meta_tag = sprintf(
			'<meta name="generator" content="Breakdance Static Pages %s" />',
			BSP_VERSION
		);

		$content = str_replace( '</head>', $meta_tag . "\n</head>", $content );

		return $content;
	}

	/**
	 * Default filter: Static file path
	 *
	 * @since  1.3.0
	 * @param  string $path    File path.
	 * @param  int    $post_id Post ID.
	 * @return string
	 */
	public static function default_file_path( $path, $post_id ) {
		// Allow custom directory structure.
		$post = get_post( $post_id );
		if ( $post && 'page' === $post->post_type && $post->post_parent ) {
			// Create nested directories for child pages.
			$upload_dir = wp_upload_dir();
			$base_dir   = $upload_dir['basedir'] . '/breakdance-static-pages/pages/';
			$ancestors  = get_post_ancestors( $post_id );

			if ( ! empty( $ancestors ) ) {
				$path_parts = array();
				foreach ( array_reverse( $ancestors ) as $ancestor_id ) {
					$ancestor = get_post( $ancestor_id );
					if ( $ancestor ) {
						$path_parts[] = $ancestor->post_name;
					}
				}
				$path_parts[] = $post->post_name . '.html';
				$path = $base_dir . implode( '/', $path_parts );
			}
		}

		return $path;
	}

	/**
	 * Default filter: Memory limit
	 *
	 * @since  1.3.0
	 * @param  string $limit Memory limit.
	 * @return string
	 */
	public static function default_memory_limit( $limit ) {
		return '256M';
	}

	/**
	 * Default filter: Time limit
	 *
	 * @since  1.3.0
	 * @param  int $limit Time limit in seconds.
	 * @return int
	 */
	public static function default_time_limit( $limit ) {
		return 300; // 5 minutes.
	}

	/**
	 * Default filter: Queue batch size
	 *
	 * @since  1.3.0
	 * @param  int $size Batch size.
	 * @return int
	 */
	public static function default_batch_size( $size ) {
		// Adjust based on server capacity.
		if ( defined( 'WP_MEMORY_LIMIT' ) ) {
			$memory = wp_convert_hr_to_bytes( WP_MEMORY_LIMIT );
			if ( $memory < 134217728 ) { // Less than 128MB.
				return 3;
			} elseif ( $memory < 268435456 ) { // Less than 256MB.
				return 5;
			}
		}

		return 10;
	}

	/**
	 * Default filter: Queue priority
	 *
	 * @since  1.3.0
	 * @param  int    $priority Queue priority.
	 * @param  string $action   Action type.
	 * @return int
	 */
	public static function default_queue_priority( $priority, $action ) {
		// Higher priority for regeneration.
		if ( 'regenerate' === $action ) {
			return 5;
		}

		return $priority;
	}

	/**
	 * Default filter: Cache duration
	 *
	 * @since  1.3.0
	 * @param  int    $duration Duration in seconds.
	 * @param  string $key      Cache key.
	 * @return int
	 */
	public static function default_cache_duration( $duration, $key ) {
		// Shorter duration for stats.
		if ( 0 === strpos( $key, 'bsp_stats_' ) ) {
			return 300; // 5 minutes.
		}

		return $duration;
	}

	/**
	 * Default filter: Should cache
	 *
	 * @since  1.3.0
	 * @param  bool   $should_cache Whether to cache.
	 * @param  string $key          Cache key.
	 * @return bool
	 */
	public static function default_should_cache( $should_cache, $key ) {
		// Don't cache in development.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return false;
		}

		return $should_cache;
	}

	/**
	 * Document available hooks
	 *
	 * This method doesn't execute any code but serves as documentation
	 * for all available hooks and filters in the plugin.
	 *
	 * @since 1.3.0
	 */
	private static function document_hooks() {
		/**
		 * Action Hooks Documentation
		 *
		 * @since 1.3.0
		 *
		 * Available action hooks:
		 *
		 * - bsp_before_generate_static( $post_id )
		 * - bsp_after_generate_static( $post_id, $file_path, $success )
		 * - bsp_before_delete_static( $post_id )
		 * - bsp_after_delete_static( $post_id, $success )
		 * - bsp_queue_processing_start( $items )
		 * - bsp_queue_processing_complete( $results )
		 * - bsp_error_logged( $error )
		 * - bsp_health_check_complete( $health_data )
		 * - bsp_activated()
		 * - bsp_deactivated()
		 * - bsp_content_updated( $post_id )
		 *
		 * Available filter hooks:
		 *
		 * - bsp_should_generate_static( $should_generate, $post_id )
		 * - bsp_static_file_content( $content, $post_id, $url )
		 * - bsp_static_file_path( $path, $post_id )
		 * - bsp_static_file_url( $url, $post_id )
		 * - bsp_static_file_max_age( $max_age )
		 * - bsp_should_serve_static( $should_serve, $post_id )
		 * - bsp_allowed_post_types( $post_types )
		 * - bsp_memory_limit( $limit )
		 * - bsp_time_limit( $limit )
		 * - bsp_queue_batch_size( $size )
		 * - bsp_queue_priority( $priority, $action )
		 * - bsp_retry_config( $config )
		 * - bsp_health_checks( $checks )
		 * - bsp_rest_authentication( $authenticated, $request )
		 * - bsp_error_notification_recipients( $recipients, $error )
		 * - bsp_send_error_notification( $send, $error )
		 * - bsp_cache_duration( $duration, $key )
		 * - bsp_should_cache( $should_cache, $key )
		 * - bsp_regeneration_delay( $delay, $post_id )
		 */
	}

	/**
	 * Get all registered hooks
	 *
	 * @since  1.3.0
	 * @return array Array of hooks with documentation.
	 */
	public static function get_hooks_documentation() {
		return array(
			'actions' => array(
				'bsp_before_generate_static' => array(
					'description' => 'Fires before static page generation starts',
					'params' => array( 'post_id' ),
					'since' => '1.0.0',
				),
				'bsp_after_generate_static' => array(
					'description' => 'Fires after static page generation completes',
					'params' => array( 'post_id', 'file_path', 'success' ),
					'since' => '1.0.0',
				),
				'bsp_before_delete_static' => array(
					'description' => 'Fires before static file deletion',
					'params' => array( 'post_id' ),
					'since' => '1.0.0',
				),
				'bsp_after_delete_static' => array(
					'description' => 'Fires after static file deletion',
					'params' => array( 'post_id', 'success' ),
					'since' => '1.0.0',
				),
				'bsp_queue_processing_start' => array(
					'description' => 'Fires when queue processing starts',
					'params' => array( 'items' ),
					'since' => '1.2.0',
				),
				'bsp_queue_processing_complete' => array(
					'description' => 'Fires when queue processing completes',
					'params' => array( 'results' ),
					'since' => '1.2.0',
				),
				'bsp_error_logged' => array(
					'description' => 'Fires when an error is logged',
					'params' => array( 'error' ),
					'since' => '1.1.0',
				),
				'bsp_health_check_complete' => array(
					'description' => 'Fires after health check completes',
					'params' => array( 'health_data' ),
					'since' => '1.1.0',
				),
			),
			'filters' => array(
				'bsp_should_generate_static' => array(
					'description' => 'Filter whether to generate static file for a post',
					'params' => array( 'should_generate', 'post_id' ),
					'since' => '1.0.0',
				),
				'bsp_static_file_content' => array(
					'description' => 'Filter static file content before saving',
					'params' => array( 'content', 'post_id', 'url' ),
					'since' => '1.0.0',
				),
				'bsp_static_file_path' => array(
					'description' => 'Filter static file path',
					'params' => array( 'path', 'post_id' ),
					'since' => '1.0.0',
				),
				'bsp_allowed_post_types' => array(
					'description' => 'Filter allowed post types for static generation',
					'params' => array( 'post_types' ),
					'since' => '1.0.0',
				),
				'bsp_memory_limit' => array(
					'description' => 'Filter memory limit for operations',
					'params' => array( 'limit' ),
					'since' => '1.0.0',
				),
				'bsp_time_limit' => array(
					'description' => 'Filter time limit for operations',
					'params' => array( 'limit' ),
					'since' => '1.0.0',
				),
				'bsp_queue_batch_size' => array(
					'description' => 'Filter queue batch size',
					'params' => array( 'size' ),
					'since' => '1.2.0',
				),
				'bsp_queue_priority' => array(
					'description' => 'Filter queue item priority',
					'params' => array( 'priority', 'action' ),
					'since' => '1.2.0',
				),
				'bsp_retry_config' => array(
					'description' => 'Filter retry configuration',
					'params' => array( 'config' ),
					'since' => '1.1.0',
				),
				'bsp_health_checks' => array(
					'description' => 'Filter health check items',
					'params' => array( 'checks' ),
					'since' => '1.1.0',
				),
				'bsp_rest_authentication' => array(
					'description' => 'Filter REST API authentication',
					'params' => array( 'authenticated', 'request' ),
					'since' => '1.2.0',
				),
			),
		);
	}
}
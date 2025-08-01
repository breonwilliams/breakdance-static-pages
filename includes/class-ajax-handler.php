<?php
/**
 * AJAX Handler Class
 *
 * Handles all AJAX requests for the plugin.
 *
 * @package    Breakdance_Static_Pages
 * @subpackage Breakdance_Static_Pages/includes
 * @author     Your Name <email@example.com>
 * @since      1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX Handler Class
 *
 * Manages all AJAX endpoints for the plugin, including page generation,
 * deletion, statistics, and maintenance operations.
 *
 * @since      1.0.0
 * @package    Breakdance_Static_Pages
 * @subpackage Breakdance_Static_Pages/includes
 */
class BSP_Ajax_Handler {

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		// AJAX actions for logged-in users.
		add_action( 'wp_ajax_bsp_generate_single', array( $this, 'handle_generate_single' ) );
		add_action( 'wp_ajax_bsp_generate_multiple', array( $this, 'handle_generate_multiple' ) );
		add_action( 'wp_ajax_bsp_delete_single', array( $this, 'handle_delete_single' ) );
		add_action( 'wp_ajax_bsp_delete_multiple', array( $this, 'handle_delete_multiple' ) );
		add_action( 'wp_ajax_bsp_toggle_static', array( $this, 'handle_toggle_static' ) );
		add_action( 'wp_ajax_bsp_get_stats', array( $this, 'handle_get_stats' ) );
		add_action( 'wp_ajax_bsp_serve_static', array( $this, 'serve_static_file' ) );
		add_action( 'wp_ajax_nopriv_bsp_serve_static', array( $this, 'serve_static_file_nopriv' ) );

		// Maintenance actions.
		add_action( 'wp_ajax_bsp_cleanup_orphaned', array( $this, 'handle_cleanup_orphaned' ) );
		add_action( 'wp_ajax_bsp_clear_all_locks', array( $this, 'handle_clear_all_locks' ) );
		add_action( 'wp_ajax_bsp_delete_all_static', array( $this, 'handle_delete_all_static' ) );

		// Error handling actions.
		add_action( 'wp_ajax_bsp_clear_errors', array( $this, 'handle_clear_errors' ) );
		add_action( 'wp_ajax_bsp_export_errors', array( $this, 'handle_export_errors' ) );

		// Queue management actions.
		add_action( 'wp_ajax_bsp_retry_failed_queue', array( $this, 'handle_retry_failed_queue' ) );
		add_action( 'wp_ajax_bsp_clear_completed_queue', array( $this, 'handle_clear_completed_queue' ) );
		add_action( 'wp_ajax_bsp_clear_all_queue', array( $this, 'handle_clear_all_queue' ) );

		// Data repair actions.
		add_action( 'wp_ajax_bsp_run_data_repair', array( $this, 'handle_data_repair' ) );
	}

	/**
	 * Handle single page generation
	 *
	 * @since 1.0.0
	 */
	public function handle_generate_single() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		// Sanitize and validate post ID.
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post_id = BSP_Security_Helper::sanitize_post_id( $post_id );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array(
				'message' => $post_id->get_error_message(),
			) );
		}

		try {
			// Use atomic operations for single page generation.
			$result = BSP_Atomic_Operations::generate_with_rollback( $post_id );

			if ( $result['success'] ) {
				// Invalidate stats cache after successful generation.
				BSP_Stats_Cache::invalidate();

				$file_size      = get_post_meta( $post_id, '_bsp_static_file_size', true );
				$generated_time = get_post_meta( $post_id, '_bsp_static_generated', true );

				wp_send_json_success( array(
					'message'        => __( 'Static file generated successfully!', 'breakdance-static-pages' ),
					'post_id'        => $post_id,
					'file_size'      => $file_size ? size_format( $file_size ) : '',
					'generated_time' => $generated_time ? sprintf(
						/* translators: %s: human-readable time difference */
						__( '%s ago', 'breakdance-static-pages' ),
						human_time_diff( strtotime( $generated_time ), current_time( 'timestamp' ) )
					) : '',
					'static_url'     => Breakdance_Static_Pages::get_static_file_url( $post_id ),
				) );
			} else {
				wp_send_json_error( array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Failed to generate static file: %s', 'breakdance-static-pages' ),
						$result['error']
					),
				) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Error: %s', 'breakdance-static-pages' ),
					$e->getMessage()
				),
			) );
		}
	}

	/**
	 * Handle multiple page generation
	 *
	 * @since 1.0.0
	 */
	public function handle_generate_multiple() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		// Sanitize post IDs.
		$post_ids = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ? array_map( 'absint', $_POST['post_ids'] ) : array();
		$post_ids = BSP_Security_Helper::sanitize_post_ids( $post_ids );

		if ( is_wp_error( $post_ids ) ) {
			wp_send_json_error( array(
				'message' => $post_ids->get_error_message(),
			) );
		}

		try {
			// Set reasonable time limit for bulk operations (30 seconds max per request).
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 30 );
			}

			// Initialize progress tracking.
			$progress_tracker = BSP_Progress_Tracker::get_instance();
			$session_id = $progress_tracker->start_progress( 'bulk_generation', count( $post_ids ), array(
				'post_ids' => $post_ids,
				'user_id'  => get_current_user_id(),
			) );

			// Use atomic bulk operations with progress tracking.
			$result = BSP_Atomic_Operations::bulk_operation_atomic( $post_ids, 'generate', $session_id );

			// Mark progress as complete.
			$progress_tracker->complete_progress( $session_id );

			// Check if operation was interrupted due to memory/time limits
			$total_processed = $result['success_count'] + $result['failure_count'];
			$remaining = count($post_ids) - $total_processed;
			
			if ($remaining > 0) {
				$message = sprintf(
					/* translators: 1: number of successful operations, 2: number of errors, 3: number remaining */
					__( 'Partial bulk generation: %1$d successful, %2$d errors. %3$d items remaining due to resource limits.', 'breakdance-static-pages' ),
					$result['success_count'],
					$result['failure_count'],
					$remaining
				);
			} else {
				$message = sprintf(
					/* translators: 1: number of successful operations, 2: number of errors */
					__( 'Bulk generation completed. %1$d successful, %2$d errors.', 'breakdance-static-pages' ),
					$result['success_count'],
					$result['failure_count']
				);
			}
			
			wp_send_json_success( array(
				'message'       => $message,
				'session_id'    => $session_id,
				'results'       => $result['completed'],
				'failed'        => $result['failed'],
				'success_count' => $result['success_count'],
				'error_count'   => $result['failure_count'],
				'remaining'     => $remaining,
				'partial'       => $remaining > 0,
			) );
		} catch ( Exception $e ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Error: %s', 'breakdance-static-pages' ),
					$e->getMessage()
				),
			) );
		}
	}

	/**
	 * Handle single page deletion
	 *
	 * @since 1.0.0
	 */
	public function handle_delete_single() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		// Sanitize and validate post ID.
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post_id = BSP_Security_Helper::sanitize_post_id( $post_id );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array(
				'message' => $post_id->get_error_message(),
			) );
		}

		try {
			// Use atomic operations for single page deletion.
			$result = BSP_Atomic_Operations::delete_with_rollback( $post_id );

			if ( $result['success'] ) {
				wp_send_json_success( array(
					'message' => __( 'Static file deleted successfully!', 'breakdance-static-pages' ),
					'post_id' => $post_id,
				) );
			} else {
				wp_send_json_error( array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Failed to delete static file: %s', 'breakdance-static-pages' ),
						$result['error']
					),
				) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Error: %s', 'breakdance-static-pages' ),
					$e->getMessage()
				),
			) );
		}
	}

	/**
	 * Handle multiple page deletion
	 *
	 * @since 1.0.0
	 */
	public function handle_delete_multiple() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		// Sanitize post IDs.
		$post_ids = isset( $_POST['post_ids'] ) && is_array( $_POST['post_ids'] ) ? array_map( 'absint', $_POST['post_ids'] ) : array();
		$post_ids = BSP_Security_Helper::sanitize_post_ids( $post_ids );

		if ( is_wp_error( $post_ids ) ) {
			wp_send_json_error( array(
				'message' => $post_ids->get_error_message(),
			) );
		}

		try {
			// Initialize progress tracking.
			$progress_tracker = BSP_Progress_Tracker::get_instance();
			$session_id = $progress_tracker->start_progress( 'bulk_deletion', count( $post_ids ), array(
				'post_ids' => $post_ids,
				'user_id'  => get_current_user_id(),
			) );

			// Use atomic bulk operations with progress tracking.
			$result = BSP_Atomic_Operations::bulk_operation_atomic( $post_ids, 'delete', $session_id );

			// Mark progress as complete.
			$progress_tracker->complete_progress( $session_id );

			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: 1: number of successful operations, 2: number of errors */
					__( 'Bulk deletion completed. %1$d successful, %2$d errors.', 'breakdance-static-pages' ),
					$result['success_count'],
					$result['failure_count']
				),
				'session_id'    => $session_id,
				'success_count' => $result['success_count'],
				'error_count'   => $result['failure_count'],
			) );
		} catch ( Exception $e ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Error: %s', 'breakdance-static-pages' ),
					$e->getMessage()
				),
			) );
		}
	}

	/**
	 * Handle toggle static generation for a page
	 *
	 * @since 1.0.0
	 */
	public function handle_toggle_static() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		// Sanitize and validate post ID.
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post_id = BSP_Security_Helper::sanitize_post_id( $post_id );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array(
				'message' => $post_id->get_error_message(),
			) );
		}

		// Sanitize enabled status.
		$enabled = isset( $_POST['enabled'] ) && 'true' === $_POST['enabled'];

		try {
			// Update the meta value.
			update_post_meta( $post_id, '_bsp_static_enabled', $enabled ? '1' : '' );

			// If disabled, delete the static file.
			if ( ! $enabled ) {
				BSP_Atomic_Operations::delete_with_rollback( $post_id );
			}

			wp_send_json_success( array(
				'message' => $enabled ?
					__( 'Static generation enabled for this page', 'breakdance-static-pages' ) :
					__( 'Static generation disabled for this page', 'breakdance-static-pages' ),
				'post_id' => $post_id,
				'enabled' => $enabled,
			) );
		} catch ( Exception $e ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Error: %s', 'breakdance-static-pages' ),
					$e->getMessage()
				),
			) );
		}
	}

	/**
	 * Get plugin statistics
	 *
	 * @since 1.0.0
	 */
	public function handle_get_stats() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		try {
			// Force refresh stats to get real-time data
			$stats = BSP_Stats_Cache::get_stats( true );

			// Format the response to match what the JavaScript expects
			$response = array(
				'total_pages' => $stats['total_pages'],
				'enabled_pages' => $stats['static_enabled'],
				'generated_pages' => $stats['static_generated'],
				'total_size' => $stats['total_size'],
				'success_rate' => $stats['success_rate'],
				'performance' => $stats['performance'],
			);

			wp_send_json_success( $response );
		} catch ( Exception $e ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Error: %s', 'breakdance-static-pages' ),
					$e->getMessage()
				),
			) );
		}
	}

	/**
	 * Serve static file to logged-in admins only
	 *
	 * @since 1.0.0
	 */
	public function serve_static_file() {
		// Check if user is logged in and has admin capabilities.
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'Access denied. Static files are only accessible to administrators.', 'breakdance-static-pages' ),
				esc_html__( 'Access Denied', 'breakdance-static-pages' ),
				array( 'response' => 403 )
			);
		}

		$this->serve_static_file_content();
	}

	/**
	 * Handle non-privileged requests (deny access)
	 *
	 * @since 1.0.0
	 */
	public function serve_static_file_nopriv() {
		wp_die(
			esc_html__( 'Access denied. Static files are only accessible to administrators to prevent SEO duplicate content issues.', 'breakdance-static-pages' ),
			esc_html__( 'Access Denied', 'breakdance-static-pages' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Actually serve the static file content
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function serve_static_file_content() {
		$file = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';

		if ( empty( $file ) ) {
			wp_die(
				esc_html__( 'No file specified.', 'breakdance-static-pages' ),
				esc_html__( 'Bad Request', 'breakdance-static-pages' ),
				array( 'response' => 400 )
			);
		}

		// Security: Only allow files from the pages directory.
		if ( false !== strpos( $file, '..' ) || 0 !== strpos( $file, 'pages/' ) ) {
			wp_die(
				esc_html__( 'Invalid file path.', 'breakdance-static-pages' ),
				esc_html__( 'Bad Request', 'breakdance-static-pages' ),
				array( 'response' => 400 )
			);
		}

		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['basedir'] . '/breakdance-static-pages/' . $file;

		// Check if file exists first before validating path.
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			wp_die(
				esc_html__( 'File not found.', 'breakdance-static-pages' ),
				esc_html__( 'Not Found', 'breakdance-static-pages' ),
				array( 'response' => 404 )
			);
		}

		// Security: Ensure it's an HTML file.
		if ( 'html' !== pathinfo( $file_path, PATHINFO_EXTENSION ) ) {
			wp_die(
				esc_html__( 'Invalid file type.', 'breakdance-static-pages' ),
				esc_html__( 'Bad Request', 'breakdance-static-pages' ),
				array( 'response' => 400 )
			);
		}

		// Set appropriate headers.
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Robots-Tag: noindex, nofollow' ); // Prevent search engine indexing.

		// Add admin notice to the HTML.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $file_path );
		$admin_notice = '
		<div style="position: fixed; top: 0; left: 0; right: 0; background: #d63638; color: white; padding: 10px; text-align: center; z-index: 999999; font-family: Arial, sans-serif; font-size: 14px;">
			<strong>' . esc_html__( 'ADMIN PREVIEW:', 'breakdance-static-pages' ) . '</strong> ' .
			esc_html__( 'This is a static file preview only accessible to administrators. Public users see the original dynamic page to prevent SEO issues.', 'breakdance-static-pages' ) . '
		</div>
		<style>body { margin-top: 50px !important; }</style>';

		$content = str_replace( '<body', $admin_notice . '<body', $content );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML content.
		echo $content;
		exit;
	}

	/**
	 * Handle cleanup orphaned files
	 *
	 * @since 1.0.0
	 */
	public function handle_cleanup_orphaned() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		$cache_manager = new BSP_Cache_Manager();
		$cleaned       = $cache_manager->cleanup_orphaned_files();

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of orphaned files */
				__( 'Cleaned up %d orphaned files', 'breakdance-static-pages' ),
				$cleaned
			),
		) );
	}

	/**
	 * Handle clear all locks
	 *
	 * @since 1.0.0
	 */
	public function handle_clear_all_locks() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		$lock_manager = BSP_File_Lock_Manager::get_instance();
		$cleared      = $lock_manager->force_release_all_locks();

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of locks */
				__( 'Cleared %d locks', 'breakdance-static-pages' ),
				$cleared
			),
		) );
	}

	/**
	 * Handle delete all static files
	 *
	 * @since 1.0.0
	 */
	public function handle_delete_all_static() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		global $wpdb;

		// Get all posts with static files.
		$posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_bsp_static_generated'
			)
		);

		$deleted = 0;

		foreach ( $posts as $post_id ) {
			$file_path = Breakdance_Static_Pages::get_static_file_path( $post_id );

			if ( file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
				if ( ! file_exists( $file_path ) ) {
					$deleted++;

					// Clean up metadata.
					delete_post_meta( $post_id, '_bsp_static_generated' );
					delete_post_meta( $post_id, '_bsp_static_file_size' );
					delete_post_meta( $post_id, '_bsp_static_etag' );
					delete_post_meta( $post_id, '_bsp_static_etag_time' );
				}
			}
		}

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of deleted files */
				__( 'Deleted %d static files', 'breakdance-static-pages' ),
				$deleted
			),
		) );
	}

	/**
	 * Handle clear errors
	 *
	 * @since 1.0.0
	 */
	public function handle_clear_errors() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		$error_handler = BSP_Error_Handler::get_instance();
		$error_handler->clear_errors();

		wp_send_json_success( array(
			'message' => __( 'All errors cleared successfully', 'breakdance-static-pages' ),
		) );
	}

	/**
	 * Handle export errors
	 *
	 * @since 1.0.0
	 */
	public function handle_export_errors() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		$error_handler = BSP_Error_Handler::get_instance();
		$export_data   = $error_handler->export_errors();

		wp_send_json_success( array(
			'message'  => __( 'Errors exported successfully', 'breakdance-static-pages' ),
			'data'     => $export_data,
			'filename' => 'bsp-errors-' . gmdate( 'Y-m-d-His' ) . '.json',
		) );
	}

	/**
	 * Handle retry failed queue items
	 *
	 * @since 1.2.0
	 */
	public function handle_retry_failed_queue() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		$queue_manager = BSP_Queue_Manager::get_instance();
		$retried       = $queue_manager->retry_failed_items();

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of retried items */
				__( '%d failed items set to retry', 'breakdance-static-pages' ),
				$retried
			),
		) );
	}

	/**
	 * Handle clear completed queue items
	 *
	 * @since 1.2.0
	 */
	public function handle_clear_completed_queue() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		$queue_manager = BSP_Queue_Manager::get_instance();
		$cleared       = $queue_manager->clear_queue( 'completed' );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %d: number of cleared items */
				__( '%d completed items cleared', 'breakdance-static-pages' ),
				$cleared
			),
		) );
	}

	/**
	 * Handle clear all queue items
	 *
	 * @since 1.2.0
	 */
	public function handle_clear_all_queue() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		$queue_manager = BSP_Queue_Manager::get_instance();
		$cleared       = $queue_manager->clear_queue();

		wp_send_json_success( array(
			'message' => __( 'Queue cleared successfully', 'breakdance-static-pages' ),
		) );
	}

	/**
	 * Handle data repair requests
	 *
	 * @since 1.3.3
	 */
	public function handle_data_repair() {
		// Verify nonce and permissions.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$verification = BSP_Security_Helper::verify_ajax_request( $nonce );

		if ( is_wp_error( $verification ) ) {
			wp_send_json_error( array(
				'message' => $verification->get_error_message(),
			) );
		}

		$repair_type = isset( $_POST['repair_type'] ) ? sanitize_text_field( $_POST['repair_type'] ) : '';

		if ( empty( $repair_type ) ) {
			wp_send_json_error( array(
				'message' => __( 'Invalid repair type', 'breakdance-static-pages' ),
			) );
		}

		$results = array();
		$message = '';

		switch ( $repair_type ) {
			case 'all':
				$results = BSP_Data_Repair::run_all_repairs();
				$total_repaired = 0;
				foreach ( $results as $key => $result ) {
					if ( isset( $result['repaired'] ) ) {
						$total_repaired += $result['repaired'];
					} elseif ( isset( $result['fixed'] ) ) {
						$total_repaired += $result['fixed'];
					} elseif ( isset( $result['synced'] ) ) {
						$total_repaired += $result['synced'];
					} elseif ( is_int( $result ) ) {
						$total_repaired += $result;
					}
				}
				$message = sprintf(
					__( 'All repairs completed. %d issues fixed. Stats cache cleared.', 'breakdance-static-pages' ),
					$total_repaired
				);
				break;

			case 'metadata':
				$results = BSP_Data_Repair::repair_missing_metadata();
				$message = sprintf(
					__( 'Metadata repair completed. Checked %d files, repaired %d issues.', 'breakdance-static-pages' ),
					$results['checked'],
					$results['repaired']
				);
				break;

			case 'meta_values':
				$results = BSP_Data_Repair::fix_inconsistent_meta_values();
				$message = sprintf(
					__( 'Meta values fixed. Checked %d records, fixed %d issues.', 'breakdance-static-pages' ),
					$results['checked'],
					$results['fixed']
				);
				break;

			case 'file_sync':
				$results = BSP_Data_Repair::sync_files_with_database();
				$message = sprintf(
					__( 'File sync completed. Checked %d database records, synced %d issues.', 'breakdance-static-pages' ),
					$results['db_checked'],
					$results['synced']
				);
				break;

			case 'orphaned':
				$results = BSP_Data_Repair::clean_orphaned_data();
				$message = sprintf(
					__( 'Orphaned data cleaned. Removed %d metadata records and %d files.', 'breakdance-static-pages' ),
					$results['metadata_cleaned'],
					$results['files_cleaned']
				);
				break;

			default:
				wp_send_json_error( array(
					'message' => __( 'Unknown repair type', 'breakdance-static-pages' ),
				) );
				return;
		}

		// Always invalidate stats cache after repairs
		BSP_Stats_Cache::invalidate();

		wp_send_json_success( array(
			'message' => $message,
			'results' => $results,
		) );
	}
}
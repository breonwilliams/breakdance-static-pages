<?php
/**
 * Static Generator Class
 *
 * Handles the generation of static HTML files from dynamic pages.
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
 * Static Generator Class
 *
 * Manages the conversion of dynamic WordPress pages into static HTML files,
 * including content capture, optimization, and file management.
 *
 * @since      1.0.0
 * @package    Breakdance_Static_Pages
 * @subpackage Breakdance_Static_Pages/includes
 */
class BSP_Static_Generator {

	/**
	 * Generate static HTML for a specific page
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID to generate static page for.
	 * @return array|bool Array with success status and error message, or false on failure.
	 */
	public function generate_static_page( $post_id ) {
		// Sanitize and validate post ID.
		$post_id = BSP_Security_Helper::sanitize_post_id( $post_id );
		if ( is_wp_error( $post_id ) ) {
			return array(
				'success' => false,
				'error'   => $post_id->get_error_message(),
			);
		}

		// Get instances.
		$lock_manager  = BSP_File_Lock_Manager::get_instance();
		$error_handler = BSP_Error_Handler::get_instance();

		// Try to acquire lock.
		if ( ! $lock_manager->acquire_lock( $post_id ) ) {
			$error_handler->log_error(
				'static_generation',
				sprintf( 'Could not acquire lock for post ID: %d - generation already in progress', $post_id ),
				'warning',
				array( 'post_id' => $post_id )
			);
			return array(
				'success' => false,
				'error'   => __( 'Generation already in progress for this page', 'breakdance-static-pages' ),
			);
		}

		try {
			// Track generation start time.
			$start_time = microtime( true );

			// Check memory before starting.
			if ( ! $this->check_memory_usage() ) {
				$lock_manager->release_lock( $post_id );
				$error_handler->log_error(
					'static_generation',
					'Insufficient memory available for static generation',
					'error',
					array(
						'post_id'      => $post_id,
						'memory_limit' => ini_get( 'memory_limit' ),
					)
				);
				return array(
					'success' => false,
					'error'   => __( 'Insufficient memory available for static generation', 'breakdance-static-pages' ),
				);
			}

			$error_handler->log_error(
				'static_generation',
				sprintf( 'Starting static generation for post ID: %d', $post_id ),
				'info',
				array( 'post_id' => $post_id )
			);

			// Get the post.
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				$error_handler->log_error(
					'static_generation',
					sprintf( 'Post not found or not published: %d', $post_id ),
					'error',
					array( 'post_id' => $post_id )
				);
				$lock_manager->release_lock( $post_id );
				return false;
			}

			// Check if static generation is allowed for this post.
			$should_generate = apply_filters( 'bsp_should_generate_static', true, $post_id );
			if ( ! $should_generate ) {
				$lock_manager->release_lock( $post_id );
				return array(
					'success' => false,
					'error'   => __( 'Static generation is not allowed for this page', 'breakdance-static-pages' ),
				);
			}

			// Get the page URL.
			$page_url = get_permalink( $post_id );
			if ( ! $page_url ) {
				$error_handler->log_error(
					'static_generation',
					sprintf( 'Could not get permalink for post: %d', $post_id ),
					'error',
					array( 'post_id' => $post_id )
				);
				$lock_manager->release_lock( $post_id );
				return false;
			}

			/**
			 * Fires before static page generation starts
			 *
			 * @since 1.0.0
			 * @param int $post_id Post ID being generated.
			 */
			do_action( 'bsp_before_generate_static', $post_id );

			// Capture the page HTML.
			$html_content = $this->capture_page_html( $page_url, $post_id );

			if ( ! $html_content ) {
				$error_handler->log_error(
					'static_generation',
					sprintf( 'Failed to capture HTML for post: %d', $post_id ),
					'error',
					array(
						'post_id' => $post_id,
						'url'     => $page_url,
					)
				);
				$lock_manager->release_lock( $post_id );
				return false;
			}

			// Process and optimize the HTML.
			$optimized_html = $this->optimize_html( $html_content, $post_id );

			/**
			 * Filter static file content before saving
			 *
			 * @since 1.0.0
			 * @param string $optimized_html HTML content.
			 * @param int    $post_id       Post ID.
			 * @param string $page_url      Page URL.
			 */
			$optimized_html = apply_filters( 'bsp_static_file_content', $optimized_html, $post_id, $page_url );

			// Save the static file.
			$static_file_path = Breakdance_Static_Pages::get_static_file_path( $post_id );
			$result           = $this->save_static_file( $static_file_path, $optimized_html );

			if ( $result ) {
				// Update metadata.
				update_post_meta( $post_id, '_bsp_static_generated', current_time( 'mysql' ) );
				update_post_meta( $post_id, '_bsp_static_file_size', filesize( $static_file_path ) );

				// Calculate and store ETag for caching.
				$etag = md5_file( $static_file_path );
				update_post_meta( $post_id, '_bsp_static_etag', $etag );
				update_post_meta( $post_id, '_bsp_static_etag_time', time() );

				// Track generation time.
				$generation_time = microtime( true ) - $start_time;
				BSP_Stats_Cache::track_generation_time( $generation_time );

				$error_handler->log_error(
					'static_generation',
					sprintf( 'Static file generated successfully for post %d in %.2f seconds', $post_id, $generation_time ),
					'info',
					array(
						'post_id'         => $post_id,
						'generation_time' => $generation_time,
						'file_size'       => filesize( $static_file_path ),
					)
				);

				/**
				 * Fires after static page generation completes
				 *
				 * @since 1.0.0
				 * @param int    $post_id        Post ID that was generated.
				 * @param string $static_file_path Path to generated file.
				 * @param bool   $success        Whether generation was successful.
				 */
				do_action( 'bsp_after_generate_static', $post_id, $static_file_path, true );

				// Release lock on success.
				$lock_manager->release_lock( $post_id );

				return array(
					'success'         => true,
					'file_path'       => $static_file_path,
					'file_size'       => filesize( $static_file_path ),
					'generation_time' => $generation_time,
				);
			}

			// Release lock on failure.
			$lock_manager->release_lock( $post_id );
			do_action( 'bsp_after_generate_static', $post_id, '', false );
			return false;

		} catch ( Exception $e ) {
			$error_handler->log_error(
				'static_generation',
				'Exception during static generation: ' . $e->getMessage(),
				'critical',
				array( 'post_id' => $post_id ),
				$e
			);
			// Always release lock in case of exception.
			$lock_manager->release_lock( $post_id );
			do_action( 'bsp_after_generate_static', $post_id, '', false );
			return false;
		}
	}

	/**
	 * Capture HTML content from a page URL
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string $url     Page URL to capture.
	 * @param  int    $post_id Post ID for context.
	 * @return string|false HTML content or false on failure.
	 */
	private function capture_page_html( $url, $post_id ) {
		// Method 1: Use WordPress internal request (preferred).
		$html = $this->capture_via_internal_request( $url, $post_id );

		if ( $html ) {
			return $html;
		}

		// Method 2: Fallback to cURL if internal request fails.
		return $this->capture_via_curl( $url );
	}

	/**
	 * Capture HTML using WordPress internal request
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string $url     Page URL to capture.
	 * @param  int    $post_id Post ID for context.
	 * @return string|false HTML content or false on failure.
	 */
	private function capture_via_internal_request( $url, $post_id ) {
		// Temporarily disable static serving to avoid recursion.
		add_filter( 'bsp_disable_static_serving', '__return_true' );

		// Set up the request.
		$args = array(
			'timeout'    => 30,
			'user-agent' => 'BSP Static Generator',
			'headers'    => array(
				'X-BSP-Static-Generation' => '1',
			),
		);

		// Make the request.
		$response = wp_remote_get( $url, $args );

		// Re-enable static serving.
		remove_filter( 'bsp_disable_static_serving', '__return_true' );

		if ( is_wp_error( $response ) ) {
			BSP_Error_Handler::get_instance()->log_error(
				'static_generation',
				'WordPress request failed: ' . $response->get_error_message(),
				'error',
				array(
					'url'     => $url,
					'post_id' => $post_id,
				)
			);
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			BSP_Error_Handler::get_instance()->log_error(
				'static_generation',
				sprintf( 'HTTP error %d for URL: %s', $response_code, $url ),
				'error',
				array(
					'url'           => $url,
					'post_id'       => $post_id,
					'response_code' => $response_code,
				)
			);
			return false;
		}

		$html = wp_remote_retrieve_body( $response );

		if ( empty( $html ) ) {
			BSP_Error_Handler::get_instance()->log_error(
				'static_generation',
				sprintf( 'Empty response body for URL: %s', $url ),
				'error',
				array(
					'url'     => $url,
					'post_id' => $post_id,
				)
			);
			return false;
		}

		return $html;
	}

	/**
	 * Capture HTML using cURL as fallback
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string $url Page URL to capture.
	 * @return string|false HTML content or false on failure.
	 */
	private function capture_via_curl( $url ) {
		if ( ! function_exists( 'curl_init' ) ) {
			BSP_Error_Handler::get_instance()->log_error(
				'static_generation',
				'cURL not available',
				'error'
			);
			return false;
		}

		$ch = curl_init();

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_USERAGENT      => 'BSP Static Generator',
				CURLOPT_HTTPHEADER     => array(
					'X-BSP-Static-Generation: 1',
				),
				CURLOPT_SSL_VERIFYPEER => false,
			)
		);

		$html      = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error     = curl_error( $ch );

		curl_close( $ch );

		if ( $error ) {
			BSP_Error_Handler::get_instance()->log_error(
				'static_generation',
				'cURL error: ' . $error,
				'error'
			);
			return false;
		}

		if ( 200 !== $http_code ) {
			BSP_Error_Handler::get_instance()->log_error(
				'static_generation',
				sprintf( 'HTTP error %d for URL: %s', $http_code, $url ),
				'error'
			);
			return false;
		}

		return $html;
	}

	/**
	 * Optimize HTML for static serving
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string $html    HTML content to optimize.
	 * @param  int    $post_id Post ID for context.
	 * @return string Optimized HTML content.
	 */
	private function optimize_html( $html, $post_id ) {
		// Remove WordPress admin bar if present.
		$html = preg_replace( '/<div[^>]*id="wpadminbar"[^>]*>.*?<\/div>/s', '', $html );

		// Remove edit links and admin elements.
		$html = preg_replace( '/<span[^>]*class="[^"]*edit-link[^"]*"[^>]*>.*?<\/span>/s', '', $html );

		// Add static generation comment.
		$comment = sprintf(
			"\n<!-- Generated by Breakdance Static Pages on %s -->\n",
			current_time( 'mysql' )
		);
		$html = str_replace( '</head>', $comment . '</head>', $html );

		// Optimize CSS and JS (basic optimization).
		$html = $this->optimize_assets( $html, $post_id );

		// Add cache headers meta tag.
		$cache_meta = sprintf(
			'<meta name="bsp-static-cache" content="%d">' . "\n",
			time()
		);
		$html = str_replace( '</head>', $cache_meta . '</head>', $html );

		return $html;
	}

	/**
	 * Optimize CSS and JS assets
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string $html    HTML content to optimize.
	 * @param  int    $post_id Post ID for context.
	 * @return string Optimized HTML content.
	 */
	private function optimize_assets( $html, $post_id ) {
		// For now, just ensure all URLs are absolute.
		$site_url = home_url();

		// Convert relative URLs to absolute.
		$html = preg_replace( '/href="\/([^"]*)"/', 'href="' . $site_url . '/$1"', $html );
		$html = preg_replace( '/src="\/([^"]*)"/', 'src="' . $site_url . '/$1"', $html );

		// Future: Could implement CSS/JS minification here.

		return $html;
	}

	/**
	 * Save static HTML file with atomic operation
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string $file_path    Path to save the file.
	 * @param  string $html_content HTML content to save.
	 * @return bool True on success, false on failure.
	 */
	private function save_static_file( $file_path, $html_content ) {
		// Ensure directory exists first.
		$dir = dirname( $file_path );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Validate file path.
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'] . '/breakdance-static-pages/';
		
		// Simple path validation without realpath since directory might be new.
		if ( 0 !== strpos( wp_normalize_path( $file_path ), wp_normalize_path( $base_dir ) ) ) {
			BSP_Error_Handler::get_instance()->log_error(
				'static_generation',
				'Invalid file path: Path is outside allowed directory',
				'error',
				array( 'file_path' => $file_path )
			);
			return false;
		}

		// Use temporary file for atomic write.
		$temp_file = $file_path . '.tmp.' . uniqid();

		// Save to temporary file first.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = file_put_contents( $temp_file, $html_content, LOCK_EX );

		if ( false === $result ) {
			BSP_Error_Handler::get_instance()->log_error(
				'static_generation',
				sprintf( 'Failed to save temporary file: %s', $temp_file ),
				'error'
			);
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
			return false;
		}

		// Set appropriate permissions on temp file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		@chmod( $temp_file, 0644 );

		// Atomically move temp file to final location.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $temp_file, $file_path ) ) {
			BSP_Error_Handler::get_instance()->log_error(
				'static_generation',
				sprintf( 'Failed to move temp file to final location: %s', $file_path ),
				'error'
			);
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}
			return false;
		}

		return true;
	}

	/**
	 * Generate static files for multiple pages
	 *
	 * @since  1.0.0
	 * @param  array $post_ids Array of post IDs to generate.
	 * @return array Results keyed by post ID.
	 */
	public function generate_multiple_pages( $post_ids ) {
		// Sanitize post IDs.
		$post_ids = BSP_Security_Helper::sanitize_post_ids( $post_ids );
		if ( is_wp_error( $post_ids ) ) {
			return array();
		}

		$results = array();

		foreach ( $post_ids as $post_id ) {
			$results[ $post_id ] = $this->generate_static_page( $post_id );

			// Small delay to prevent server overload.
			usleep( 100000 ); // 0.1 seconds.
		}

		return $results;
	}

	/**
	 * Delete static file for a page
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID to delete static file for.
	 * @return bool True on success, false on failure.
	 */
	public function delete_static_page( $post_id ) {
		// Sanitize post ID.
		$post_id = BSP_Security_Helper::sanitize_post_id( $post_id );
		if ( is_wp_error( $post_id ) ) {
			return false;
		}

		/**
		 * Fires before static file deletion
		 *
		 * @since 1.0.0
		 * @param int $post_id Post ID being deleted.
		 */
		do_action( 'bsp_before_delete_static', $post_id );

		$static_file_path = Breakdance_Static_Pages::get_static_file_path( $post_id );

		if ( file_exists( $static_file_path ) ) {
			$result = wp_delete_file( $static_file_path );

			if ( $result || ! file_exists( $static_file_path ) ) {
				delete_post_meta( $post_id, '_bsp_static_generated' );
				delete_post_meta( $post_id, '_bsp_static_file_size' );
				delete_post_meta( $post_id, '_bsp_static_etag' );
				delete_post_meta( $post_id, '_bsp_static_etag_time' );

				/**
				 * Fires after static file deletion
				 *
				 * @since 1.0.0
				 * @param int  $post_id Post ID that was deleted.
				 * @param bool $success Whether deletion was successful.
				 */
				do_action( 'bsp_after_delete_static', $post_id, true );

				return true;
			}
		}

		do_action( 'bsp_after_delete_static', $post_id, false );
		return false;
	}

	/**
	 * Check if page content has changed since last generation
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID to check.
	 * @return bool True if content has changed, false otherwise.
	 */
	public function has_content_changed( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return true;
		}

		$last_generated = get_post_meta( $post_id, '_bsp_static_generated', true );
		if ( ! $last_generated ) {
			return true;
		}

		$last_generated_time = strtotime( $last_generated );
		$post_modified_time  = strtotime( $post->post_modified );

		// Check if post was modified after static generation.
		if ( $post_modified_time > $last_generated_time ) {
			return true;
		}

		// Check if ACF fields were modified (if ACF is active).
		if ( function_exists( 'get_fields' ) ) {
			// This is a simplified check - in practice, you might want to store
			// a hash of ACF field values and compare.
			return true;
		}

		return false;
	}

	/**
	 * Check memory usage before operation
	 *
	 * @since  1.0.0
	 * @access private
	 * @return bool True if enough memory is available.
	 */
	private function check_memory_usage() {
		$memory_limit     = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$memory_usage     = memory_get_usage( true );
		$memory_available = $memory_limit - $memory_usage;

		// Require at least 50MB free memory.
		$required_memory = 50 * MB_IN_BYTES;

		if ( $memory_available < $required_memory ) {
			BSP_Error_Handler::get_instance()->log_error(
				'static_generation',
				sprintf(
					'Low memory warning - Available: %s, Required: %s',
					size_format( $memory_available ),
					size_format( $required_memory )
				),
				'warning'
			);
			return false;
		}

		return true;
	}

	/**
	 * Get generation statistics
	 *
	 * @since  1.0.0
	 * @return array Generation statistics.
	 */
	public function get_generation_stats() {
		global $wpdb;

		$stats = array();

		// Count total pages with static generation enabled.
		$stats['enabled_pages'] = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'_bsp_static_enabled',
				'1'
			)
		);

		// Count pages with generated static files.
		$stats['generated_pages'] = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_bsp_static_generated'
			)
		);

		// Calculate total size of static files.
		$file_sizes = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_bsp_static_file_size'
			)
		);

		$stats['total_size']   = array_sum( $file_sizes );
		$stats['average_size'] = count( $file_sizes ) > 0 ? $stats['total_size'] / count( $file_sizes ) : 0;

		return $stats;
	}
}
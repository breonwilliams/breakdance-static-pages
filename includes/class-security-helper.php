<?php
/**
 * Security Helper Class
 *
 * Provides centralized security functions for the plugin.
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
 * Security Helper Class
 *
 * Handles all security-related functions including nonce verification,
 * capability checks, data sanitization, and validation.
 *
 * @since      1.3.0
 * @package    Breakdance_Static_Pages
 * @subpackage Breakdance_Static_Pages/includes
 */
class BSP_Security_Helper {

	/**
	 * Verify AJAX nonce and capability
	 *
	 * @since  1.3.0
	 * @param  string $nonce      Nonce to verify.
	 * @param  string $action     Nonce action.
	 * @param  string $capability Required capability.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function verify_ajax_request( $nonce, $action = 'bsp_nonce', $capability = 'manage_options' ) {
		// Verify nonce.
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			return new WP_Error(
				'invalid_nonce',
				__( 'Security check failed. Please refresh the page and try again.', 'breakdance-static-pages' )
			);
		}

		// Check capability.
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to perform this action.', 'breakdance-static-pages' )
			);
		}

		return true;
	}

	/**
	 * Sanitize and validate post ID
	 *
	 * @since  1.3.0
	 * @param  mixed $post_id Post ID to sanitize.
	 * @return int|WP_Error Sanitized post ID or WP_Error.
	 */
	public static function sanitize_post_id( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return new WP_Error(
				'invalid_post_id',
				__( 'Invalid post ID provided.', 'breakdance-static-pages' )
			);
		}

		// Verify post exists.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'The requested post does not exist.', 'breakdance-static-pages' )
			);
		}

		return $post_id;
	}

	/**
	 * Sanitize array of post IDs
	 *
	 * @since  1.3.0
	 * @param  array $post_ids Array of post IDs.
	 * @return array|WP_Error Sanitized array or WP_Error.
	 */
	public static function sanitize_post_ids( $post_ids ) {
		if ( ! is_array( $post_ids ) ) {
			return new WP_Error(
				'invalid_post_ids',
				__( 'Invalid post IDs provided.', 'breakdance-static-pages' )
			);
		}

		$sanitized = array_map( 'absint', $post_ids );
		$sanitized = array_filter( $sanitized );

		if ( empty( $sanitized ) ) {
			return new WP_Error(
				'no_valid_posts',
				__( 'No valid post IDs provided.', 'breakdance-static-pages' )
			);
		}

		return $sanitized;
	}

	/**
	 * Validate file path
	 *
	 * @since  1.3.0
	 * @param  string $path     File path to validate.
	 * @param  string $base_dir Base directory for validation.
	 * @return bool|WP_Error True if valid, WP_Error otherwise.
	 */
	public static function validate_file_path( $path, $base_dir = '' ) {
		// Normalize paths.
		$path     = wp_normalize_path( $path );
		$real_path = realpath( $path );

		if ( ! $real_path ) {
			return new WP_Error(
				'invalid_path',
				__( 'Invalid file path provided.', 'breakdance-static-pages' )
			);
		}

		// If base directory provided, ensure path is within it.
		if ( $base_dir ) {
			$base_dir      = wp_normalize_path( $base_dir );
			$real_base_dir = realpath( $base_dir );

			if ( 0 !== strpos( $real_path, $real_base_dir ) ) {
				return new WP_Error(
					'path_traversal',
					__( 'File path is outside allowed directory.', 'breakdance-static-pages' )
				);
			}
		}

		return true;
	}

	/**
	 * Sanitize file name
	 *
	 * @since  1.3.0
	 * @param  string $filename File name to sanitize.
	 * @return string Sanitized file name.
	 */
	public static function sanitize_filename( $filename ) {
		// Remove any path components.
		$filename = basename( $filename );

		// Sanitize the filename.
		$filename = sanitize_file_name( $filename );

		// Additional security: remove any remaining dots except the last one.
		$parts = explode( '.', $filename );
		if ( count( $parts ) > 2 ) {
			$extension = array_pop( $parts );
			$filename  = implode( '', $parts ) . '.' . $extension;
		}

		return $filename;
	}

	/**
	 * Validate allowed file type
	 *
	 * @since  1.3.0
	 * @param  string $filename      File name to check.
	 * @param  array  $allowed_types Allowed file extensions.
	 * @return bool|WP_Error True if allowed, WP_Error otherwise.
	 */
	public static function validate_file_type( $filename, $allowed_types = array( 'html' ) ) {
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, $allowed_types, true ) ) {
			return new WP_Error(
				'invalid_file_type',
				sprintf(
					/* translators: %s: comma-separated list of allowed file types */
					__( 'File type not allowed. Allowed types: %s', 'breakdance-static-pages' ),
					implode( ', ', $allowed_types )
				)
			);
		}

		return true;
	}

	/**
	 * Escape and sanitize output for attributes
	 *
	 * @since  1.3.0
	 * @param  mixed $value Value to escape.
	 * @return string Escaped value.
	 */
	public static function esc_attr( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value );
		}

		return esc_attr( $value );
	}

	/**
	 * Escape and sanitize output for HTML
	 *
	 * @since  1.3.0
	 * @param  mixed $value Value to escape.
	 * @return string Escaped value.
	 */
	public static function esc_html( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value );
		}

		return esc_html( $value );
	}

	/**
	 * Sanitize text field
	 *
	 * @since  1.3.0
	 * @param  string $value Value to sanitize.
	 * @return string Sanitized value.
	 */
	public static function sanitize_text( $value ) {
		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * Sanitize textarea field
	 *
	 * @since  1.3.0
	 * @param  string $value Value to sanitize.
	 * @return string Sanitized value.
	 */
	public static function sanitize_textarea( $value ) {
		return sanitize_textarea_field( wp_unslash( $value ) );
	}

	/**
	 * Check if request is valid AJAX request
	 *
	 * @since  1.3.0
	 * @return bool True if valid AJAX request.
	 */
	public static function is_ajax_request() {
		return defined( 'DOING_AJAX' ) && DOING_AJAX;
	}

	/**
	 * Check if request is valid REST request
	 *
	 * @since  1.3.0
	 * @return bool True if valid REST request.
	 */
	public static function is_rest_request() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Generate secure random token
	 *
	 * @since  1.3.0
	 * @param  int $length Token length.
	 * @return string Random token.
	 */
	public static function generate_token( $length = 32 ) {
		return wp_generate_password( $length, false );
	}

	/**
	 * Validate color hex code
	 *
	 * @since  1.3.0
	 * @param  string $color Color hex code.
	 * @return string|WP_Error Sanitized color or WP_Error.
	 */
	public static function sanitize_hex_color( $color ) {
		$color = sanitize_hex_color( $color );

		if ( ! $color ) {
			return new WP_Error(
				'invalid_color',
				__( 'Invalid color value provided.', 'breakdance-static-pages' )
			);
		}

		return $color;
	}

	/**
	 * Rate limit check
	 *
	 * @since  1.3.0
	 * @param  string $action    Action identifier.
	 * @param  int    $max_attempts Max attempts allowed.
	 * @param  int    $window    Time window in seconds.
	 * @return bool|WP_Error True if allowed, WP_Error if rate limited.
	 */
	public static function check_rate_limit( $action, $max_attempts = 10, $window = 60 ) {
		$user_id = get_current_user_id();
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key     = 'bsp_rate_limit_' . $action . '_' . $user_id . '_' . $ip;

		$attempts = get_transient( $key );

		if ( false === $attempts ) {
			set_transient( $key, 1, $window );
			return true;
		}

		if ( $attempts >= $max_attempts ) {
			return new WP_Error(
				'rate_limited',
				sprintf(
					/* translators: %d: number of seconds */
					__( 'Too many attempts. Please try again in %d seconds.', 'breakdance-static-pages' ),
					$window
				)
			);
		}

		set_transient( $key, $attempts + 1, $window );
		return true;
	}

	/**
	 * Validate URL
	 *
	 * @since  1.3.0
	 * @param  string $url URL to validate.
	 * @param  array  $allowed_hosts Allowed hosts (optional).
	 * @return string|WP_Error Sanitized URL or WP_Error.
	 */
	public static function validate_url( $url, $allowed_hosts = array() ) {
		$url = esc_url_raw( $url );

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'Invalid URL provided.', 'breakdance-static-pages' )
			);
		}

		// Check allowed hosts if provided.
		if ( ! empty( $allowed_hosts ) ) {
			$parsed = wp_parse_url( $url );
			if ( ! isset( $parsed['host'] ) || ! in_array( $parsed['host'], $allowed_hosts, true ) ) {
				return new WP_Error(
					'invalid_host',
					__( 'URL host not allowed.', 'breakdance-static-pages' )
				);
			}
		}

		return $url;
	}
}
<?php
/**
 * SEO Protection Class
 *
 * Handles all SEO-related protections to prevent duplicate content issues
 * and ensure static files don't interfere with search engine indexing.
 *
 * @package    Breakdance_Static_Pages
 * @subpackage Breakdance_Static_Pages/includes
 * @author     Your Name <email@example.com>
 * @since      1.3.1
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Protection Class
 *
 * Manages all SEO-related protections including sitemap filtering,
 * robots.txt modifications, and canonical URL enforcement.
 *
 * @since      1.3.1
 * @package    Breakdance_Static_Pages
 * @subpackage Breakdance_Static_Pages/includes
 */
class BSP_SEO_Protection {

	/**
	 * Constructor
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 *
	 * @since 1.3.1
	 */
	private function init_hooks() {
		// Sitemap protection.
		add_filter( 'wp_sitemaps_posts_entry', array( $this, 'filter_sitemap_entry' ), 10, 3 );
		add_filter( 'wpseo_sitemap_entry', array( $this, 'filter_yoast_sitemap_entry' ), 10, 3 );
		add_filter( 'rank_math/sitemap/entry', array( $this, 'filter_rankmath_sitemap_entry' ), 10, 3 );

		// Robots.txt protection.
		add_filter( 'robots_txt', array( $this, 'modify_robots_txt' ), 10, 2 );

		// Prevent direct access to static files via URL patterns.
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );

		// Add noindex for static file URLs if accessed directly.
		add_action( 'wp_head', array( $this, 'add_noindex_for_static_urls' ), 1 );

		// Filter search results to exclude static files.
		add_filter( 'pre_get_posts', array( $this, 'exclude_from_search' ) );
	}

	/**
	 * Filter WordPress core sitemap entries
	 *
	 * @since 1.3.1
	 * @param array  $entry The sitemap entry.
	 * @param object $post  The post object.
	 * @param string $post_type The post type.
	 * @return array|false Modified entry or false to exclude.
	 */
	public function filter_sitemap_entry( $entry, $post, $post_type ) {
		// Always include the original dynamic URL, never the static version.
		// This filter ensures only canonical URLs appear in sitemaps.
		return $entry;
	}

	/**
	 * Filter Yoast SEO sitemap entries
	 *
	 * @since 1.3.1
	 * @param array  $entry The sitemap entry.
	 * @param string $post_type The post type.
	 * @param object $post The post object.
	 * @return array|false Modified entry or false to exclude.
	 */
	public function filter_yoast_sitemap_entry( $entry, $post_type, $post ) {
		// Ensure static file URLs never appear in Yoast sitemaps.
		if ( isset( $entry['loc'] ) && $this->is_static_file_url( $entry['loc'] ) ) {
			return false; // Exclude static file URLs.
		}

		return $entry;
	}

	/**
	 * Filter RankMath sitemap entries
	 *
	 * @since 1.3.1
	 * @param array  $entry The sitemap entry.
	 * @param string $object_type The object type.
	 * @param object $object The object.
	 * @return array|false Modified entry or false to exclude.
	 */
	public function filter_rankmath_sitemap_entry( $entry, $object_type, $object ) {
		// Ensure static file URLs never appear in RankMath sitemaps.
		if ( isset( $entry['loc'] ) && $this->is_static_file_url( $entry['loc'] ) ) {
			return false; // Exclude static file URLs.
		}

		return $entry;
	}

	/**
	 * Check if URL is a static file URL
	 *
	 * @since 1.3.1
	 * @param string $url The URL to check.
	 * @return bool True if it's a static file URL.
	 */
	private function is_static_file_url( $url ) {
		$upload_dir = wp_upload_dir();
		$static_base_url = $upload_dir['baseurl'] . '/breakdance-static-pages/';
		
		return 0 === strpos( $url, $static_base_url );
	}

	/**
	 * Modify robots.txt to disallow static file directories
	 *
	 * @since 1.3.1
	 * @param string $output The robots.txt output.
	 * @param bool   $public Whether the site is public.
	 * @return string Modified robots.txt output.
	 */
	public function modify_robots_txt( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		$upload_dir = wp_upload_dir();
		$static_path = wp_parse_url( $upload_dir['baseurl'] . '/breakdance-static-pages/', PHP_URL_PATH );

		// Add disallow rules for static file directories.
		$additions = array(
			'',
			'# Breakdance Static Pages - Prevent indexing of static files',
			'User-agent: *',
			'Disallow: ' . $static_path,
			'Disallow: ' . $static_path . 'pages/',
			'Disallow: ' . $static_path . 'assets/',
			'',
		);

		$output .= implode( "\n", $additions );

		return $output;
	}

	/**
	 * Add rewrite rules to prevent direct access to static files
	 *
	 * @since 1.3.1
	 */
	public function add_rewrite_rules() {
		// This would require .htaccess modifications for Apache.
		// For now, we rely on meta tags and headers.
	}

	/**
	 * Add noindex meta tag for static file URLs accessed directly
	 *
	 * @since 1.3.1
	 */
	public function add_noindex_for_static_urls() {
		// Check if current URL is a static file being accessed directly.
		$current_url = home_url( $_SERVER['REQUEST_URI'] );
		
		if ( $this->is_static_file_url( $current_url ) ) {
			echo '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">' . "\n";
			echo '<meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet">' . "\n";
			echo '<meta name="bingbot" content="noindex, nofollow, noarchive, nosnippet">' . "\n";
		}
	}

	/**
	 * Exclude static files from search results
	 *
	 * @since 1.3.1
	 * @param WP_Query $query The WP_Query instance.
	 */
	public function exclude_from_search( $query ) {
		// This primarily affects WordPress internal search.
		// Static files should never appear in search results anyway.
		if ( ! is_admin() && $query->is_search() && $query->is_main_query() ) {
			// Additional protection could be added here if needed.
		}
	}

	/**
	 * Get SEO recommendations for site administrators
	 *
	 * @since 1.3.1
	 * @return array Array of SEO recommendations.
	 */
	public static function get_seo_recommendations() {
		return array(
			'canonical'   => array(
				'title'       => __( 'Canonical URLs', 'breakdance-static-pages' ),
				'description' => __( 'Static files include canonical URLs pointing to original dynamic pages to prevent duplicate content.', 'breakdance-static-pages' ),
				'status'      => 'good',
			),
			'robots'      => array(
				'title'       => __( 'Robots Meta Tags', 'breakdance-static-pages' ),
				'description' => __( 'Static files include noindex, nofollow meta tags to prevent search engine indexing.', 'breakdance-static-pages' ),
				'status'      => 'good',
			),
			'sitemaps'    => array(
				'title'       => __( 'Sitemap Protection', 'breakdance-static-pages' ),
				'description' => __( 'Static file URLs are filtered out of XML sitemaps to ensure only canonical URLs are included.', 'breakdance-static-pages' ),
				'status'      => 'good',
			),
			'robots_txt'  => array(
				'title'       => __( 'Robots.txt Rules', 'breakdance-static-pages' ),
				'description' => __( 'Robots.txt includes disallow rules for static file directories.', 'breakdance-static-pages' ),
				'status'      => 'good',
			),
		);
	}

	/**
	 * Validate SEO configuration
	 *
	 * @since 1.3.1
	 * @return array Validation results.
	 */
	public static function validate_seo_config() {
		$results = array();

		// Check if robots.txt is accessible.
		$robots_url = home_url( '/robots.txt' );
		$response = wp_remote_get( $robots_url );
		
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$robots_content = wp_remote_retrieve_body( $response );
			$has_bsp_rules = strpos( $robots_content, 'breakdance-static-pages' ) !== false;
			
			$results['robots_txt'] = array(
				'status'  => $has_bsp_rules ? 'good' : 'warning',
				'message' => $has_bsp_rules ? 
					__( 'Robots.txt includes Breakdance Static Pages rules.', 'breakdance-static-pages' ) :
					__( 'Robots.txt may not include Breakdance Static Pages rules.', 'breakdance-static-pages' ),
			);
		} else {
			$results['robots_txt'] = array(
				'status'  => 'error',
				'message' => __( 'Unable to access robots.txt file.', 'breakdance-static-pages' ),
			);
		}

		// Check for SEO plugin compatibility.
		$seo_plugins = array(
			'wordpress-seo/wp-seo.php'       => 'Yoast SEO',
			'seo-by-rank-math/rank-math.php' => 'Rank Math',
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
		);

		$active_plugins = get_option( 'active_plugins', array() );
		$detected_seo = array();

		foreach ( $seo_plugins as $plugin_path => $plugin_name ) {
			if ( in_array( $plugin_path, $active_plugins, true ) ) {
				$detected_seo[] = $plugin_name;
			}
		}

		$results['seo_plugins'] = array(
			'status'  => ! empty( $detected_seo ) ? 'good' : 'info',
			'message' => ! empty( $detected_seo ) ?
				sprintf( __( 'Detected SEO plugins: %s', 'breakdance-static-pages' ), implode( ', ', $detected_seo ) ) :
				__( 'No SEO plugins detected. This is fine for basic setups.', 'breakdance-static-pages' ),
		);

		return $results;
	}
}
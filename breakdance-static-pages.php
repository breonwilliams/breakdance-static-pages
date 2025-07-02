<?php
/**
 * Breakdance Static Pages
 *
 * @package           Breakdance_Static_Pages
 * @author            Your Name <email@example.com>
 * @copyright         2024 Your Name or Company
 * @license           GPL v2 or later
 *
 * @wordpress-plugin
 * Plugin Name:       Breakdance Static Pages
 * Plugin URI:        https://yoursite.com/plugins/breakdance-static-pages/
 * Description:       Convert Breakdance pages with ACF fields into lightning-fast static HTML files for dramatically improved performance.
 * Version:           1.3.1
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Your Name
 * Author URI:        https://yoursite.com/
 * Text Domain:       breakdance-static-pages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path:       /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'BSP_VERSION', '1.3.1' );
define( 'BSP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BSP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BSP_PLUGIN_FILE', __FILE__ );
define( 'BSP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class
 *
 * @since      1.0.0
 * @package    Breakdance_Static_Pages
 * @author     Your Name <email@example.com>
 */
final class Breakdance_Static_Pages {

	/**
	 * Single instance of the plugin
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    Breakdance_Static_Pages|null
	 */
	private static $instance = null;

	/**
	 * Get single instance of the plugin
	 *
	 * @since  1.0.0
	 * @return Breakdance_Static_Pages
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function __construct() {
		$this->register_hooks();
	}

	/**
	 * Prevent cloning
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing
	 *
	 * @since  1.0.0
	 * @access private
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}

	/**
	 * Register hooks
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function register_hooks() {
		// Load plugin.
		add_action( 'plugins_loaded', array( $this, 'load_plugin' ) );

		// Activation/Deactivation.
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Add settings link.
		add_filter( 'plugin_action_links_' . BSP_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );
	}

	/**
	 * Load plugin
	 *
	 * @since 1.0.0
	 */
	public function load_plugin() {
		// Load text domain.
		$this->load_textdomain();

		// Load dependencies.
		$this->load_dependencies();

		// Initialize components.
		$this->init_components();

		// Initialize hooks manager.
		BSP_Hooks_Manager::init();
	}

	/**
	 * Load plugin text domain
	 *
	 * @since 1.0.0
	 */
	private function load_textdomain() {
		load_plugin_textdomain(
			'breakdance-static-pages',
			false,
			dirname( BSP_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Load plugin dependencies
	 *
	 * @since 1.0.0
	 */
	private function load_dependencies() {
		// Core files.
		require_once BSP_PLUGIN_DIR . 'includes/class-security-helper.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-hooks-manager.php';

		// Phase 1: Foundation.
		require_once BSP_PLUGIN_DIR . 'includes/class-file-lock-manager.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-health-check.php';

		// Phase 2: Performance.
		require_once BSP_PLUGIN_DIR . 'includes/class-stats-cache.php';

		// Phase 3: Reliability.
		require_once BSP_PLUGIN_DIR . 'includes/class-error-handler.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-retry-manager.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-atomic-operations.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-recovery-manager.php';

		// Phase 4: Scalability.
		require_once BSP_PLUGIN_DIR . 'includes/class-queue-manager.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-batch-processor.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-progress-tracker.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-rest-api.php';

		// Core components.
		require_once BSP_PLUGIN_DIR . 'includes/class-static-generator.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-cache-manager.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-admin-interface.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-ajax-handler.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-url-rewriter.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-performance-monitor.php';
		require_once BSP_PLUGIN_DIR . 'includes/class-seo-protection.php';
	}

	/**
	 * Initialize plugin components
	 *
	 * @since 1.0.0
	 */
	private function init_components() {
		// Initialize error handler first (used by other components).
		BSP_Error_Handler::get_instance();

		// Initialize recovery manager.
		BSP_Recovery_Manager::get_instance();

		// Initialize scalability components.
		BSP_Queue_Manager::get_instance();
		BSP_Batch_Processor::get_instance();
		BSP_Progress_Tracker::get_instance();
		new BSP_REST_API();

		// Initialize admin interface.
		if ( is_admin() ) {
			new BSP_Admin_Interface();
		}

		// Initialize AJAX handler.
		new BSP_Ajax_Handler();

		// Initialize URL rewriter for frontend.
		if ( ! is_admin() ) {
			new BSP_URL_Rewriter();
		}

		// Initialize cache manager.
		new BSP_Cache_Manager();

		// Initialize performance monitor.
		new BSP_Performance_Monitor();

		// Initialize health check.
		if ( is_admin() ) {
			new BSP_Health_Check();
		}

		// Initialize SEO protection.
		new BSP_SEO_Protection();

		// Hook into content updates.
		add_action( 'acf/save_post', array( $this, 'handle_content_update' ), 20 );
		add_action( 'save_post', array( $this, 'handle_post_save' ), 20, 2 );

		// Add admin bar menu.
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 100 );
	}

	/**
	 * Handle content updates (ACF fields)
	 *
	 * @since 1.0.0
	 * @param int $post_id Post ID.
	 */
	public function handle_content_update( $post_id ) {
		// Bail early if revision or autosave.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Check if static generation is enabled.
		$static_enabled = get_post_meta( $post_id, '_bsp_static_enabled', true );

		if ( ! $static_enabled ) {
			return;
		}

		/**
		 * Fires when content is updated and needs regeneration
		 *
		 * @since 1.0.0
		 * @param int $post_id Post ID that was updated.
		 */
		do_action( 'bsp_content_updated', $post_id );

		// Schedule regeneration.
		$delay = apply_filters( 'bsp_regeneration_delay', 30, $post_id );
		wp_schedule_single_event( time() + $delay, 'bsp_regenerate_static_page', array( $post_id ) );
	}

	/**
	 * Handle post save
	 *
	 * @since 1.0.0
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function handle_post_save( $post_id, $post ) {
		// Get allowed post types.
		$allowed_types = apply_filters( 'bsp_allowed_post_types', array( 'page', 'post' ) );

		// Bail if not allowed post type.
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}

		$this->handle_content_update( $post_id );
	}

	/**
	 * Add admin bar menu
	 *
	 * @since 1.0.0
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar object.
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		// Check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only show on singular pages.
		if ( ! is_singular() ) {
			return;
		}

		global $post;

		if ( ! $post ) {
			return;
		}

		$static_enabled = get_post_meta( $post->ID, '_bsp_static_enabled', true );

		// Add main menu item.
		$wp_admin_bar->add_menu(
			array(
				'id'    => 'bsp-static-control',
				'title' => $static_enabled ? __( '⚡ Static Active', 'breakdance-static-pages' ) : __( '🐌 Dynamic', 'breakdance-static-pages' ),
				'href'  => admin_url( 'tools.php?page=breakdance-static-pages' ),
				'meta'  => array(
					'title' => $static_enabled ?
						__( 'This page is served as static HTML', 'breakdance-static-pages' ) :
						__( 'This page is served dynamically', 'breakdance-static-pages' ),
				),
			)
		);

		// Add regenerate submenu if enabled.
		if ( $static_enabled ) {
			$wp_admin_bar->add_menu(
				array(
					'parent' => 'bsp-static-control',
					'id'     => 'bsp-regenerate',
					'title'  => __( '🔄 Regenerate Static', 'breakdance-static-pages' ),
					'href'   => wp_nonce_url(
						admin_url( 'admin-post.php?action=bsp_regenerate_page&post_id=' . $post->ID ),
						'bsp_regenerate_' . $post->ID
					),
				)
			);
		}
	}

	/**
	 * Plugin activation
	 *
	 * @since 1.0.0
	 */
	public function activate() {
		// Load dependencies first.
		$this->load_dependencies();

		// Add database version.
		add_option( 'bsp_db_version', BSP_VERSION );
		add_option( 'bsp_activation_time', current_time( 'timestamp' ) );

		// Create directories.
		$this->create_directories();

		// Create database tables.
		$this->create_tables();

		// Schedule events.
		$this->schedule_events();

		// Flush rewrite rules.
		flush_rewrite_rules();

		/**
		 * Fires after plugin activation
		 *
		 * @since 1.0.0
		 */
		do_action( 'bsp_activated' );
	}

	/**
	 * Create plugin directories
	 *
	 * @since 1.0.0
	 */
	private function create_directories() {
		$upload_dir = wp_upload_dir();
		$dirs = array(
			$upload_dir['basedir'] . '/breakdance-static-pages',
			$upload_dir['basedir'] . '/breakdance-static-pages/pages',
			$upload_dir['basedir'] . '/breakdance-static-pages/assets',
		);

		foreach ( $dirs as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}

		// Create lock directory.
		BSP_File_Lock_Manager::get_instance();

		// Create .htaccess for security.
		$htaccess_content = "# Breakdance Static Pages\n";
		$htaccess_content .= "Options -Indexes\n";
		$htaccess_content .= "<Files *.html>\n";
		$htaccess_content .= "    Header set Cache-Control \"public, max-age=3600\"\n";
		$htaccess_content .= "</Files>\n";

		$htaccess_path = $upload_dir['basedir'] . '/breakdance-static-pages/.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_write_file_put_contents
			file_put_contents( $htaccess_path, $htaccess_content );
		}
	}

	/**
	 * Create database tables
	 *
	 * @since 1.0.0
	 */
	private function create_tables() {
		// Create queue table.
		$queue_manager = BSP_Queue_Manager::get_instance();
		$queue_manager->create_table();
	}

	/**
	 * Schedule events
	 *
	 * @since 1.0.0
	 */
	private function schedule_events() {
		// Schedule cleanup.
		if ( ! wp_next_scheduled( 'bsp_cleanup_old_static_files' ) ) {
			wp_schedule_event( time(), 'daily', 'bsp_cleanup_old_static_files' );
		}

		// Schedule lock cleanup.
		if ( ! wp_next_scheduled( 'bsp_cleanup_locks' ) ) {
			wp_schedule_event( time(), 'hourly', 'bsp_cleanup_locks' );
		}
	}

	/**
	 * Plugin deactivation
	 *
	 * @since 1.0.0
	 */
	public function deactivate() {
		// Clear scheduled events.
		$events = array(
			'bsp_cleanup_old_static_files',
			'bsp_regenerate_static_page',
			'bsp_cleanup_locks',
			'bsp_cleanup_error_logs',
			'bsp_hourly_recovery',
			'bsp_daily_recovery',
			'bsp_process_queue',
			'bsp_queue_cleanup',
			'bsp_cleanup_progress_sessions',
		);

		foreach ( $events as $event ) {
			wp_clear_scheduled_hook( $event );
		}

		// Flush rewrite rules.
		flush_rewrite_rules();

		/**
		 * Fires after plugin deactivation
		 *
		 * @since 1.0.0
		 */
		do_action( 'bsp_deactivated' );
	}

	/**
	 * Add settings link to plugins page
	 *
	 * @since 1.0.0
	 * @param array $links Plugin action links.
	 * @return array Modified links.
	 */
	public function add_settings_link( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'tools.php?page=breakdance-static-pages' ),
			__( 'Settings', 'breakdance-static-pages' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Get static file path for a post
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID.
	 * @return string File path.
	 */
	public static function get_static_file_path( $post_id ) {
		$upload_dir = wp_upload_dir();
		$base_path  = $upload_dir['basedir'] . '/breakdance-static-pages/pages/';
		$file_name  = 'page-' . intval( $post_id ) . '.html';

		/**
		 * Filter static file path
		 *
		 * @since 1.0.0
		 * @param string $path    Full file path.
		 * @param int    $post_id Post ID.
		 */
		$path = apply_filters( 'bsp_static_file_path', $base_path . $file_name, $post_id );

		return $path;
	}

	/**
	 * Get static file URL for a post (admin-only access)
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID.
	 * @return string File URL.
	 */
	public static function get_static_file_url( $post_id ) {
		// Return admin-ajax URL for secure access.
		$url = add_query_arg(
			array(
				'action' => 'bsp_serve_static',
				'file'   => 'pages/page-' . intval( $post_id ) . '.html',
			),
			admin_url( 'admin-ajax.php' )
		);

		/**
		 * Filter static file URL
		 *
		 * @since 1.0.0
		 * @param string $url     File URL.
		 * @param int    $post_id Post ID.
		 */
		return apply_filters( 'bsp_static_file_url', $url, $post_id );
	}

	/**
	 * Check if a page should be served statically
	 *
	 * @since  1.0.0
	 * @param  int $post_id Post ID.
	 * @return bool True if should serve static.
	 */
	public static function should_serve_static( $post_id ) {
		// Check if static generation is enabled.
		$static_enabled = get_post_meta( $post_id, '_bsp_static_enabled', true );

		if ( ! $static_enabled ) {
			return false;
		}

		// Check if static file exists.
		$static_file = self::get_static_file_path( $post_id );

		if ( ! file_exists( $static_file ) ) {
			return false;
		}

		// Check file age.
		$max_age  = apply_filters( 'bsp_static_file_max_age', DAY_IN_SECONDS );
		$file_age = time() - filemtime( $static_file );

		if ( $file_age > $max_age ) {
			return false;
		}

		/**
		 * Filter whether to serve static
		 *
		 * @since 1.0.0
		 * @param bool $should_serve Whether to serve static.
		 * @param int  $post_id      Post ID.
		 */
		return apply_filters( 'bsp_should_serve_static', true, $post_id );
	}
}

// Initialize the plugin.
Breakdance_Static_Pages::get_instance();

/**
 * Hook implementations
 */

// Handle regeneration action.
add_action( 'admin_post_bsp_regenerate_page', 'bsp_handle_regenerate_page' );

/**
 * Handle manual regeneration request
 *
 * @since 1.0.0
 */
function bsp_handle_regenerate_page() {
	// Security check.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Unauthorized', 'breakdance-static-pages' ) );
	}

	// Verify nonce.
	$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

	if ( ! $post_id || ! wp_verify_nonce( $_GET['_wpnonce'], 'bsp_regenerate_' . $post_id ) ) {
		wp_die( esc_html__( 'Security check failed', 'breakdance-static-pages' ) );
	}

	// Regenerate static page.
	$result = BSP_Atomic_Operations::generate_with_rollback( $post_id );

	// Redirect with result.
	if ( $result['success'] ) {
		wp_safe_redirect( add_query_arg( 'bsp_regenerated', '1', get_permalink( $post_id ) ) );
	} else {
		wp_safe_redirect( add_query_arg( 'bsp_error', '1', get_permalink( $post_id ) ) );
	}
	exit;
}

// Handle scheduled regeneration.
add_action( 'bsp_regenerate_static_page', 'bsp_handle_scheduled_regeneration' );

/**
 * Handle scheduled regeneration
 *
 * @since 1.0.0
 * @param int $post_id Post ID to regenerate.
 */
function bsp_handle_scheduled_regeneration( $post_id ) {
	// Use atomic operations with retry.
	BSP_Retry_Manager::retry(
		function() use ( $post_id ) {
			return BSP_Atomic_Operations::generate_with_rollback( $post_id );
		},
		array( 'max_attempts' => 2 )
	);
}

// Handle cleanup.
add_action( 'bsp_cleanup_old_static_files', 'bsp_handle_cleanup_old_files' );

/**
 * Handle cleanup of old static files
 *
 * @since 1.0.0
 */
function bsp_handle_cleanup_old_files() {
	$cache_manager = new BSP_Cache_Manager();
	$cache_manager->cleanup_old_files();
}

// Handle lock cleanup.
add_action( 'bsp_cleanup_locks', 'bsp_handle_cleanup_locks' );

/**
 * Handle cleanup of expired locks
 *
 * @since 1.0.0
 */
function bsp_handle_cleanup_locks() {
	$lock_manager = BSP_File_Lock_Manager::get_instance();
	$lock_manager->cleanup_expired_locks();
}
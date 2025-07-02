<?php
/**
 * Base Test Case for Breakdance Static Pages
 *
 * @package Breakdance_Static_Pages
 * @subpackage Tests
 */

/**
 * Base test case class
 *
 * All test classes should extend this class.
 */
abstract class BSP_Test_Case extends WP_UnitTestCase {

	/**
	 * Set up before class
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// Create test upload directory.
		$upload_dir = wp_upload_dir();
		$test_dir   = $upload_dir['basedir'] . '/breakdance-static-pages-test/';
		if ( ! file_exists( $test_dir ) ) {
			wp_mkdir_p( $test_dir );
		}
	}

	/**
	 * Tear down after class
	 */
	public static function tearDownAfterClass(): void {
		parent::tearDownAfterClass();

		// Clean up test directory.
		$upload_dir = wp_upload_dir();
		$test_dir   = $upload_dir['basedir'] . '/breakdance-static-pages-test/';
		if ( file_exists( $test_dir ) ) {
			self::remove_directory( $test_dir );
		}
	}

	/**
	 * Set up test
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset any singleton instances.
		$this->reset_singletons();

		// Clear any scheduled events.
		$this->clear_scheduled_events();

		// Reset options.
		delete_option( 'bsp_db_version' );
		delete_option( 'bsp_settings' );
		delete_option( 'bsp_activation_time' );
	}

	/**
	 * Tear down test
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clean up any test files.
		$this->cleanup_test_files();

		// Clear error logs.
		$error_handler = BSP_Error_Handler::get_instance();
		$error_handler->clear_errors();
	}

	/**
	 * Reset singleton instances
	 */
	protected function reset_singletons() {
		$singletons = array(
			'BSP_File_Lock_Manager',
			'BSP_Error_Handler',
			'BSP_Recovery_Manager',
			'BSP_Queue_Manager',
			'BSP_Batch_Processor',
			'BSP_Progress_Tracker',
		);

		foreach ( $singletons as $class ) {
			if ( class_exists( $class ) ) {
				$reflection = new ReflectionClass( $class );
				$instance   = $reflection->getProperty( 'instance' );
				$instance->setAccessible( true );
				$instance->setValue( null, null );
			}
		}
	}

	/**
	 * Clear scheduled events
	 */
	protected function clear_scheduled_events() {
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
	}

	/**
	 * Clean up test files
	 */
	protected function cleanup_test_files() {
		$upload_dir = wp_upload_dir();
		$static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/';

		if ( file_exists( $static_dir ) ) {
			$files = glob( $static_dir . 'pages/*.html' );
			if ( $files ) {
				foreach ( $files as $file ) {
					unlink( $file );
				}
			}
		}
	}

	/**
	 * Create a test post
	 *
	 * @param array $args Post arguments.
	 * @return int Post ID.
	 */
	protected function create_test_post( $args = array() ) {
		$defaults = array(
			'post_title'   => 'Test Post',
			'post_content' => '<p>Test content</p>',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);

		$args    = wp_parse_args( $args, $defaults );
		$post_id = $this->factory->post->create( $args );

		// Enable static generation by default.
		update_post_meta( $post_id, '_bsp_static_enabled', '1' );

		return $post_id;
	}

	/**
	 * Generate static file for post
	 *
	 * @param int $post_id Post ID.
	 * @return array Result.
	 */
	protected function generate_static_file( $post_id ) {
		$generator = new BSP_Static_Generator();
		return $generator->generate_static_page( $post_id );
	}

	/**
	 * Assert static file exists
	 *
	 * @param int $post_id Post ID.
	 */
	protected function assertStaticFileExists( $post_id ) {
		$file_path = Breakdance_Static_Pages::get_static_file_path( $post_id );
		$this->assertFileExists( $file_path, "Static file should exist for post $post_id" );
	}

	/**
	 * Assert static file does not exist
	 *
	 * @param int $post_id Post ID.
	 */
	protected function assertStaticFileNotExists( $post_id ) {
		$file_path = Breakdance_Static_Pages::get_static_file_path( $post_id );
		$this->assertFileDoesNotExist( $file_path, "Static file should not exist for post $post_id" );
	}

	/**
	 * Mock external HTTP request
	 *
	 * @param string $response Response body.
	 * @param int    $code     Response code.
	 * @return callable
	 */
	protected function mock_http_request( $response, $code = 200 ) {
		return function( $preempt, $parsed_args, $url ) use ( $response, $code ) {
			return array(
				'response' => array(
					'code'    => $code,
					'message' => 'OK',
				),
				'body'     => $response,
			);
		};
	}

	/**
	 * Get protected/private property
	 *
	 * @param object $object   Object.
	 * @param string $property Property name.
	 * @return mixed
	 */
	protected function get_protected_property( $object, $property ) {
		$reflection = new ReflectionClass( $object );
		$property   = $reflection->getProperty( $property );
		$property->setAccessible( true );
		return $property->getValue( $object );
	}

	/**
	 * Set protected/private property
	 *
	 * @param object $object   Object.
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 */
	protected function set_protected_property( $object, $property, $value ) {
		$reflection = new ReflectionClass( $object );
		$property   = $reflection->getProperty( $property );
		$property->setAccessible( true );
		$property->setValue( $object, $value );
	}

	/**
	 * Call protected/private method
	 *
	 * @param object $object Object.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	protected function call_protected_method( $object, $method, $args = array() ) {
		$reflection = new ReflectionClass( $object );
		$method     = $reflection->getMethod( $method );
		$method->setAccessible( true );
		return $method->invokeArgs( $object, $args );
	}

	/**
	 * Remove directory recursively
	 *
	 * @param string $dir Directory path.
	 */
	protected static function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );

		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) ) {
				self::remove_directory( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}
}
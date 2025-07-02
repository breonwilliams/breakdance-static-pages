<?php
/**
 * AJAX Handler Integration Tests
 *
 * @package Breakdance_Static_Pages
 * @subpackage Tests/Integration
 */

/**
 * Test AJAX Handler integration
 */
class BSP_Ajax_Handler_Integration_Test extends BSP_Test_Case {

	use BSP_Test_Helpers;

	/**
	 * AJAX Handler instance
	 *
	 * @var BSP_Ajax_Handler
	 */
	private $ajax_handler;

	/**
	 * Set up test
	 */
	public function setUp(): void {
		parent::setUp();
		$this->ajax_handler = new BSP_Ajax_Handler();
		
		// Set up admin user.
		wp_set_current_user( 1 );
	}

	/**
	 * Test handle generate single - success
	 */
	public function test_handle_generate_single_success() {
		$post_id = $this->create_test_post();
		
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action'  => 'bsp_generate_single',
			'post_id' => $post_id,
		) );
		
		// Mock HTTP request for static generation.
		$html = $this->create_test_html();
		add_filter( 'pre_http_request', $this->mock_http_request( $html ), 10, 3 );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_generate_single();
		} );
		
		// Remove filter.
		remove_all_filters( 'pre_http_request' );
		
		// Assertions.
		$this->assertAjaxSuccess( $response );
		$this->assertArrayHasKey( 'post_id', $response['data'] );
		$this->assertEquals( $post_id, $response['data']['post_id'] );
		$this->assertStaticFileExists( $post_id );
	}

	/**
	 * Test handle generate single - invalid nonce
	 */
	public function test_handle_generate_single_invalid_nonce() {
		$post_id = $this->create_test_post();
		
		// Mock AJAX request with invalid nonce.
		$this->mock_ajax_request( array(
			'action'  => 'bsp_generate_single',
			'post_id' => $post_id,
			'nonce'   => 'invalid_nonce',
		) );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_generate_single();
		} );
		
		// Assertions.
		$this->assertAjaxError( $response );
		$this->assertStringContainsString( 'Security check failed', $response['data']['message'] );
	}

	/**
	 * Test handle generate single - no permission
	 */
	public function test_handle_generate_single_no_permission() {
		// Set up non-admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		
		$post_id = $this->create_test_post();
		
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action'  => 'bsp_generate_single',
			'post_id' => $post_id,
		) );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_generate_single();
		} );
		
		// Assertions.
		$this->assertAjaxError( $response );
		$this->assertStringContainsString( 'permission', $response['data']['message'] );
	}

	/**
	 * Test handle generate multiple - success
	 */
	public function test_handle_generate_multiple_success() {
		$post_ids = array(
			$this->create_test_post(),
			$this->create_test_post(),
			$this->create_test_post(),
		);
		
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action'   => 'bsp_generate_multiple',
			'post_ids' => $post_ids,
		) );
		
		// Mock HTTP request for static generation.
		$html = $this->create_test_html();
		add_filter( 'pre_http_request', $this->mock_http_request( $html ), 10, 3 );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_generate_multiple();
		} );
		
		// Remove filter.
		remove_all_filters( 'pre_http_request' );
		
		// Assertions.
		$this->assertAjaxSuccess( $response );
		$this->assertEquals( 3, $response['data']['success_count'] );
		$this->assertEquals( 0, $response['data']['error_count'] );
		
		foreach ( $post_ids as $post_id ) {
			$this->assertStaticFileExists( $post_id );
		}
	}

	/**
	 * Test handle delete single - success
	 */
	public function test_handle_delete_single_success() {
		$post_id = $this->create_test_post();
		
		// Generate static file first.
		$this->generate_static_file( $post_id );
		$this->assertStaticFileExists( $post_id );
		
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action'  => 'bsp_delete_single',
			'post_id' => $post_id,
		) );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_delete_single();
		} );
		
		// Assertions.
		$this->assertAjaxSuccess( $response );
		$this->assertStaticFileNotExists( $post_id );
	}

	/**
	 * Test handle toggle static - enable
	 */
	public function test_handle_toggle_static_enable() {
		$post_id = $this->create_test_post();
		
		// Disable static generation first.
		update_post_meta( $post_id, '_bsp_static_enabled', '' );
		
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action'  => 'bsp_toggle_static',
			'post_id' => $post_id,
			'enabled' => 'true',
		) );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_toggle_static();
		} );
		
		// Assertions.
		$this->assertAjaxSuccess( $response );
		$this->assertTrue( $response['data']['enabled'] );
		$this->assertPostMetaExists( $post_id, '_bsp_static_enabled', '1' );
	}

	/**
	 * Test handle toggle static - disable
	 */
	public function test_handle_toggle_static_disable() {
		$post_id = $this->create_test_post();
		
		// Generate static file first.
		$this->generate_static_file( $post_id );
		$this->assertStaticFileExists( $post_id );
		
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action'  => 'bsp_toggle_static',
			'post_id' => $post_id,
			'enabled' => 'false',
		) );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_toggle_static();
		} );
		
		// Assertions.
		$this->assertAjaxSuccess( $response );
		$this->assertFalse( $response['data']['enabled'] );
		$this->assertPostMetaExists( $post_id, '_bsp_static_enabled', '' );
		$this->assertStaticFileNotExists( $post_id );
	}

	/**
	 * Test handle get stats
	 */
	public function test_handle_get_stats() {
		// Create some test data.
		$post_ids = array(
			$this->create_test_post(),
			$this->create_test_post(),
		);
		
		// Generate static files.
		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, '_bsp_static_generated', current_time( 'mysql' ) );
			update_post_meta( $post_id, '_bsp_static_file_size', 1024 );
		}
		
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action' => 'bsp_get_stats',
		) );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_get_stats();
		} );
		
		// Assertions.
		$this->assertAjaxSuccess( $response );
		$this->assertIsArray( $response['data'] );
		$this->assertArrayHasKey( 'enabled_pages', $response['data'] );
		$this->assertArrayHasKey( 'generated_pages', $response['data'] );
	}

	/**
	 * Test serve static file - admin access
	 */
	public function test_serve_static_file_admin_access() {
		$post_id = $this->create_test_post();
		
		// Generate static file.
		$this->generate_static_file( $post_id );
		
		// Set up GET request.
		$_GET['action'] = 'bsp_serve_static';
		$_GET['file'] = 'pages/page-' . $post_id . '.html';
		
		// Set up admin user.
		wp_set_current_user( 1 );
		
		// Capture output.
		ob_start();
		
		try {
			$this->ajax_handler->serve_static_file();
		} catch ( WPDieException $e ) {
			// Expected for successful file serving.
		}
		
		$output = ob_get_clean();
		
		// Assertions.
		$this->assertStringContainsString( 'Test content', $output );
		$this->assertStringContainsString( 'ADMIN PREVIEW', $output );
	}

	/**
	 * Test serve static file - no access
	 */
	public function test_serve_static_file_no_access() {
		$post_id = $this->create_test_post();
		
		// Generate static file.
		$this->generate_static_file( $post_id );
		
		// Set up GET request.
		$_GET['action'] = 'bsp_serve_static';
		$_GET['file'] = 'pages/page-' . $post_id . '.html';
		
		// Set up non-admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		
		// Test should throw WP_Die with 403.
		$this->expectException( 'WPDieException' );
		$this->ajax_handler->serve_static_file();
	}

	/**
	 * Test serve static file - invalid file path
	 */
	public function test_serve_static_file_invalid_path() {
		// Set up GET request with path traversal attempt.
		$_GET['action'] = 'bsp_serve_static';
		$_GET['file'] = '../../../wp-config.php';
		
		// Set up admin user.
		wp_set_current_user( 1 );
		
		// Test should throw WP_Die with 400.
		$this->expectException( 'WPDieException' );
		$this->ajax_handler->serve_static_file();
	}

	/**
	 * Test handle cleanup orphaned
	 */
	public function test_handle_cleanup_orphaned() {
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action' => 'bsp_cleanup_orphaned',
		) );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_cleanup_orphaned();
		} );
		
		// Assertions.
		$this->assertAjaxSuccess( $response );
		$this->assertStringContainsString( 'Cleaned up', $response['data']['message'] );
	}

	/**
	 * Test handle clear all locks
	 */
	public function test_handle_clear_all_locks() {
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action' => 'bsp_clear_all_locks',
		) );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_clear_all_locks();
		} );
		
		// Assertions.
		$this->assertAjaxSuccess( $response );
		$this->assertStringContainsString( 'Cleared', $response['data']['message'] );
	}

	/**
	 * Test handle delete all static
	 */
	public function test_handle_delete_all_static() {
		// Create posts and generate static files.
		$post_ids = array(
			$this->create_test_post(),
			$this->create_test_post(),
		);
		
		foreach ( $post_ids as $post_id ) {
			$this->generate_static_file( $post_id );
			$this->assertStaticFileExists( $post_id );
		}
		
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action' => 'bsp_delete_all_static',
		) );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_delete_all_static();
		} );
		
		// Assertions.
		$this->assertAjaxSuccess( $response );
		$this->assertStringContainsString( 'Deleted', $response['data']['message'] );
		
		foreach ( $post_ids as $post_id ) {
			$this->assertStaticFileNotExists( $post_id );
		}
	}

	/**
	 * Test handle clear errors
	 */
	public function test_handle_clear_errors() {
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action' => 'bsp_clear_errors',
		) );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_clear_errors();
		} );
		
		// Assertions.
		$this->assertAjaxSuccess( $response );
		$this->assertStringContainsString( 'cleared successfully', $response['data']['message'] );
	}

	/**
	 * Test handle export errors
	 */
	public function test_handle_export_errors() {
		// Mock AJAX request.
		$this->mock_ajax_request( array(
			'action' => 'bsp_export_errors',
		) );
		
		// Capture AJAX response.
		$response = $this->capture_ajax_response( function() {
			$this->ajax_handler->handle_export_errors();
		} );
		
		// Assertions.
		$this->assertAjaxSuccess( $response );
		$this->assertArrayHasKey( 'data', $response['data'] );
		$this->assertArrayHasKey( 'filename', $response['data'] );
		$this->assertStringContainsString( 'bsp-errors-', $response['data']['filename'] );
	}
}
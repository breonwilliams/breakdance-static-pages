<?php
/**
 * Static Generator Tests
 *
 * @package Breakdance_Static_Pages
 * @subpackage Tests/Unit
 */

/**
 * Test Static Generator class
 */
class BSP_Static_Generator_Test extends BSP_Test_Case {

	use BSP_Test_Helpers;

	/**
	 * Static Generator instance
	 *
	 * @var BSP_Static_Generator
	 */
	private $generator;

	/**
	 * Set up test
	 */
	public function setUp(): void {
		parent::setUp();
		$this->generator = new BSP_Static_Generator();
	}

	/**
	 * Test generate static page success
	 */
	public function test_generate_static_page_success() {
		$post_id = $this->create_test_post();
		
		// Mock HTTP request.
		$html = $this->create_test_html();
		add_filter( 'pre_http_request', $this->mock_http_request( $html ), 10, 3 );
		
		// Generate static page.
		$result = $this->generator->generate_static_page( $post_id );
		
		// Remove filter.
		remove_all_filters( 'pre_http_request' );
		
		// Assertions.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'file_path', $result );
		$this->assertArrayHasKey( 'file_size', $result );
		$this->assertArrayHasKey( 'generation_time', $result );
		
		// Check file exists.
		$this->assertStaticFileExists( $post_id );
		
		// Check meta values.
		$this->assertPostMetaExists( $post_id, '_bsp_static_generated' );
		$this->assertPostMetaExists( $post_id, '_bsp_static_file_size' );
		$this->assertPostMetaExists( $post_id, '_bsp_static_etag' );
	}

	/**
	 * Test generate static page with invalid post ID
	 */
	public function test_generate_static_page_invalid_post_id() {
		$result = $this->generator->generate_static_page( 0 );
		
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'error', $result );
	}

	/**
	 * Test generate static page with non-existent post
	 */
	public function test_generate_static_page_non_existent_post() {
		$result = $this->generator->generate_static_page( 999999 );
		
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test generate static page with unpublished post
	 */
	public function test_generate_static_page_unpublished_post() {
		$post_id = $this->create_test_post( array( 'post_status' => 'draft' ) );
		
		$result = $this->generator->generate_static_page( $post_id );
		
		$this->assertFalse( $result );
	}

	/**
	 * Test generate static page with filter blocking generation
	 */
	public function test_generate_static_page_blocked_by_filter() {
		$post_id = $this->create_test_post();
		
		// Add filter to block generation.
		add_filter( 'bsp_should_generate_static', '__return_false' );
		
		$result = $this->generator->generate_static_page( $post_id );
		
		// Remove filter.
		remove_filter( 'bsp_should_generate_static', '__return_false' );
		
		$this->assertIsArray( $result );
		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test generate static page with HTTP error
	 */
	public function test_generate_static_page_http_error() {
		$post_id = $this->create_test_post();
		
		// Mock HTTP error.
		add_filter( 'pre_http_request', function() {
			return new WP_Error( 'http_request_failed', 'Connection timeout' );
		} );
		
		$result = $this->generator->generate_static_page( $post_id );
		
		// Remove filter.
		remove_all_filters( 'pre_http_request' );
		
		$this->assertFalse( $result );
		$this->assertStaticFileNotExists( $post_id );
	}

	/**
	 * Test generate multiple pages
	 */
	public function test_generate_multiple_pages() {
		// Create test posts.
		$post_ids = array(
			$this->create_test_post(),
			$this->create_test_post(),
			$this->create_test_post(),
		);
		
		// Mock HTTP request.
		$html = $this->create_test_html();
		add_filter( 'pre_http_request', $this->mock_http_request( $html ), 10, 3 );
		
		// Generate multiple pages.
		$results = $this->generator->generate_multiple_pages( $post_ids );
		
		// Remove filter.
		remove_all_filters( 'pre_http_request' );
		
		// Assertions.
		$this->assertIsArray( $results );
		$this->assertCount( 3, $results );
		
		foreach ( $post_ids as $post_id ) {
			$this->assertArrayHasKey( $post_id, $results );
			$this->assertTrue( $results[ $post_id ]['success'] );
			$this->assertStaticFileExists( $post_id );
		}
	}

	/**
	 * Test delete static page
	 */
	public function test_delete_static_page() {
		$post_id = $this->create_test_post();
		
		// Generate static file first.
		$this->generate_static_file( $post_id );
		$this->assertStaticFileExists( $post_id );
		
		// Delete static page.
		$result = $this->generator->delete_static_page( $post_id );
		
		// Assertions.
		$this->assertTrue( $result );
		$this->assertStaticFileNotExists( $post_id );
		$this->assertPostMetaNotExists( $post_id, '_bsp_static_generated' );
		$this->assertPostMetaNotExists( $post_id, '_bsp_static_file_size' );
	}

	/**
	 * Test delete non-existent static page
	 */
	public function test_delete_non_existent_static_page() {
		$post_id = $this->create_test_post();
		
		// Try to delete non-existent file.
		$result = $this->generator->delete_static_page( $post_id );
		
		$this->assertFalse( $result );
	}

	/**
	 * Test has content changed - new post
	 */
	public function test_has_content_changed_new_post() {
		$post_id = $this->create_test_post();
		
		// New post should show as changed.
		$result = $this->generator->has_content_changed( $post_id );
		$this->assertTrue( $result );
	}

	/**
	 * Test has content changed - unchanged post
	 */
	public function test_has_content_changed_unchanged() {
		$post_id = $this->create_test_post();
		
		// Set generated time.
		update_post_meta( $post_id, '_bsp_static_generated', current_time( 'mysql' ) );
		
		// Wait a moment.
		sleep( 1 );
		
		// Should not show as changed.
		$result = $this->generator->has_content_changed( $post_id );
		$this->assertFalse( $result );
	}

	/**
	 * Test has content changed - modified post
	 */
	public function test_has_content_changed_modified() {
		$post_id = $this->create_test_post();
		
		// Set old generated time.
		update_post_meta( $post_id, '_bsp_static_generated', '2020-01-01 00:00:00' );
		
		// Should show as changed.
		$result = $this->generator->has_content_changed( $post_id );
		$this->assertTrue( $result );
	}

	/**
	 * Test get generation stats
	 */
	public function test_get_generation_stats() {
		// Create posts with static generation enabled.
		$post_ids = array(
			$this->create_test_post(),
			$this->create_test_post(),
			$this->create_test_post(),
		);
		
		// Generate static files for some posts.
		foreach ( array_slice( $post_ids, 0, 2 ) as $post_id ) {
			update_post_meta( $post_id, '_bsp_static_generated', current_time( 'mysql' ) );
			update_post_meta( $post_id, '_bsp_static_file_size', 1024 );
		}
		
		// Get stats.
		$stats = $this->generator->get_generation_stats();
		
		// Assertions.
		$this->assertIsArray( $stats );
		$this->assertEquals( 3, $stats['enabled_pages'] );
		$this->assertEquals( 2, $stats['generated_pages'] );
		$this->assertEquals( 2048, $stats['total_size'] );
		$this->assertEquals( 1024, $stats['average_size'] );
	}

	/**
	 * Test capture page HTML with internal request
	 */
	public function test_capture_page_html_internal_request() {
		$post_id = $this->create_test_post();
		
		// Mock successful HTTP request.
		$html = $this->create_test_html();
		add_filter( 'pre_http_request', $this->mock_http_request( $html ), 10, 3 );
		
		// Call protected method.
		$url = get_permalink( $post_id );
		$result = $this->call_protected_method( 
			$this->generator, 
			'capture_page_html', 
			array( $url, $post_id ) 
		);
		
		// Remove filter.
		remove_all_filters( 'pre_http_request' );
		
		$this->assertEquals( $html, $result );
	}

	/**
	 * Test optimize HTML
	 */
	public function test_optimize_html() {
		$post_id = $this->create_test_post();
		
		// Create HTML with admin bar and edit links.
		$html = '<!DOCTYPE html>
<html>
<head><title>Test</title></head>
<body>
	<div id="wpadminbar">Admin Bar</div>
	<div class="content">
		<span class="edit-link">Edit</span>
		<p>Content</p>
	</div>
</body>
</html>';
		
		// Call protected method.
		$result = $this->call_protected_method( 
			$this->generator, 
			'optimize_html', 
			array( $html, $post_id ) 
		);
		
		// Assertions.
		$this->assertStringNotContainsString( 'wpadminbar', $result );
		$this->assertStringNotContainsString( 'edit-link', $result );
		$this->assertStringContainsString( 'Generated by Breakdance Static Pages', $result );
		$this->assertStringContainsString( 'bsp-static-cache', $result );
	}

	/**
	 * Test memory check
	 */
	public function test_memory_check() {
		// Call protected method.
		$result = $this->call_protected_method( $this->generator, 'check_memory_usage' );
		
		// Should pass on test environment.
		$this->assertTrue( $result );
	}

	/**
	 * Test action hooks are fired
	 */
	public function test_action_hooks_fired() {
		$post_id = $this->create_test_post();
		$hooks_fired = array();
		
		// Add hooks to track firing.
		add_action( 'bsp_before_generate_static', function( $id ) use ( &$hooks_fired ) {
			$hooks_fired[] = 'before_generate:' . $id;
		} );
		
		add_action( 'bsp_after_generate_static', function( $id, $path, $success ) use ( &$hooks_fired ) {
			$hooks_fired[] = 'after_generate:' . $id . ':' . ( $success ? 'success' : 'failure' );
		} );
		
		// Mock HTTP request.
		$html = $this->create_test_html();
		add_filter( 'pre_http_request', $this->mock_http_request( $html ), 10, 3 );
		
		// Generate static page.
		$this->generator->generate_static_page( $post_id );
		
		// Remove filters.
		remove_all_filters( 'pre_http_request' );
		
		// Check hooks were fired.
		$this->assertContains( 'before_generate:' . $post_id, $hooks_fired );
		$this->assertContains( 'after_generate:' . $post_id . ':success', $hooks_fired );
	}

	/**
	 * Test content filter is applied
	 */
	public function test_content_filter_applied() {
		$post_id = $this->create_test_post();
		
		// Add filter to modify content.
		add_filter( 'bsp_static_file_content', function( $content ) {
			return str_replace( 'Test content', 'Modified content', $content );
		} );
		
		// Mock HTTP request.
		$html = $this->create_test_html( array( 'content' => '<p>Test content</p>' ) );
		add_filter( 'pre_http_request', $this->mock_http_request( $html ), 10, 3 );
		
		// Generate static page.
		$result = $this->generator->generate_static_page( $post_id );
		
		// Remove filters.
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'bsp_static_file_content' );
		
		// Check file content was modified.
		$file_path = Breakdance_Static_Pages::get_static_file_path( $post_id );
		$this->assertFileContains( 'Modified content', $file_path );
	}
}
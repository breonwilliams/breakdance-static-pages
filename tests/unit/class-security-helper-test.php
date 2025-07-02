<?php
/**
 * Security Helper Tests
 *
 * @package Breakdance_Static_Pages
 * @subpackage Tests/Unit
 */

/**
 * Test Security Helper class
 */
class BSP_Security_Helper_Test extends BSP_Test_Case {

	use BSP_Test_Helpers;

	/**
	 * Test verify AJAX request with valid nonce
	 */
	public function test_verify_ajax_request_valid_nonce() {
		// Set up admin user.
		wp_set_current_user( 1 );
		
		// Create valid nonce.
		$nonce = wp_create_nonce( 'bsp_nonce' );
		
		// Verify request.
		$result = BSP_Security_Helper::verify_ajax_request( $nonce );
		
		$this->assertTrue( $result );
	}

	/**
	 * Test verify AJAX request with invalid nonce
	 */
	public function test_verify_ajax_request_invalid_nonce() {
		// Set up admin user.
		wp_set_current_user( 1 );
		
		// Verify request with invalid nonce.
		$result = BSP_Security_Helper::verify_ajax_request( 'invalid_nonce' );
		
		$this->assertWPError( $result, 'invalid_nonce' );
	}

	/**
	 * Test verify AJAX request without permission
	 */
	public function test_verify_ajax_request_no_permission() {
		// Set up non-admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		
		// Create valid nonce.
		$nonce = wp_create_nonce( 'bsp_nonce' );
		
		// Verify request.
		$result = BSP_Security_Helper::verify_ajax_request( $nonce );
		
		$this->assertWPError( $result, 'insufficient_permissions' );
	}

	/**
	 * Test sanitize post ID with valid ID
	 */
	public function test_sanitize_post_id_valid() {
		// Create a post.
		$post_id = $this->create_test_post();
		
		// Sanitize valid ID.
		$result = BSP_Security_Helper::sanitize_post_id( $post_id );
		
		$this->assertEquals( $post_id, $result );
	}

	/**
	 * Test sanitize post ID with invalid ID
	 */
	public function test_sanitize_post_id_invalid() {
		// Test with zero.
		$result = BSP_Security_Helper::sanitize_post_id( 0 );
		$this->assertWPError( $result, 'invalid_post_id' );
		
		// Test with negative.
		$result = BSP_Security_Helper::sanitize_post_id( -1 );
		$this->assertWPError( $result, 'invalid_post_id' );
		
		// Test with string.
		$result = BSP_Security_Helper::sanitize_post_id( 'abc' );
		$this->assertWPError( $result, 'invalid_post_id' );
	}

	/**
	 * Test sanitize post ID with non-existent post
	 */
	public function test_sanitize_post_id_non_existent() {
		$result = BSP_Security_Helper::sanitize_post_id( 999999 );
		$this->assertWPError( $result, 'post_not_found' );
	}

	/**
	 * Test sanitize post IDs array
	 */
	public function test_sanitize_post_ids() {
		// Create posts.
		$post_ids = array(
			$this->create_test_post(),
			$this->create_test_post(),
			$this->create_test_post(),
		);
		
		// Test valid array.
		$result = BSP_Security_Helper::sanitize_post_ids( $post_ids );
		$this->assertEquals( $post_ids, $result );
		
		// Test with mixed valid/invalid.
		$mixed = array( $post_ids[0], 0, 'abc', $post_ids[1] );
		$result = BSP_Security_Helper::sanitize_post_ids( $mixed );
		$this->assertEquals( array( $post_ids[0], $post_ids[1] ), array_values( $result ) );
		
		// Test empty array.
		$result = BSP_Security_Helper::sanitize_post_ids( array() );
		$this->assertWPError( $result, 'no_valid_posts' );
	}

	/**
	 * Test validate file path
	 */
	public function test_validate_file_path() {
		// Create test directory.
		$test_dir = sys_get_temp_dir() . '/bsp-test/';
		wp_mkdir_p( $test_dir );
		
		// Create test file.
		$test_file = $test_dir . 'test.html';
		file_put_contents( $test_file, 'test' );
		
		// Test valid path.
		$result = BSP_Security_Helper::validate_file_path( $test_file );
		$this->assertTrue( $result );
		
		// Test with base directory restriction.
		$result = BSP_Security_Helper::validate_file_path( $test_file, $test_dir );
		$this->assertTrue( $result );
		
		// Test path traversal attempt.
		$result = BSP_Security_Helper::validate_file_path( $test_file, $test_dir . 'subdir/' );
		$this->assertWPError( $result, 'path_traversal' );
		
		// Clean up.
		unlink( $test_file );
		rmdir( $test_dir );
	}

	/**
	 * Test sanitize filename
	 */
	public function test_sanitize_filename() {
		// Test normal filename.
		$result = BSP_Security_Helper::sanitize_filename( 'test.html' );
		$this->assertEquals( 'test.html', $result );
		
		// Test with path (should strip).
		$result = BSP_Security_Helper::sanitize_filename( 'path/to/file.html' );
		$this->assertEquals( 'file.html', $result );
		
		// Test with special characters.
		$result = BSP_Security_Helper::sanitize_filename( 'test@#$%.html' );
		$this->assertEquals( 'test.html', $result );
		
		// Test multiple dots.
		$result = BSP_Security_Helper::sanitize_filename( 'test.backup.html' );
		$this->assertEquals( 'testbackup.html', $result );
	}

	/**
	 * Test validate file type
	 */
	public function test_validate_file_type() {
		// Test allowed type.
		$result = BSP_Security_Helper::validate_file_type( 'test.html', array( 'html' ) );
		$this->assertTrue( $result );
		
		// Test disallowed type.
		$result = BSP_Security_Helper::validate_file_type( 'test.php', array( 'html' ) );
		$this->assertWPError( $result, 'invalid_file_type' );
		
		// Test case insensitive.
		$result = BSP_Security_Helper::validate_file_type( 'test.HTML', array( 'html' ) );
		$this->assertTrue( $result );
	}

	/**
	 * Test escape methods
	 */
	public function test_escape_methods() {
		// Test esc_attr.
		$result = BSP_Security_Helper::esc_attr( 'test"value' );
		$this->assertEquals( 'test&quot;value', $result );
		
		// Test esc_attr with array.
		$result = BSP_Security_Helper::esc_attr( array( 'key' => 'value' ) );
		$this->assertEquals( '{&quot;key&quot;:&quot;value&quot;}', $result );
		
		// Test esc_html.
		$result = BSP_Security_Helper::esc_html( '<script>alert("test")</script>' );
		$this->assertEquals( '&lt;script&gt;alert(&quot;test&quot;)&lt;/script&gt;', $result );
	}

	/**
	 * Test sanitize text
	 */
	public function test_sanitize_text() {
		// Test normal text.
		$result = BSP_Security_Helper::sanitize_text( 'Normal text' );
		$this->assertEquals( 'Normal text', $result );
		
		// Test with HTML.
		$result = BSP_Security_Helper::sanitize_text( 'Text with <script>alert("xss")</script>' );
		$this->assertEquals( 'Text with alert("xss")', $result );
		
		// Test with slashes.
		$_POST['test'] = 'Text with \\slashes';
		$result = BSP_Security_Helper::sanitize_text( $_POST['test'] );
		$this->assertEquals( 'Text with slashes', $result );
	}

	/**
	 * Test is AJAX request
	 */
	public function test_is_ajax_request() {
		// Not AJAX by default.
		$this->assertFalse( BSP_Security_Helper::is_ajax_request() );
		
		// Define DOING_AJAX.
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
		
		$this->assertTrue( BSP_Security_Helper::is_ajax_request() );
	}

	/**
	 * Test generate token
	 */
	public function test_generate_token() {
		// Test default length.
		$token = BSP_Security_Helper::generate_token();
		$this->assertEquals( 32, strlen( $token ) );
		
		// Test custom length.
		$token = BSP_Security_Helper::generate_token( 16 );
		$this->assertEquals( 16, strlen( $token ) );
		
		// Test uniqueness.
		$token1 = BSP_Security_Helper::generate_token();
		$token2 = BSP_Security_Helper::generate_token();
		$this->assertNotEquals( $token1, $token2 );
	}

	/**
	 * Test sanitize hex color
	 */
	public function test_sanitize_hex_color() {
		// Test valid 6-digit hex.
		$result = BSP_Security_Helper::sanitize_hex_color( '#ff0000' );
		$this->assertEquals( '#ff0000', $result );
		
		// Test valid 3-digit hex.
		$result = BSP_Security_Helper::sanitize_hex_color( '#f00' );
		$this->assertEquals( '#f00', $result );
		
		// Test without hash.
		$result = BSP_Security_Helper::sanitize_hex_color( 'ff0000' );
		$this->assertEquals( '#ff0000', $result );
		
		// Test invalid color.
		$result = BSP_Security_Helper::sanitize_hex_color( 'invalid' );
		$this->assertWPError( $result, 'invalid_color' );
	}

	/**
	 * Test rate limit check
	 */
	public function test_check_rate_limit() {
		// First attempt should pass.
		$result = BSP_Security_Helper::check_rate_limit( 'test_action', 3, 60 );
		$this->assertTrue( $result );
		
		// Second attempt should pass.
		$result = BSP_Security_Helper::check_rate_limit( 'test_action', 3, 60 );
		$this->assertTrue( $result );
		
		// Third attempt should pass.
		$result = BSP_Security_Helper::check_rate_limit( 'test_action', 3, 60 );
		$this->assertTrue( $result );
		
		// Fourth attempt should fail.
		$result = BSP_Security_Helper::check_rate_limit( 'test_action', 3, 60 );
		$this->assertWPError( $result, 'rate_limited' );
	}

	/**
	 * Test validate URL
	 */
	public function test_validate_url() {
		// Test valid URL.
		$result = BSP_Security_Helper::validate_url( 'https://example.com/page' );
		$this->assertEquals( 'https://example.com/page', $result );
		
		// Test invalid URL.
		$result = BSP_Security_Helper::validate_url( 'not a url' );
		$this->assertWPError( $result, 'invalid_url' );
		
		// Test with allowed hosts.
		$result = BSP_Security_Helper::validate_url( 
			'https://example.com/page', 
			array( 'example.com' ) 
		);
		$this->assertEquals( 'https://example.com/page', $result );
		
		// Test with disallowed host.
		$result = BSP_Security_Helper::validate_url( 
			'https://evil.com/page', 
			array( 'example.com' ) 
		);
		$this->assertWPError( $result, 'invalid_host' );
	}
}
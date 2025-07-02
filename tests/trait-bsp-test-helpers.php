<?php
/**
 * Test Helpers Trait for Breakdance Static Pages
 *
 * @package Breakdance_Static_Pages
 * @subpackage Tests
 */

/**
 * Test helpers trait
 *
 * Provides common helper methods for tests.
 */
trait BSP_Test_Helpers {

	/**
	 * Assert WP_Error
	 *
	 * @param mixed  $actual   Actual value.
	 * @param string $code     Expected error code.
	 * @param string $message  Custom message.
	 */
	protected function assertWPError( $actual, $code = null, $message = '' ) {
		$this->assertInstanceOf( 'WP_Error', $actual, $message ?: 'Value should be a WP_Error' );
		
		if ( $code ) {
			$this->assertEquals( $code, $actual->get_error_code(), 'Error code should match' );
		}
	}

	/**
	 * Assert not WP_Error
	 *
	 * @param mixed  $actual  Actual value.
	 * @param string $message Custom message.
	 */
	protected function assertNotWPError( $actual, $message = '' ) {
		$this->assertNotInstanceOf( 'WP_Error', $actual, $message ?: 'Value should not be a WP_Error' );
	}

	/**
	 * Assert hook was added
	 *
	 * @param string   $tag      Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Number of arguments.
	 */
	protected function assertHookAdded( $tag, $callback, $priority = 10, $args = 1 ) {
		$this->assertNotFalse(
			has_filter( $tag, $callback ),
			"Hook '$tag' should be added"
		);
	}

	/**
	 * Assert hook was not added
	 *
	 * @param string   $tag      Hook name.
	 * @param callable $callback Callback.
	 */
	protected function assertHookNotAdded( $tag, $callback ) {
		$this->assertFalse(
			has_filter( $tag, $callback ),
			"Hook '$tag' should not be added"
		);
	}

	/**
	 * Assert option exists
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Expected value.
	 */
	protected function assertOptionExists( $option, $value = null ) {
		$actual = get_option( $option );
		$this->assertNotFalse( $actual, "Option '$option' should exist" );
		
		if ( null !== $value ) {
			$this->assertEquals( $value, $actual, "Option '$option' value should match" );
		}
	}

	/**
	 * Assert option does not exist
	 *
	 * @param string $option Option name.
	 */
	protected function assertOptionNotExists( $option ) {
		$this->assertFalse(
			get_option( $option ),
			"Option '$option' should not exist"
		);
	}

	/**
	 * Assert post meta exists
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Expected value.
	 */
	protected function assertPostMetaExists( $post_id, $key, $value = null ) {
		$actual = get_post_meta( $post_id, $key, true );
		$this->assertNotEmpty( $actual, "Post meta '$key' should exist for post $post_id" );
		
		if ( null !== $value ) {
			$this->assertEquals( $value, $actual, "Post meta '$key' value should match" );
		}
	}

	/**
	 * Assert post meta does not exist
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 */
	protected function assertPostMetaNotExists( $post_id, $key ) {
		$this->assertEmpty(
			get_post_meta( $post_id, $key, true ),
			"Post meta '$key' should not exist for post $post_id"
		);
	}

	/**
	 * Assert cron event scheduled
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Arguments.
	 */
	protected function assertCronScheduled( $hook, $args = array() ) {
		$this->assertNotFalse(
			wp_next_scheduled( $hook, $args ),
			"Cron event '$hook' should be scheduled"
		);
	}

	/**
	 * Assert cron event not scheduled
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Arguments.
	 */
	protected function assertCronNotScheduled( $hook, $args = array() ) {
		$this->assertFalse(
			wp_next_scheduled( $hook, $args ),
			"Cron event '$hook' should not be scheduled"
		);
	}

	/**
	 * Assert file contains
	 *
	 * @param string $needle   String to find.
	 * @param string $file     File path.
	 * @param string $message  Custom message.
	 */
	protected function assertFileContains( $needle, $file, $message = '' ) {
		$this->assertFileExists( $file );
		$contents = file_get_contents( $file );
		$this->assertStringContainsString( 
			$needle, 
			$contents, 
			$message ?: "File should contain '$needle'"
		);
	}

	/**
	 * Assert file does not contain
	 *
	 * @param string $needle   String to find.
	 * @param string $file     File path.
	 * @param string $message  Custom message.
	 */
	protected function assertFileNotContains( $needle, $file, $message = '' ) {
		$this->assertFileExists( $file );
		$contents = file_get_contents( $file );
		$this->assertStringNotContainsString( 
			$needle, 
			$contents, 
			$message ?: "File should not contain '$needle'"
		);
	}

	/**
	 * Create mock AJAX request
	 *
	 * @param array $data Request data.
	 */
	protected function mock_ajax_request( $data = array() ) {
		$_POST = array_merge( array(
			'action' => 'test_action',
			'nonce'  => wp_create_nonce( 'bsp_nonce' ),
		), $data );

		// Set current user as admin.
		wp_set_current_user( 1 );
	}

	/**
	 * Capture AJAX response
	 *
	 * @param callable $callback Callback to execute.
	 * @return array Response data.
	 */
	protected function capture_ajax_response( $callback ) {
		ob_start();
		
		try {
			$callback();
		} catch ( WPDieException $e ) {
			// Expected for AJAX responses.
		}
		
		$response = ob_get_clean();
		return json_decode( $response, true );
	}

	/**
	 * Assert AJAX success
	 *
	 * @param array  $response Response data.
	 * @param string $message  Custom message.
	 */
	protected function assertAjaxSuccess( $response, $message = '' ) {
		$this->assertTrue( 
			isset( $response['success'] ) && $response['success'], 
			$message ?: 'AJAX response should be successful'
		);
	}

	/**
	 * Assert AJAX error
	 *
	 * @param array  $response Response data.
	 * @param string $message  Custom message.
	 */
	protected function assertAjaxError( $response, $message = '' ) {
		$this->assertTrue( 
			isset( $response['success'] ) && ! $response['success'], 
			$message ?: 'AJAX response should be an error'
		);
	}

	/**
	 * Get test file path
	 *
	 * @param string $file File name.
	 * @return string Full path.
	 */
	protected function get_test_file( $file ) {
		return dirname( __FILE__ ) . '/fixtures/' . $file;
	}

	/**
	 * Create test HTML content
	 *
	 * @param array $args Arguments.
	 * @return string HTML content.
	 */
	protected function create_test_html( $args = array() ) {
		$defaults = array(
			'title'   => 'Test Page',
			'content' => '<p>Test content</p>',
			'scripts' => '',
			'styles'  => '',
		);

		$args = wp_parse_args( $args, $defaults );

		return sprintf(
			'<!DOCTYPE html>
<html>
<head>
	<title>%s</title>
	%s
</head>
<body>
	<div id="content">
		%s
	</div>
	%s
</body>
</html>',
			esc_html( $args['title'] ),
			$args['styles'],
			$args['content'],
			$args['scripts']
		);
	}
}
<?php
/**
 * Plugin Integration Tests
 *
 * @package Breakdance_Static_Pages
 * @subpackage Tests/Integration
 */

/**
 * Test plugin integration
 */
class BSP_Plugin_Integration_Test extends BSP_Test_Case {

	use BSP_Test_Helpers;

	/**
	 * Test plugin activation
	 */
	public function test_plugin_activation() {
		// Simulate plugin activation.
		$plugin = Breakdance_Static_Pages::get_instance();
		$plugin->activate();
		
		// Check options are created.
		$this->assertOptionExists( 'bsp_db_version', BSP_VERSION );
		$this->assertOptionExists( 'bsp_activation_time' );
		
		// Check directories are created.
		$upload_dir = wp_upload_dir();
		$static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/';
		$this->assertDirectoryExists( $static_dir );
		$this->assertDirectoryExists( $static_dir . 'pages/' );
		$this->assertDirectoryExists( $static_dir . 'assets/' );
		
		// Check .htaccess file.
		$this->assertFileExists( $static_dir . '.htaccess' );
		$this->assertFileContains( 'Breakdance Static Pages', $static_dir . '.htaccess' );
		
		// Check cron events are scheduled.
		$this->assertCronScheduled( 'bsp_cleanup_old_static_files' );
		$this->assertCronScheduled( 'bsp_cleanup_locks' );
	}

	/**
	 * Test plugin deactivation
	 */
	public function test_plugin_deactivation() {
		// Schedule some events first.
		wp_schedule_event( time(), 'daily', 'bsp_cleanup_old_static_files' );
		wp_schedule_event( time(), 'hourly', 'bsp_cleanup_locks' );
		
		// Simulate plugin deactivation.
		$plugin = Breakdance_Static_Pages::get_instance();
		$plugin->deactivate();
		
		// Check cron events are cleared.
		$this->assertCronNotScheduled( 'bsp_cleanup_old_static_files' );
		$this->assertCronNotScheduled( 'bsp_cleanup_locks' );
	}

	/**
	 * Test content update handling
	 */
	public function test_content_update_handling() {
		$post_id = $this->create_test_post();
		
		// Simulate content update.
		$plugin = Breakdance_Static_Pages::get_instance();
		$plugin->handle_content_update( $post_id );
		
		// Check regeneration is scheduled.
		$this->assertCronScheduled( 'bsp_regenerate_static_page', array( $post_id ) );
	}

	/**
	 * Test content update with disabled static generation
	 */
	public function test_content_update_disabled() {
		$post_id = $this->create_test_post();
		
		// Disable static generation.
		update_post_meta( $post_id, '_bsp_static_enabled', '' );
		
		// Simulate content update.
		$plugin = Breakdance_Static_Pages::get_instance();
		$plugin->handle_content_update( $post_id );
		
		// Check regeneration is not scheduled.
		$this->assertCronNotScheduled( 'bsp_regenerate_static_page', array( $post_id ) );
	}

	/**
	 * Test post save handling
	 */
	public function test_post_save_handling() {
		$post_id = $this->create_test_post();
		$post = get_post( $post_id );
		
		// Simulate post save.
		$plugin = Breakdance_Static_Pages::get_instance();
		$plugin->handle_post_save( $post_id, $post );
		
		// Check regeneration is scheduled.
		$this->assertCronScheduled( 'bsp_regenerate_static_page', array( $post_id ) );
	}

	/**
	 * Test post save with unsupported post type
	 */
	public function test_post_save_unsupported_type() {
		// Create attachment post.
		$post_id = $this->factory->post->create( array(
			'post_type' => 'attachment',
		) );
		$post = get_post( $post_id );
		
		// Simulate post save.
		$plugin = Breakdance_Static_Pages::get_instance();
		$plugin->handle_post_save( $post_id, $post );
		
		// Check regeneration is not scheduled.
		$this->assertCronNotScheduled( 'bsp_regenerate_static_page', array( $post_id ) );
	}

	/**
	 * Test admin bar menu for static-enabled page
	 */
	public function test_admin_bar_menu_static_enabled() {
		$post_id = $this->create_test_post();
		
		// Set up global post.
		global $post;
		$post = get_post( $post_id );
		
		// Set up admin user.
		wp_set_current_user( 1 );
		
		// Create admin bar.
		$wp_admin_bar = new WP_Admin_Bar();
		
		// Simulate admin bar menu.
		$plugin = Breakdance_Static_Pages::get_instance();
		$plugin->add_admin_bar_menu( $wp_admin_bar );
		
		// Check main menu item.
		$node = $wp_admin_bar->get_node( 'bsp-static-control' );
		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'Static Active', $node->title );
		
		// Check regenerate submenu.
		$regen_node = $wp_admin_bar->get_node( 'bsp-regenerate' );
		$this->assertNotNull( $regen_node );
		$this->assertEquals( 'bsp-static-control', $regen_node->parent );
	}

	/**
	 * Test admin bar menu for non-static page
	 */
	public function test_admin_bar_menu_static_disabled() {
		$post_id = $this->create_test_post();
		
		// Disable static generation.
		update_post_meta( $post_id, '_bsp_static_enabled', '' );
		
		// Set up global post.
		global $post;
		$post = get_post( $post_id );
		
		// Set up admin user.
		wp_set_current_user( 1 );
		
		// Create admin bar.
		$wp_admin_bar = new WP_Admin_Bar();
		
		// Simulate admin bar menu.
		$plugin = Breakdance_Static_Pages::get_instance();
		$plugin->add_admin_bar_menu( $wp_admin_bar );
		
		// Check main menu item.
		$node = $wp_admin_bar->get_node( 'bsp-static-control' );
		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'Dynamic', $node->title );
		
		// Check regenerate submenu doesn't exist.
		$regen_node = $wp_admin_bar->get_node( 'bsp-regenerate' );
		$this->assertNull( $regen_node );
	}

	/**
	 * Test admin bar menu without permission
	 */
	public function test_admin_bar_menu_no_permission() {
		$post_id = $this->create_test_post();
		
		// Set up global post.
		global $post;
		$post = get_post( $post_id );
		
		// Set up non-admin user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		
		// Create admin bar.
		$wp_admin_bar = new WP_Admin_Bar();
		
		// Simulate admin bar menu.
		$plugin = Breakdance_Static_Pages::get_instance();
		$plugin->add_admin_bar_menu( $wp_admin_bar );
		
		// Check no menu items added.
		$node = $wp_admin_bar->get_node( 'bsp-static-control' );
		$this->assertNull( $node );
	}

	/**
	 * Test get static file path
	 */
	public function test_get_static_file_path() {
		$post_id = $this->create_test_post();
		
		$path = Breakdance_Static_Pages::get_static_file_path( $post_id );
		
		$upload_dir = wp_upload_dir();
		$expected = $upload_dir['basedir'] . '/breakdance-static-pages/pages/page-' . $post_id . '.html';
		
		$this->assertEquals( $expected, $path );
	}

	/**
	 * Test get static file URL
	 */
	public function test_get_static_file_url() {
		$post_id = $this->create_test_post();
		
		$url = Breakdance_Static_Pages::get_static_file_url( $post_id );
		
		$this->assertStringContainsString( 'admin-ajax.php', $url );
		$this->assertStringContainsString( 'action=bsp_serve_static', $url );
		$this->assertStringContainsString( 'file=pages/page-' . $post_id . '.html', $url );
	}

	/**
	 * Test should serve static - enabled and file exists
	 */
	public function test_should_serve_static_enabled() {
		$post_id = $this->create_test_post();
		
		// Generate static file.
		$this->generate_static_file( $post_id );
		
		$result = Breakdance_Static_Pages::should_serve_static( $post_id );
		$this->assertTrue( $result );
	}

	/**
	 * Test should serve static - disabled
	 */
	public function test_should_serve_static_disabled() {
		$post_id = $this->create_test_post();
		
		// Disable static generation.
		update_post_meta( $post_id, '_bsp_static_enabled', '' );
		
		// Generate static file.
		$this->generate_static_file( $post_id );
		
		$result = Breakdance_Static_Pages::should_serve_static( $post_id );
		$this->assertFalse( $result );
	}

	/**
	 * Test should serve static - file doesn't exist
	 */
	public function test_should_serve_static_no_file() {
		$post_id = $this->create_test_post();
		
		$result = Breakdance_Static_Pages::should_serve_static( $post_id );
		$this->assertFalse( $result );
	}

	/**
	 * Test should serve static - file too old
	 */
	public function test_should_serve_static_old_file() {
		$post_id = $this->create_test_post();
		
		// Generate static file.
		$this->generate_static_file( $post_id );
		
		// Backdate file.
		$file_path = Breakdance_Static_Pages::get_static_file_path( $post_id );
		touch( $file_path, time() - 2 * DAY_IN_SECONDS );
		
		$result = Breakdance_Static_Pages::should_serve_static( $post_id );
		$this->assertFalse( $result );
	}

	/**
	 * Test settings link is added
	 */
	public function test_settings_link_added() {
		$plugin = Breakdance_Static_Pages::get_instance();
		
		$links = array( 'deactivate' => 'Deactivate' );
		$result = $plugin->add_settings_link( $links );
		
		$this->assertCount( 2, $result );
		$this->assertStringContainsString( 'Settings', $result[0] );
		$this->assertStringContainsString( 'tools.php?page=breakdance-static-pages', $result[0] );
	}

	/**
	 * Test singleton pattern
	 */
	public function test_singleton_pattern() {
		$instance1 = Breakdance_Static_Pages::get_instance();
		$instance2 = Breakdance_Static_Pages::get_instance();
		
		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test hooks are registered
	 */
	public function test_hooks_registered() {
		// Check activation/deactivation hooks.
		$this->assertHookAdded( 'plugins_loaded', array( Breakdance_Static_Pages::get_instance(), 'load_plugin' ) );
		
		// Check action hooks are registered.
		$this->assertNotFalse( has_action( 'acf/save_post' ) );
		$this->assertNotFalse( has_action( 'save_post' ) );
		$this->assertNotFalse( has_action( 'admin_bar_menu' ) );
		
		// Check filter hooks are registered.
		$this->assertNotFalse( has_filter( 'plugin_action_links_' . BSP_PLUGIN_BASENAME ) );
	}

	/**
	 * Test end-to-end static page generation
	 */
	public function test_end_to_end_generation() {
		$post_id = $this->create_test_post( array(
			'post_title'   => 'Test Static Page',
			'post_content' => '<h1>Static Page Content</h1><p>This is a test.</p>',
		) );
		
		// Mock HTTP request.
		$html = $this->create_test_html( array(
			'title'   => 'Test Static Page',
			'content' => '<h1>Static Page Content</h1><p>This is a test.</p>',
		) );
		add_filter( 'pre_http_request', $this->mock_http_request( $html ), 10, 3 );
		
		// Generate static file.
		$generator = new BSP_Static_Generator();
		$result = $generator->generate_static_page( $post_id );
		
		// Remove filter.
		remove_all_filters( 'pre_http_request' );
		
		// Verify result.
		$this->assertTrue( $result['success'] );
		$this->assertStaticFileExists( $post_id );
		
		// Check file content.
		$file_path = Breakdance_Static_Pages::get_static_file_path( $post_id );
		$this->assertFileContains( 'Test Static Page', $file_path );
		$this->assertFileContains( 'Static Page Content', $file_path );
		$this->assertFileContains( 'Generated by Breakdance Static Pages', $file_path );
		
		// Check meta values.
		$this->assertPostMetaExists( $post_id, '_bsp_static_generated' );
		$this->assertPostMetaExists( $post_id, '_bsp_static_file_size' );
		$this->assertPostMetaExists( $post_id, '_bsp_static_etag' );
		
		// Test should serve static.
		$this->assertTrue( Breakdance_Static_Pages::should_serve_static( $post_id ) );
		
		// Test delete.
		$generator->delete_static_page( $post_id );
		$this->assertStaticFileNotExists( $post_id );
		$this->assertPostMetaNotExists( $post_id, '_bsp_static_generated' );
	}
}
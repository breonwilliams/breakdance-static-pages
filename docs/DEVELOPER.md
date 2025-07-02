# Developer Documentation

This document provides detailed information for developers who want to extend, customize, or contribute to the Breakdance Static Pages plugin.

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [Class Structure](#class-structure)
- [Hooks and Filters](#hooks-and-filters)
- [API Reference](#api-reference)
- [Extending the Plugin](#extending-the-plugin)
- [Development Setup](#development-setup)
- [Contributing](#contributing)

## Architecture Overview

The plugin follows a modular architecture with clear separation of concerns:

```
breakdance-static-pages/
├── includes/                 # Core classes
│   ├── class-static-generator.php
│   ├── class-ajax-handler.php
│   ├── class-admin-interface.php
│   ├── class-cache-manager.php
│   ├── class-file-lock-manager.php
│   ├── class-error-handler.php
│   ├── class-security-helper.php
│   └── ...
├── assets/                   # CSS/JS files
├── tests/                    # Test suite
├── docs/                     # Documentation
└── breakdance-static-pages.php  # Main plugin file
```

### Core Components

1. **Static Generator** - Handles HTML capture and file generation
2. **AJAX Handler** - Manages all AJAX endpoints and security
3. **Admin Interface** - Provides the administrative dashboard
4. **Cache Manager** - Handles file caching and cleanup
5. **File Lock Manager** - Prevents concurrent generation conflicts
6. **Error Handler** - Centralized error logging and notifications
7. **Security Helper** - Input validation and security functions

## Class Structure

### Main Plugin Class

```php
final class Breakdance_Static_Pages {
    private static $instance = null;
    
    public static function get_instance() {
        // Singleton implementation
    }
    
    private function __construct() {
        // Initialize hooks and components
    }
    
    public function activate() {
        // Plugin activation logic
    }
    
    public function deactivate() {
        // Plugin deactivation logic
    }
}
```

### Static Generator

```php
class BSP_Static_Generator {
    public function generate_static_page( $post_id ) {
        // Main generation method
    }
    
    private function capture_page_html( $url, $post_id ) {
        // Capture HTML content
    }
    
    private function optimize_html( $html, $post_id ) {
        // Optimize HTML for static serving
    }
    
    private function save_static_file( $file_path, $content ) {
        // Save file with atomic operations
    }
}
```

### Security Helper

```php
class BSP_Security_Helper {
    public static function verify_ajax_request( $nonce, $action, $capability ) {
        // Verify AJAX security
    }
    
    public static function sanitize_post_id( $post_id ) {
        // Sanitize and validate post ID
    }
    
    public static function validate_file_path( $path, $base_dir ) {
        // Prevent directory traversal
    }
}
```

## Hooks and Filters

### Action Hooks

The plugin provides numerous action hooks for extending functionality:

#### Generation Hooks

```php
// Before generation starts
do_action( 'bsp_before_generate_static', $post_id );

// After generation completes
do_action( 'bsp_after_generate_static', $post_id, $file_path, $success );

// Before file deletion
do_action( 'bsp_before_delete_static', $post_id );

// After file deletion
do_action( 'bsp_after_delete_static', $post_id, $success );
```

#### Content Hooks

```php
// When content is updated
do_action( 'bsp_content_updated', $post_id );

// Plugin activation
do_action( 'bsp_activated' );

// Plugin deactivation
do_action( 'bsp_deactivated' );
```

#### Queue Hooks

```php
// Queue processing starts
do_action( 'bsp_queue_processing_start', $items );

// Queue processing completes
do_action( 'bsp_queue_processing_complete', $results );

// Error logged
do_action( 'bsp_error_logged', $error_data );
```

### Filter Hooks

#### Generation Control

```php
// Control whether to generate static file
apply_filters( 'bsp_should_generate_static', $should_generate, $post_id );

// Modify content before saving
apply_filters( 'bsp_static_file_content', $content, $post_id, $url );

// Custom file paths
apply_filters( 'bsp_static_file_path', $path, $post_id );

// Custom file URLs
apply_filters( 'bsp_static_file_url', $url, $post_id );
```

#### Performance Settings

```php
// Memory limits
apply_filters( 'bsp_memory_limit', '256M' );

// Time limits
apply_filters( 'bsp_time_limit', 300 );

// Queue batch size
apply_filters( 'bsp_queue_batch_size', 5 );

// Queue priority
apply_filters( 'bsp_queue_priority', 10, $action );
```

#### Cache Settings

```php
// Cache duration
apply_filters( 'bsp_cache_duration', $duration, $key );

// Whether to cache
apply_filters( 'bsp_should_cache', $should_cache, $key );

// File max age
apply_filters( 'bsp_static_file_max_age', DAY_IN_SECONDS );
```

## API Reference

### Public Methods

#### Breakdance_Static_Pages

```php
// Get singleton instance
$plugin = Breakdance_Static_Pages::get_instance();

// Get static file path
$path = Breakdance_Static_Pages::get_static_file_path( $post_id );

// Get static file URL
$url = Breakdance_Static_Pages::get_static_file_url( $post_id );

// Check if should serve static
$should_serve = Breakdance_Static_Pages::should_serve_static( $post_id );
```

#### BSP_Static_Generator

```php
$generator = new BSP_Static_Generator();

// Generate single page
$result = $generator->generate_static_page( $post_id );

// Generate multiple pages
$results = $generator->generate_multiple_pages( $post_ids );

// Delete static page
$success = $generator->delete_static_page( $post_id );

// Check if content changed
$changed = $generator->has_content_changed( $post_id );

// Get generation statistics
$stats = $generator->get_generation_stats();
```

#### BSP_Security_Helper

```php
// Verify AJAX request
$result = BSP_Security_Helper::verify_ajax_request( $nonce, $action, $capability );

// Sanitize post ID
$post_id = BSP_Security_Helper::sanitize_post_id( $input );

// Validate file path
$valid = BSP_Security_Helper::validate_file_path( $path, $base_dir );

// Generate secure token
$token = BSP_Security_Helper::generate_token( $length );
```

### Constants

```php
// Plugin version
BSP_VERSION

// Plugin directory
BSP_PLUGIN_DIR

// Plugin URL
BSP_PLUGIN_URL

// Plugin file
BSP_PLUGIN_FILE

// Plugin basename
BSP_PLUGIN_BASENAME
```

## Extending the Plugin

### Custom Static File Paths

```php
add_filter( 'bsp_static_file_path', function( $path, $post_id ) {
    $post = get_post( $post_id );
    $upload_dir = wp_upload_dir();
    
    // Organize by post type
    return $upload_dir['basedir'] . '/static/' . $post->post_type . '/' . $post_id . '.html';
}, 10, 2 );
```

### Content Modification

```php
add_filter( 'bsp_static_file_content', function( $content, $post_id, $url ) {
    // Add custom meta tags
    $meta = '<meta name="static-generated" content="' . time() . '">';
    $content = str_replace( '</head>', $meta . '</head>', $content );
    
    // Remove admin elements
    $content = preg_replace( '/<div[^>]*class="[^"]*admin[^"]*"[^>]*>.*?<\/div>/s', '', $content );
    
    return $content;
}, 10, 3 );
```

### Custom Generation Logic

```php
add_filter( 'bsp_should_generate_static', function( $should_generate, $post_id ) {
    // Don't generate for specific categories
    if ( has_category( 'no-static', $post_id ) ) {
        return false;
    }
    
    // Only generate during specific hours
    $current_hour = (int) current_time( 'H' );
    if ( $current_hour < 2 || $current_hour > 22 ) {
        return false;
    }
    
    return $should_generate;
}, 10, 2 );
```

### Performance Monitoring

```php
add_action( 'bsp_after_generate_static', function( $post_id, $file_path, $success ) {
    $generation_time = microtime( true ) - $GLOBALS['bsp_start_time'];
    $file_size = $success ? filesize( $file_path ) : 0;
    
    // Log to custom analytics
    error_log( sprintf(
        'BSP Generation: Post %d, Time: %.2fs, Size: %s, Success: %s',
        $post_id,
        $generation_time,
        size_format( $file_size ),
        $success ? 'Yes' : 'No'
    ) );
    
    // Send to monitoring service
    if ( function_exists( 'send_metrics' ) ) {
        send_metrics( 'static_generation', [
            'post_id' => $post_id,
            'duration' => $generation_time,
            'file_size' => $file_size,
            'success' => $success
        ] );
    }
});
```

### Custom Cache Management

```php
add_filter( 'bsp_cache_duration', function( $duration, $key ) {
    // Shorter duration for specific content
    if ( strpos( $key, 'news_' ) === 0 ) {
        return 300; // 5 minutes
    }
    
    // Longer duration for static content
    if ( strpos( $key, 'about_' ) === 0 ) {
        return DAY_IN_SECONDS * 7; // 1 week
    }
    
    return $duration;
}, 10, 2 );
```

### Queue Priority Management

```php
add_filter( 'bsp_queue_priority', function( $priority, $action ) {
    // Higher priority for homepage
    if ( $action === 'generate' && is_front_page() ) {
        return 1; // Highest priority
    }
    
    // Lower priority for old posts
    global $post;
    if ( $post && strtotime( $post->post_date ) < strtotime( '-1 year' ) ) {
        return 50; // Lower priority
    }
    
    return $priority;
}, 10, 2 );
```

## Development Setup

### Requirements

- PHP 7.4+
- WordPress 5.0+
- Composer
- PHPUnit
- Node.js (for asset building)

### Installation

```bash
# Clone repository
git clone https://github.com/your-username/breakdance-static-pages.git
cd breakdance-static-pages

# Install PHP dependencies
composer install

# Install Node dependencies (if applicable)
npm install

# Set up WordPress test environment
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

### Running Tests

```bash
# Run all tests
composer test

# Run specific test suite
composer test:unit
composer test:integration

# Run with coverage
composer test:coverage

# Code style check
composer phpcs

# Fix code style
composer phpcbf
```

### Building Assets

```bash
# Build CSS/JS for development
npm run dev

# Build for production
npm run build

# Watch for changes
npm run watch
```

### Debugging

Enable debug mode in `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'BSP_DEBUG', true );
```

Use the built-in error handler:

```php
$error_handler = BSP_Error_Handler::get_instance();
$error_handler->log_error( 'custom', 'Debug message', 'info', [ 'data' => $debug_data ] );
```

### Performance Profiling

```php
// Profile generation time
add_action( 'bsp_before_generate_static', function() {
    $GLOBALS['bsp_profile_start'] = microtime( true );
});

add_action( 'bsp_after_generate_static', function( $post_id ) {
    $duration = microtime( true ) - $GLOBALS['bsp_profile_start'];
    error_log( "Generation time for post $post_id: {$duration}s" );
});
```

## Contributing

### Code Standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- Use proper PHPDoc comments
- Write comprehensive tests
- Maintain backwards compatibility

### Pull Request Process

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Make your changes with tests
4. Run test suite: `composer test`
5. Check code style: `composer phpcs`
6. Commit with clear messages
7. Push and create pull request

### Testing Guidelines

- Write unit tests for all new methods
- Include integration tests for feature interactions
- Test both success and failure scenarios
- Mock external dependencies
- Ensure 80%+ code coverage

### Documentation Updates

- Update README.md for user-facing changes
- Update DEVELOPER.md for API changes
- Add inline code comments
- Update hook documentation
- Include examples for complex features

### Release Process

1. Update version numbers
2. Update CHANGELOG.md
3. Run full test suite
4. Tag release: `git tag v1.x.x`
5. Create GitHub release
6. Deploy to WordPress.org (if applicable)

## Security Considerations

### Input Validation

Always validate and sanitize inputs:

```php
$post_id = BSP_Security_Helper::sanitize_post_id( $_POST['post_id'] );
if ( is_wp_error( $post_id ) ) {
    wp_send_json_error( $post_id->get_error_message() );
}
```

### Nonce Verification

Verify nonces for all AJAX requests:

```php
$verification = BSP_Security_Helper::verify_ajax_request( $nonce );
if ( is_wp_error( $verification ) ) {
    wp_send_json_error( $verification->get_error_message() );
}
```

### File Operations

Always validate file paths:

```php
$validation = BSP_Security_Helper::validate_file_path( $path, $base_dir );
if ( is_wp_error( $validation ) ) {
    return $validation;
}
```

### Capability Checks

Verify user permissions:

```php
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'Insufficient permissions' );
}
```

## Performance Best Practices

### Memory Management

- Use streaming for large files
- Unset variables when done
- Monitor memory usage
- Implement memory limits

### Database Optimization

- Use prepared statements
- Limit query results
- Cache expensive queries
- Clean up temporary data

### File Operations

- Use atomic file operations
- Implement file locking
- Clean up temporary files
- Optimize file permissions

### Caching Strategy

- Cache expensive operations
- Implement cache invalidation
- Use appropriate cache duration
- Monitor cache hit rates

## Troubleshooting

### Common Development Issues

**Tests Failing**
- Check WordPress test environment setup
- Verify database permissions
- Ensure all dependencies installed

**Memory Errors**
- Increase PHP memory limit
- Use streaming for large operations
- Profile memory usage

**Permission Errors**
- Check file system permissions
- Verify upload directory access
- Test with different user roles

**Performance Issues**
- Profile slow operations
- Optimize database queries
- Implement proper caching
- Monitor resource usage
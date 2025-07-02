# Troubleshooting Guide

This guide helps you diagnose and resolve common issues with the Breakdance Static Pages plugin.

## Table of Contents

- [Quick Diagnostics](#quick-diagnostics)
- [Common Issues](#common-issues)
- [Error Messages](#error-messages)
- [Performance Problems](#performance-problems)
- [Configuration Issues](#configuration-issues)
- [Debug Techniques](#debug-techniques)
- [Getting Help](#getting-help)

## Quick Diagnostics

### Health Check Tool

Before troubleshooting manually, run the built-in health check:

1. Go to `Tools > Breakdance Static Pages`
2. Click "Run Health Check"
3. Review all warnings and errors
4. Follow recommended actions

### Basic System Check

```bash
# Check PHP version
php -v

# Check memory limit
php -i | grep memory_limit

# Check WordPress version
wp core version

# Check plugin status
wp plugin status breakdance-static-pages
```

### File Permissions Check

```bash
# Check upload directory permissions
ls -la wp-content/uploads/

# Check plugin directory permissions
ls -la wp-content/plugins/breakdance-static-pages/

# Check if directories are writable
[ -w wp-content/uploads/breakdance-static-pages/ ] && echo "Writable" || echo "Not writable"
```

## Common Issues

### 1. Plugin Won't Activate

**Symptoms:**
- "Plugin could not be activated because it triggered a fatal error"
- White screen during activation
- Error about missing dependencies

**Solutions:**

1. **Check PHP Version**
   ```bash
   php -v
   # Ensure PHP 7.4 or higher
   ```

2. **Increase Memory Limit**
   ```php
   // Add to wp-config.php
   ini_set('memory_limit', '256M');
   ```

3. **Check for Plugin Conflicts**
   ```bash
   # Deactivate all plugins except Breakdance Static Pages
   wp plugin deactivate --all --skip-plugins=breakdance-static-pages
   ```

4. **Re-upload Plugin Files**
   - Download fresh copy
   - Replace existing files via FTP
   - Ensure all files uploaded correctly

### 2. Static Files Not Generating

**Symptoms:**
- "Generate Static" button doesn't work
- No files in `/wp-content/uploads/breakdance-static-pages/pages/`
- Generation appears to succeed but no file created

**Diagnosis:**
```bash
# Check if generation was attempted
tail -50 wp-content/debug.log | grep BSP

# Check directory permissions
ls -la wp-content/uploads/breakdance-static-pages/

# Check for lock files
ls -la wp-content/uploads/breakdance-static-pages/locks/
```

**Solutions:**

1. **Fix Directory Permissions**
   ```bash
   chmod 755 wp-content/uploads/breakdance-static-pages/
   chmod 755 wp-content/uploads/breakdance-static-pages/pages/
   chown www-data:www-data wp-content/uploads/breakdance-static-pages/
   ```

2. **Clear Stuck Locks**
   ```bash
   # Remove old lock files
   find wp-content/uploads/breakdance-static-pages/locks/ -name "*.lock" -mtime +1 -delete
   ```

3. **Increase PHP Limits**
   ```php
   // Add to wp-config.php
   ini_set('memory_limit', '512M');
   ini_set('max_execution_time', 300);
   ```

4. **Check for HTTP Errors**
   ```php
   // Enable debug mode
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('BSP_DEBUG', true);
   ```

### 3. Static Files Not Accessible

**Symptoms:**
- "Invalid file path" error when viewing static files
- 404 errors for static file URLs
- Admin bar shows "Dynamic" instead of "Static Active"

**Solutions:**

1. **Check File Exists**
   ```bash
   ls -la wp-content/uploads/breakdance-static-pages/pages/
   ```

2. **Verify .htaccess Rules**
   ```bash
   cat wp-content/uploads/breakdance-static-pages/.htaccess
   ```
   Should contain security rules protecting direct access.

3. **Check Admin Permissions**
   - Ensure you're logged in as administrator
   - Check user capabilities: `current_user_can('manage_options')`

4. **Clear WordPress Cache**
   ```bash
   # If using caching plugins
   wp cache flush
   
   # Clear object cache
   wp transient delete --all
   ```

### 4. Generation Fails with Memory Errors

**Symptoms:**
- "Fatal error: Allowed memory size exhausted"
- Generation stops mid-process
- Large pages fail but small pages work

**Solutions:**

1. **Increase PHP Memory Limit**
   ```php
   // Method 1: wp-config.php
   ini_set('memory_limit', '512M');
   
   // Method 2: .htaccess
   php_value memory_limit 512M
   
   // Method 3: Custom filter
   add_filter('bsp_memory_limit', function() {
       return '512M';
   });
   ```

2. **Use Streaming for Large Content**
   ```php
   // Enable streaming mode for large files
   add_filter('bsp_use_streaming', '__return_true');
   ```

3. **Optimize Page Content**
   - Reduce large images before generation
   - Minimize inline CSS/JS
   - Use external stylesheets instead of inline styles

### 5. Slow Generation Performance

**Symptoms:**
- Generation takes very long time
- Timeouts during bulk operations
- Server becomes unresponsive during generation

**Solutions:**

1. **Enable Queue Processing**
   ```php
   // Use background queue for bulk operations
   add_filter('bsp_use_queue_for_bulk', '__return_true');
   ```

2. **Reduce Batch Size**
   ```php
   add_filter('bsp_queue_batch_size', function() {
       return 3; // Process fewer items at once
   });
   ```

3. **Optimize Server Resources**
   ```php
   // Increase time limits
   add_filter('bsp_time_limit', function() {
       return 600; // 10 minutes
   });
   ```

4. **Use Cron for Background Processing**
   ```bash
   # Set up system cron instead of WordPress cron
   # Add to system crontab:
   */5 * * * * /usr/bin/wp cron event run --due-now --path=/path/to/wordpress
   ```

## Error Messages

### "Plugin could not be activated because it triggered a fatal error"

**Cause:** Usually PHP version incompatibility or missing dependencies.

**Solution:**
1. Check PHP version (requires 7.4+)
2. Increase memory limit
3. Check for plugin conflicts
4. Review error logs for specific error

### "Invalid file path"

**Cause:** Security validation failing or incorrect file paths.

**Solution:**
1. Check file actually exists
2. Verify admin permissions
3. Clear any corrupted cache
4. Check for special characters in post slug

### "Generation failed: HTTP request failed"

**Cause:** Unable to fetch page content for static generation.

**Solution:**
1. Check page is publicly accessible
2. Verify site URL in WordPress settings
3. Check for redirect loops
4. Disable caching plugins temporarily

### "Lock acquisition failed"

**Cause:** Another generation process is running or stuck locks.

**Solution:**
1. Wait for current process to complete
2. Clear stuck lock files
3. Check for zombie processes

### "Database error during generation"

**Cause:** Database connection issues or corrupted data.

**Solution:**
1. Check database connection
2. Repair WordPress database
3. Clear corrupted meta data
4. Check for plugin conflicts

## Performance Problems

### Slow Admin Interface

**Symptoms:**
- Admin pages load slowly
- Bulk operations timeout
- Browser becomes unresponsive

**Solutions:**

1. **Increase PHP Limits**
   ```ini
   memory_limit = 512M
   max_execution_time = 300
   max_input_vars = 3000
   ```

2. **Optimize Database Queries**
   ```php
   // Enable query debugging
   define('SAVEQUERIES', true);
   
   // Add to functions.php to log slow queries
   add_action('wp_footer', function() {
       global $wpdb;
       foreach($wpdb->queries as $query) {
           if($query[1] > 1.0) { // Queries taking > 1 second
               error_log('Slow query: ' . $query[0]);
           }
       }
   });
   ```

3. **Use Pagination**
   - Reduce number of items displayed per page
   - Use AJAX for dynamic loading

### High Memory Usage

**Symptoms:**
- Server running out of memory
- WordPress fatal errors
- Generation fails on large sites

**Solutions:**

1. **Memory Profiling**
   ```php
   // Add to wp-config.php for debugging
   define('BSP_PROFILE_MEMORY', true);
   
   // Memory usage will be logged during generation
   ```

2. **Optimize Data Loading**
   ```php
   // Reduce memory usage for large datasets
   add_filter('bsp_posts_per_page', function() {
       return 50; // Process fewer posts at once
   });
   ```

3. **Clear Memory Between Operations**
   ```php
   // Force garbage collection
   add_action('bsp_after_generate_static', function() {
       if(function_exists('gc_collect_cycles')) {
           gc_collect_cycles();
       }
   });
   ```

## Configuration Issues

### WordPress Multisite Problems

**Issues:**
- Plugin only works on main site
- Different behavior across network sites
- File permissions vary between sites

**Solutions:**

1. **Network Activation**
   ```bash
   wp plugin activate breakdance-static-pages --network
   ```

2. **Site-Specific Configuration**
   ```php
   // Different settings per site
   add_filter('bsp_memory_limit', function() {
       if(is_main_site()) {
           return '512M';
       }
       return '256M';
   });
   ```

3. **Shared Upload Directory**
   ```php
   // Use site-specific directories
   add_filter('bsp_static_file_path', function($path, $post_id) {
       $site_id = get_current_blog_id();
       return str_replace('/pages/', "/site-{$site_id}/pages/", $path);
   }, 10, 2);
   ```

### Server Configuration Conflicts

**Common Conflicts:**
- ModSecurity blocking requests
- Firewall interfering with generation
- Server-level caching conflicts

**Solutions:**

1. **ModSecurity Whitelist**
   ```apache
   # Add to .htaccess
   <IfModule mod_security.c>
       SecFilterEngine Off
       SecFilterScanPOST Off
   </IfModule>
   ```

2. **Bypass Server Cache**
   ```php
   // Add cache-busting headers during generation
   add_action('bsp_before_capture_html', function() {
       header('Cache-Control: no-cache, must-revalidate');
       header('Pragma: no-cache');
   });
   ```

3. **Firewall Exceptions**
   - Whitelist your server IP for HTTP requests
   - Allow larger POST requests
   - Increase timeout limits

## Debug Techniques

### Enable Debug Logging

1. **WordPress Debug Mode**
   ```php
   // Add to wp-config.php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   define('BSP_DEBUG', true);
   ```

2. **Plugin-Specific Debugging**
   ```php
   // Detailed logging for generation process
   add_filter('bsp_debug_level', function() {
       return 'verbose'; // 'basic', 'verbose', 'detailed'
   });
   ```

### Monitoring Tools

1. **Generation Performance**
   ```php
   // Track generation times
   add_action('bsp_before_generate_static', function($post_id) {
       set_transient('bsp_start_time_' . $post_id, microtime(true), 300);
   });
   
   add_action('bsp_after_generate_static', function($post_id) {
       $start = get_transient('bsp_start_time_' . $post_id);
       if($start) {
           $duration = microtime(true) - $start;
           error_log("Generation time for post $post_id: {$duration}s");
           delete_transient('bsp_start_time_' . $post_id);
       }
   });
   ```

2. **Memory Usage Monitoring**
   ```php
   // Log memory usage during generation
   add_action('bsp_after_generate_static', function($post_id) {
       $memory = memory_get_peak_usage(true);
       error_log("Peak memory for post $post_id: " . size_format($memory));
   });
   ```

### Testing Procedures

1. **Isolated Testing**
   ```bash
   # Test with clean WordPress installation
   # Deactivate all other plugins
   wp plugin deactivate --all --skip-plugins=breakdance-static-pages
   
   # Switch to default theme
   wp theme activate twentytwentyone
   ```

2. **Progressive Testing**
   - Test with single page first
   - Gradually increase complexity
   - Test different post types
   - Test with various plugins active

3. **Load Testing**
   ```bash
   # Test bulk generation with many pages
   wp post generate --count=100
   
   # Time the bulk operation
   time wp eval "
   \$posts = get_posts(['numberposts' => 50]);
   foreach(\$posts as \$post) {
       update_post_meta(\$post->ID, '_bsp_static_enabled', '1');
   }
   "
   ```

## Getting Help

### Before Requesting Support

1. **Gather Information**
   - WordPress version
   - Plugin version
   - PHP version
   - Server environment
   - Error messages
   - Debug log excerpts

2. **Document the Issue**
   - Steps to reproduce
   - Expected vs actual behavior
   - Screenshots if applicable
   - Any recent changes

3. **Try Basic Troubleshooting**
   - Run health check
   - Check debug logs
   - Test with default theme
   - Deactivate other plugins

### Support Channels

1. **Community Support**
   - WordPress.org support forums
   - GitHub issues (for bugs)
   - Community Discord/Slack

2. **Professional Support**
   - Premium support (if available)
   - Consulting services
   - Custom development

### Information to Provide

When requesting help, include:

```
WordPress Version: 6.0
Plugin Version: 1.3.0
PHP Version: 8.0.12
Server: Apache 2.4.41
Memory Limit: 256M

Error Message:
[Exact error message here]

Steps to Reproduce:
1. [Step 1]
2. [Step 2]
3. [Error occurs]

Debug Log:
[Relevant log entries]

Additional Context:
[Any other relevant information]
```

### Self-Help Resources

1. **Documentation**
   - README.md - Usage guide
   - DEVELOPER.md - API reference
   - INSTALLATION.md - Setup instructions

2. **Code Examples**
   - Check `docs/` directory for examples
   - Review test files for usage patterns
   - Examine filter and action implementations

3. **Community Resources**
   - GitHub repository
   - WordPress.org plugin page
   - Community forums and discussions

---

**Remember:** Most issues can be resolved by following the systematic troubleshooting steps outlined above. Always start with the health check and basic diagnostics before diving into complex solutions.
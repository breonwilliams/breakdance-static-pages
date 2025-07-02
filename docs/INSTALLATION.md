# Installation and Setup Guide

This guide provides detailed installation instructions and setup procedures for the Breakdance Static Pages plugin.

## Table of Contents

- [System Requirements](#system-requirements)
- [Installation Methods](#installation-methods)
- [Initial Setup](#initial-setup)
- [Configuration](#configuration)
- [Verification](#verification)
- [Troubleshooting](#troubleshooting)

## System Requirements

### Minimum Requirements

| Component | Requirement |
|-----------|------------|
| **WordPress** | 5.0 or higher |
| **PHP** | 7.4 or higher |
| **Memory** | 128MB minimum |
| **Disk Space** | 10MB plugin + storage for static files |
| **Permissions** | Write access to `wp-content/uploads/` |

### Recommended Requirements

| Component | Recommendation |
|-----------|----------------|
| **WordPress** | Latest stable version |
| **PHP** | 8.0 or higher |
| **Memory** | 256MB or higher |
| **Web Server** | Apache 2.4+ or Nginx 1.18+ |
| **SSL** | HTTPS enabled |

### Optional Dependencies

- **Breakdance Builder** - For optimal integration and compatibility
- **Advanced Custom Fields (ACF)** - For ACF field processing support
- **Composer** - For development and testing environments
- **cURL Extension** - Usually included with PHP (fallback for HTTP requests)

## Installation Methods

### Method 1: WordPress Admin (Recommended)

1. **Download the Plugin**
   - Obtain the plugin ZIP file from the official source
   - Ensure the file is named `breakdance-static-pages.zip`

2. **Upload via Admin Dashboard**
   ```
   WordPress Admin > Plugins > Add New > Upload Plugin
   ```
   - Click "Choose file" and select the ZIP file
   - Click "Install Now"

3. **Activate the Plugin**
   - Click "Activate Plugin" after installation completes
   - Or navigate to `Plugins > Installed Plugins` and activate manually

### Method 2: FTP/SFTP Upload

1. **Extract the Plugin**
   ```bash
   unzip breakdance-static-pages.zip
   ```

2. **Upload to WordPress**
   ```
   Upload the entire 'breakdance-static-pages' folder to:
   /wp-content/plugins/
   ```

3. **Set Permissions**
   ```bash
   # Set appropriate permissions
   chmod 755 /wp-content/plugins/breakdance-static-pages/
   chmod 644 /wp-content/plugins/breakdance-static-pages/*.php
   chmod 755 /wp-content/plugins/breakdance-static-pages/includes/
   chmod 644 /wp-content/plugins/breakdance-static-pages/includes/*.php
   ```

4. **Activate via WordPress Admin**
   - Go to `Plugins > Installed Plugins`
   - Find "Breakdance Static Pages" and click "Activate"

### Method 3: WP-CLI

1. **Install via WP-CLI**
   ```bash
   # If you have the plugin ZIP file
   wp plugin install /path/to/breakdance-static-pages.zip

   # Or from a URL
   wp plugin install https://example.com/breakdance-static-pages.zip
   ```

2. **Activate the Plugin**
   ```bash
   wp plugin activate breakdance-static-pages
   ```

### Method 4: Development Installation

1. **Clone Repository**
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/your-username/breakdance-static-pages.git
   cd breakdance-static-pages
   ```

2. **Install Dependencies**
   ```bash
   # PHP dependencies (for development)
   composer install

   # Node dependencies (if applicable)
   npm install
   ```

3. **Activate Plugin**
   ```bash
   wp plugin activate breakdance-static-pages
   ```

## Initial Setup

### Step 1: Verify Installation

1. **Check Plugin Activation**
   - Navigate to `Plugins > Installed Plugins`
   - Confirm "Breakdance Static Pages" shows as "Active"

2. **Check Admin Menu**
   - Look for `Tools > Breakdance Static Pages` in the admin menu
   - This confirms the plugin loaded successfully

### Step 2: Directory Structure Verification

The plugin automatically creates the following structure upon activation:

```
wp-content/uploads/breakdance-static-pages/
├── pages/          # Generated static HTML files
├── assets/         # Cached assets (CSS, JS, images)
├── cache/          # Internal cache files
├── locks/          # File generation locks
└── .htaccess       # Security protection
```

**Verify Creation:**
```bash
# Check if directories exist
ls -la wp-content/uploads/breakdance-static-pages/
```

### Step 3: Permission Check

Ensure WordPress can write to the upload directory:

```bash
# Check permissions
ls -la wp-content/uploads/

# Set correct permissions if needed
chmod 755 wp-content/uploads/breakdance-static-pages/
chmod 755 wp-content/uploads/breakdance-static-pages/pages/
chmod 755 wp-content/uploads/breakdance-static-pages/assets/
```

### Step 4: Health Check

1. **Run Built-in Health Check**
   - Go to `Tools > Breakdance Static Pages`
   - Click "Run Health Check" button
   - Review any warnings or errors

2. **Common Health Check Items**
   - PHP memory limit (minimum 128MB)
   - Directory write permissions
   - Required PHP extensions
   - WordPress version compatibility

## Configuration

### Basic Configuration

1. **Access Settings**
   ```
   WordPress Admin > Tools > Breakdance Static Pages
   ```

2. **General Settings**
   - **Generation Mode**: Choose Manual, Automatic, or Queue-based
   - **Allowed Post Types**: Select which post types can use static generation
   - **Cache Duration**: Set how long static files remain valid (default: 24 hours)

3. **Performance Settings**
   - **Memory Limit**: Adjust memory allocation for generation (default: 256MB)
   - **Time Limit**: Set maximum execution time for generation (default: 300 seconds)
   - **Batch Size**: Configure queue processing batch size (default: 5)

### Advanced Configuration

#### 1. Memory Optimization

Add to `wp-config.php`:
```php
// Increase memory limit for static generation
define( 'BSP_MEMORY_LIMIT', '512M' );

// Increase execution time limit
define( 'BSP_TIME_LIMIT', 600 );
```

#### 2. Custom File Paths

```php
// Custom static file directory
add_filter( 'bsp_static_file_path', function( $path, $post_id ) {
    $upload_dir = wp_upload_dir();
    return $upload_dir['basedir'] . '/my-static-pages/page-' . $post_id . '.html';
}, 10, 2 );
```

#### 3. Debug Mode

Enable debug logging:
```php
// Add to wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'BSP_DEBUG', true );
```

#### 4. Queue Configuration

```php
// Customize queue settings
add_filter( 'bsp_queue_batch_size', function() {
    return 10; // Process 10 items per batch
});

add_filter( 'bsp_queue_interval', function() {
    return 30; // Process queue every 30 seconds
});
```

### Server-Specific Configuration

#### Apache Configuration

Ensure mod_rewrite is enabled:
```apache
# Check if mod_rewrite is loaded
apache2ctl -M | grep rewrite

# Enable if needed
a2enmod rewrite
systemctl restart apache2
```

#### Nginx Configuration

Add to server block:
```nginx
# Handle static files directly
location ~* /wp-content/uploads/breakdance-static-pages/pages/ {
    try_files $uri $uri/ =404;
    expires 1d;
    add_header Cache-Control "public, immutable";
}
```

#### PHP Configuration

Recommended PHP settings:
```ini
; In php.ini or .htaccess
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 64M
post_max_size = 64M
max_input_vars = 3000
```

## Verification

### Step 1: Test Basic Functionality

1. **Create Test Page**
   - Create a new page or post
   - Add some content with images and styling

2. **Enable Static Generation**
   - In the page editor, find the "Static Generation" meta box
   - Check "Enable Static Generation"
   - Save the page

3. **Generate Static File**
   - Click "Generate Static File" button
   - Wait for completion message

### Step 2: Verify File Generation

1. **Check File Exists**
   ```bash
   ls -la wp-content/uploads/breakdance-static-pages/pages/
   ```
   You should see a file like `page-123.html`

2. **Check File Content**
   ```bash
   head -20 wp-content/uploads/breakdance-static-pages/pages/page-123.html
   ```
   Verify it contains your page content

### Step 3: Test Static Serving

1. **View Static Version**
   - While logged in as admin, visit your page
   - Look for "⚡ Static Active" in the admin bar
   - Click to view the static version

2. **Check Performance**
   - Use browser dev tools to check load time
   - Static version should load significantly faster

### Step 4: Test Bulk Operations

1. **Create Multiple Pages**
   - Create 3-5 test pages with static generation enabled

2. **Bulk Generate**
   - Go to `Tools > Breakdance Static Pages`
   - Select multiple pages
   - Use "Generate Static Files" bulk action

3. **Monitor Progress**
   - Watch the progress indicators
   - Check for any errors in the log

## Post-Installation Checklist

- [ ] Plugin activated successfully
- [ ] Directory structure created automatically
- [ ] Write permissions verified
- [ ] Health check passed
- [ ] Test page generates static file correctly
- [ ] Admin bar shows static status
- [ ] Bulk operations work properly
- [ ] Debug logging enabled (if needed)
- [ ] Performance settings optimized
- [ ] Server configuration updated (if needed)

## Initial Optimization

### 1. Configure Cron Jobs

Ensure WordPress cron is working properly:
```bash
# Test WordPress cron
wp cron test

# List scheduled events
wp cron event list
```

### 2. Set Up Monitoring

Enable performance monitoring:
```php
// Add to functions.php or custom plugin
add_action( 'bsp_after_generate_static', function( $post_id, $file_path, $success ) {
    $generation_time = get_transient( 'bsp_generation_time_' . $post_id );
    error_log( sprintf(
        'BSP: Post %d generated in %.2fs, Size: %s',
        $post_id,
        $generation_time,
        size_format( filesize( $file_path ) )
    ) );
});
```

### 3. Optimize Settings

Recommended initial settings:
```php
// Performance-optimized configuration
add_filter( 'bsp_memory_limit', function() {
    return '512M';
});

add_filter( 'bsp_time_limit', function() {
    return 300;
});

add_filter( 'bsp_queue_batch_size', function() {
    return 5; // Start conservative
});

add_filter( 'bsp_cache_duration', function() {
    return DAY_IN_SECONDS; // 24 hours
});
```

## Next Steps

After successful installation:

1. **Read the User Guide** - Review `README.md` for usage instructions
2. **Configure Automation** - Set up automatic generation triggers
3. **Monitor Performance** - Track generation times and file sizes
4. **Optimize Settings** - Adjust based on your server capabilities
5. **Plan Maintenance** - Schedule regular cleanup and health checks

## Getting Help

If you encounter issues during installation:

1. **Check Health Status** - Run the built-in health check
2. **Review Debug Logs** - Enable debug mode and check logs
3. **Verify Requirements** - Ensure all system requirements are met
4. **Check Permissions** - Verify file and directory permissions
5. **Consult Documentation** - Review troubleshooting guide
6. **Community Support** - Visit WordPress forums or GitHub issues

## Security Considerations

### File Permissions

Set secure permissions:
```bash
# Plugin files (read-only)
find /wp-content/plugins/breakdance-static-pages/ -type f -exec chmod 644 {} \;
find /wp-content/plugins/breakdance-static-pages/ -type d -exec chmod 755 {} \;

# Upload directory (writable)
chmod 755 /wp-content/uploads/breakdance-static-pages/
chmod 755 /wp-content/uploads/breakdance-static-pages/pages/
```

### Access Control

The plugin automatically:
- Restricts static file access to administrators
- Prevents direct file system access via `.htaccess`
- Validates all file paths to prevent traversal attacks
- Sanitizes all user inputs

### Regular Updates

- Keep the plugin updated to latest version
- Monitor security announcements
- Review access logs periodically
- Backup before major updates

---

**Installation Complete!** You're now ready to start generating lightning-fast static pages with the Breakdance Static Pages plugin.
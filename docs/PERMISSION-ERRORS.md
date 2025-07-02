# Fixing "Failed to write temporary file" Errors

This guide helps resolve file permission errors when generating static pages.

## Error Symptoms

```
"Failed to write temporary file"
"Cannot create directory"
"Directory not writable"
```

## Root Cause

The web server user (typically `www-data`, `apache`, or `nginx`) doesn't have write permissions to create files in the plugin's upload directories.

## Quick Fix Solutions

### Solution 1: Fix Permissions via SSH/Terminal

Connect to your server and run these commands:

```bash
# Navigate to WordPress directory
cd /path/to/your/wordpress/

# Create the main plugin directory if it doesn't exist
sudo mkdir -p wp-content/uploads/breakdance-static-pages/

# Create subdirectories
sudo mkdir -p wp-content/uploads/breakdance-static-pages/pages/
sudo mkdir -p wp-content/uploads/breakdance-static-pages/pages/services/
sudo mkdir -p wp-content/uploads/breakdance-static-pages/pages/areas-we-serve/
sudo mkdir -p wp-content/uploads/breakdance-static-pages/assets/
sudo mkdir -p wp-content/uploads/breakdance-static-pages/cache/
sudo mkdir -p wp-content/uploads/bsp-locks/

# Set ownership (replace www-data with your web server user)
sudo chown -R www-data:www-data wp-content/uploads/breakdance-static-pages/
sudo chown -R www-data:www-data wp-content/uploads/bsp-locks/

# Set permissions
sudo chmod -R 755 wp-content/uploads/breakdance-static-pages/
sudo chmod -R 755 wp-content/uploads/bsp-locks/
```

### Solution 2: Using File Manager (cPanel/Plesk)

1. Log into your hosting control panel
2. Navigate to File Manager
3. Go to: `/wp-content/uploads/`
4. Create folder: `breakdance-static-pages`
5. Right-click the folder → Change Permissions
6. Set to `755` (or `775` if needed)
7. Apply recursively to all subdirectories

### Solution 3: WordPress Fix (Temporary)

Add this to your `wp-config.php` file temporarily:

```php
// Increase PHP limits
define('FS_METHOD', 'direct');
define('FS_CHMOD_DIR', (0755 & ~ umask()));
define('FS_CHMOD_FILE', (0644 & ~ umask()));
```

## Finding Your Web Server User

To identify which user runs your web server:

```bash
# For Apache
ps aux | grep apache
# or
ps aux | grep httpd

# For Nginx
ps aux | grep nginx

# For PHP-FPM
ps aux | grep php-fpm

# Check current user
whoami
```

Common web server users:
- **Ubuntu/Debian**: `www-data`
- **CentOS/RHEL**: `apache` or `nginx`
- **cPanel**: `nobody` or your cPanel username
- **Plesk**: `psacln` or domain-specific user

## Specific Hosting Provider Solutions

### WP Engine
```bash
# WP Engine uses specific permissions
cd ~/sites/yoursite/
chmod -R 775 wp-content/uploads/breakdance-static-pages/
```

### SiteGround
1. Use Site Tools → File Manager
2. Set permissions to `755`
3. Contact support if issues persist

### Kinsta
1. Use MyKinsta → Sites → Tools → File Manager
2. Or contact support for permission changes

## Verifying the Fix

After applying permissions:

1. Go to **Tools → Breakdance Static Pages**
2. Click **Health Check** tab
3. Look for "Write permissions" status
4. All directories should show green checkmarks

## Advanced Troubleshooting

### Check SELinux (CentOS/RHEL)

```bash
# Check if SELinux is enforcing
getenforce

# If enforcing, allow httpd to write
sudo setsebool -P httpd_unified 1
sudo chcon -R -t httpd_sys_rw_content_t /path/to/wordpress/wp-content/uploads/
```

### Check Disk Space

```bash
# Check available space
df -h

# Check inode usage
df -i
```

### PHP Configuration

Check these PHP settings:
- `open_basedir` - Should include wp-content/uploads
- `disable_functions` - Should not include `file_put_contents`
- `upload_tmp_dir` - Should be writable

## Prevention

1. **Regular Health Checks**: Run plugin health check weekly
2. **Monitor Permissions**: Use monitoring tools to alert on permission changes
3. **Update Carefully**: Some updates may reset permissions

## Still Having Issues?

If permissions look correct but errors persist:

1. **Enable Debug Mode**:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('BSP_DEBUG', true);
   ```

2. **Check Error Log**:
   - Look in `wp-content/debug.log`
   - Check server error logs
   - Review plugin error logs in admin

3. **Test Manually**:
   ```php
   // Add to a test file
   $test_file = WP_CONTENT_DIR . '/uploads/breakdance-static-pages/test.txt';
   $result = file_put_contents($test_file, 'test');
   echo $result ? 'Success' : 'Failed: ' . error_get_last()['message'];
   ```

4. **Contact Support** with:
   - Exact error message
   - Server type and PHP version
   - Health check results
   - Debug log excerpts

## Security Note

After fixing permissions:
- Never set permissions to `777`
- Ensure `.htaccess` files are in place
- Review security settings in plugin

Remember: The plugin needs write access only to its specific directories, not the entire WordPress installation.
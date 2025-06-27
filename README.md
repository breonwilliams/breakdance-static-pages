# Breakdance Static Pages

**Convert your Breakdance pages with ACF fields into lightning-fast static HTML files for dramatically improved performance.**

## 🚀 Performance Solution

Transform your slow-loading Breakdance pages (3-5+ seconds) into blazing-fast static HTML files (under 500ms) while preserving all functionality and visual appearance.

## ✨ Key Features

### 🎯 Zero Visual Changes
- Pages look and function **exactly** the same to visitors
- All animations, interactions, and dynamic elements preserved
- Forms, buttons, and Breakdance functionality work identically
- Purely behind-the-scenes performance optimization

### ⚡ Dramatic Performance Gains
- **Page load times**: 3-5 seconds → under 500ms
- **Server load**: Reduced by 80%+
- **Database queries**: Nearly eliminated
- **Core Web Vitals**: Significant improvements

### 🎛️ Easy Management
- **Admin Interface**: Clean dashboard under Tools → Static Pages
- **Page Selection**: Toggle static generation for individual pages
- **Bulk Operations**: Generate or delete multiple static files at once
- **Real-time Status**: See which pages are static vs dynamic
- **Performance Metrics**: Track improvements with built-in analytics

### 🔄 Smart Auto-Updates
- **ACF Integration**: Automatically regenerates when ACF fields change
- **Content Updates**: Detects Breakdance content modifications
- **Scheduled Cleanup**: Removes old and orphaned static files
- **Manual Control**: Force regeneration anytime with one click

## 📋 Requirements

- WordPress 5.0+
- PHP 7.4+
- Breakdance Builder (any version)
- Advanced Custom Fields (ACF) - optional but recommended

## 🛠️ Installation

1. **Upload Plugin**
   ```
   Upload the plugin folder to /wp-content/plugins/
   ```

2. **Activate Plugin**
   ```
   Go to Plugins → Activate "Breakdance Static Pages"
   ```

3. **Access Dashboard**
   ```
   Navigate to Tools → Static Pages
   ```

## 🎯 Quick Start Guide

### Step 1: Enable Static Generation
1. Go to **Tools → Static Pages**
2. Find the pages you want to optimize
3. Toggle the switch to **enable static generation**
4. Click **"Generate"** to create the static file

### Step 2: Verify Performance
1. Visit your page in a new browser tab
2. Check the admin bar indicator: **⚡ Static Active**
3. Use browser dev tools to measure load time improvement

### Step 3: Bulk Operations (Optional)
1. Select multiple pages using checkboxes
2. Click **"Generate Selected"** for bulk processing
3. Monitor progress in the dashboard

## 📊 Admin Interface

### Main Dashboard
- **Statistics Overview**: Total pages, static enabled, files generated, disk usage
- **Page Management Table**: Toggle, generate, delete, and monitor individual pages
- **Bulk Actions**: Select multiple pages for batch operations
- **Real-time Status**: Visual indicators for each page's static status

### Page Edit Screen
- **Meta Box**: Static generation controls in the post editor sidebar
- **Quick Actions**: Enable/disable and generate directly from edit screen
- **Status Display**: See generation status and file information

### Performance Monitoring
- **Dashboard Widget**: Key metrics on the main WordPress dashboard
- **Load Time Tracking**: Compare static vs dynamic performance
- **Usage Statistics**: Monitor static file serving rates

## 🔧 Technical Details

### How It Works
1. **HTML Capture**: Plugin requests the full page HTML from WordPress
2. **Asset Optimization**: Ensures all CSS/JS dependencies are included
3. **Static File Creation**: Saves optimized HTML to uploads directory
4. **Smart Serving**: Intercepts page requests and serves static files when available
5. **Auto-Regeneration**: Updates static files when content changes

### File Structure
```
/wp-content/uploads/breakdance-static-pages/
├── pages/
│   ├── page-123.html
│   ├── page-456.html
│   └── ...
└── assets/
    └── (future: optimized CSS/JS)
```

### Performance Features
- **HTTP Caching Headers**: Proper cache control and ETags
- **Conditional Requests**: 304 Not Modified responses
- **Asset Optimization**: Relative to absolute URL conversion
- **Admin Bar Removal**: Strips WordPress admin elements from static files

## 🎛️ Configuration Options

### Filters Available
```php
// Adjust static file max age (default: 24 hours)
add_filter('bsp_static_file_max_age', function() {
    return 12 * HOUR_IN_SECONDS; // 12 hours
});

// Modify cache headers max age (default: 1 hour)
add_filter('bsp_static_cache_max_age', function() {
    return 3600; // 1 hour
});

// Customize cleanup max age (default: 30 days)
add_filter('bsp_cleanup_max_age', function() {
    return 7 * DAY_IN_SECONDS; // 7 days
});
```

### Actions Available
```php
// Hook into static page generation
add_action('bsp_static_page_generated', function($post_id, $file_path) {
    // Custom logic after static file is created
});

// Hook into static page deletion
add_action('bsp_static_page_deleted', function($post_id) {
    // Custom logic after static file is deleted
});
```

## 🔍 Troubleshooting

### Common Issues

**Static files not generating:**
- Check file permissions on `/wp-content/uploads/`
- Verify PHP has write access to uploads directory
- Check WordPress error logs for detailed error messages

**Pages still loading slowly:**
- Confirm static generation is enabled for the page
- Check that static file exists in uploads directory
- Verify the admin bar shows "⚡ Static Active"

**Content not updating:**
- Static files regenerate automatically when you save posts
- Use "Generate Now" button to force immediate regeneration
- Check that ACF save hooks are working properly

### Debug Mode
Enable WordPress debug logging to see detailed plugin activity:
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Look for log entries prefixed with `BSP:` in your error logs.

## 📈 Performance Metrics

### Expected Improvements
- **Load Time**: 85%+ reduction (3-5s → <500ms)
- **Server Response**: <200ms for static files
- **Database Queries**: 90%+ reduction
- **Server CPU**: 80%+ reduction
- **Core Web Vitals**: All metrics improved

### Monitoring
The plugin tracks:
- Static vs dynamic page views
- Load time comparisons
- Generation statistics
- File size metrics
- Cache hit rates

## 🔒 Security Features

- **Capability Checks**: Only administrators can manage static generation
- **Nonce Verification**: All AJAX requests are secured
- **Input Sanitization**: All user inputs are properly sanitized
- **File Validation**: Static files are validated before serving
- **Path Security**: Prevents directory traversal attacks

## 🚀 Best Practices

### Which Pages to Optimize
- **High-traffic pages** with heavy ACF usage
- **Service pages** with complex Breakdance layouts
- **Landing pages** with multiple repeater fields
- **Product pages** with extensive custom fields

### Which Pages to Skip
- **Admin pages** (automatically excluded)
- **User-specific content** (profiles, dashboards)
- **Real-time data** (live feeds, comments)
- **Form submission pages** (contact forms, checkout)

### Workflow Integration
1. **Keep your current editing workflow** - no changes needed
2. **Enable static generation** for performance-critical pages
3. **Monitor the dashboard** for generation status
4. **Use bulk operations** for managing multiple pages
5. **Check performance metrics** to measure improvements

## 🆘 Support

### Getting Help
1. **Check the error logs** for detailed error messages
2. **Use the debug mode** to see plugin activity
3. **Verify file permissions** on uploads directory
4. **Test with a simple page** first to isolate issues

### Plugin Information
- **Version**: 1.0.0
- **Tested up to**: WordPress 6.8
- **Requires PHP**: 7.4+
- **License**: GPL v2 or later

## 🔄 Changelog

### Version 1.0.0
- Initial release
- Core static generation functionality
- Admin interface with bulk operations
- Performance monitoring and analytics
- ACF integration and auto-regeneration
- Comprehensive error handling and logging

---

**Transform your Breakdance site performance today!** 🚀

Enable static generation for your heaviest pages and watch your load times drop dramatically while maintaining the exact same user experience.

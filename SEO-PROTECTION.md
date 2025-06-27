# SEO Protection Features

## Overview

The Breakdance Static Pages plugin includes comprehensive SEO protection to prevent duplicate content issues that could harm your search engine rankings.

## The Problem

When static HTML files are generated, they create duplicate URLs:
- **Original URL**: `https://yoursite.com/your-page/` (dynamic WordPress page)
- **Static URL**: `https://yoursite.com/wp-content/uploads/breakdance-static-pages/pages/page-123.html` (static file)

Search engines could index both URLs, causing duplicate content penalties.

## Our Solution

### 1. Admin-Only Access
- Static files are only accessible to logged-in WordPress administrators
- Public users and search engines cannot access static files directly
- Prevents duplicate content indexing

### 2. Secure URL System
- "View Static" buttons use admin-ajax.php URLs instead of direct file paths
- Example: `wp-admin/admin-ajax.php?action=bsp_serve_static&file=pages/page-123.html`
- Requires admin authentication before serving files

### 3. Multiple Protection Layers

#### Layer 1: .htaccess Protection
```apache
# Block direct browser access to HTML files (but allow internal PHP access)
RewriteCond %{REQUEST_METHOD} ^(GET|POST|HEAD)$
RewriteCond %{HTTP_USER_AGENT} !^$
RewriteCond %{REQUEST_URI} \.html$
RewriteRule ^(.*)$ /wp-admin/admin-ajax.php?action=bsp_serve_static&file=$1 [QSA,L]
```

#### Layer 2: PHP Access Control
- `index.php` file in static directory prevents directory browsing
- Checks user authentication and capabilities
- Provides proper error messages

#### Layer 3: WordPress AJAX Handler
- Server-side authentication checks
- Validates file paths for security
- Adds SEO-safe headers (`X-Robots-Tag: noindex, nofollow`)

### 4. Admin Preview Features
- Static files include admin notice banner when viewed by administrators
- Clear indication that it's a preview-only version
- Prevents confusion between static and dynamic versions

## SEO Benefits

✅ **No Duplicate Content**: Search engines only see the original dynamic URLs
✅ **Proper Canonicalization**: Static files are never indexed
✅ **Admin Transparency**: Administrators can preview static files safely
✅ **Performance Gains**: Users get fast static files, search engines see dynamic pages

## Technical Implementation

### For Apache Servers
- Uses .htaccess rules to deny direct access
- Redirects all requests through WordPress authentication

### For Nginx Servers (like Local by Flywheel)
- Falls back to PHP-based protection
- WordPress handles all static file requests
- Maintains security even without .htaccess support

### Security Headers
When serving static files to admins, the plugin adds:
```
X-Robots-Tag: noindex, nofollow
Cache-Control: no-cache, no-store, must-revalidate
```

## Best Practices

1. **Never share static file URLs** with non-admin users
2. **Use the "View Static" button** in the admin interface for previews
3. **Monitor your site** with tools like Google Search Console to ensure no static URLs are indexed
4. **Test regularly** by trying to access static files in an incognito browser window

## Troubleshooting

### If Static Files Are Still Accessible
1. Check if your server supports .htaccess files
2. Verify WordPress admin-ajax.php is working
3. Ensure the plugin is properly activated
4. Contact your hosting provider about URL rewriting support

### For Advanced Users
You can add additional server-level protection by configuring your web server to deny access to the `/wp-content/uploads/breakdance-static-pages/` directory entirely, forcing all requests through WordPress.

## Monitoring

Use these tools to verify SEO protection:
- **Google Search Console**: Check for indexed static URLs
- **Screaming Frog**: Crawl your site to find any accessible static files
- **Browser Incognito Mode**: Test direct static file access without admin privileges

## Support

If you discover any static files are accessible to the public, please:
1. Check your server configuration
2. Verify the plugin is up to date
3. Contact support with your server environment details

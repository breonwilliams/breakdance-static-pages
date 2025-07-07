<?php
/**
 * Debug script to check BSP_Stats_Cache values
 */

// Load WordPress
require_once dirname(__FILE__) . '/../../../../wp-load.php';

// Check if user is admin
if (!current_user_can('manage_options')) {
    die('Access denied');
}

global $wpdb;

echo "<h2>Breakdance Static Pages - Debug Stats</h2>";

// Check meta values for _bsp_static_enabled
echo "<h3>1. Checking _bsp_static_enabled meta values:</h3>";
$enabled_values = $wpdb->get_results("
    SELECT meta_value, COUNT(*) as count 
    FROM {$wpdb->postmeta} 
    WHERE meta_key = '_bsp_static_enabled' 
    GROUP BY meta_value
");
echo "<pre>";
var_dump($enabled_values);
echo "</pre>";

// Check the main query from BSP_Stats_Cache
echo "<h3>2. Running the main stats query from BSP_Stats_Cache:</h3>";
$results = $wpdb->get_row("
    SELECT 
        COUNT(DISTINCT p.ID) as total_pages,
        COUNT(DISTINCT CASE WHEN pm1.meta_value = '1' THEN pm1.post_id END) as static_enabled,
        COUNT(DISTINCT pm2.post_id) as static_generated
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_bsp_static_enabled'
    LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bsp_static_generated'
    WHERE p.post_type IN ('page', 'post') 
    AND p.post_status = 'publish'
");
echo "<pre>";
var_dump($results);
echo "</pre>";

// Check pages with _bsp_static_generated
echo "<h3>3. Pages with _bsp_static_generated meta:</h3>";
$generated_pages = $wpdb->get_results("
    SELECT p.ID, p.post_title, pm.meta_value as generated_time
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
    WHERE pm.meta_key = '_bsp_static_generated'
    AND p.post_status = 'publish'
    ORDER BY p.ID
");
echo "<pre>";
echo "Count: " . count($generated_pages) . "\n";
foreach ($generated_pages as $page) {
    echo "ID: {$page->ID}, Title: {$page->post_title}, Generated: {$page->generated_time}\n";
}
echo "</pre>";

// Check actual files
echo "<h3>4. Actual static files on disk:</h3>";
$upload_dir = wp_upload_dir();
$static_dir = $upload_dir['basedir'] . '/breakdance-static-pages/pages/';
if (is_dir($static_dir)) {
    $files = glob($static_dir . '*.html');
    echo "<pre>";
    echo "Count: " . count($files) . "\n";
    foreach ($files as $file) {
        echo basename($file) . " - " . size_format(filesize($file)) . "\n";
    }
    echo "</pre>";
} else {
    echo "<p>Static directory not found: $static_dir</p>";
}

// Check for pages that should be static but aren't counted
echo "<h3>5. Detailed page analysis:</h3>";
$all_pages = $wpdb->get_results("
    SELECT 
        p.ID,
        p.post_title,
        pm1.meta_value as static_enabled,
        pm2.meta_value as static_generated,
        pm3.meta_value as file_size
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_bsp_static_enabled'
    LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_bsp_static_generated'
    LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_bsp_static_file_size'
    WHERE p.post_type IN ('page', 'post') 
    AND p.post_status = 'publish'
    ORDER BY p.ID
    LIMIT 20
");

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Title</th><th>Enabled</th><th>Generated</th><th>File Size</th><th>File Exists</th></tr>";
foreach ($all_pages as $page) {
    $file_path = Breakdance_Static_Pages::get_static_file_path($page->ID);
    $file_exists = file_exists($file_path) ? 'Yes' : 'No';
    echo "<tr>";
    echo "<td>{$page->ID}</td>";
    echo "<td>{$page->post_title}</td>";
    echo "<td>" . ($page->static_enabled ? $page->static_enabled : 'NULL') . "</td>";
    echo "<td>" . ($page->static_generated ? 'Yes' : 'No') . "</td>";
    echo "<td>" . ($page->file_size ? size_format($page->file_size) : '-') . "</td>";
    echo "<td>$file_exists</td>";
    echo "</tr>";
}
echo "</table>";

// Test BSP_Stats_Cache directly
echo "<h3>6. BSP_Stats_Cache::get_stats() output:</h3>";
$stats = BSP_Stats_Cache::get_stats(true); // Force refresh
echo "<pre>";
var_dump($stats);
echo "</pre>";

echo "<p><strong>Debug completed.</strong></p>";
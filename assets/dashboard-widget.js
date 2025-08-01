/**
 * Lightweight Dashboard Widget Script for Breakdance Static Pages
 * Only includes functionality needed for the dashboard widget
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // Only refresh stats if widget exists
    if ($('#bsp_performance_widget').length === 0) {
        return;
    }
    
    // Format bytes to human readable format
    function formatBytes(bytes, decimals) {
        if (bytes === undefined || bytes === null || isNaN(bytes)) {
            return '0 Bytes';
        }
        
        bytes = parseInt(bytes) || 0;
        decimals = decimals || 2;
        
        if (bytes === 0) return '0 Bytes';
        
        var k = 1024;
        var dm = decimals < 0 ? 0 : decimals;
        var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }
    
    // Refresh stats function
    function refreshDashboardStats() {
        $.ajax({
            url: bsp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bsp_get_stats',
                nonce: bsp_ajax.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    var stats = response.data;
                    
                    // Update dashboard widget stats
                    if (stats.enabled_pages !== undefined) {
                        $('#bsp_performance_widget .bsp-stat-enabled').text(stats.enabled_pages.toLocaleString());
                    }
                    if (stats.generated_pages !== undefined) {
                        $('#bsp_performance_widget .bsp-stat-generated').text(stats.generated_pages.toLocaleString());
                    }
                    if (stats.total_size !== undefined) {
                        $('#bsp_performance_widget .bsp-stat-size').text(formatBytes(stats.total_size));
                    }
                    if (stats.success_rate !== undefined) {
                        $('#bsp_performance_widget .bsp-stat-success-rate').text(stats.success_rate + '%');
                    }
                }
            }
        });
    }
    
    // Initial load
    refreshDashboardStats();
    
    // Refresh every 30 seconds (reduced from 10 seconds)
    setInterval(refreshDashboardStats, 30000);
});
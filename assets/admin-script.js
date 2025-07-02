/**
 * Admin JavaScript for Breakdance Static Pages
 */

// Wait for jQuery to be available
jQuery(document).ready(function($) {
    'use strict';
    
    // Define BSP_Admin object
    window.BSP_Admin = {
        
        /**
         * Initialize admin functionality
         */
        init: function() {
            this.bindEvents();
            this.initTooltips();
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;
            
            // Bulk actions
            $('#bsp-select-all').on('click', this.selectAll);
            $('#bsp-select-none').on('click', this.selectNone);
            $('#bsp-select-all-checkbox').on('change', this.toggleSelectAll);
            $('#bsp-bulk-generate').on('click', this.bulkGenerate);
            $('#bsp-bulk-delete').on('click', this.bulkDelete);
            
            // Individual actions
            $(document).on('click', '.bsp-generate-single', this.generateSingle);
            $(document).on('click', '.bsp-delete-single', this.deleteSingle);
            $(document).on('change', '.bsp-static-toggle', this.toggleStatic);
            
            // Page checkboxes
            $(document).on('change', '.bsp-page-checkbox', this.updateBulkActions);
        },
        
        /**
         * Initialize tooltips
         */
        initTooltips: function() {
            // Add tooltips to status indicators
            $('.bsp-status-active, .bsp-status-pending, .bsp-status-disabled').each(function() {
                var $this = $(this);
                var title = '';
                
                if ($this.hasClass('bsp-status-active')) {
                    title = 'This page is served as static HTML for faster loading';
                } else if ($this.hasClass('bsp-status-pending')) {
                    title = 'Static generation is enabled but file needs to be generated';
                } else if ($this.hasClass('bsp-status-disabled')) {
                    title = 'This page is served dynamically (slower)';
                }
                
                $this.attr('title', title);
            });
        },
        
        /**
         * Select all pages
         */
        selectAll: function() {
            $('.bsp-page-checkbox').prop('checked', true);
            BSP_Admin.updateBulkActions();
        },
        
        /**
         * Select no pages
         */
        selectNone: function() {
            $('.bsp-page-checkbox').prop('checked', false);
            $('#bsp-select-all-checkbox').prop('checked', false);
            BSP_Admin.updateBulkActions();
        },
        
        /**
         * Toggle select all checkbox
         */
        toggleSelectAll: function() {
            var checked = $(this).is(':checked');
            $('.bsp-page-checkbox').prop('checked', checked);
            BSP_Admin.updateBulkActions();
        },
        
        /**
         * Update bulk action buttons state
         */
        updateBulkActions: function() {
            var selectedCount = $('.bsp-page-checkbox:checked').length;
            var totalCount = $('.bsp-page-checkbox').length;
            
            // Update select all checkbox state
            if (selectedCount === 0) {
                $('#bsp-select-all-checkbox').prop('indeterminate', false).prop('checked', false);
            } else if (selectedCount === totalCount) {
                $('#bsp-select-all-checkbox').prop('indeterminate', false).prop('checked', true);
            } else {
                $('#bsp-select-all-checkbox').prop('indeterminate', true);
            }
            
            // Enable/disable bulk action buttons
            $('#bsp-bulk-generate, #bsp-bulk-delete').prop('disabled', selectedCount === 0);
        },
        
        /**
         * Bulk generate static files
         */
        bulkGenerate: function() {
            var selectedIds = BSP_Admin.getSelectedPageIds();
            
            if (selectedIds.length === 0) {
                alert('Please select at least one page.');
                return;
            }
            
            if (!confirm(bsp_ajax.strings.confirm_bulk_generate || 'Generate static files for selected pages?')) {
                return;
            }
            
            BSP_Admin.showProgress('Starting bulk generation...');
            var progressInterval = null;
            var sessionId = null;
            var isRequestComplete = false;
            
            // First, start the progress polling immediately
            progressInterval = setInterval(function() {
                $.ajax({
                    url: bsp_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'bsp_get_progress',
                        session_id: sessionId,
                        nonce: bsp_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            var progress = response.data;
                            
                            // Extract session ID from first response
                            if (!sessionId && progress.id) {
                                sessionId = progress.id;
                            }
                            
                            if (progress.percentage !== undefined) {
                                BSP_Admin.updateProgress(progress.percentage, progress.current_item || 'Processing...');
                            }
                            
                            // Stop polling if completed and main request is done
                            if ((progress.status === 'completed' || progress.status === 'failed') && isRequestComplete) {
                                clearInterval(progressInterval);
                                progressInterval = null;
                            }
                        }
                    }
                });
            }, 500); // Poll every 500ms for smoother updates
            
            // Then start the bulk generation request
            $.ajax({
                url: bsp_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'bsp_generate_multiple',
                    post_ids: selectedIds,
                    nonce: bsp_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Extract session ID from response if we don't have it yet
                        if (!sessionId && response.data.session_id) {
                            sessionId = response.data.session_id;
                        }
                        
                        BSP_Admin.updateProgress(100, 'Completed!');
                        BSP_Admin.showSuccess(response.data.message);
                        BSP_Admin.refreshPageData(selectedIds);
                    } else {
                        BSP_Admin.showError(response.data.message);
                    }
                },
                error: function() {
                    BSP_Admin.showError('An error occurred during bulk generation.');
                },
                complete: function() {
                    isRequestComplete = true;
                    
                    // Stop polling after a short delay if it's still running
                    setTimeout(function() {
                        if (progressInterval) {
                            clearInterval(progressInterval);
                        }
                        BSP_Admin.hideProgress();
                    }, 2000);
                }
            });
        },
        
        /**
         * Bulk delete static files
         */
        bulkDelete: function() {
            var selectedIds = BSP_Admin.getSelectedPageIds();
            
            if (selectedIds.length === 0) {
                alert('Please select at least one page.');
                return;
            }
            
            if (!confirm(bsp_ajax.strings.confirm_bulk_delete || 'Delete static files for selected pages?')) {
                return;
            }
            
            BSP_Admin.showProgress('Deleting static files...');
            var progressInterval = null;
            var sessionId = null;
            var isRequestComplete = false;
            
            // First, start the progress polling immediately
            progressInterval = setInterval(function() {
                $.ajax({
                    url: bsp_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'bsp_get_progress',
                        session_id: sessionId,
                        nonce: bsp_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            var progress = response.data;
                            
                            // Extract session ID from first response
                            if (!sessionId && progress.id) {
                                sessionId = progress.id;
                            }
                            
                            if (progress.percentage !== undefined) {
                                BSP_Admin.updateProgress(progress.percentage, progress.current_item || 'Deleting...');
                            }
                            
                            // Stop polling if completed and main request is done
                            if ((progress.status === 'completed' || progress.status === 'failed') && isRequestComplete) {
                                clearInterval(progressInterval);
                                progressInterval = null;
                            }
                        }
                    }
                });
            }, 500); // Poll every 500ms for smoother updates
            
            // Then start the bulk deletion request
            $.ajax({
                url: bsp_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'bsp_delete_multiple',
                    post_ids: selectedIds,
                    nonce: bsp_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Extract session ID from response if we don't have it yet
                        if (!sessionId && response.data.session_id) {
                            sessionId = response.data.session_id;
                        }
                        
                        BSP_Admin.updateProgress(100, 'Completed!');
                        BSP_Admin.showSuccess(response.data.message);
                        BSP_Admin.refreshPageData(selectedIds);
                    } else {
                        BSP_Admin.showError(response.data.message);
                    }
                },
                error: function() {
                    BSP_Admin.showError('An error occurred during bulk deletion.');
                },
                complete: function() {
                    isRequestComplete = true;
                    
                    // Stop polling after a short delay if it's still running
                    setTimeout(function() {
                        if (progressInterval) {
                            clearInterval(progressInterval);
                        }
                        BSP_Admin.hideProgress();
                    }, 2000);
                }
            });
        },
        
        /**
         * Generate single static file
         */
        generateSingle: function() {
            var $btn = $(this);
            var postId = $btn.data('post-id');
            
            $btn.prop('disabled', true).text('Generating...');
            
            $.ajax({
                url: bsp_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'bsp_generate_single',
                    post_id: postId,
                    nonce: bsp_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        BSP_Admin.showSuccess(response.data.message);
                        BSP_Admin.refreshPageRow(postId, response.data);
                    } else {
                        BSP_Admin.showError(response.data.message);
                    }
                },
                error: function() {
                    BSP_Admin.showError('An error occurred during generation.');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Generate');
                }
            });
        },
        
        /**
         * Delete single static file
         */
        deleteSingle: function() {
            var $btn = $(this);
            var postId = $btn.data('post-id');
            
            if (!confirm(bsp_ajax.strings.confirm_delete || 'Are you sure you want to delete the static file for this page?')) {
                return;
            }
            
            $btn.prop('disabled', true).text('Deleting...');
            
            $.ajax({
                url: bsp_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'bsp_delete_single',
                    post_id: postId,
                    nonce: bsp_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        BSP_Admin.showSuccess(response.data.message);
                        BSP_Admin.refreshPageRowAfterDelete(postId);
                    } else {
                        BSP_Admin.showError(response.data.message);
                    }
                },
                error: function() {
                    BSP_Admin.showError('An error occurred during deletion.');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Delete');
                }
            });
        },
        
        /**
         * Toggle static generation for a page
         */
        toggleStatic: function() {
            var $toggle = $(this);
            var postId = $toggle.data('post-id');
            var enabled = $toggle.is(':checked');
            
            $.ajax({
                url: bsp_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'bsp_toggle_static',
                    post_id: postId,
                    enabled: enabled,
                    nonce: bsp_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        BSP_Admin.updateStatusDisplay(postId, enabled);
                    } else {
                        // Revert toggle on error
                        $toggle.prop('checked', !enabled);
                        BSP_Admin.showError(response.data.message);
                    }
                },
                error: function() {
                    // Revert toggle on error
                    $toggle.prop('checked', !enabled);
                    BSP_Admin.showError('An error occurred while updating the setting.');
                }
            });
        },
        
        /**
         * Get selected page IDs
         */
        getSelectedPageIds: function() {
            var ids = [];
            $('.bsp-page-checkbox:checked').each(function() {
                ids.push($(this).val());
            });
            return ids;
        },
        
        /**
         * Show progress indicator
         */
        showProgress: function(message) {
            $('#bsp-progress').show();
            $('#bsp-progress .bsp-progress-text').text(message || 'Processing...');
            $('#bsp-progress .bsp-progress-fill').css('width', '0%');
            $('#bsp-results').hide();
        },
        
        /**
         * Hide progress indicator
         */
        hideProgress: function() {
            $('#bsp-progress').hide();
        },
        
        /**
         * Update progress bar
         */
        updateProgress: function(percentage, message) {
            $('#bsp-progress .bsp-progress-fill').css('width', percentage + '%');
            if (message) {
                $('#bsp-progress .bsp-progress-text').text(message);
            }
        },
        
        /**
         * Show success message
         */
        showSuccess: function(message) {
            $('#bsp-results')
                .removeClass('error')
                .addClass('success')
                .html('<strong>Success:</strong> ' + message)
                .show();
            
            // Auto-hide after 5 seconds
            setTimeout(function() {
                $('#bsp-results').fadeOut();
            }, 5000);
        },
        
        /**
         * Show error message
         */
        showError: function(message) {
            $('#bsp-results')
                .removeClass('success')
                .addClass('error')
                .html('<strong>Error:</strong> ' + message)
                .show();
        },
        
        /**
         * Show notification message
         */
        showNotification: function(message, type) {
            type = type || 'info';
            var className = 'notice notice-' + type;
            
            // Remove existing notifications
            $('.bsp-notification').remove();
            
            // Create notification
            var $notification = $('<div class="' + className + ' bsp-notification is-dismissible"><p>' + message + '</p></div>');
            
            // Add to page
            $('.wrap h1').after($notification);
            
            // Auto-hide after 4 seconds
            setTimeout(function() {
                $notification.fadeOut(function() {
                    $(this).remove();
                });
            }, 4000);
        },
        
        /**
         * Update status display for a page
         */
        updateStatusDisplay: function(postId, enabled) {
            var $row = $('tr[data-post-id="' + postId + '"]');
            var $statusText = $row.find('.bsp-status-text');
            var $actions = $row.find('.bsp-actions');
            
            if (enabled) {
                $statusText.html('<span class="bsp-status-pending">⏳ Needs Generation</span>');
                $actions.html('<button type="button" class="button button-small bsp-generate-single" data-post-id="' + postId + '">Generate</button>');
                
                // Show success notification
                BSP_Admin.showNotification('Static generation enabled! Click "Generate" to create the static file.', 'success');
            } else {
                $statusText.html('<span class="bsp-status-disabled">🐌 Dynamic</span>');
                $actions.html('<span class="description">Enable static generation first</span>');
                
                // Show info notification
                BSP_Admin.showNotification('Static generation disabled for this page.', 'info');
            }
        },
        
        /**
         * Refresh page row data
         */
        refreshPageRow: function(postId, data) {
            var $row = $('tr[data-post-id="' + postId + '"]');
            
            if (data) {
                // Update last generated
                if (data.generated_time) {
                    $row.find('td:nth-child(5)').text(data.generated_time);
                }
                
                // Update file size
                if (data.file_size) {
                    $row.find('td:nth-child(6)').text(data.file_size);
                }
                
                // Update status
                var $statusText = $row.find('.bsp-status-text');
                $statusText.html('<span class="bsp-status-active">⚡ Static Active</span>');
                
                // Update actions
                var $actions = $row.find('.bsp-actions');
                var actionsHtml = '<button type="button" class="button button-small bsp-generate-single" data-post-id="' + postId + '">Generate</button>';
                actionsHtml += '<button type="button" class="button button-small bsp-delete-single" data-post-id="' + postId + '">Delete</button>';
                if (data.static_url) {
                    actionsHtml += '<a href="' + data.static_url + '" target="_blank" class="button button-small">View Static</a>';
                }
                $actions.html(actionsHtml);
            }
        },

        /**
         * Refresh page row after deletion
         */
        refreshPageRowAfterDelete: function(postId) {
            var $row = $('tr[data-post-id="' + postId + '"]');
            
            // Clear last generated and file size
            $row.find('td:nth-child(5)').text('Never');
            $row.find('td:nth-child(6)').text('—');
            
            // Check if static generation is enabled for this page
            var $checkbox = $row.find('.bsp-static-toggle');
            var isEnabled = $checkbox.is(':checked');
            
            // Update status based on whether static generation is enabled
            var $statusText = $row.find('.bsp-status-text');
            if (isEnabled) {
                $statusText.html('<span class="bsp-status-pending">⏳ Needs Generation</span>');
            } else {
                $statusText.html('<span class="bsp-status-disabled">🐌 Dynamic</span>');
            }
            
            // Update actions - only show Generate button if enabled
            var $actions = $row.find('.bsp-actions');
            if (isEnabled) {
                $actions.html('<button type="button" class="button button-small bsp-generate-single" data-post-id="' + postId + '">Generate</button>');
            } else {
                $actions.html('<span class="description">Enable static generation first</span>');
            }
        },
        
        /**
         * Refresh multiple page rows
         */
        refreshPageData: function(postIds) {
            // For now, just reload the page
            // In a more advanced implementation, you could fetch updated data via AJAX
            setTimeout(function() {
                window.location.reload();
            }, 2000);
        }
    };

    // Initialize the admin functionality
    BSP_Admin.init();

    // Meta box functionality for post edit screens
    if ($('.bsp-meta-box').length > 0) {
        
        // Handle generate button in meta box
        $(document).on('click', '.bsp-meta-box .bsp-generate-single', function() {
            var $btn = $(this);
            var postId = $btn.data('post-id');
            
            $btn.prop('disabled', true).text('Generating...');
            
            $.ajax({
                url: bsp_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'bsp_generate_single',
                    post_id: postId,
                    nonce: bsp_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $btn.after('<div class="notice notice-success inline" style="margin: 10px 0;"><p>Static file generated successfully!</p></div>');
                        
                        // Update meta box display
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        $btn.after('<div class="notice notice-error inline" style="margin: 10px 0;"><p>Error: ' + response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    $btn.after('<div class="notice notice-error inline" style="margin: 10px 0;"><p>An error occurred during generation.</p></div>');
                },
                complete: function() {
                    $btn.prop('disabled', false).text('Generate Now');
                    
                    // Remove notices after 5 seconds
                    setTimeout(function() {
                        $('.notice.inline').fadeOut();
                    }, 5000);
                }
            });
        });
    }

    // Auto-refresh stats periodically
    if ($('.bsp-stats-grid').length > 0) {
        setInterval(function() {
            $.ajax({
                url: bsp_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'bsp_get_stats',
                    nonce: bsp_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update stats display
                        var stats = response.data;
                        $('.bsp-stats-grid .bsp-stat-card:nth-child(2) h3').text(stats.enabled_pages || 0);
                        $('.bsp-stats-grid .bsp-stat-card:nth-child(3) h3').text(stats.generated_pages || 0);
                        $('.bsp-stats-grid .bsp-stat-card:nth-child(4) h3').text(formatBytes(stats.total_size || 0));
                    }
                }
            });
        }, 30000); // Update every 30 seconds
    }

    /**
     * Format bytes to human readable format
     */
    function formatBytes(bytes, decimals) {
        decimals = decimals || 2;
        if (bytes === 0) return '0 Bytes';
        
        var k = 1024;
        var dm = decimals < 0 ? 0 : decimals;
        var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

});

// Health Check functionality
jQuery(document).ready(function($) {
    // Run health check
    $('#bsp-run-health-check').on('click', function() {
        var $button = $(this);
        var $spinner = $button.next('.spinner');
        
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        
        $.ajax({
            url: bsp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bsp_health_check',
                nonce: bsp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Reload page to show updated results
                    window.location.reload();
                } else {
                    alert('Health check failed: ' + response.data);
                }
            },
            error: function() {
                alert('Error running health check');
            },
            complete: function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
    
    // Maintenance actions
    $('#bsp-cleanup-orphaned').on('click', function() {
        if (!confirm('Clean up orphaned static files?')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true);
        
        $.ajax({
            url: bsp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bsp_cleanup_orphaned',
                nonce: bsp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Cleanup completed: ' + response.data.message);
                } else {
                    alert('Cleanup failed: ' + response.data);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    $('#bsp-clear-all-locks').on('click', function() {
        if (!confirm('Clear all file locks? This should only be done if you\'re sure no generation is in progress.')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true);
        
        $.ajax({
            url: bsp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bsp_clear_all_locks',
                nonce: bsp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Locks cleared: ' + response.data.message);
                } else {
                    alert('Failed to clear locks: ' + response.data);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    $('#bsp-delete-all-static').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true);
        
        $.ajax({
            url: bsp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bsp_delete_all_static',
                nonce: bsp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('All static files deleted');
                    window.location.reload();
                } else {
                    alert('Failed to delete files: ' + response.data);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    // Error management actions
    $('#bsp-clear-errors').on('click', function() {
        if (!confirm('Clear all error logs?')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true);
        
        $.ajax({
            url: bsp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bsp_clear_errors',
                nonce: bsp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Errors cleared successfully');
                    window.location.reload();
                } else {
                    alert('Failed to clear errors: ' + response.data);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    $('#bsp-export-errors').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true);
        
        $.ajax({
            url: bsp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bsp_export_errors',
                nonce: bsp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Create and download JSON file
                    var blob = new Blob([response.data.data], {type: 'application/json'});
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = response.data.filename;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                } else {
                    alert('Failed to export errors: ' + response.data);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    // Queue management actions
    $('#bsp-retry-failed').on('click', function() {
        if (!confirm('Retry all failed queue items?')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true);
        
        $.ajax({
            url: bsp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bsp_retry_failed_queue',
                nonce: bsp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    window.location.reload();
                } else {
                    alert('Failed to retry items: ' + response.data);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    $('#bsp-clear-completed').on('click', function() {
        if (!confirm('Clear all completed queue items?')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true);
        
        $.ajax({
            url: bsp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bsp_clear_completed_queue',
                nonce: bsp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    window.location.reload();
                } else {
                    alert('Failed to clear items: ' + response.data);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    $('#bsp-clear-queue').on('click', function() {
        if (!confirm('Clear ALL queue items? This cannot be undone!')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true);
        
        $.ajax({
            url: bsp_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bsp_clear_all_queue',
                nonce: bsp_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    window.location.reload();
                } else {
                    alert('Failed to clear queue: ' + response.data);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    // Progress polling for active sessions
    function pollProgress() {
        $('.bsp-progress-container').each(function() {
            var $container = $(this);
            var sessionId = $container.data('session-id');
            
            $.ajax({
                url: bsp_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'bsp_get_progress',
                    session_id: sessionId,
                    nonce: bsp_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var progress = response.data;
                        
                        // Update progress bar
                        $container.find('.progress-bar').css('width', progress.percentage + '%');
                        $container.find('.progress-percentage').text(progress.percentage + '%');
                        $container.find('.progress-current').text(progress.current + ' of ' + progress.total + ' items');
                        
                        if (progress.current_item) {
                            $container.find('.progress-current-item').text(progress.current_item);
                        }
                        
                        // Stop polling if completed
                        if (progress.status !== 'running') {
                            $container.removeClass('active-progress');
                        }
                    }
                }
            });
        });
    }
    
    // Poll every 2 seconds if there are active progress sessions
    if ($('.bsp-progress-container').length > 0) {
        setInterval(pollProgress, 2000);
    }
});

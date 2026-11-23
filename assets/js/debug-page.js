/**
 * Debug Page Module - Comprehensive debugging and diagnostic tools.
 *
 * This module provides a full-featured debugging interface for the AI Image SEO plugin.
 * It includes real-time statistics monitoring, API testing, log management, cache operations,
 * and system diagnostics with keyboard shortcuts and auto-refresh capabilities.
 *
 * ## Features
 *
 * - Real-time statistics with auto-refresh
 * - API connectivity testing (OpenAI, Anthropic, Google Gemini)
 * - Debug log viewing and management
 * - Cache clearing operations
 * - Page builder log tracking
 * - Debug information export
 * - Keyboard shortcuts (Ctrl+R, Ctrl+D, Ctrl+L)
 * - Page visibility-aware auto-refresh
 * - Download logs functionality
 *
 * ## Usage
 *
 * This module is automatically initialized on the debug page when
 * INFYP_DEBUG_MODE is enabled.
 *
 * @file Comprehensive debugging and diagnostic tools.
 * @author AI Image SEO
 * @since 1.0.0
 * @requires jQuery
 */
(function($) {
    'use strict';

    const INFYP_Debug = {
        /* =====================================================================
           INITIALIZATION
           ===================================================================== */

        /**
         * Initializes the debug interface.
         *
         * Sets up event handlers and configures auto-refresh functionality.
         *
         * @since 1.0.0
         * @access public
         *
         * @return {void}
         */
        init: function() {
            this.bindEvents();
            this.setupAutoRefresh();
        },

        /* =====================================================================
           EVENT BINDING
           ===================================================================== */

        /**
         * Binds all event handlers for debug interface interactions.
         *
         * Sets up click handlers for buttons, keyboard shortcuts, and
         * tab switching functionality.
         *
         * @since 1.0.0
         * @access private
         *
         * @return {void}
         */
        bindEvents: function() {
            $('#infyp-debug-refresh').on('click', this.refreshAll.bind(this));
            $('#infyp-debug-clear-cache').on('click', this.clearCache.bind(this));
            $('#infyp-debug-clear-logs').on('click', this.clearLogs.bind(this));
            $('#infyp-debug-export').on('click', this.exportDebugInfo.bind(this));
            $('#infyp-debug-clear-cache-tool').on('click', this.clearCache.bind(this));
            $('#infyp-debug-clear-logs-tool').on('click', this.clearLogs.bind(this));
            $('#infyp-debug-test-apis').on('click', this.testAllAPIs.bind(this));
            $('#infyp-debug-export-tool').on('click', this.exportDebugInfo.bind(this));
            $('.infyp-test-api').on('click', this.testSingleAPI.bind(this));
            $('.infyp-suggestion-action').on('click', this.applySuggestion.bind(this));
            $('.infyp-show-more-logs').on('click', this.showMoreLogs.bind(this));
            $('.infyp-log-tab').on('click', this.switchLogTab.bind(this));
            $('.infyp-view-builder-log').on('click', this.viewBuilderLog.bind(this));
            $('.infyp-debug-log-tab').on('click', this.switchDebugLogTab.bind(this));
            $('.infyp-download-logs').on('click', this.downloadLogs.bind(this));
            $(document).on('keydown', this.handleKeyboardShortcuts.bind(this));
        },

        /* =====================================================================
           AUTO-REFRESH SYSTEM
           ===================================================================== */

        /**
         * Sets up automatic statistics refresh system.
         *
         * Configures 30-second auto-refresh interval that pauses when page
         * is not visible to conserve resources. Resumes when page becomes
         * visible again.
         *
         * @since 1.0.0
         * @access private
         *
         * @return {void}
         */
        setupAutoRefresh: function() {
            let autoRefreshInterval;
            let isPageVisible = true;
            $(document).on('visibilitychange', function() {
                isPageVisible = !document.hidden;
                if (isPageVisible) {
                    setTimeout(function() {
                        autoRefreshInterval = setInterval(function() {
                            INFYP_Debug.refreshStats();
                        }, 30000);
                    }, 30000);
                } else {
                    clearInterval(autoRefreshInterval);
                }
            });
            if (isPageVisible) {
                setTimeout(function() {
                    autoRefreshInterval = setInterval(function() {
                        INFYP_Debug.refreshStats();
                    }, 30000);
                }, 30000);
            }
        },

        /* =====================================================================
           STATISTICS OPERATIONS
           ===================================================================== */

        /**
         * Refreshes all debug statistics and information.
         *
         * Sends AJAX request to reload all statistics, system information,
         * and diagnostic data. Shows loading state and updates page content
         * on success.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        refreshAll: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const originalText = $button.text();
            $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> ' + infyp_debug.strings.refreshing).addClass('infyp-debug-loading');
            $.ajax({
                url: infyp_debug.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_debug_refresh_stats',
                    nonce: infyp_debug.nonce
                },
                success: function(response) {
                    if (response.success) {
                        INFYP_Debug.updatePageContent(response.data);
                        INFYP_Debug.showMessage(infyp_debug.strings.success, 'success');
                    } else {
                        INFYP_Debug.showMessage(response.data.message || infyp_debug.strings.error, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    INFYP_Debug.showMessage(infyp_debug.strings.error + ':' + error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalText).removeClass('infyp-debug-loading');
                }
            });
        },

        /**
         * Silently refreshes statistics in background.
         *
         * Used by auto-refresh system to update stats without user
         * interaction. Does not show loading states or notifications.
         *
         * @since 1.0.0
         * @access private
         *
         * @return {void}
         */
        refreshStats: function() {
            $.ajax({
                url: infyp_debug.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_debug_refresh_stats',
                    nonce: infyp_debug.nonce
                },
                success: function(response) {
                    if (response.success) {
                        INFYP_Debug.updatePageContent(response.data);
                    }
                },
                error: function() {}
            });
        },

        /* =====================================================================
           CACHE OPERATIONS
           ===================================================================== */

        /**
         * Clears all plugin cache.
         *
         * Prompts user for confirmation before clearing cache. Reloads
         * page after successful cache clear to show updated statistics.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        clearCache: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const originalText = $button.text();
            if (!confirm(infyp_debug.i18n.confirm_clear_cache || 'Are you sure you want to clear all cache? This may temporarily slow down the site.')) {
                return;
            }
            $button.prop('disabled', true).html('<span class="dashicons dashicons-trash"></span> ' + infyp_debug.strings.clearing).addClass('infyp-debug-loading');
            $.ajax({
                url: infyp_debug.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_debug_clear_cache',
                    nonce: infyp_debug.nonce
                },
                success: function(response) {
                    if (response.success) {
                        INFYP_Debug.showMessage(response.data, 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        INFYP_Debug.showMessage(response.data.message || infyp_debug.strings.error, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    INFYP_Debug.showMessage(infyp_debug.strings.error + ':' + error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalText).removeClass('infyp-debug-loading');
                }
            });
        },

        /* =====================================================================
           LOG OPERATIONS
           ===================================================================== */

        /**
         * Clears all debug logs.
         *
         * Prompts user for confirmation before deleting logs. Updates log
         * display and file size indicator after successful clear.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        clearLogs: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const originalText = $button.text();
            if (!confirm(infyp_debug.i18n.confirm_clear_debug_logs || 'Are you sure you want to clear all debug logs? This action cannot be undone.')) {
                return;
            }
            $button.prop('disabled', true).html('<span class="dashicons dashicons-media-text"></span> ' + infyp_debug.strings.clearing).addClass('infyp-debug-loading');
            $.ajax({
                url: infyp_debug.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_debug_clear_logs',
                    nonce: infyp_debug.nonce
                },
                success: function(response) {
                    if (response.success) {
                        INFYP_Debug.showMessage(response.data, 'success');
                        $('.infyp-debug-log-entries').empty().append('<p>No log entries found.</p>');
                        $('.infyp-debug-item').find('.infyp-debug-label:contains("Log File Size")').next('.infyp-debug-value').text('0 bytes');
                    } else {
                        INFYP_Debug.showMessage(response.data.message || infyp_debug.strings.error, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    INFYP_Debug.showMessage(infyp_debug.strings.error + ':' + error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalText).removeClass('infyp-debug-loading');
                }
            });
        },

        /**
         * Exports debug information to text file.
         *
         * Generates comprehensive debug report including system info,
         * statistics, and logs. Downloads as text file for support tickets
         * or troubleshooting.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        exportDebugInfo: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const originalText = $button.text();
            $button.prop('disabled', true).html('<span class="dashicons dashicons-download"></span> ' + infyp_debug.strings.exporting).addClass('infyp-debug-loading');
            $.ajax({
                url: infyp_debug.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_debug_export_info',
                    nonce: infyp_debug.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const debugData = response.data.content;
                        const filename = response.data.filename;
                        const blob = new Blob([debugData], {
                            type: 'text/plain'
                        });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                        INFYP_Debug.showMessage(infyp_debug.i18n.debug_exported_successfully || 'Debug information exported successfully!', 'success');
                    } else {
                        INFYP_Debug.showMessage(response.data.message || infyp_debug.strings.error, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    INFYP_Debug.showMessage(infyp_debug.strings.error + ':' + error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalText).removeClass('infyp-debug-loading');
                }
            });
        },

        /* =====================================================================
           API TESTING
           ===================================================================== */

        /**
         * Tests a single API provider connection.
         *
         * Sends test request to specified AI provider (OpenAI, Anthropic,
         * or Google) to verify API key and connectivity. Shows success or
         * error message with details.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        testSingleAPI: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const provider = $button.data('provider');
            const originalText = $button.text();
            $button.prop('disabled', true).text(infyp_debug.strings.testing).addClass('infyp-debug-loading');
            $.ajax({
                url: infyp_debug.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_debug_test_api',
                    provider: provider,
                    nonce: infyp_debug.nonce
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.success) {
                            INFYP_Debug.showMessage(provider + ' API test successful!', 'success');
                        } else {
                            INFYP_Debug.showMessage(provider + ' API test failed:' + response.data.error, 'error');
                        }
                    } else {
                        INFYP_Debug.showMessage(response.data.message || infyp_debug.strings.error, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    INFYP_Debug.showMessage(infyp_debug.strings.error + ':' + error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText).removeClass('infyp-debug-loading');
                }
            });
        },

        /**
         * Tests all configured API providers.
         *
         * Runs parallel connectivity tests for all available API providers.
         * Shows summary of successful and failed tests. Uses Promise.all
         * for concurrent execution.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        testAllAPIs: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const $apiButtons = $('.infyp-test-api');
            $button.prop('disabled', true).addClass('infyp-debug-loading');
            let testPromises = [];
            $apiButtons.each(function() {
                const provider = $(this).data('provider');
                if (provider) {
                    testPromises.push(INFYP_Debug.testAPIPromise(provider));
                }
            });
            Promise.all(testPromises).then(function(results) {
                let successCount = 0;
                let failureCount = 0;
                results.forEach(function(result) {
                    if (result.success) {
                        successCount++;
                    } else {
                        failureCount++;
                    }
                });
                const message = (infyp_debug.i18n.api_tests_completed || 'API Tests completed: %s successful, %s failed').replace('%s', successCount).replace('%s', failureCount);
                INFYP_Debug.showMessage(message, successCount > 0 ? 'success' : 'error');
                $button.prop('disabled', false).removeClass('infyp-debug-loading');
            }).catch(function(error) {
                const errorMsg = (infyp_debug.i18n.error_testing_apis || 'Error testing APIs: %s').replace('%s', error);
                INFYP_Debug.showMessage(errorMsg, 'error');
                $button.prop('disabled', false).removeClass('infyp-debug-loading');
            });
        },

        /**
         * Creates promise for API test request.
         *
         * Helper function that wraps AJAX API test in a promise for
         * use with Promise.all in testAllAPIs function.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {string} provider - API provider name (openai, anthropic, google).
         *
         * @return {Promise} Promise that resolves with test result.
         */
        testAPIPromise: function(provider) {
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: infyp_debug.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'infyp_debug_test_api',
                        provider: provider,
                        nonce: infyp_debug.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            resolve(response.data);
                        } else {
                            resolve({
                                success: false,
                                error: response.data.message
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        resolve({
                            success: false,
                            error: error
                        });
                    }
                });
            });
        },

        /* =====================================================================
           UTILITY FUNCTIONS
           ===================================================================== */

        /**
         * Applies suggested action from diagnostic panel.
         *
         * Executes suggested fixes or maintenance tasks based on diagnostic
         * recommendations (e.g., clearing logs when file size is large).
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        applySuggestion: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const action = $button.data('action');
            const originalText = $button.text();
            $button.prop('disabled', true).text(infyp_debug.i18n.applying || 'Applying...').addClass('infyp-debug-loading');
            switch (action) {
                case 'clear_logs':
                    INFYP_Debug.clearLogs({
                        preventDefault: function() {}
                    });
                    break;
                default:
                    const errorMsg = (infyp_debug.i18n.unknown_suggestion_action || 'Unknown suggestion action: %s').replace('%s', action);
                    INFYP_Debug.showMessage(errorMsg, 'error');
                    $button.prop('disabled', false).text(originalText).removeClass('infyp-debug-loading');
                    break;
            }
        },

        /**
         * Loads more log entries via pagination.
         *
         * Fetches additional log entries beyond the initial page load.
         * Appends new entries to log display and updates "Show More"
         * button state based on availability.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        showMoreLogs: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const $logEntries = $('.infyp-debug-log-entries');
            const currentLogCount = $logEntries.find('.infyp-debug-log-entry').length;
            $button.prop('disabled', true).text(infyp_debug.i18n.loading_more_logs || 'Loading more logs...');
            $.ajax({
                url: infyp_debug.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_debug_load_more_logs',
                    offset: currentLogCount,
                    nonce: infyp_debug.nonce
                },
                success: function(response) {
                    if (response.success && response.data.logs.length > 0) {
                        response.data.logs.forEach(function(log) {
                            const logHtml = ` <div class="infyp-debug-log-entry infyp-log-level-${log.level.toLowerCase()}"> <span class="infyp-debug-log-time">${log.timestamp}</span> <span class="infyp-debug-log-level">${log.level}</span> <span class="infyp-debug-log-message">${log.message}</span> </div> `;
                            $logEntries.append(logHtml);
                        });
                        if (response.data.has_more) {
                            $button.prop('disabled', false).text(infyp_debug.i18n.show_more_logs || 'Show More Logs');
                        } else {
                            $button.text(infyp_debug.i18n.no_more_logs || 'No more logs to show').prop('disabled', true);
                        }
                    } else {
                        $button.text(infyp_debug.i18n.no_more_logs || 'No more logs to show').prop('disabled', true);
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text(infyp_debug.i18n.show_more_logs || 'Show More Logs');
                    INFYP_Debug.showMessage(infyp_debug.i18n.error_loading_logs || 'Error loading more logs', 'error');
                }
            });
        },

        /**
         * Handles keyboard shortcuts for debug interface.
         *
         * Provides quick access to common operations:
         * - Ctrl+R: Refresh statistics
         * - Ctrl+D: Export debug info
         * - Ctrl+L: Clear logs
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Keydown event.
         *
         * @return {void}
         */
        handleKeyboardShortcuts: function(e) {
            if ($('input:focus,textarea:focus').length > 0) {
                return;
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                $('#infyp-debug-refresh').click();
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
                e.preventDefault();
                $('#infyp-debug-export').click();
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'l') {
                e.preventDefault();
                $('#infyp-debug-clear-logs').click();
            }
        },

        /**
         * Updates page content with fresh data.
         *
         * Reloads page to display updated statistics and diagnostic
         * information after successful refresh operation.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {Object} data - Response data from server.
         *
         * @return {void}
         */
        updatePageContent: function(data) {
            location.reload();
        },

        /**
         * Displays notification message at top of page.
         *
         * Shows WordPress-style admin notice with success or error styling.
         * Auto-hides after 5 seconds and scrolls page to make visible.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {string} message - Message to display.
         * @param {string} type - Message type ('success' or 'error').
         *
         * @return {void}
         */
        showMessage: function(message, type) {
            const $notice = $('#infyp-debug-notice');
            $notice.removeClass('infyp-debug-message success error').addClass('infyp-debug-message ' + type).text(message).show();
            setTimeout(function() {
                $notice.fadeOut();
            }, 5000);
            $('html,body').animate({
                scrollTop: 0
            }, 300);
        },

        /**
         * Shows loading state on element.
         *
         * Adds loading class and disables element during operations.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {jQuery} $element - Element to show loading state on.
         *
         * @return {void}
         */
        showLoading: function($element) {
            $element.addClass('infyp-debug-loading').prop('disabled', true);
        },

        /**
         * Hides loading state on element.
         *
         * Removes loading class and re-enables element after operations complete.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {jQuery} $element - Element to hide loading state from.
         *
         * @return {void}
         */
        hideLoading: function($element) {
            $element.removeClass('infyp-debug-loading').prop('disabled', false);
        },

        /* =====================================================================
           TAB SWITCHING
           ===================================================================== */

        /**
         * Switches between page builder log tabs.
         *
         * Changes active tab and loads corresponding log file content.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        switchLogTab: function(e) {
            e.preventDefault();
            const $tab = $(e.currentTarget);
            const logFile = $tab.data('logfile');

            // Update active tab
            $('.infyp-log-tab').removeClass('active');
            $tab.addClass('active');

            // Load logs for selected file
            this.loadPageBuilderLogs(logFile);
        },

        /**
         * Loads page builder logs from server.
         *
         * Fetches log entries for specific page builder (Elementor, Divi, etc.)
         * and displays them in the log viewer with syntax highlighting.
         *
         * @since 1.0.0
         * @access private
         *
         * @param {string} logFile - Log file identifier.
         *
         * @return {void}
         */
        loadPageBuilderLogs: function(logFile) {
            const $logContent = $('.infyp-pagebuilder-log-content .infyp-debug-log-entries');

            // Show loading state
            $logContent.html('<div class="infyp-loading"><span class="dashicons dashicons-update-alt"></span> Loading logs...</div>');

            $.ajax({
                url: infyp_debug.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_get_pagebuilder_logs',
                    nonce: infyp_debug.nonce,
                    log_file: logFile,
                    limit: 50
                },
                success: function(response) {
                    if (response.success && response.data.logs) {
                        let html = '';
                        if (response.data.logs.length > 0) {
                            response.data.logs.forEach(function(log) {
                                html += '<div class="infyp-debug-log-entry infyp-log-level-' + log.level.toLowerCase() + '">';
                                html += '<span class="infyp-debug-log-time">' + log.timestamp + '</span>';
                                html += '<span class="infyp-debug-log-level">' + log.level + '</span>';
                                html += '<span class="infyp-debug-log-message">' + log.message + '</span>';
                                html += '</div>';
                            });
                        } else {
                            html = '<p class="infyp-no-logs">No logs found for this page builder.</p>';
                        }
                        $logContent.html(html);
                    } else {
                        $logContent.html('<p class="infyp-no-logs">Failed to load logs.</p>');
                    }
                },
                error: function() {
                    $logContent.html('<p class="infyp-no-logs">Error loading logs. Please try again.</p>');
                }
            });
        },

        /**
         * Views specific page builder log.
         *
         * Switches to the builder's log tab and scrolls to log viewer.
         * Triggered from page builder diagnostic cards.
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        viewBuilderLog: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const logFile = $button.data('logfile');

            // Switch to the builder's tab
            const $tab = $('.infyp-log-tab[data-logfile="' + logFile + '"]');
            if ($tab.length) {
                $tab.trigger('click');
            }

            // Scroll to the log viewer
            $('html, body').animate({
                scrollTop: $('.infyp-pagebuilder-recent-changes').offset().top - 100
            }, 500);
        },

        /**
         * Switches between debug log tabs.
         *
         * Changes active tab in the debug log viewer (general logs,
         * error logs, etc.).
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        switchDebugLogTab: function(e) {
            e.preventDefault();
            const $tab = $(e.currentTarget);
            const tabName = $tab.data('tab');

            // Update active tab button
            $('.infyp-debug-log-tab').removeClass('active');
            $tab.addClass('active');

            // Show/hide tab content
            $('.infyp-debug-log-tab-content').removeClass('active');
            $('.infyp-debug-log-tab-content[data-tab="' + tabName + '"]').addClass('active');
        },

        /* =====================================================================
           LOG DOWNLOAD
           ===================================================================== */

        /**
         * Downloads log files.
         *
         * Fetches complete log content from server and triggers browser
         * download. Supports different log types (debug, error, etc.).
         *
         * @since 1.0.0
         * @access public
         *
         * @param {Event} e - Click event.
         *
         * @return {void}
         */
        downloadLogs: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const logType = $button.data('type');
            const originalHtml = $button.html();

            $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> ' + (infyp_debug.i18n.downloading || 'Downloading...'));

            $.ajax({
                url: infyp_debug.ajax_url,
                type: 'POST',
                data: {
                    action: 'infyp_download_logs',
                    log_type: logType,
                    nonce: infyp_debug.nonce
                },
                success: function(response) {
                    if (response.success && response.data.content) {
                        // Create and trigger download
                        const blob = new Blob([response.data.content], { type: 'text/plain' });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = response.data.filename || 'ai-image-seo-logs.txt';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);

                        INFYP_Debug.showMessage(infyp_debug.i18n.logs_downloaded || 'Logs downloaded successfully!', 'success');
                    } else {
                        INFYP_Debug.showMessage(response.data.message || infyp_debug.i18n.no_logs_to_download || 'No logs to download', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    INFYP_Debug.showMessage(infyp_debug.strings.error + ': ' + error, 'error');
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalHtml);
                }
            });
        }
    };

    /* =========================================================================
       MODULE INITIALIZATION
       ========================================================================= */

    /**
     * Initializes debug module on document ready.
     *
     * Validates required configuration and starts debug interface when
     * debug mode is enabled.
     *
     * @since 1.0.0
     * @access private
     *
     * @return {void}
     */
    $(document).ready(function() {
        if (window.INFYP_DEBUG_MODE) {
            if (typeof infyp_debug !== 'undefined') {} else {
                console.error('infyp_debug object not found - AJAX calls will fail');
            }
        }
        INFYP_Debug.init();
    });

    // Expose debug interface globally for console access
    window.INFYP_Debug = INFYP_Debug;
})(jQuery);
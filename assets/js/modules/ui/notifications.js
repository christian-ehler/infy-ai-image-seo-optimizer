/**
 * Notifications Module - User feedback and alerts
 *
 * This module provides a toast notification system for displaying user feedback
 * messages. Notifications appear temporarily and automatically fade out.
 * Uses WordPress dashicons for visual type indicators.
 *
 * ## Features
 *
 * - Auto-dismissing toast notifications
 * - Four notification types (success, error, warning, info)
 * - XSS-safe message escaping
 * - Configurable display duration
 * - Fade in/out animations
 *
 * ## Notification Types
 *
 * | Type    | Icon      | Color   | Use Case                    |
 * |---------|-----------|---------|-----------------------------
 * | success | yes-alt   | Green   | Action completed successfully|
 * | error   | dismiss   | Red     | Action failed                |
 * | warning | warning   | Yellow  | Potential issue              |
 * | info    | info      | Blue    | General information          |
 *
 * ## Dependencies
 *
 * - jQuery
 * - WordPress dashicons CSS
 *
 * ## Usage
 *
 * ```javascript
 * import notifications from './ui/notifications.js';
 *
 * // Show notification by type
 * notifications.show('Settings saved!', 'success');
 * notifications.error('Failed to save');
 * notifications.info('Processing...', 5000); // Custom duration
 * ```
 *
 * @module ui/notifications
 * @requires jQuery
 */

const notifications = (function($) {
    'use strict';

    /* =========================================================================
       CONFIGURATION
       ========================================================================= */

    /**
     * Default notification display duration in milliseconds
     * @constant {number}
     */
    const NOTIFICATION_DURATION = 3000;

    /**
     * Fade animation duration in milliseconds
     * @constant {number}
     */
    const FADE_DURATION = 200;

    /* =========================================================================
       HELPER FUNCTIONS
       ========================================================================= */

    /**
     * Escape HTML entities to prevent XSS attacks
     *
     * @param {string} text - Text to escape
     * @returns {string} Escaped text safe for HTML insertion
     * @private
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    /**
     * Get dashicon class name for notification type
     *
     * @param {string} type - Notification type
     * @returns {string} Dashicons class name (without 'dashicons-' prefix)
     * @private
     */
    function getIconClass(type) {
        const icons = {
            'success': 'yes-alt',
            'error': 'dismiss',
            'warning': 'warning',
            'info': 'info'
        };
        return icons[type] || 'info';
    }

    /* =========================================================================
       NOTIFICATION DISPLAY
       ========================================================================= */

    /**
     * Show a toast notification message
     *
     * Creates and displays a notification that automatically fades out.
     * Message is HTML-escaped for security.
     *
     * @param {string} message - Message to display (will be escaped)
     * @param {string} [type='info'] - Type: 'success', 'error', 'info', 'warning'
     * @param {number} [duration=3000] - Display duration in milliseconds
     * @returns {void}
     */
    function show(message, type = 'info', duration = NOTIFICATION_DURATION) {
        const iconClass = getIconClass(type);
        const $notification = $(`
            <div class="infyp-notification infyp-notification-${type}" style="display: flex; align-items: center; gap: 8px; opacity: 0;">
                <span class="dashicons dashicons-${iconClass}"></span>
                <span class="infyp-notification-text">${escapeHtml(message)}</span>
            </div>
        `);

        $('body').append($notification);
        $notification.animate({ opacity: 1 }, FADE_DURATION);

        setTimeout(() => {
            $notification.animate({ opacity: 0 }, FADE_DURATION, function() {
                $(this).remove();
            });
        }, duration);
    }

    /* =========================================================================
       CONVENIENCE METHODS
       ========================================================================= */

    /**
     * Show success notification (convenience method)
     *
     * @param {string} message - Message to display
     * @param {number} [duration] - Optional display duration in ms
     * @returns {void}
     */
    function success(message, duration) {
        show(message, 'success', duration);
    }

    /**
     * Show error notification (convenience method)
     *
     * @param {string} message - Message to display
     * @param {number} [duration] - Optional display duration in ms
     * @returns {void}
     */
    function error(message, duration) {
        show(message, 'error', duration);
    }

    /**
     * Show info notification (convenience method)
     *
     * @param {string} message - Message to display
     * @param {number} [duration] - Optional display duration in ms
     * @returns {void}
     */
    function info(message, duration) {
        show(message, 'info', duration);
    }

    /**
     * Show warning notification (convenience method)
     *
     * @param {string} message - Message to display
     * @param {number} [duration] - Optional display duration in ms
     * @returns {void}
     */
    function warning(message, duration) {
        show(message, 'warning', duration);
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Public interface for the notifications module
     *
     * @returns {Object} Public methods
     * @property {Function} show - Show notification with type parameter
     * @property {Function} success - Show success notification
     * @property {Function} error - Show error notification
     * @property {Function} info - Show info notification
     * @property {Function} warning - Show warning notification
     * @property {Function} escapeHtml - Escape HTML for XSS prevention
     */
    return {
        show: show,
        success: success,
        error: error,
        info: info,
        warning: warning,
        escapeHtml: escapeHtml
    };

})(jQuery);

// Export for ES6 modules
export default notifications;

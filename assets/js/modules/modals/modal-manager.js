/**
 * Modal Manager - Unified modal handling system
 *
 * This module provides a centralized API for managing all modals in the plugin.
 * It handles modal lifecycle, body scroll locking, overlay management, and
 * provides consistent behavior across all modal types.
 *
 * ## Features
 *
 * - Class-based state management (.is-open)
 * - Automatic body scroll locking
 * - Overlay creation and management
 * - Escape key to close
 * - Click outside to close (optional)
 * - Focus management
 * - Animation support via CSS transitions
 *
 * ## Usage
 *
 * ```javascript
 * import modalManager from './modals/modal-manager.js';
 *
 * // Open a modal
 * modalManager.open('#infyp-alt-text-lightbox', {
 *     onOpen: () => console.log('Modal opened'),
 *     onClose: () => console.log('Modal closed'),
 *     closeOnOverlay: true,
 *     closeOnEscape: true
 * });
 *
 * // Close current modal
 * modalManager.close();
 *
 * // Close specific modal
 * modalManager.close('#infyp-alt-text-lightbox');
 * ```
 *
 * @module modals/modal-manager
 * @requires jQuery
 */

const modalManager = (function($) {
    'use strict';

    /* =========================================================================
       PRIVATE STATE
       ========================================================================= */

    /**
     * Stack of currently open modals (supports nested modals)
     * @type {Array<Object>}
     */
    const modalStack = [];

    /**
     * Default options for modal behavior
     * @type {Object}
     */
    const defaultOptions = {
        closeOnOverlay: true,
        closeOnEscape: true,
        lockScroll: true,
        animationDuration: 200,
        onOpen: null,
        onClose: null,
        overlayId: 'infyp-lightbox-overlay'
    };

    /**
     * CSS classes used by the modal system
     * @type {Object}
     */
    const CSS_CLASSES = {
        modalOpen: 'is-open',
        bodyLocked: 'infyp-lightbox-open',
        overlay: 'infyp-lightbox-overlay',
        overlayVisible: 'is-visible'
    };

    /* =========================================================================
       SCROLL LOCK
       ========================================================================= */

    /**
     * Lock body scroll to prevent background scrolling
     * @private
     */
    function lockBodyScroll() {
        const scrollY = window.scrollY;
        $('body')
            .addClass(CSS_CLASSES.bodyLocked)
            .css('top', `-${scrollY}px`)
            .data('scroll-position', scrollY);
    }

    /**
     * Unlock body scroll and restore scroll position
     * @private
     */
    function unlockBodyScroll() {
        const scrollY = $('body').data('scroll-position') || 0;
        $('body')
            .removeClass(CSS_CLASSES.bodyLocked)
            .css('top', '');
        window.scrollTo(0, scrollY);
    }

    /* =========================================================================
       OVERLAY MANAGEMENT
       ========================================================================= */

    /**
     * Get or create the modal overlay element
     * @private
     * @param {string} overlayId - ID for the overlay element
     * @returns {jQuery} The overlay element
     */
    function getOrCreateOverlay(overlayId) {
        let $overlay = $(`#${overlayId}`);

        if (!$overlay.length) {
            $overlay = $(`<div class="${CSS_CLASSES.overlay}" id="${overlayId}"></div>`);
            $('body').append($overlay);
        }

        return $overlay;
    }

    /**
     * Show the overlay with animation
     * @private
     * @param {jQuery} $overlay - The overlay element
     * @param {number} duration - Animation duration in ms
     * @returns {Promise}
     */
    function showOverlay($overlay, duration) {
        return new Promise(resolve => {
            $overlay
                .css('display', 'block')
                .addClass(CSS_CLASSES.overlayVisible);

            // Use requestAnimationFrame for smooth animation
            requestAnimationFrame(() => {
                $overlay.css('opacity', '1');
                setTimeout(resolve, duration);
            });
        });
    }

    /**
     * Hide the overlay with animation
     * @private
     * @param {jQuery} $overlay - The overlay element
     * @param {number} duration - Animation duration in ms
     * @returns {Promise}
     */
    function hideOverlay($overlay, duration) {
        return new Promise(resolve => {
            $overlay
                .css('opacity', '0')
                .removeClass(CSS_CLASSES.overlayVisible);

            setTimeout(() => {
                $overlay.css('display', 'none');
                resolve();
            }, duration);
        });
    }

    /* =========================================================================
       EVENT HANDLERS
       ========================================================================= */

    /**
     * Handle escape key press
     * @private
     * @param {KeyboardEvent} e
     */
    function handleEscapeKey(e) {
        if (e.key === 'Escape' && modalStack.length > 0) {
            const currentModal = modalStack[modalStack.length - 1];
            if (currentModal.options.closeOnEscape) {
                close();
            }
        }
    }

    /**
     * Handle overlay click
     * @private
     * @param {Event} e
     */
    function handleOverlayClick(e) {
        if (modalStack.length > 0 && e.target === e.currentTarget) {
            const currentModal = modalStack[modalStack.length - 1];
            if (currentModal.options.closeOnOverlay) {
                close();
            }
        }
    }

    /**
     * Bind global event listeners
     * @private
     */
    function bindGlobalEvents() {
        $(document).off('keydown.modalManager').on('keydown.modalManager', handleEscapeKey);
    }

    /**
     * Unbind global event listeners when no modals are open
     * @private
     */
    function unbindGlobalEvents() {
        if (modalStack.length === 0) {
            $(document).off('keydown.modalManager');
        }
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Open a modal
     *
     * @param {string|jQuery} modal - Modal selector or jQuery element
     * @param {Object} [options] - Modal options
     * @param {boolean} [options.closeOnOverlay=true] - Close when clicking overlay
     * @param {boolean} [options.closeOnEscape=true] - Close when pressing Escape
     * @param {boolean} [options.lockScroll=true] - Lock body scroll
     * @param {number} [options.animationDuration=200] - Animation duration in ms
     * @param {Function} [options.onOpen] - Callback after modal opens
     * @param {Function} [options.onClose] - Callback after modal closes
     * @param {string} [options.overlayId] - Custom overlay ID
     * @returns {Promise} Resolves when modal is fully open
     */
    function open(modal, options = {}) {
        const $modal = $(modal);

        if (!$modal.length) {
            console.warn('[ModalManager] Modal not found:', modal);
            return Promise.reject(new Error('Modal not found'));
        }

        const opts = { ...defaultOptions, ...options };
        const $overlay = getOrCreateOverlay(opts.overlayId);

        // Store modal in stack
        modalStack.push({
            $modal,
            $overlay,
            options: opts
        });

        // Lock scroll if first modal
        if (modalStack.length === 1 && opts.lockScroll) {
            lockBodyScroll();
        }

        // Bind events
        bindGlobalEvents();
        $overlay.off('click.modalManager').on('click.modalManager', handleOverlayClick);

        // Show overlay and modal
        return new Promise(resolve => {
            // Show overlay first
            $overlay.fadeIn(opts.animationDuration);

            // Add open class to modal (triggers CSS Grid display)
            $modal.addClass(CSS_CLASSES.modalOpen);

            // Trigger reflow for animation
            $modal[0].offsetHeight;

            // Wait for animation
            setTimeout(() => {
                // Focus first focusable element
                const $focusable = $modal.find('input, textarea, button, [tabindex]:not([tabindex="-1"])').first();
                if ($focusable.length) {
                    $focusable.focus();
                }

                // Call onOpen callback
                if (typeof opts.onOpen === 'function') {
                    opts.onOpen($modal);
                }

                resolve($modal);
            }, opts.animationDuration);
        });
    }

    /**
     * Close the current modal or a specific modal
     *
     * @param {string|jQuery} [modal] - Optional modal to close (defaults to top modal)
     * @returns {Promise} Resolves when modal is fully closed
     */
    function close(modal) {
        if (modalStack.length === 0) {
            return Promise.resolve();
        }

        let modalIndex = modalStack.length - 1;

        // If specific modal provided, find it in stack
        if (modal) {
            const $targetModal = $(modal);
            modalIndex = modalStack.findIndex(m => m.$modal.is($targetModal));
            if (modalIndex === -1) {
                return Promise.resolve();
            }
        }

        const { $modal, $overlay, options } = modalStack[modalIndex];

        // Remove from stack
        modalStack.splice(modalIndex, 1);

        return new Promise(resolve => {
            // Remove open class (triggers CSS transition)
            $modal.removeClass(CSS_CLASSES.modalOpen);

            // Hide overlay if no more modals
            if (modalStack.length === 0) {
                $overlay.fadeOut(options.animationDuration, function() {
                    $(this).remove();
                });

                // Unlock scroll
                if (options.lockScroll) {
                    unlockBodyScroll();
                }

                // Unbind events
                unbindGlobalEvents();
            }

            // Wait for animation
            setTimeout(() => {
                // Call onClose callback
                if (typeof options.onClose === 'function') {
                    options.onClose($modal);
                }

                resolve($modal);
            }, options.animationDuration);
        });
    }

    /**
     * Close all open modals
     *
     * @returns {Promise} Resolves when all modals are closed
     */
    function closeAll() {
        const closePromises = modalStack.map(() => close());
        return Promise.all(closePromises);
    }

    /**
     * Check if a modal is currently open
     *
     * @param {string|jQuery} [modal] - Optional modal to check
     * @returns {boolean}
     */
    function isOpen(modal) {
        if (modal) {
            const $targetModal = $(modal);
            return modalStack.some(m => m.$modal.is($targetModal));
        }
        return modalStack.length > 0;
    }

    /**
     * Get the currently active (top) modal
     *
     * @returns {jQuery|null}
     */
    function getCurrent() {
        if (modalStack.length === 0) {
            return null;
        }
        return modalStack[modalStack.length - 1].$modal;
    }

    /* =========================================================================
       EXPOSE PUBLIC API
       ========================================================================= */

    return {
        open,
        close,
        closeAll,
        isOpen,
        getCurrent,
        CSS_CLASSES
    };

})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = modalManager;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.modalManager = modalManager;
}

export default modalManager;

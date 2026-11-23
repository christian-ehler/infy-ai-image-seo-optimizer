/**
 * Event Manager Module - Centralized Event Handling
 *
 * This module provides a centralized system for managing event listeners
 * with namespace support, preventing memory leaks and providing clean
 * cleanup mechanisms for dynamic content.
 *
 * Features:
 * - Event handler registry with namespaces
 * - Automatic cleanup on destroy
 * - Prevents duplicate event bindings
 * - Memory leak prevention
 *
 * @file Centralized event handling with namespace support and memory leak prevention.
 * @author InfyPress
 * @since 1.0.0
 * @module core/event-manager
 * @requires jQuery
 */

/**
 * Export as ES6 module with WordPress compatibility fallback
 */
const eventManager = (function($) {
    'use strict';

    /**
     * Registry to track all bound event handlers
     * Structure: { namespace: [{ element, event, handler, selector }] }
     */
    const eventRegistry = {};

    /**
     * Bind an event with namespace tracking
     *
     * @param {jQuery|HTMLElement|string} element - Element or selector to bind event to
     * @param {string} event - Event name (e.g., 'click', 'input', 'change')
     * @param {string} [selector] - Optional selector for event delegation
     * @param {Function} handler - Event handler function
     * @param {string} [namespace='default'] - Namespace for event grouping
     * @returns {jQuery} jQuery object for chaining
     *
     * @example
     * // Direct binding
     * eventManager.on('.my-button', 'click', handleClick, 'myFeature');
     *
     * @example
     * // Delegated binding
     * eventManager.on(document, 'click', '.dynamic-button', handleClick, 'myFeature');
     */
    function on(element, event, selector, handler, namespace = 'default') {
        // Handle optional selector parameter
        if (typeof selector === 'function') {
            namespace = handler || 'default';
            handler = selector;
            selector = null;
        }

        const $element = $(element);

        // Bind the event
        if (selector) {
            $element.on(event, selector, handler);
        } else {
            $element.on(event, handler);
        }

        // Register the binding
        if (!eventRegistry[namespace]) {
            eventRegistry[namespace] = [];
        }

        eventRegistry[namespace].push({
            element: $element,
            event: event,
            handler: handler,
            selector: selector
        });

        return $element;
    }

    /**
     * Bind a one-time event with namespace tracking
     *
     * @param {jQuery|HTMLElement|string} element - Element or selector to bind event to
     * @param {string} event - Event name
     * @param {string} [selector] - Optional selector for event delegation
     * @param {Function} handler - Event handler function
     * @param {string} [namespace='default'] - Namespace for event grouping
     * @returns {jQuery} jQuery object for chaining
     */
    function one(element, event, selector, handler, namespace = 'default') {
        // Handle optional selector parameter
        if (typeof selector === 'function') {
            namespace = handler || 'default';
            handler = selector;
            selector = null;
        }

        const $element = $(element);

        // Create wrapped handler that removes from registry after execution
        const wrappedHandler = function(...args) {
            handler.apply(this, args);
            // Remove from registry after execution
            if (eventRegistry[namespace]) {
                eventRegistry[namespace] = eventRegistry[namespace].filter(binding =>
                    binding.element[0] !== $element[0] ||
                    binding.event !== event ||
                    binding.handler !== wrappedHandler
                );
            }
        };

        // Bind the event
        if (selector) {
            $element.one(event, selector, wrappedHandler);
        } else {
            $element.one(event, wrappedHandler);
        }

        // Register the binding
        if (!eventRegistry[namespace]) {
            eventRegistry[namespace] = [];
        }

        eventRegistry[namespace].push({
            element: $element,
            event: event,
            handler: wrappedHandler,
            selector: selector
        });

        return $element;
    }

    /**
     * Unbind events by namespace
     *
     * @param {string} namespace - Namespace to unbind (unbinds all events in this namespace)
     * @returns {number} Number of event bindings removed
     *
     * @example
     * eventManager.off('myFeature'); // Removes all events in 'myFeature' namespace
     */
    function off(namespace) {
        if (!eventRegistry[namespace]) {
            return 0;
        }

        let count = 0;
        eventRegistry[namespace].forEach(binding => {
            if (binding.selector) {
                binding.element.off(binding.event, binding.selector, binding.handler);
            } else {
                binding.element.off(binding.event, binding.handler);
            }
            count++;
        });

        delete eventRegistry[namespace];
        return count;
    }

    /**
     * Unbind a specific event handler
     *
     * @param {jQuery|HTMLElement|string} element - Element to unbind from
     * @param {string} event - Event name
     * @param {Function} handler - Handler function to remove
     * @param {string} [namespace='default'] - Namespace to search in
     * @returns {boolean} True if handler was found and removed
     */
    function offHandler(element, event, handler, namespace = 'default') {
        if (!eventRegistry[namespace]) {
            return false;
        }

        const $element = $(element);
        let removed = false;

        eventRegistry[namespace] = eventRegistry[namespace].filter(binding => {
            const matches = binding.element[0] === $element[0] &&
                          binding.event === event &&
                          binding.handler === handler;

            if (matches) {
                if (binding.selector) {
                    binding.element.off(binding.event, binding.selector, binding.handler);
                } else {
                    binding.element.off(binding.event, binding.handler);
                }
                removed = true;
                return false; // Remove from registry
            }
            return true; // Keep in registry
        });

        return removed;
    }

    /**
     * Clean up all event handlers
     * Useful for preventing memory leaks when destroying components
     *
     * @returns {number} Total number of event bindings removed
     *
     * @example
     * eventManager.destroy(); // Removes all tracked events
     */
    function destroy() {
        let totalCount = 0;

        Object.keys(eventRegistry).forEach(namespace => {
            eventRegistry[namespace].forEach(binding => {
                if (binding.selector) {
                    binding.element.off(binding.event, binding.selector, binding.handler);
                } else {
                    binding.element.off(binding.event, binding.handler);
                }
                totalCount++;
            });
        });

        // Clear registry
        Object.keys(eventRegistry).forEach(key => delete eventRegistry[key]);

        return totalCount;
    }

    /**
     * Get information about registered events
     *
     * @param {string} [namespace] - Optional namespace to inspect
     * @returns {Object|Array} Registry info for namespace or all namespaces
     */
    function getRegistry(namespace) {
        if (namespace) {
            return eventRegistry[namespace] || [];
        }
        return { ...eventRegistry };
    }

    /**
     * Check if a namespace has any registered events
     *
     * @param {string} namespace - Namespace to check
     * @returns {boolean} True if namespace has registered events
     */
    function hasEvents(namespace) {
        return !!(eventRegistry[namespace] && eventRegistry[namespace].length > 0);
    }

    /**
     * Get count of registered events
     *
     * @param {string} [namespace] - Optional namespace to count
     * @returns {number} Count of registered events
     */
    function count(namespace) {
        if (namespace) {
            return eventRegistry[namespace] ? eventRegistry[namespace].length : 0;
        }

        return Object.values(eventRegistry).reduce((total, bindings) => total + bindings.length, 0);
    }

    // Public API
    return {
        on,
        one,
        off,
        offHandler,
        destroy,
        getRegistry,
        hasEvents,
        count
    };

})(jQuery);

// Export for ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = eventManager;
}

// Export for WordPress/global scope
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.eventManager = eventManager;
}

export default eventManager;

/**
 * Optimize Status Utility - Shared status text and timer for AI optimization
 *
 * Provides reusable functions for displaying timed status messages and an
 * elapsed-time counter during AI optimization. Used in both table action
 * columns and modal optimize spinners.
 *
 * @file Shared optimize status utility
 * @author AI Image SEO
 * @since 1.3.0
 * @module ui/optimize-status
 */

const optimizeStatus = (function() {
    'use strict';

    /**
     * Returns HTML string for the status container (spinner + status text + timer).
     *
     * @since 1.3.0
     * @returns {string} HTML string
     */
    function createStatusHtml() {
        return (
            '<div class="infyp-optimize-status">' +
                '<span class="spinner is-active infyp-field-spinner"></span>' +
                '<span class="infyp-status-text"></span>' +
                '<span class="infyp-status-timer">0.0s</span>' +
            '</div>'
        );
    }

    /**
     * Starts timed status messages and an elapsed-time counter.
     * Looks for `.infyp-status-text` and `.infyp-status-timer` inside
     * the given container.
     *
     * @since 1.3.0
     * @param {jQuery|HTMLElement} container - Element containing the status elements
     * @returns {Function} stopStatus - Call to clear the interval
     */
    function startStatusTimer(container) {
        const $container = jQuery(container);
        const $status  = $container.find('.infyp-optimize-status');
        const $text  = $container.find('.infyp-status-text');
        const $timer = $container.find('.infyp-status-timer');

        const i18n       = window.infyp_vars?.i18n || {};
        const modelLabel = window.infyp_vars?.ai_model_label || 'AI';

        const stages = [
            { at: 0,  msg: i18n.status_preparing || 'Preparing image...' },
            { at: 2,  msg: (i18n.status_sending  || 'Sending to %s...').replace('%s', modelLabel) },
            { at: 5,  msg: i18n.status_analyzing  || 'AI is analyzing...' },
            { at: 15, msg: i18n.status_generating  || 'Generating metadata...' },
            { at: 30, msg: i18n.status_still       || 'Still processing...' },
        ];

        let currentStage = 0;
        const start = Date.now();

        $text.text(stages[0].msg);

        const intervalId = setInterval(() => {
            const elapsed = (Date.now() - start) / 1000;

            $timer.text(elapsed.toFixed(1) + 's');

            if (currentStage < stages.length - 1 && elapsed >= stages[currentStage + 1].at) {
                currentStage++;
                $text.text(stages[currentStage].msg);
            }
        }, 100);

        return function stopStatus() {
            clearInterval(intervalId);
        };
    }

    return {
        createStatusHtml,
        startStatusTimer,
    };

})();

// Expose globally for extension scripts (non-ES6 modules)
if (typeof window !== 'undefined') {
    window.infyp = window.infyp || {};
    window.infyp.optimizeStatus = optimizeStatus;
}

export default optimizeStatus;

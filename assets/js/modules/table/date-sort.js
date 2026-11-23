/**
 * Upload Date Sort Module
 *
 * Toggle button that cycles through upload date sort directions:
 * desc (newest first) → asc (oldest first) → inactive (back to default SEO sort)
 *
 * @module table/date-sort
 * @since 1.2.0
 */
import ajaxTableLoader from './ajax-table-loader.js';

const dateSort = (function($) {
    'use strict';

    /* =========================================================================
       CONSTANTS
       ========================================================================= */

    const SESSION_KEY = 'infyp_date_sort';
    // Cycle: inactive → desc (newest) → asc (oldest) → inactive
    const CYCLE = ['', 'desc', 'asc'];

    /* =========================================================================
       DOM REFERENCES
       ========================================================================= */

    let $btn, $arrow;

    /* =========================================================================
       PRIVATE METHODS
       ========================================================================= */

    /**
     * Update button UI for current direction
     * @param {string} direction - '', 'desc', or 'asc'
     */
    function updateUI(direction) {
        $btn.attr('data-direction', direction);

        if (direction === 'desc') {
            $btn.addClass('active');
            $arrow.html('&#9662;'); // ▾
        } else if (direction === 'asc') {
            $btn.addClass('active');
            $arrow.html('&#9652;'); // ▴
        } else {
            $btn.removeClass('active');
            $arrow.html('');
        }
    }

    /**
     * Handle button click — cycle through directions
     */
    function handleClick() {
        const current = $btn.attr('data-direction') || '';
        const idx = CYCLE.indexOf(current);
        const next = CYCLE[(idx + 1) % CYCLE.length];

        updateUI(next);

        // Store in session
        try {
            if (next) {
                sessionStorage.setItem(SESSION_KEY, next);
            } else {
                sessionStorage.removeItem(SESSION_KEY);
            }
        } catch (e) { /* silent */ }

        // Trigger table reload
        if (next) {
            // Deactivate SEO sort button when date sort is active
            deactivateSeoSort();
            ajaxTableLoader.setSort('date', next);
        } else {
            // Return to default: seo_score asc
            reactivateSeoSort();
            ajaxTableLoader.setSort('seo_score', 'asc');
        }
    }

    /**
     * Deactivate the SEO sort button visually when date sort takes over
     */
    function deactivateSeoSort() {
        const $seoBtn = $('#infyp-seo-sort-btn');
        if ($seoBtn.length) {
            $seoBtn.attr('data-direction', '').removeClass('active');
            $seoBtn.find('.infyp-seo-sort-arrow').html('');
        }
        try {
            sessionStorage.setItem('infyp_seo_sort', 'off');
        } catch (e) { /* silent */ }
    }

    /**
     * Reactivate the SEO sort button to default asc state
     */
    function reactivateSeoSort() {
        const $seoBtn = $('#infyp-seo-sort-btn');
        if ($seoBtn.length) {
            $seoBtn.attr('data-direction', 'asc').addClass('active');
            $seoBtn.find('.infyp-seo-sort-arrow').html('&#9652;'); // ▴
        }
        try {
            sessionStorage.setItem('infyp_seo_sort', 'asc');
        } catch (e) { /* silent */ }
    }

    /**
     * Restore state from session storage
     */
    function restoreFromSession() {
        try {
            const saved = sessionStorage.getItem(SESSION_KEY);
            if (!saved) return;

            const urlOrderby = new URL(window.location.href).searchParams.get('orderby');
            if (urlOrderby) return; // URL takes priority

            if (saved === 'desc' || saved === 'asc') {
                updateUI(saved);
                if (typeof ajaxTableLoader.setSortSilent === 'function') {
                    ajaxTableLoader.setSortSilent('date', saved);
                }
                deactivateSeoSort();
            }
        } catch (e) { /* silent */ }
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Initialize the upload date sort module
     */
    function init() {
        $btn = $('#infyp-date-sort-btn');
        if (!$btn.length) return;

        $arrow = $btn.find('.infyp-date-sort-arrow');

        // Restore from session if no URL state
        restoreFromSession();

        // Bind click handler
        $btn.on('click', handleClick);
    }

    /**
     * Deactivate button externally (when SEO sort takes over)
     */
    function deactivate() {
        updateUI('');
        try {
            sessionStorage.removeItem(SESSION_KEY);
        } catch (e) { /* silent */ }
    }

    return {
        init,
        deactivate
    };

})(jQuery);

export default dateSort;

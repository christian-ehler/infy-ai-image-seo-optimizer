/**
 * SEO Score Sort Module
 *
 * Toggle button that cycles through SEO score sort directions:
 * asc → desc → inactive (upload date) → asc
 *
 * Default sort is seo_score ASC (low scores first).
 *
 * @module table/seo-sort
 * @since 1.2.0
 */
import ajaxTableLoader from './ajax-table-loader.js';

const seoSort = (function($) {
    'use strict';

    /* =========================================================================
       CONSTANTS
       ========================================================================= */

    const SESSION_KEY = 'infyp_seo_sort';
    // Cycle: asc (default) → desc → inactive → asc
    const CYCLE = ['asc', 'desc', ''];

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
     * Deactivate the date sort button when SEO sort takes over
     */
    function deactivateDateSort() {
        const $dateBtn = $('#infyp-date-sort-btn');
        if ($dateBtn.length) {
            $dateBtn.attr('data-direction', '').removeClass('active');
            $dateBtn.find('.infyp-date-sort-arrow').html('');
        }
        try {
            sessionStorage.removeItem('infyp_date_sort');
        } catch (e) { /* silent */ }
    }

    /**
     * Activate the date sort button to desc when SEO sort turns off
     */
    function activateDateSort() {
        const $dateBtn = $('#infyp-date-sort-btn');
        if ($dateBtn.length) {
            $dateBtn.attr('data-direction', 'desc').addClass('active');
            $dateBtn.find('.infyp-date-sort-arrow').html('&#9662;'); // ▾
        }
        try {
            sessionStorage.setItem('infyp_date_sort', 'desc');
        } catch (e) { /* silent */ }
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
                sessionStorage.setItem(SESSION_KEY, 'off');
            }
        } catch (e) { /* silent */ }

        // Trigger table reload
        if (next) {
            deactivateDateSort();
            ajaxTableLoader.setSort('seo_score', next);
        } else {
            activateDateSort();
            ajaxTableLoader.setSort('date', 'desc');
        }
    }

    /**
     * Restore state from session storage
     */
    function restoreFromSession() {
        try {
            const saved = sessionStorage.getItem(SESSION_KEY);
            if (!saved) return; // No saved state — use default from URL/PHP

            // Only restore if not already set from URL
            const current = $btn.attr('data-direction') || '';
            const urlOrderby = new URL(window.location.href).searchParams.get('orderby');

            // If URL has explicit params, don't override
            if (urlOrderby) return;

            if (saved === 'asc' || saved === 'desc') {
                if (current !== saved) {
                    updateUI(saved);
                    if (typeof ajaxTableLoader.setSortSilent === 'function') {
                        ajaxTableLoader.setSortSilent('seo_score', saved);
                    }
                }
            } else if (saved === 'off') {
                // User explicitly turned off SEO sort — use date sort
                updateUI('');
                if (typeof ajaxTableLoader.setSortSilent === 'function') {
                    ajaxTableLoader.setSortSilent('date', 'desc');
                }
            }
        } catch (e) { /* silent */ }
    }

    /* =========================================================================
       PUBLIC API
       ========================================================================= */

    /**
     * Initialize the SEO sort module
     */
    function init() {
        $btn = $('#infyp-seo-sort-btn');
        if (!$btn.length) return;

        $arrow = $btn.find('.infyp-seo-sort-arrow');

        // Restore from session if no URL state
        restoreFromSession();

        // Bind click handler
        $btn.on('click', handleClick);
    }

    return {
        init
    };

})(jQuery);

export default seoSort;

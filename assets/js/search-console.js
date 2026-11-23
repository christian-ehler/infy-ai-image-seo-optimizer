/**
 * Search Console Dashboard JavaScript
 *
 * Manages the Google Search Console integration dashboard, including:
 * - OAuth 2.0 authentication flow and property selection
 * - Dashboard data loading with date range filtering
 * - Performance timeline chart (Chart.js) with period comparison
 * - Combined queries & pages table with drill-down navigation
 * - Winning/losing queries tables with period-over-period comparison
 * - Optimization impact section with per-page breakdown
 * - Image sitemap status display
 * - Server-side pagination, sorting, and search for data tables
 * - Client-side pagination and search for the impact table
 * - Metric card toggle for showing/hiding chart datasets
 *
 * @file   search-console.js
 * @author InfyPress
 * @since  1.1.0
 *
 * @requires jQuery
 * @requires Chart.js (loaded as infyp-chart-js)
 * @requires infypGsc (localized via wp_localize_script)
 */

/* global jQuery, infypGsc, Chart */
(function ($) {
	'use strict';

	// Bail if localized data is missing.
	if (typeof infypGsc === 'undefined') {
		return;
	}

	/** Escape HTML entities to prevent XSS when inserting into .html(). */
	function escapeHtml(text) {
		if (typeof text !== 'string') return '';
		var map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
		return text.replace(/[&<>"']/g, function(c) { return map[c]; });
	}

	/* =================================================================
	   Global State
	   ================================================================= */

	/** @type {string} AJAX nonce for security verification. */
	var nonce      = infypGsc.nonce;

	/** @type {string} WordPress AJAX endpoint URL. */
	var ajaxUrl    = infypGsc.ajax_url;

	/** @type {boolean} Whether OAuth is connected. */
	var connected  = infypGsc.connected;

	/** @type {boolean} Whether a GSC property is selected. */
	var hasProperty = infypGsc.has_property;

	/** @type {string} Active date range identifier (7d, 28d, 3m, 6m, 12m). */
	var currentRange = '28d';

	/** @type {boolean} Prevents concurrent dashboard data requests. */
	var isLoading  = false;

	/** @type {Chart|null} Chart.js instance for the performance timeline. */
	var timelineChart = null;

	/**
	 * Per-table state for search, sort, drill-down, and pagination.
	 *
	 * Each table key maps to an object tracking the current search term,
	 * sort column, sort direction, and page number. The combined table
	 * additionally tracks drill-down state for query/page breakdowns.
	 *
	 * @type {Object}
	 */
	var tableState = {
		combined:      { search: '', orderBy: 'impressions', order: 'desc', page: 1, drill: null, drillSearch: '', drillOrderBy: 'impressions', drillOrder: 'desc', drillPage: 1 },
		winners:       { search: '', orderBy: 'position_change', order: 'desc', page: 1 },
		losers:        { search: '', orderBy: 'position_change', order: 'asc', page: 1 }
	};

	/** @type {number} Rows per page for server-side paginated tables. */
	var perPage = 10;

	/* Client-side state for the optimization impact per-page table. */

	/** @type {Array} Full unfiltered impact rows from the server. */
	var impactAllRows   = [];

	/** @type {Array} Filtered subset after search is applied. */
	var impactFiltered  = [];

	/** @type {number} Current page number for the impact table. */
	var impactPage      = 1;

	/** @type {number} Rows per page for the impact table. */
	var impactPerPage   = 10;

	/**
	 * Maps table keys to their AJAX request configuration.
	 *
	 * @type {Object}
	 */
	var tableConfig = {
		combined:          { ajaxTable: 'combined' },
		'combined-drill':  { parentTable: 'combined' },
		winners:           { ajaxTable: 'winners' },
		losers:            { ajaxTable: 'losers' }
	};

	/* =====================================================================
	   Helpers
	   ===================================================================== */

	/**
	 * Show a notice in #infyp-gsc-notice.
	 */
	function showNotice(message, type) {
		var $notice = $('#infyp-gsc-notice');
		type = type || 'info';
		$notice.html(
			'<div class="notice notice-' + type + ' is-dismissible"><p>' + escapeHtml(message) + '</p>' +
			'<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>'
		).show();
		$notice.find('.notice-dismiss').on('click', function () {
			$notice.hide().empty();
		});
	}

	/**
	 * Format a number with commas.
	 */
	function formatNumber(num) {
		if (num === null || num === undefined || num === '-') {
			return '-';
		}
		num = parseFloat(num);
		if (isNaN(num)) {
			return '-';
		}
		return num.toLocaleString(undefined, { maximumFractionDigits: 0 });
	}

	/**
	 * Format a percentage.
	 */
	function formatPercent(num) {
		if (num === null || num === undefined) {
			return '-';
		}
		num = parseFloat(num);
		if (isNaN(num)) {
			return '-';
		}
		return (num * 100).toFixed(1) + '%';
	}

	/**
	 * Format position to 1 decimal.
	 */
	function formatPosition(num) {
		if (num === null || num === undefined) {
			return '-';
		}
		num = parseFloat(num);
		if (isNaN(num)) {
			return '-';
		}
		return num.toFixed(1);
	}

	/**
	 * Calculate and display a delta value with arrow indicator.
	 */
	function showDelta($el, current, previous, isPosition) {
		if (!previous || !current) {
			$el.text('').removeClass('positive negative neutral');
			return;
		}

		current  = parseFloat(current);
		previous = parseFloat(previous);

		if (isNaN(current) || isNaN(previous) || previous === 0) {
			$el.text('').removeClass('positive negative neutral');
			return;
		}

		var delta, pctChange, arrow, cssClass, text;

		if (isPosition) {
			// Lower position is better: decrease = positive (green).
			delta = previous - current;
			pctChange = (delta / previous) * 100;
			arrow = delta > 0 ? '\u25B2 ' : (delta < 0 ? '\u25BC ' : '');
			cssClass = delta > 0.5 ? 'positive' : (delta < -0.5 ? 'negative' : 'neutral');
			text = arrow + Math.abs(delta).toFixed(1) + ' pos';
		} else {
			pctChange = ((current - previous) / previous) * 100;
			arrow = pctChange > 0 ? '\u25B2 ' : (pctChange < 0 ? '\u25BC ' : '');
			cssClass = pctChange > 0 ? 'positive' : (pctChange < 0 ? 'negative' : 'neutral');
			text = arrow + (pctChange > 0 ? '+' : '') + pctChange.toFixed(0) + '%';
		}

		$el.text(text).removeClass('positive negative neutral').addClass(cssClass);
	}

	/**
	 * Truncate a URL for display.
	 */
	function truncateUrl(url) {
		if (!url) {
			return '';
		}
		return url.replace(/^https?:\/\/[^/]+/, '').replace(/\/$/, '') || '/';
	}

	/* =====================================================================
	   Table Data Loading (Search / Sort / Drill-Down)
	   ===================================================================== */

	/**
	 * Simple debounce utility.
	 */
	function debounce(fn, delay) {
		var timer;
		return function () {
			var ctx = this, args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () { fn.apply(ctx, args); }, delay);
		};
	}

	/**
	 * Load table data via the infyp_gsc_get_table_data endpoint.
	 *
	 * @param {string} tableKey  One of: combined, combined-drill, winners, losers
	 */
	function loadTableData(tableKey) {
		var cfg = tableConfig[tableKey];
		if (!cfg) {
			return;
		}

		// Determine state source.
		var parentKey = cfg.parentTable || tableKey;
		var state     = tableState[parentKey];
		if (!state) {
			return;
		}

		var isDrill = tableKey.indexOf('-drill') !== -1;
		var search, orderBy, order;

		if (isDrill) {
			search  = state.drillSearch || '';
			orderBy = state.drillOrderBy || 'impressions';
			order   = state.drillOrder || 'desc';
		} else {
			search  = state.search || '';
			orderBy = state.orderBy || 'impressions';
			order   = state.order || 'desc';
		}

		var page = isDrill ? (state.drillPage || 1) : (state.page || 1);
		var offset = (page - 1) * perPage;

		var postData = {
			action:     'infyp_gsc_get_table_data',
			nonce:      nonce,
			table:      cfg.ajaxTable || 'combined',
			date_range: currentRange,
			search:     search,
			order_by:   orderBy,
			order:      order,
			limit:      perPage,
			offset:     offset
		};

		// Handle combined-drill: AJAX table type depends on drill type.
		if (tableKey === 'combined-drill' && state.drill) {
			if (state.drill.type === 'query') {
				postData.table       = 'query_pages';
				postData.drill_query = state.drill.value;
			} else if (state.drill.type === 'page') {
				postData.table      = 'page_queries';
				postData.drill_page = state.drill.value;
			}
		}

		// Show loading in the target tbody.
		var $tbody = getTableBody(tableKey);
		var cols = getColCount(tableKey);
		if ($tbody) {
			$tbody.html('<tr class="infyp-gsc-loading-row"><td colspan="' + cols + '">' + infypGsc.i18n.loading + '</td></tr>');
		}

		$.post(ajaxUrl, postData, function (response) {
			if (!response.success) {
				if ($tbody) {
					$tbody.html('<tr class="infyp-gsc-loading-row"><td colspan="' + cols + '">' + escapeHtml(response.data.message || infypGsc.i18n.error) + '</td></tr>');
				}
				return;
			}

			var rows  = response.data.rows || [];
			var total = response.data.total || rows.length;

			renderTableRows(tableKey, rows);
			updateTableInfo(tableKey, rows.length, total);
		}).fail(function () {
			if ($tbody) {
				$tbody.html('<tr class="infyp-gsc-loading-row"><td colspan="' + cols + '">' + infypGsc.i18n.error + '</td></tr>');
			}
		});
	}

	/**
	 * Get the jQuery tbody element for a given table key.
	 */
	function getTableBody(tableKey) {
		switch (tableKey) {
			case 'combined':        return $('#infyp-gsc-combined-table tbody');
			case 'combined-drill':  return $('#infyp-gsc-combined-drill table tbody');
			case 'winners':         return $('#infyp-gsc-winners-table tbody');
			case 'losers':          return $('#infyp-gsc-losers-table tbody');
		}
		return null;
	}

	/**
	 * Get column count for a table key.
	 */
	function getColCount(tableKey) {
		switch (tableKey) {
			case 'combined':        return 6;
			case 'combined-drill':  return 5;
			case 'winners':         return 4;
			case 'losers':          return 4;
		}
		return 6;
	}

	/**
	 * Render rows into the correct table.
	 */
	function renderTableRows(tableKey, rows) {
		switch (tableKey) {
			case 'combined':
				updateCombinedTable(rows);
				break;
			case 'combined-drill':
				if (tableState.combined.drill && tableState.combined.drill.type === 'query') {
					renderDrillPages(rows, '#infyp-gsc-combined-drill table tbody');
				} else {
					renderDrillQueries(rows, '#infyp-gsc-combined-drill table tbody');
				}
				break;
			case 'winners':
				renderWLTable($('#infyp-gsc-winners-table tbody'), rows, true);
				break;
			case 'losers':
				renderWLTable($('#infyp-gsc-losers-table tbody'), rows, false);
				break;
		}
	}

	/**
	 * Render drill-down pages (for a specific query).
	 */
	function renderDrillPages(rows, selector) {
		var $tbody = $(selector);
		$tbody.empty();

		if (!rows || rows.length === 0) {
			$tbody.html('<tr class="infyp-gsc-loading-row"><td colspan="5">' + infypGsc.i18n.no_results + '</td></tr>');
			return;
		}

		$.each(rows, function (i, row) {
			var displayUrl = truncateUrl(row.page_url);
			$tbody.append(
				'<tr>' +
				'<td><span class="infyp-gsc-page-url" title="' + $('<span>').text(row.page_url).html() + '">' +
				$('<span>').text(displayUrl).html() + '</span></td>' +
				'<td class="infyp-gsc-num">' + formatNumber(row.impressions) + '</td>' +
				'<td class="infyp-gsc-num">' + formatNumber(row.clicks) + '</td>' +
				'<td class="infyp-gsc-num">' + formatPercent(row.ctr) + '</td>' +
				'<td class="infyp-gsc-num">' + formatPosition(row.position) + '</td>' +
				'</tr>'
			);
		});
	}

	/**
	 * Render drill-down queries (for a specific page).
	 */
	function renderDrillQueries(rows, selector) {
		var $tbody = $(selector);
		$tbody.empty();

		if (!rows || rows.length === 0) {
			$tbody.html('<tr class="infyp-gsc-loading-row"><td colspan="5">' + infypGsc.i18n.no_results + '</td></tr>');
			return;
		}

		$.each(rows, function (i, row) {
			$tbody.append(
				'<tr>' +
				'<td>' + $('<span>').text(row.query || '(not set)').html() + '</td>' +
				'<td class="infyp-gsc-num">' + formatNumber(row.impressions) + '</td>' +
				'<td class="infyp-gsc-num">' + formatNumber(row.clicks) + '</td>' +
				'<td class="infyp-gsc-num">' + formatPercent(row.ctr) + '</td>' +
				'<td class="infyp-gsc-num">' + formatPosition(row.position) + '</td>' +
				'</tr>'
			);
		});
	}

	/**
	 * Render pagination controls below a table.
	 */
	function updateTableInfo(tableKey, shown, total) {
		var infoId;
		switch (tableKey) {
			case 'combined':        infoId = '#infyp-gsc-combined-info'; break;
			case 'combined-drill':  infoId = '#infyp-gsc-combined-drill-info'; break;
			case 'winners':         infoId = '#infyp-gsc-winners-info'; break;
			case 'losers':          infoId = '#infyp-gsc-losers-info'; break;
			default: return;
		}

		var $info = $(infoId);
		$info.empty();

		if (total <= 0) {
			return;
		}

		var isDrill   = tableKey.indexOf('-drill') !== -1;
		var parentKey = isDrill ? tableKey.replace('-drill', '') : tableKey;
		var state     = tableState[parentKey];
		if (!state) {
			return;
		}

		var page      = isDrill ? (state.drillPage || 1) : (state.page || 1);
		var startItem = (page - 1) * perPage + 1;
		var endItem   = Math.min(startItem + shown - 1, total);
		var hasNext   = endItem < total;
		var hasPrev   = page > 1;

		var rangeText = (infypGsc.i18n.showing_range || 'Showing %1$s\u2013%2$s of %3$s')
			.replace('%1$s', startItem)
			.replace('%2$s', endItem)
			.replace('%3$s', total);

		var html = '<span class="infyp-gsc-pagination-text">' + rangeText + '</span>';

		if (hasPrev || hasNext) {
			html += '<span class="infyp-gsc-pagination-btns">';
			html += '<button type="button" class="infyp-gsc-page-btn infyp-gsc-page-prev" data-table="' + tableKey + '"' +
				(hasPrev ? '' : ' disabled') + '>&larr; ' + (infypGsc.i18n.prev || 'Prev') + '</button>';
			html += '<button type="button" class="infyp-gsc-page-btn infyp-gsc-page-next" data-table="' + tableKey + '"' +
				(hasNext ? '' : ' disabled') + '>' + (infypGsc.i18n.next || 'Next') + ' &rarr;</button>';
			html += '</span>';
		}

		$info.html(html);
	}

	/**
	 * Enter drill-down mode for the combined table.
	 *
	 * @param {string} tableKey   Always 'combined'.
	 * @param {object} drillInfo  { type: 'query'|'page', value: string }
	 */
	function enterDrillDown(tableKey, drillInfo) {
		var state = tableState[tableKey];
		if (!state) {
			return;
		}

		// Store drill state with type and value.
		state.drill = drillInfo;

		// Reset drill search/sort/page.
		state.drillSearch  = '';
		state.drillOrderBy = 'impressions';
		state.drillOrder   = 'desc';
		state.drillPage    = 1;

		// Update UI: hide main, show drill-down.
		var $card  = $('#infyp-gsc-' + tableKey + '-card');
		var $main  = $('#infyp-gsc-' + tableKey + '-main');
		var $drill = $('#infyp-gsc-' + tableKey + '-drill');
		var $searchInput = $card.find('.infyp-gsc-search-input');

		$main.hide();
		$drill.show();

		// Update search placeholder, clear value, switch data-table to drill mode.
		$searchInput.val('');
		$searchInput.attr('data-table', tableKey + '-drill');

		if (drillInfo.type === 'query') {
			$searchInput.attr('placeholder', infypGsc.i18n.search_pages);
			$drill.find('.infyp-gsc-drill-title').text(infypGsc.i18n.pages_for_query + ' ' + drillInfo.value);
			$drill.find('.infyp-gsc-drill-dimension-header').text(infypGsc.i18n.page || 'Page');
		} else if (drillInfo.type === 'page') {
			$searchInput.attr('placeholder', infypGsc.i18n.search_queries);
			$drill.find('.infyp-gsc-drill-title').text(infypGsc.i18n.queries_for_page + ' ' + truncateUrl(drillInfo.value));
			$drill.find('.infyp-gsc-drill-dimension-header').text(infypGsc.i18n.query || 'Query');
		}

		// Reset drill sort indicators.
		$drill.find('.infyp-gsc-sortable').removeClass('asc desc');
		$drill.find('.infyp-gsc-sortable[data-sort="impressions"]').addClass('desc');

		// Load drill-down data.
		loadTableData(tableKey + '-drill');
	}

	/**
	 * Exit drill-down mode.
	 */
	function exitDrillDown(tableKey) {
		var state = tableState[tableKey];
		if (!state) {
			return;
		}

		state.drill = null;

		// Update UI: show main, hide drill-down.
		var $card  = $('#infyp-gsc-' + tableKey + '-card');
		var $main  = $('#infyp-gsc-' + tableKey + '-main');
		var $drill = $('#infyp-gsc-' + tableKey + '-drill');
		var $searchInput = $card.find('.infyp-gsc-search-input');

		// Restore search input to main table mode.
		$searchInput.attr('data-table', tableKey);
		$searchInput.val(state.search);
		$searchInput.attr('placeholder', infypGsc.i18n.search_combined);

		$drill.hide();
		$main.show();
	}

	/**
	 * Reset all table states (called on date range change).
	 */
	function resetTableStates() {
		// Exit any active drill-down on combined table.
		if (tableState.combined.drill) {
			exitDrillDown('combined');
		}
		tableState.combined.search    = '';
		tableState.combined.orderBy   = 'impressions';
		tableState.combined.order     = 'desc';
		tableState.combined.page      = 1;
		tableState.combined.drillPage = 1;

		$.each(['winners', 'losers'], function (i, key) {
			tableState[key].search = '';
			tableState[key].page   = 1;
		});
		tableState.winners.orderBy = 'position_change';
		tableState.winners.order = 'desc';
		tableState.losers.orderBy = 'position_change';
		tableState.losers.order = 'asc';

		// Clear search inputs.
		$('.infyp-gsc-search-input').val('');

		// Reset sort indicators to defaults.
		$('.infyp-gsc-sortable').removeClass('asc desc');
		$('.infyp-gsc-sortable[data-sort="impressions"][data-table="combined"]').addClass('desc');
		$('.infyp-gsc-sortable[data-sort="position_change"][data-table="winners"]').addClass('desc');
		$('.infyp-gsc-sortable[data-sort="position_change"][data-table="losers"]').addClass('asc');

		// Clear pagination controls.
		$('.infyp-gsc-pagination').empty();
	}

	/* =====================================================================
	   OAuth Flow
	   ===================================================================== */

	/**
	 * Show an error message from the OAuth flow (e.g. consent denial).
	 *
	 * Maps error codes to user-friendly i18n messages, displays them
	 * via showNotice, and cleans the URL of OAuth query params.
	 *
	 * @param {string} errorCode Error code from gsc_error query param.
	 */
	window.infypGscShowError = function (errorCode) {
		var messages = {
			access_denied: infypGsc.i18n.connect_cancelled || 'You cancelled the Google authorization.',
			token_exchange_failed: infypGsc.i18n.connect_failed || 'Failed to connect to Google Search Console. Please try again.',
			missing_code: infypGsc.i18n.connect_failed || 'Failed to connect to Google Search Console. Please try again.'
		};

		var msg = messages[errorCode] || messages.missing_code;
		showNotice(msg, 'error');

		// Clean OAuth params from the URL.
		if (window.history && window.history.replaceState) {
			var cleanUrl = window.location.pathname + '?page=ai-image-seo-optimizer-search-console';
			window.history.replaceState({}, document.title, cleanUrl);
		}
	};

	/**
	 * Exchange the OAuth code for tokens.
	 *
	 * Can be triggered automatically via localized `infypGsc.oauth_code` /
	 * `infypGsc.oauth_proxy_code` data, or called programmatically.
	 */
	window.infypGscExchangeCode = function (code, proxyCode, state) {
		var postData = {
			action: 'infyp_gsc_handle_callback',
			nonce: nonce
		};

		if (proxyCode) {
			postData.proxy_code = proxyCode;
		} else {
			postData.code = code;
		}

		if (state) {
			postData.state = state;
		}

		$.post(ajaxUrl, postData, function (response) {
			$('#infyp-gsc-callback-loading').hide();

			if (response.success) {
				showNotice(response.data.message, 'success');

				// If we got sites, show property selection.
				if (response.data.sites && response.data.sites.length > 0) {
					renderPropertyList(response.data.sites);
				} else {
					// Reload to show updated state.
					window.location.href = window.location.pathname + '?page=ai-image-seo-optimizer-search-console';
				}
			} else {
				showNotice(response.data.message || 'Connection failed.', 'error');
			}
		}).fail(function () {
			$('#infyp-gsc-callback-loading').hide();
			showNotice('Connection request failed. Please try again.', 'error');
		});
	};

	/**
	 * Render property list after successful OAuth.
	 */
	function renderPropertyList(sites) {
		var $wrap = $('#infyp-gsc-wrap');
		var html  = '<div id="infyp-gsc-state-property" class="infyp-gsc-state">' +
			'<div class="infyp-card infyp-gsc-property-card">' +
			'<div class="infyp-card-header"><h3><span class="dashicons dashicons-yes-alt"></span> ' +
			infypGsc.i18n.connected + '</h3></div>' +
			'<div class="infyp-card-body">' +
			'<p>' + infypGsc.i18n.select_property + '</p>' +
			'<div class="infyp-gsc-property-list">';

		$.each(sites, function (i, site) {
			var type = site.siteUrl && site.siteUrl.indexOf('sc-domain:') === 0 ? 'Domain' : 'URL Prefix';
			html += '<div class="infyp-gsc-property-item">' +
				'<div><span class="infyp-gsc-property-url">' + $('<span>').text(site.siteUrl).html() + '</span>' +
				'<span class="infyp-gsc-property-type">(' + type + ')</span></div>' +
				'<button type="button" class="button button-primary button-small infyp-gsc-select-property" data-url="' +
				$('<span>').text(site.siteUrl).html() + '">' + infypGsc.i18n.select + '</button></div>';
		});

		html += '</div><div class="infyp-gsc-property-actions">' +
			'<button type="button" id="infyp-gsc-disconnect" class="button button-link-delete">' +
			infypGsc.i18n.disconnect + '</button></div></div></div></div>';

		// Replace current content.
		$wrap.find('.infyp-gsc-state').remove();
		$wrap.find('#infyp-gsc-callback-loading').remove();
		$wrap.append(html);
	}

	/* =====================================================================
	   Event Handlers
	   ===================================================================== */

	$(document).ready(function () {

		// Quick Connect button.
		$('#infyp-gsc-quick-connect').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true).text(infypGsc.i18n.connecting);

			$.post(ajaxUrl, {
				action: 'infyp_gsc_get_auth_url',
				nonce: nonce,
				method: 'quick'
			}, function (response) {
				if (response.success && response.data.auth_url) {
					window.location.href = response.data.auth_url;
				} else {
					showNotice(response.data.message || 'Failed to get authorization URL.', 'error');
					$btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-links"></span>' + infypGsc.i18n.connect);
				}
			}).fail(function () {
				showNotice('Request failed. Please try again.', 'error');
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-links"></span>' + infypGsc.i18n.connect);
			});
		});

		// Select property (delegated for dynamic content).
		$(document).on('click', '.infyp-gsc-select-property', function () {
			var $btn = $(this);
			var propertyUrl = $btn.data('url');

			$btn.prop('disabled', true).text(infypGsc.i18n.selecting);

			$.post(ajaxUrl, {
				action: 'infyp_gsc_select_property',
				nonce: nonce,
				property_url: propertyUrl
			}, function (response) {
				if (response.success) {
					showNotice(response.data.message, 'success');
					// Reload to show dashboard.
					setTimeout(function () {
						window.location.href = window.location.pathname + '?page=ai-image-seo-optimizer-search-console';
					}, 1000);
				} else {
					showNotice(response.data.message || 'Failed to select property.', 'error');
					$btn.prop('disabled', false).text(infypGsc.i18n.select);
				}
			}).fail(function () {
				showNotice('Request failed.', 'error');
				$btn.prop('disabled', false).text(infypGsc.i18n.select);
			});
		});

		// Disconnect (delegated).
		$(document).on('click', '#infyp-gsc-disconnect', function () {
			if (!confirm(infypGsc.i18n.confirm_disconnect)) {
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true);

			$.post(ajaxUrl, {
				action: 'infyp_gsc_disconnect',
				nonce: nonce
			}, function (response) {
				if (response.success) {
					showNotice(response.data.message, 'success');
					setTimeout(function () {
						window.location.reload();
					}, 1000);
				} else {
					showNotice(response.data.message || 'Disconnect failed.', 'error');
					$btn.prop('disabled', false);
				}
			}).fail(function () {
				showNotice('Request failed.', 'error');
				$btn.prop('disabled', false);
			});
		});

		// Date range buttons.
		$('.infyp-gsc-range-btn').on('click', function () {
			$('.infyp-gsc-range-btn').removeClass('active');
			$(this).addClass('active');
			currentRange = $(this).data('range');
			resetTableStates();
			loadDashboardData();
		});

		// Per-page selector.
		$('#infyp-gsc-per-page').on('change', function () {
			var val = parseInt($(this).val(), 10) || 10;
			perPage = val;
			impactPerPage = val;

			// Reset all table pages to 1.
			$.each(tableState, function (key, state) {
				state.page = 1;
				if (state.drillPage) {
					state.drillPage = 1;
				}
			});
			impactPage = 1;

			// Reload AJAX tables with new per-page.
			loadTableData('combined');
			loadTableData('winners');
			loadTableData('losers');

			// Re-paginate impact table client-side.
			renderImpactPage();
		});

		// Sync Now.
		$('#infyp-gsc-sync-now').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true);

			$.post(ajaxUrl, {
				action: 'infyp_gsc_manual_sync',
				nonce: nonce
			}, function (response) {
				if (response.success) {
					showNotice(response.data.message, 'success');
					if (response.data.last_sync) {
						$('.infyp-gsc-last-sync').text(infypGsc.i18n.last_synced + ' ' + response.data.last_sync);
					}
					loadDashboardData();
				} else {
					showNotice(response.data.message || 'Sync failed.', 'error');
				}
				$btn.prop('disabled', false);
			}).fail(function () {
				showNotice('Sync request failed.', 'error');
				$btn.prop('disabled', false);
			});
		});

		// Dev: Test Connect with mock data.
		$('#infyp-gsc-test-connect').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true).text('Seeding mock data...');

			$.post(ajaxUrl, {
				action: 'infyp_gsc_test_connect',
				nonce: nonce
			}, function (response) {
				if (response.success) {
					showNotice(response.data.message, 'success');
					setTimeout(function () {
						window.location.href = window.location.pathname + '?page=ai-image-seo-optimizer-search-console';
					}, 800);
				} else {
					showNotice(response.data.message || 'Failed to create mock connection.', 'error');
					$btn.prop('disabled', false).text('Dev: Test Connect with Mock Data');
				}
			}).fail(function () {
				showNotice('Request failed.', 'error');
				$btn.prop('disabled', false).text('Dev: Test Connect with Mock Data');
			});
		});

		// Dev: Remove mock data.
		$('#infyp-gsc-test-disconnect').on('click', function () {
			if (!confirm('Remove all mock Search Console data?')) {
				return;
			}

			var $btn = $(this);
			$btn.prop('disabled', true);

			$.post(ajaxUrl, {
				action: 'infyp_gsc_test_disconnect',
				nonce: nonce
			}, function (response) {
				if (response.success) {
					showNotice(response.data.message, 'success');
					setTimeout(function () {
						window.location.reload();
					}, 800);
				} else {
					showNotice(response.data.message || 'Failed to remove mock data.', 'error');
					$btn.prop('disabled', false);
				}
			}).fail(function () {
				showNotice('Request failed.', 'error');
				$btn.prop('disabled', false);
			});
		});

		/* =============================================================
		   Table Search Handlers (debounced)
		   ============================================================= */
		$(document).on('input', '.infyp-gsc-search-input', debounce(function () {
			var $input   = $(this);
			var tableKey = $input.attr('data-table');
			if (!tableKey) {
				return;
			}

			var isDrill   = tableKey.indexOf('-drill') !== -1;
			var parentKey = isDrill ? tableKey.replace('-drill', '') : tableKey;
			var state     = tableState[parentKey];
			if (!state) {
				return;
			}

			var val = $input.val().trim();

			if (isDrill) {
				state.drillSearch = val;
				state.drillPage = 1;
			} else {
				state.search = val;
				state.page = 1;
			}

			loadTableData(tableKey);
		}, 300));

		/* =============================================================
		   Sortable Column Headers
		   ============================================================= */
		$(document).on('click', '.infyp-gsc-sortable', function () {
			var $th      = $(this);
			var tableKey = $th.data('table');
			var sortCol  = $th.data('sort');
			if (!tableKey || !sortCol) {
				return;
			}

			var isDrill   = tableKey.indexOf('-drill') !== -1;
			var parentKey = isDrill ? tableKey.replace('-drill', '') : tableKey;
			var state     = tableState[parentKey];
			if (!state) {
				return;
			}

			// Determine current and new sort direction.
			var currentOrder = isDrill ? state.drillOrder : state.order;
			var currentCol   = isDrill ? state.drillOrderBy : state.orderBy;
			var newOrder;

			if (currentCol === sortCol) {
				newOrder = currentOrder === 'desc' ? 'asc' : 'desc';
			} else {
				newOrder = 'desc';
			}

			// Update state and reset page.
			if (isDrill) {
				state.drillOrderBy = sortCol;
				state.drillOrder   = newOrder;
				state.drillPage    = 1;
			} else {
				state.orderBy = sortCol;
				state.order   = newOrder;
				state.page    = 1;
			}

			// Update header indicators: clear siblings, set active.
			$th.closest('thead').find('.infyp-gsc-sortable').removeClass('asc desc');
			$th.addClass(newOrder);

			loadTableData(tableKey);
		});

		/* =============================================================
		   Drill-Down: Click query or page link in combined table
		   ============================================================= */
		$(document).on('click', '.infyp-gsc-drill-link', function (e) {
			e.preventDefault();
			var drillType  = $(this).data('drill-type');
			var drillValue = $(this).data('drill-value');
			if (drillType && drillValue) {
				enterDrillDown('combined', { type: drillType, value: drillValue });
			}
		});

		/* =============================================================
		   Drill-Down: Back button
		   ============================================================= */
		$(document).on('click', '.infyp-gsc-drill-back', function () {
			var tableKey = $(this).data('table');
			if (tableKey) {
				exitDrillDown(tableKey);
			}
		});

		/* =============================================================
		   Pagination: Prev / Next
		   ============================================================= */
		$(document).on('click', '.infyp-gsc-page-prev, .infyp-gsc-page-next', function () {
			var $btn     = $(this);
			var tableKey = $btn.data('table');
			if (!tableKey || $btn.prop('disabled')) {
				return;
			}

			var isDrill   = tableKey.indexOf('-drill') !== -1;
			var parentKey = isDrill ? tableKey.replace('-drill', '') : tableKey;
			var state     = tableState[parentKey];
			if (!state) {
				return;
			}

			var delta = $btn.hasClass('infyp-gsc-page-next') ? 1 : -1;

			if (isDrill) {
				state.drillPage = Math.max(1, (state.drillPage || 1) + delta);
			} else {
				state.page = Math.max(1, (state.page || 1) + delta);
			}

			loadTableData(tableKey);
		});

		/* =============================================================
		   Impact Table Search & Pagination
		   ============================================================= */
		$('#infyp-gsc-impact-search').on('keyup', function () {
			filterImpactTable();
		});

		$(document).on('click', '.infyp-gsc-impact-prev', function () {
			if (!$(this).prop('disabled') && impactPage > 1) {
				impactPage--;
				renderImpactPage();
			}
		});

		$(document).on('click', '.infyp-gsc-impact-next', function () {
			if (!$(this).prop('disabled')) {
				impactPage++;
				renderImpactPage();
			}
		});

		/* =============================================================
		   Metric Card Toggle (show/hide chart datasets)
		   ============================================================= */
		var metricDatasetMap = {
			impressions: [0, 4],
			clicks:      [1, 5],
			ctr:         [2, 6],
			position:    [3, 7]
		};

		$(document).on('click', '.infyp-gsc-metric-card', function () {
			var $card  = $(this);
			var metric = $card.data('metric');
			if (!metric || !timelineChart) {
				return;
			}

			$card.toggleClass('active');
			var isActive = $card.hasClass('active');
			var indices  = metricDatasetMap[metric];
			if (!indices) {
				return;
			}

			$.each(indices, function (i, idx) {
				var ds = timelineChart.data.datasets[idx];
				if (ds) {
					ds.hidden = !isActive;
				}
			});

			timelineChart.update();
		});

		// Load property list on State 2 (connected but no property).
		if (connected && !hasProperty) {
			loadPropertyList();
		}

		// Load dashboard data on State 3.
		if (connected && hasProperty) {
			loadDashboardData();
		}

		// Handle OAuth callback: auto-detect data passed via wp_localize_script.
		if (infypGsc.oauth_error) {
			infypGscShowError(infypGsc.oauth_error);
		} else if (infypGsc.oauth_code || infypGsc.oauth_proxy_code) {
			var urlParams = new URLSearchParams(window.location.search);
			infypGscExchangeCode(
				infypGsc.oauth_code || '',
				infypGsc.oauth_proxy_code || '',
				urlParams.get('state') || ''
			);
		}
	});

	/* =====================================================================
	   Data Loading
	   ===================================================================== */

	/**
	 * Load available GSC properties for selection.
	 */
	function loadPropertyList() {
		$.post(ajaxUrl, {
			action: 'infyp_gsc_handle_callback',
			nonce: nonce,
			code: '' // Empty code triggers a sites-only fetch.
		}, function (response) {
			if (response.success && response.data.sites) {
				var $list = $('#infyp-gsc-property-list');
				$list.empty();

				if (response.data.sites.length === 0) {
					$list.html('<p>' + infypGsc.i18n.no_properties + '</p>');
					return;
				}

				$.each(response.data.sites, function (i, site) {
					var type = site.siteUrl && site.siteUrl.indexOf('sc-domain:') === 0 ? 'Domain' : 'URL Prefix';
					$list.append(
						'<div class="infyp-gsc-property-item">' +
						'<div><span class="infyp-gsc-property-url">' + $('<span>').text(site.siteUrl).html() + '</span>' +
						'<span class="infyp-gsc-property-type">(' + type + ')</span></div>' +
						'<button type="button" class="button button-primary button-small infyp-gsc-select-property" data-url="' +
						$('<span>').text(site.siteUrl).html() + '">' + infypGsc.i18n.select + '</button></div>'
					);
				});
			} else {
				$('#infyp-gsc-property-list').html('<p>' + escapeHtml(response.data.message || infypGsc.i18n.no_properties) + '</p>');
			}
		}).fail(function () {
			$('#infyp-gsc-property-list').html('<p>Failed to load properties.</p>');
		});
	}

	/**
	 * Load dashboard data for the selected date range.
	 */
	function loadDashboardData() {
		if (isLoading) {
			return;
		}
		isLoading = true;

		$.post(ajaxUrl, {
			action: 'infyp_gsc_get_dashboard_data',
			nonce: nonce,
			date_range: currentRange
		}, function (response) {
			isLoading = false;

			if (!response.success) {
				showNotice(response.data.message || 'Failed to load data.', 'error');
				return;
			}

			var data = response.data;

			// Update metrics.
			updateMetrics(data.summary, data.comparison);

			// Update performance timeline chart.
			renderTimelineChart(data.daily_series, data.prev_daily_series);

			// Update impact section.
			updateImpact(data.impact);

			// Update sitemap.
			updateSitemap(data.sitemap);

			// Show/hide no-data message.
			var hasData = data.summary &&
				(parseInt(data.summary.total_impressions, 10) > 0 || parseInt(data.summary.total_clicks, 10) > 0);

			$('#infyp-gsc-no-data').toggle(!hasData);
			$('#infyp-gsc-metrics, #infyp-gsc-chart-card, #infyp-gsc-combined-card, .infyp-gsc-tables-grid').toggle(!!hasData);

			// Show winning/losing section if data exists.
			var hasWL = (data.winning_queries && data.winning_queries.length > 0) ||
				(data.losing_queries && data.losing_queries.length > 0);
			$('#infyp-gsc-wl-section').toggle(hasWL);

			// Load tables with pagination via separate AJAX calls.
			if (hasData) {
				loadTableData('combined');
				loadTableData('winners');
				loadTableData('losers');
			}

		}).fail(function () {
			isLoading = false;
			showNotice('Failed to load dashboard data.', 'error');
		});
	}

	/**
	 * Update the 4 metric cards.
	 */
	function updateMetrics(summary, comparison) {
		if (!summary) {
			return;
		}

		$('#infyp-gsc-impressions').text(formatNumber(summary.total_impressions));
		$('#infyp-gsc-clicks').text(formatNumber(summary.total_clicks));
		$('#infyp-gsc-ctr').text(formatPercent(summary.avg_ctr));
		$('#infyp-gsc-position').text(formatPosition(summary.avg_position));

		// Show deltas if comparison data exists.
		if (comparison && comparison.period1) {
			var prev = comparison.period1;
			showDelta($('#infyp-gsc-impressions-delta'), summary.total_impressions, prev.total_impressions, false);
			showDelta($('#infyp-gsc-clicks-delta'), summary.total_clicks, prev.total_clicks, false);
			showDelta($('#infyp-gsc-ctr-delta'), summary.avg_ctr, prev.avg_ctr, false);
			showDelta($('#infyp-gsc-position-delta'), summary.avg_position, prev.avg_position, true);
		}
	}

	/**
	 * Update the combined queries & pages table.
	 */
	function updateCombinedTable(rows) {
		var $tbody = $('#infyp-gsc-combined-table tbody');
		$tbody.empty();

		if (!rows || rows.length === 0) {
			$tbody.html('<tr class="infyp-gsc-loading-row"><td colspan="6">' + (infypGsc.i18n.no_results || infypGsc.i18n.no_data) + '</td></tr>');
			return;
		}

		$.each(rows, function (i, row) {
			var queryText  = row.query || '(not set)';
			var displayUrl = truncateUrl(row.page_url);
			var queryHtml  = $('<span>').text(queryText).html();
			var pageHtml   = $('<span>').text(row.page_url).html();

			$tbody.append(
				'<tr>' +
				'<td><a href="#" class="infyp-gsc-drill-link" data-drill-type="query" data-drill-value="' + queryHtml + '">' + queryHtml + '</a></td>' +
				'<td><a href="#" class="infyp-gsc-drill-link infyp-gsc-page-url" data-drill-type="page" data-drill-value="' + pageHtml + '" title="' + pageHtml + '">' +
				$('<span>').text(displayUrl).html() + '</a></td>' +
				'<td class="infyp-gsc-num">' + formatNumber(row.impressions) + '</td>' +
				'<td class="infyp-gsc-num">' + formatNumber(row.clicks) + '</td>' +
				'<td class="infyp-gsc-num">' + formatPercent(row.ctr) + '</td>' +
				'<td class="infyp-gsc-num">' + formatPosition(row.position) + '</td>' +
				'</tr>'
			);
		});
	}

	/**
	 * Get the badge CSS class string for an impact change value.
	 *
	 * Returns framework badge classes based on the direction of change:
	 * positive values get success (green), negative get error (red),
	 * and zero/NaN get warning (yellow).
	 *
	 * @since  1.1.0
	 *
	 * @param  {number|string} change - The numeric change value.
	 * @returns {string} Space-separated CSS class string (e.g. 'infyp-badge infyp-badge-success').
	 */
	function getImpactBadgeClass(change) {
		change = parseFloat(change);
		if (isNaN(change) || change === 0) {
			return 'infyp-badge infyp-badge-warning';
		}
		return change > 0 ? 'infyp-badge infyp-badge-success' : 'infyp-badge infyp-badge-error';
	}

	/**
	 * Update the optimization impact section.
	 *
	 * Renders the 4 KPI summary items (impressions, clicks, position, pages tracked)
	 * and populates the per-page impact table with client-side search and pagination.
	 *
	 * @since  1.1.0
	 *
	 * @param  {Object} impact - Impact data from the server (wp_option: infyp_gsc_optimization_impact).
	 */
	function updateImpact(impact) {
		var $section = $('#infyp-gsc-impact-section');
		var $metrics = $('#infyp-gsc-impact-metrics');

		if (!impact || !impact.pages_tracked || impact.pages_tracked === 0) {
			$section.hide();
			return;
		}

		$section.show();
		$metrics.empty();

		// Impressions change.
		var imprBefore = parseInt(impact.total_impressions_before, 10) || 0;
		var imprAfter  = parseInt(impact.total_impressions_after, 10) || 0;
		var imprChange = imprBefore > 0 ? ((imprAfter - imprBefore) / imprBefore * 100).toFixed(0) : 0;

		$metrics.append(
			'<div class="infyp-gsc-impact-item">' +
			'<div class="infyp-gsc-impact-label">' + infypGsc.i18n.impressions + '</div>' +
			'<div class="infyp-gsc-impact-value">' + formatNumber(imprBefore) + ' &rarr; ' + formatNumber(imprAfter) + '</div>' +
			'<span class="' + getImpactBadgeClass(imprChange) + '">' + (imprChange > 0 ? '+' : '') + imprChange + '%</span></div>'
		);

		// Clicks change.
		var clicksBefore = parseInt(impact.total_clicks_before, 10) || 0;
		var clicksAfter  = parseInt(impact.total_clicks_after, 10) || 0;
		var clicksChange = clicksBefore > 0 ? ((clicksAfter - clicksBefore) / clicksBefore * 100).toFixed(0) : 0;

		$metrics.append(
			'<div class="infyp-gsc-impact-item">' +
			'<div class="infyp-gsc-impact-label">' + infypGsc.i18n.clicks + '</div>' +
			'<div class="infyp-gsc-impact-value">' + formatNumber(clicksBefore) + ' &rarr; ' + formatNumber(clicksAfter) + '</div>' +
			'<span class="' + getImpactBadgeClass(clicksChange) + '">' + (clicksChange > 0 ? '+' : '') + clicksChange + '%</span></div>'
		);

		// Position change.
		var posBefore = parseFloat(impact.avg_position_before) || 0;
		var posAfter  = parseFloat(impact.avg_position_after) || 0;
		var posImproved = posBefore - posAfter;
		var posBadge = getImpactBadgeClass(posImproved);

		$metrics.append(
			'<div class="infyp-gsc-impact-item">' +
			'<div class="infyp-gsc-impact-label">' + infypGsc.i18n.avg_position + '</div>' +
			'<div class="infyp-gsc-impact-value">' + formatPosition(posBefore) + ' &rarr; ' + formatPosition(posAfter) + '</div>' +
			'<span class="' + posBadge + '">' + (posImproved > 0 ? '\u25B2 ' : '\u25BC ') +
			Math.abs(posImproved).toFixed(1) + ' ' + infypGsc.i18n.positions + '</span></div>'
		);

		// Pages tracked.
		$metrics.append(
			'<div class="infyp-gsc-impact-item">' +
			'<div class="infyp-gsc-impact-label">' + infypGsc.i18n.pages_tracked + '</div>' +
			'<div class="infyp-gsc-impact-value">' + formatNumber(impact.pages_tracked) + '</div>' +
			'<span class="infyp-badge infyp-badge-success">' + infypGsc.i18n.optimized_pages + '</span></div>'
		);

		// Per-page impact table (client-side search + pagination).
		var $tableWrap = $('#infyp-gsc-impact-table-wrap');

		if (impact.per_page_impact && impact.per_page_impact.length > 0) {
			$tableWrap.show();
			impactAllRows  = impact.per_page_impact;
			impactPage     = 1;
			$('#infyp-gsc-impact-search').val('');
			filterImpactTable();
		} else {
			$tableWrap.hide();
		}
	}

	/**
	 * Filter the per-page impact table by search query and reset to page 1.
	 *
	 * Reads the search input value, filters impactAllRows by URL match,
	 * stores the result in impactFiltered, and re-renders page 1.
	 *
	 * @since  1.1.0
	 */
	function filterImpactTable() {
		var query = ($('#infyp-gsc-impact-search').val() || '').toLowerCase();
		impactFiltered = query
			? $.grep(impactAllRows, function (row) { return row.page_url.toLowerCase().indexOf(query) !== -1; })
			: impactAllRows;
		impactPage = 1;
		renderImpactPage();
	}

	/**
	 * Render the current page of impact table rows and pagination controls.
	 *
	 * Slices impactFiltered based on impactPage and impactPerPage,
	 * renders table rows with change badges, and updates pagination
	 * info with prev/next buttons.
	 *
	 * @since  1.1.0
	 */
	function renderImpactPage() {
		var $tbody = $('#infyp-gsc-impact-table tbody');
		$tbody.empty();

		var total  = impactFiltered.length;
		var start  = (impactPage - 1) * impactPerPage;
		var slice  = impactFiltered.slice(start, start + impactPerPage);

		$.each(slice, function (i, page) {
			var pageImprChange = page.impressions_before > 0
				? ((page.impressions_after - page.impressions_before) / page.impressions_before * 100).toFixed(0)
				: 0;
			var pageClicksChange = page.clicks_before > 0
				? ((page.clicks_after - page.clicks_before) / page.clicks_before * 100).toFixed(0)
				: 0;
			var pagePosChange = (page.position_before - page.position_after).toFixed(1);

			$tbody.append(
				'<tr>' +
				'<td><span class="infyp-gsc-page-url" title="' + $('<span>').text(page.page_url).html() + '">' +
				$('<span>').text(truncateUrl(page.page_url)).html() + '</span></td>' +
				'<td class="infyp-gsc-num">' + page.optimized_date + '</td>' +
				'<td class="infyp-gsc-num"><span class="' + getImpactBadgeClass(pageImprChange) + '">' + (pageImprChange > 0 ? '+' : '') + pageImprChange + '%</span></td>' +
				'<td class="infyp-gsc-num"><span class="' + getImpactBadgeClass(pageClicksChange) + '">' + (pageClicksChange > 0 ? '+' : '') + pageClicksChange + '%</span></td>' +
				'<td class="infyp-gsc-num"><span class="' + getImpactBadgeClass(pagePosChange) + '">' + (pagePosChange > 0 ? '\u25B2 ' : '\u25BC ') + Math.abs(pagePosChange) + '</span></td>' +
				'</tr>'
			);
		});

		// Pagination info.
		var $info = $('#infyp-gsc-impact-info');
		$info.empty();

		if (total <= 0) {
			return;
		}

		var startItem = start + 1;
		var endItem   = Math.min(start + slice.length, total);
		var hasNext   = endItem < total;
		var hasPrev   = impactPage > 1;

		var rangeText = (infypGsc.i18n.showing_range || 'Showing %1$s\u2013%2$s of %3$s')
			.replace('%1$s', startItem)
			.replace('%2$s', endItem)
			.replace('%3$s', total);

		var html = '<span class="infyp-gsc-pagination-text">' + rangeText + '</span>';

		if (hasPrev || hasNext) {
			html += '<span class="infyp-gsc-pagination-btns">';
			html += '<button type="button" class="infyp-gsc-page-btn infyp-gsc-impact-prev"' +
				(hasPrev ? '' : ' disabled') + '>&larr; ' + (infypGsc.i18n.prev || 'Prev') + '</button>';
			html += '<button type="button" class="infyp-gsc-page-btn infyp-gsc-impact-next"' +
				(hasNext ? '' : ' disabled') + '>' + (infypGsc.i18n.next || 'Next') + ' &rarr;</button>';
			html += '</span>';
		}

		$info.html(html);
	}

	/**
	 * Update the sitemap status section.
	 */
	function updateSitemap(sitemap) {
		var $section = $('#infyp-gsc-sitemap-section');
		var $info    = $('#infyp-gsc-sitemap-info');

		if (!sitemap) {
			$section.hide();
			return;
		}

		$section.show();
		$info.empty();

		$info.append(
			'<div class="infyp-gsc-sitemap-stat">' +
			'<div class="infyp-gsc-sitemap-stat-label">' + infypGsc.i18n.sitemap_url + '</div>' +
			'<div class="infyp-gsc-sitemap-stat-value" style="font-size: 13px; word-break: break-all;">' +
			$('<span>').text(sitemap.sitemap_url || '-').html() + '</div></div>'
		);

		$info.append(
			'<div class="infyp-gsc-sitemap-stat">' +
			'<div class="infyp-gsc-sitemap-stat-label">' + infypGsc.i18n.images_submitted + '</div>' +
			'<div class="infyp-gsc-sitemap-stat-value">' + formatNumber(sitemap.images_submitted) + '</div></div>'
		);

		$info.append(
			'<div class="infyp-gsc-sitemap-stat">' +
			'<div class="infyp-gsc-sitemap-stat-label">' + infypGsc.i18n.last_downloaded + '</div>' +
			'<div class="infyp-gsc-sitemap-stat-value">' +
			(sitemap.last_downloaded ? sitemap.last_downloaded : '-') + '</div></div>'
		);

		$info.append(
			'<div class="infyp-gsc-sitemap-stat">' +
			'<div class="infyp-gsc-sitemap-stat-label">' + infypGsc.i18n.errors_warnings + '</div>' +
			'<div class="infyp-gsc-sitemap-stat-value">' +
			(sitemap.errors || 0) + ' / ' + (sitemap.warnings || 0) + '</div></div>'
		);
	}

	/* =====================================================================
	   Performance Timeline Chart
	   ===================================================================== */

	/**
	 * Render the performance timeline chart with optional comparison period.
	 *
	 * Creates a Chart.js line chart with 4 current-period datasets (impressions,
	 * clicks, CTR, position) and optionally 4 dashed comparison-period datasets.
	 * Uses three Y-axes: left for counts, right for CTR percentage, and a second
	 * right axis for position (inverted, since lower is better).
	 *
	 * Destroys any existing chart instance before creating a new one to prevent
	 * memory leaks on date range changes.
	 *
	 * @since  1.1.0
	 *
	 * @param  {Array}  dailySeries     - Array of { date, clicks, impressions, ctr, position } objects.
	 * @param  {Array}  prevDailySeries - Previous period data, aligned by index (not date).
	 */
	function renderTimelineChart(dailySeries, prevDailySeries) {
		if (!dailySeries || dailySeries.length === 0 || typeof Chart === 'undefined') {
			return;
		}

		var canvas = document.getElementById('infyp-gsc-timeline-chart');
		if (!canvas) {
			return;
		}

		// Destroy previous chart instance on re-render.
		if (timelineChart) {
			timelineChart.destroy();
		}

		// Build date labels and current period data.
		var labels = [];
		var impressions = [];
		var clicks = [];
		var ctr = [];
		var position = [];

		var monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
			'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

		$.each(dailySeries, function (i, day) {
			var d = new Date(day.date);
			labels.push(monthNames[d.getMonth()] + ' ' + d.getDate());
			impressions.push(parseFloat(day.impressions) || 0);
			clicks.push(parseFloat(day.clicks) || 0);
			ctr.push((parseFloat(day.ctr) || 0) * 100);
			position.push(parseFloat(day.position) || 0);
		});

		// Build previous period data (aligned by index, not date).
		var prevImpressions = [];
		var prevClicks = [];
		var prevCtr = [];
		var prevPosition = [];
		var hasPrev = prevDailySeries && prevDailySeries.length > 0;

		if (hasPrev) {
			$.each(dailySeries, function (i) {
				var prev = prevDailySeries[i];
				if (prev) {
					prevImpressions.push(parseFloat(prev.impressions) || 0);
					prevClicks.push(parseFloat(prev.clicks) || 0);
					prevCtr.push((parseFloat(prev.ctr) || 0) * 100);
					prevPosition.push(parseFloat(prev.position) || 0);
				} else {
					prevImpressions.push(null);
					prevClicks.push(null);
					prevCtr.push(null);
					prevPosition.push(null);
				}
			});
		}

		// Shared style for comparison (previous period) datasets.
		var prevStyle = {
			borderWidth: 1.5,
			borderDash: [5, 4],
			pointRadius: 0,
			pointHoverRadius: 3,
			fill: false,
			tension: 0.3
		};

		// Current period datasets.
		var datasets = [
			{
				label: infypGsc.i18n.impressions || 'Impressions',
				data: impressions,
				borderColor: '#2271b1',
				backgroundColor: '#2271b115',
				borderWidth: 2,
				pointRadius: 0,
				pointHoverRadius: 4,
				fill: false,
				tension: 0.3,
				yAxisID: 'y'
			},
			{
				label: infypGsc.i18n.clicks || 'Clicks',
				data: clicks,
				borderColor: '#10b981',
				backgroundColor: '#10b98115',
				borderWidth: 2,
				pointRadius: 0,
				pointHoverRadius: 4,
				fill: false,
				tension: 0.3,
				yAxisID: 'y'
			},
			{
				label: 'CTR',
				data: ctr,
				borderColor: '#f59e0b',
				backgroundColor: '#f59e0b15',
				borderWidth: 2,
				pointRadius: 0,
				pointHoverRadius: 4,
				fill: false,
				tension: 0.3,
				yAxisID: 'y1'
			},
			{
				label: infypGsc.i18n.avg_position || 'Avg. Position',
				data: position,
				borderColor: '#6366f1',
				backgroundColor: '#6366f115',
				borderWidth: 2,
				pointRadius: 0,
				pointHoverRadius: 4,
				fill: false,
				tension: 0.3,
				yAxisID: 'y2'
			}
		];

		// Add previous period datasets if data exists.
		if (hasPrev) {
			var prevLabel = infypGsc.i18n.previous_period || 'Previous period';
			datasets.push(
				$.extend({}, prevStyle, {
					label: (infypGsc.i18n.impressions || 'Impressions') + ' (' + prevLabel + ')',
					data: prevImpressions,
					borderColor: 'rgba(34,113,177,0.35)',
					yAxisID: 'y',
					_isPrev: true
				}),
				$.extend({}, prevStyle, {
					label: (infypGsc.i18n.clicks || 'Clicks') + ' (' + prevLabel + ')',
					data: prevClicks,
					borderColor: 'rgba(16,185,129,0.35)',
					yAxisID: 'y',
					_isPrev: true
				}),
				$.extend({}, prevStyle, {
					label: 'CTR (' + prevLabel + ')',
					data: prevCtr,
					borderColor: 'rgba(245,158,11,0.35)',
					yAxisID: 'y1',
					_isPrev: true
				}),
				$.extend({}, prevStyle, {
					label: (infypGsc.i18n.avg_position || 'Avg. Position') + ' (' + prevLabel + ')',
					data: prevPosition,
					borderColor: 'rgba(99,102,241,0.35)',
					yAxisID: 'y2',
					_isPrev: true
				})
			);
		}

		timelineChart = new Chart(canvas.getContext('2d'), {
			type: 'line',
			data: {
				labels: labels,
				datasets: datasets
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: {
					mode: 'index',
					intersect: false
				},
				plugins: {
					legend: {
						display: true,
						position: 'top',
						labels: {
							usePointStyle: true,
							padding: 16,
							font: { size: 12 },
							filter: function (item, chartData) {
								// Hide individual previous period items from legend.
								return !chartData.datasets[item.datasetIndex]._isPrev;
							}
						}
					},
					tooltip: {
						enabled: true,
						callbacks: {
							label: function (context) {
								var ds    = context.dataset;
								var label = ds.label || '';
								var value = context.parsed.y;
								if (value === null || value === undefined) {
									return null;
								}
								var isCtr = label.indexOf('CTR') === 0;
								var isPos = label.indexOf(infypGsc.i18n.avg_position || 'Avg. Position') === 0;
								var prefix = ds._isPrev ? '  prev ' : '';

								if (isCtr) {
									return prefix + 'CTR: ' + value.toFixed(1) + '%';
								}
								if (isPos) {
									return prefix + (infypGsc.i18n.avg_position || 'Position') + ': ' + value.toFixed(1);
								}
								// Impressions / Clicks.
								var metricName = ds._isPrev ? label.replace(/ \(.*\)$/, '') : label;
								return prefix + metricName + ': ' + value.toLocaleString(undefined, { maximumFractionDigits: 0 });
							}
						}
					}
				},
				scales: {
					x: {
						display: true,
						grid: { display: false },
						ticks: {
							maxRotation: 0,
							autoSkip: true,
							maxTicksLimit: 12,
							font: { size: 11 }
						}
					},
					y: {
						type: 'linear',
						display: true,
						position: 'left',
						title: {
							display: true,
							text: (infypGsc.i18n.impressions || 'Impressions') + ' / ' + (infypGsc.i18n.clicks || 'Clicks'),
							font: { size: 11 }
						},
						beginAtZero: true,
						grid: { color: 'rgba(0,0,0,0.06)' },
						ticks: {
							font: { size: 11 },
							callback: function (value) {
								return value.toLocaleString(undefined, { maximumFractionDigits: 0 });
							}
						}
					},
					y1: {
						type: 'linear',
						display: true,
						position: 'right',
						title: {
							display: true,
							text: 'CTR (%)',
							font: { size: 11 }
						},
						beginAtZero: true,
						grid: { drawOnChartArea: false },
						ticks: {
							font: { size: 11 },
							callback: function (value) {
								return value.toFixed(0) + '%';
							}
						}
					},
					y2: {
						type: 'linear',
						display: true,
						position: 'right',
						reverse: true,
						title: {
							display: true,
							text: infypGsc.i18n.avg_position || 'Position',
							font: { size: 11 }
						},
						grid: { drawOnChartArea: false },
						ticks: {
							font: { size: 11 },
							callback: function (value) {
								return value.toFixed(0);
							}
						}
					}
				},
				elements: { line: { borderCapStyle: 'round' } },
				animation: { duration: 400 }
			}
		});
	}

	/* =====================================================================
	   Winning & Losing Queries
	   ===================================================================== */

	/**
	 * Update the winning and losing queries tables.
	 */
	function updateWinningLosing(winners, losers) {
		var $section = $('#infyp-gsc-wl-section');
		var hasWinners = winners && winners.length > 0;
		var hasLosers  = losers && losers.length > 0;

		if (!hasWinners && !hasLosers) {
			$section.hide();
			return;
		}

		$section.show();
		renderWLTable($('#infyp-gsc-winners-table tbody'), winners, true);
		renderWLTable($('#infyp-gsc-losers-table tbody'), losers, false);
	}

	/**
	 * Render rows for a winning or losing queries table.
	 *
	 * Populates the given tbody with query rows showing position, change
	 * (with badge), and impressions change (with badge). Winners use
	 * success badges; losers use error badges.
	 *
	 * @since  1.1.0
	 *
	 * @param  {jQuery}  $tbody    - The jQuery tbody element to populate.
	 * @param  {Array}   rows      - Array of query row objects from the server.
	 * @param  {boolean} isWinners - True for winning queries, false for losing.
	 */
	function renderWLTable($tbody, rows, isWinners) {
		$tbody.empty();

		if (!rows || rows.length === 0) {
			var label = isWinners ? infypGsc.i18n.no_winners : infypGsc.i18n.no_losers;
			$tbody.html('<tr class="infyp-gsc-loading-row"><td colspan="4">' + (label || infypGsc.i18n.no_results || infypGsc.i18n.no_data) + '</td></tr>');
			return;
		}

		$.each(rows, function (i, row) {
			var change    = parseFloat(row.position_change) || 0;
			var arrow     = isWinners ? '\u25B2' : '\u25BC';
			var changeBadge = isWinners ? 'infyp-badge infyp-badge-success' : 'infyp-badge infyp-badge-error';
			var imprDelta = parseInt(row.impressions_change, 10) || 0;
			var imprBadge = getImpactBadgeClass(imprDelta);
			var imprText  = (imprDelta >= 0 ? '+' : '') + formatNumber(imprDelta);

			$tbody.append(
				'<tr>' +
				'<td>' + $('<span>').text(row.query || '(not set)').html() + '</td>' +
				'<td class="infyp-gsc-num">' + formatPosition(row.current_position) + '</td>' +
				'<td class="infyp-gsc-num"><span class="' + changeBadge + '">' + arrow + ' ' +
				Math.abs(change).toFixed(1) + '</span></td>' +
				'<td class="infyp-gsc-num"><span class="' + imprBadge + '">' + imprText + '</span></td>' +
				'</tr>'
			);
		});
	}

})(jQuery);

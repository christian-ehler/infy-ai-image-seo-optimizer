/**
 * Dashboard JavaScript
 *
 * Handles dashboard card interactions:
 * - Extended fields toggle button (show/hide extension stats)
 * - Compression recommendation card dismissal via AJAX
 *
 * @file   dashboard.js
 * @author InfyPress
 * @since  2.1.0
 *
 * @requires jQuery
 * @requires infypDashboard (localized via wp_localize_script)
 */

/* global jQuery, infypDashboard */
(function ($) {
	'use strict';

	// Bail if localized data is missing.
	if (typeof infypDashboard === 'undefined') {
		return;
	}

	$(function () {

		/* =============================================================
		   Extended Fields Toggle
		   ============================================================= */

		var $toggleBtn = $('#infyp-toggle-all-fields');
		if ($toggleBtn.length) {
			var $card     = $toggleBtn.closest('.infyp-field-optimization-card');
			var $showSpan = $toggleBtn.find('.infyp-toggle-show');
			var $hideSpan = $toggleBtn.find('.infyp-toggle-hide');

			$toggleBtn.on('click', function () {
				var isExpanded = $card.toggleClass('infyp-fields-expanded').hasClass('infyp-fields-expanded');
				$toggleBtn.attr('aria-expanded', isExpanded);
				$showSpan.toggle(!isExpanded);
				$hideSpan.toggle(isExpanded);
			});
		}

		/* =============================================================
		   Compression Card Dismissal
		   ============================================================= */

		$(document).on('click', '#infyp-dismiss-compression-card', function (e) {
			e.preventDefault();

			var $cardEl  = $('#infyp-compression-card');
			var $apiCard = $('#infyp-api-usage-card');

			$.ajax({
				url:  infypDashboard.ajax_url,
				type: 'POST',
				data: {
					action: 'infyp_dismiss_compression_notice',
					nonce:  infypDashboard.dismiss_nonce
				},
				success: function (response) {
					if (response.success) {
						$cardEl.fadeOut(300, function () {
							$(this).remove();
							if ($apiCard.length && $apiCard.is(':hidden')) {
								$apiCard.fadeIn(400);
							}
						});
					}
				}
			});
		});

	});

})(jQuery);

/**
 * API Usage Chart JavaScript
 *
 * Renders the daily API usage line chart on the API Usage admin page.
 * Uses Chart.js to visualize per-provider call counts over the last 30 days.
 *
 * Default provider colors:
 *   Google    — Google Blue (#4285F4)
 *   OpenAI    — OpenAI Green (#10A37F)
 *   Anthropic — Anthropic Terracotta (#D97356)
 *
 * Colors and display names are passed from PHP via wp_localize_script
 * and can be extended by hooking into the infyp_usage_chart_provider_colors
 * and infyp_provider_display_names filters.
 *
 * @file   api-usage-chart.js
 * @author InfyPress
 * @since  2.1.0
 *
 * @requires jQuery
 * @requires Chart.js (loaded as infyp-chart-js)
 * @requires infypApiUsage (localized via wp_localize_script)
 */

/* global jQuery, Chart, infypApiUsage */
(function ($) {
	'use strict';

	if (typeof infypApiUsage === 'undefined' || typeof Chart === 'undefined') {
		return;
	}

	$(function () {
		var canvas = document.getElementById('infyp-usage-chart');
		if (!canvas) {
			return;
		}

		var dailyData = infypApiUsage.daily_data || [];
		var labels    = [];
		var datasets  = {};

		// Provider colors and display names from PHP (extensible via filters).
		var providerColors = infypApiUsage.provider_colors || {};
		var providerNames  = infypApiUsage.provider_names || {};

		// Build unique date labels and initialize per-provider datasets.
		dailyData.forEach(function (item) {
			if (labels.indexOf(item.date) === -1) {
				labels.push(item.date);
			}

			if (!datasets[item.provider]) {
				var colors = providerColors[item.provider] || {
					border: '#999999',
					bg: 'rgba(153, 153, 153, 0.1)'
				};

				var displayName = providerNames[item.provider]
					|| item.provider.charAt(0).toUpperCase() + item.provider.slice(1);

				datasets[item.provider] = {
					label:           displayName,
					data:            [],
					borderWidth:     2,
					borderColor:     colors.border,
					backgroundColor: colors.bg
				};
			}
		});

		// Fill in call counts for each provider per date (0 if no data for that day).
		labels.forEach(function (date) {
			Object.keys(datasets).forEach(function (provider) {
				var dayData = null;
				for (var i = 0; i < dailyData.length; i++) {
					if (dailyData[i].date === date && dailyData[i].provider === provider) {
						dayData = dailyData[i];
						break;
					}
				}
				datasets[provider].data.push(dayData ? parseInt(dayData.calls, 10) : 0);
			});
		});

		// Render the chart with dates in chronological order (oldest first).
		new Chart(canvas.getContext('2d'), {
			type: 'line',
			data: {
				labels:   labels.reverse(),
				datasets: Object.values(datasets)
			},
			options: {
				responsive:          true,
				maintainAspectRatio: false,
				plugins: {
					legend: { position: 'top' },
					title:  { display: false }
				},
				scales: {
					y: {
						beginAtZero: true,
						ticks: { precision: 0 }
					}
				}
			}
		});
	});

})(jQuery);

/**
 * REST API activity chart.
 *
 * Renders daily request counts for the last 30 days as stacked bars
 * (successful vs failed) with dry runs overlaid as a line, using the data
 * localized by INFYP_REST_API_Page::on_load_page().
 *
 * @package IPAIS
 * @since 1.0.6
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var canvas = document.getElementById('infyp-rest-chart');

		if (!canvas || typeof Chart === 'undefined') {
			return;
		}

		var config = window.infypRestChart || {};
		var series = Array.isArray(config.series) ? config.series : [];
		var labels = config.labels || {};

		if (!series.length) {
			return;
		}

		var days      = series.map(function (row) { return row.date; });
		var succeeded = series.map(function (row) { return parseInt(row.success, 10) || 0; });
		var failed    = series.map(function (row) { return parseInt(row.failed, 10) || 0; });
		var dryRuns   = series.map(function (row) { return parseInt(row.dry_run, 10) || 0; });

		new Chart(canvas.getContext('2d'), {
			data: {
				labels: days,
				datasets: [
					{
						type: 'bar',
						label: labels.success || 'Successful',
						data: succeeded,
						backgroundColor: 'rgba(0, 163, 42, 0.65)',
						borderColor: 'rgba(0, 163, 42, 1)',
						borderWidth: 1,
						stack: 'requests'
					},
					{
						type: 'bar',
						label: labels.failed || 'Failed',
						data: failed,
						backgroundColor: 'rgba(214, 54, 56, 0.65)',
						borderColor: 'rgba(214, 54, 56, 1)',
						borderWidth: 1,
						stack: 'requests'
					},
					{
						type: 'line',
						label: labels.dryRun || 'Dry runs',
						data: dryRuns,
						borderColor: 'rgba(34, 113, 177, 1)',
						backgroundColor: 'rgba(34, 113, 177, 0.15)',
						borderWidth: 2,
						pointRadius: 2,
						tension: 0.3,
						fill: false
					}
				]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				interaction: {
					mode: 'index',
					intersect: false
				},
				plugins: {
					legend: { position: 'top' },
					title: { display: false }
				},
				scales: {
					x: { stacked: true },
					y: {
						stacked: true,
						beginAtZero: true,
						ticks: { precision: 0 }
					}
				}
			}
		});
	});
})();

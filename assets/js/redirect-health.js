/**
 * Redirect health — browser probe.
 *
 * The definitive test of image redirects runs here, in the admin's own
 * browser: it travels the real public path (CDN and cache layers included),
 * which the server's loopback probe never sees. Flow:
 *
 *   1. AJAX infyp_redirect_health_probe  → server runs its loopback probe
 *      and returns a live probe URL (a redirect row for a file that never
 *      existed).
 *   2. fetch(probe URL) with redirect:'follow' — observe whether the
 *      response was redirected and where it landed.
 *   3. AJAX infyp_redirect_health_report → server stores the verdict and
 *      returns the final state for display.
 *
 * @package AI_Image_SEO
 * @since   1.0.7
 */

/* global infypRedirectHealth, jQuery */
( function ( $ ) {
	'use strict';

	var config = window.infypRedirectHealth || null;
	if ( ! config ) {
		return;
	}

	var $button = $( '#infyp-health-test' );
	var $status = $( '#infyp-health-status' );
	var $detail = $( '#infyp-health-detail' );

	function setBusy( busy ) {
		$button.prop( 'disabled', busy );
		$button.text( busy ? config.i18n.testing : config.i18n.testNow );
	}

	function render( state ) {
		var cls = 'infyp-health-badge infyp-health-' + state.state;
		$status.attr( 'class', cls ).text( state.label );
		if ( state.checked_at ) {
			$detail.text( config.i18n.lastChecked + ' ' + state.checked_at );
		}
		// The page-level warning banners reflect stored state; reload so
		// notices appear/disappear consistently after a verdict change.
		if ( state.reload ) {
			window.location.reload();
		}
	}

	function report( observation ) {
		return $.post( config.ajaxUrl, {
			action: 'infyp_redirect_health_report',
			nonce: config.nonce,
			redirected: observation.redirected ? '1' : '0',
			final_url: observation.finalUrl,
			status: observation.status,
		} );
	}

	function runBrowserLeg( probe ) {
		// cache:'no-store' so a CDN-cached 404 from an earlier visit cannot
		// mask a fixed server, and vice versa.
		return fetch( probe.url, { redirect: 'follow', cache: 'no-store', credentials: 'omit' } )
			.then( function ( response ) {
				return {
					redirected: response.redirected,
					finalUrl: response.url || '',
					status: response.status,
				};
			} )
			.catch( function () {
				// Network-level failure: report as inconclusive (status 0).
				return { redirected: false, finalUrl: '', status: 0 };
			} );
	}

	$button.on( 'click', function () {
		setBusy( true );
		$.post( config.ajaxUrl, {
			action: 'infyp_redirect_health_probe',
			nonce: config.nonce,
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success || ! res.data.probe ) {
					setBusy( false );
					render( res && res.data && res.data.loopback ? res.data.loopback : { state: 'inconclusive', label: config.i18n.error } );
					return;
				}
				runBrowserLeg( res.data.probe ).then( function ( observation ) {
					report( observation )
						.done( function ( r2 ) {
							if ( r2 && r2.success ) {
								r2.data.reload = true;
								render( r2.data );
							} else {
								render( { state: 'inconclusive', label: config.i18n.error } );
							}
						} )
						.fail( function () {
							render( { state: 'inconclusive', label: config.i18n.error } );
						} )
						.always( function () {
							setBusy( false );
						} );
				} );
			} )
			.fail( function () {
				setBusy( false );
				render( { state: 'inconclusive', label: config.i18n.error } );
			} );
	} );
} )( jQuery );

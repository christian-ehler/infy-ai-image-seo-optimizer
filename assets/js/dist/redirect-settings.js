/**
 * Redirect Settings — Handles the AJAX save for the redirect settings card.
 *
 * Enqueued only on the Settings admin page. Receives translated strings
 * and the AJAX nonce via `infypRedirectSettings` (wp_localize_script).
 *
 * @package IPAIS
 * @since   1.1.0
 */
jQuery( function( $ ) {
	'use strict';

	var $form   = $( '#infyp-redirect-settings-form' );
	var $btn    = $( '#infyp-save-redirect-settings' );
	var $status = $( '#infyp-redirect-settings-status' );

	if ( ! $form.length ) {
		return;
	}

	$form.on( 'submit', function( e ) {
		e.preventDefault();

		$btn.prop( 'disabled', true );
		$status.text( infypRedirectSettings.saving );

		$.post( ajaxurl, {
			action:       'infyp_save_redirect_settings',
			nonce:        $( '#infyp_redirect_settings_nonce' ).val(),
			enabled:      $( '#infyp-redirect-enabled' ).is( ':checked' ) ? '1' : '0',
			expiry_months: $( '#infyp-redirect-expiry' ).val()
		} ).done( function( resp ) {
			$status.text( resp.data && resp.data.message ? resp.data.message : infypRedirectSettings.saved );
		} ).fail( function() {
			$status.text( infypRedirectSettings.error );
		} ).always( function() {
			$btn.prop( 'disabled', false );
			setTimeout( function() {
				$status.text( '' );
			}, 3000 );
		} );
	} );
} );

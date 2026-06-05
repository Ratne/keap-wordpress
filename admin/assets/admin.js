( function ( $ ) {
	'use strict';

	function toggleTargets( $row ) {
		var type = $row.find( '.kc-target-type' ).val();
		$row.find( '.kc-target' ).hide();
		$row.find( '.kc-target-' + type ).show();
	}

	$( document ).on( 'change', '.kc-target-type', function () {
		toggleTargets( $( this ).closest( '.kc-map-row' ) );
	} );

	$( document ).on( 'click', '.kc-remove-row', function () {
		var $tbody = $( this ).closest( 'tbody' );
		$( this ).closest( '.kc-map-row' ).remove();
		if ( $tbody.find( '.kc-map-row' ).length === 0 ) {
			addRow();
		}
	} );

	function addRow() {
		var tpl = $( '#kc-row-template' ).html();
		if ( ! tpl ) {
			return;
		}
		var index = Date.now();
		var html = tpl.replace( /__INDEX__/g, index );
		var $row = $( html );
		$( '#kc-map-table tbody' ).append( $row );
		toggleTargets( $row );
	}

	$( document ).on( 'click', '#kc-add-row', function ( e ) {
		e.preventDefault();
		addRow();
	} );

	var i18n = ( window.kcAdmin && window.kcAdmin.i18n ) || { show: 'Show', hide: 'Hide', error: 'Error' };

	$( document ).on( 'click', '.kc-reveal-ajax', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $input = $btn.prev( 'input.kc-secret-ajax' );
		if ( ! $input.length ) {
			return;
		}

		// Se gia' mostrato, ri-maschera e svuota (il valore non resta nel DOM).
		if ( $btn.attr( 'data-shown' ) === '1' ) {
			$input.attr( 'type', 'password' ).val( '' );
			$btn.attr( 'data-shown', '0' ).text( i18n.show );
			return;
		}

		$btn.prop( 'disabled', true );
		$.post(
			( window.kcAdmin && window.kcAdmin.ajaxUrl ) || window.ajaxurl,
			{
				action: 'kc_reveal_secret',
				_ajax_nonce: window.kcAdmin && window.kcAdmin.revealNonce,
				field: $btn.attr( 'data-field' )
			}
		).done( function ( resp ) {
			if ( resp && resp.success && resp.data ) {
				$input.attr( 'type', 'text' ).val( resp.data.value );
				$btn.attr( 'data-shown', '1' ).text( i18n.hide );
			} else {
				window.alert( ( resp && resp.data && resp.data.message ) || i18n.error );
			}
		} ).fail( function () {
			window.alert( i18n.error );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	$( document ).on( 'click', '.kc-reveal', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $input = $btn.prev( 'input.kc-secret' );
		if ( ! $input.length ) {
			return;
		}
		var shown = $btn.attr( 'data-shown' ) === '1';
		$input.attr( 'type', shown ? 'password' : 'text' );
		$btn.attr( 'data-shown', shown ? '0' : '1' );
		$btn.text( shown ? i18n.show : i18n.hide );
	} );

	$( function () {
		$( '.kc-map-row' ).each( function () {
			toggleTargets( $( this ) );
		} );
	} );
} )( jQuery );

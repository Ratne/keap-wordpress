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

	var i18n = ( window.kcAdmin && window.kcAdmin.i18n ) || { show: 'Show', hide: 'Hide' };

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

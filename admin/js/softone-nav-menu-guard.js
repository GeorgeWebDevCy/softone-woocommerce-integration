/**
 * Prevents Softone placeholder menu items from being submitted.
 *
 * @package Softone_Woocommerce_Integration
 */
( function() {
	'use strict';

	function disableMenuItemFields( menuItem ) {
		if ( ! menuItem ) {
			return;
		}

		var fields = menuItem.querySelectorAll( 'input, select, textarea' );

		for ( var index = 0; index < fields.length; index++ ) {
			fields[ index ].disabled = true;
			fields[ index ].setAttribute( 'data-softone-dynamic-disabled', '1' );
		}
	}

	function disableDynamicMenuItems( scope ) {
		if ( ! scope || ! scope.querySelectorAll ) {
			return;
		}

		var menuItems = scope.querySelectorAll( '.menu-item.softone-dynamic-menu-item' );

		if ( ! menuItems.length ) {
			return;
		}

		for ( var i = 0; i < menuItems.length; i++ ) {
			disableMenuItemFields( menuItems[ i ] );
		}
	}

	function init() {
		var form = document.getElementById( 'update-nav-menu' );

		if ( ! form ) {
			return;
		}

		disableDynamicMenuItems( form );

		form.addEventListener( 'submit', function() {
			disableDynamicMenuItems( form );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

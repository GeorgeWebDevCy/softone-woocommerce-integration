/**
 * Prevents Softone placeholder menu items from being submitted.
 *
 * @package Softone_Woocommerce_Integration
 */
( function() {
	'use strict';

	var DYNAMIC_ITEM_SELECTOR = '.menu-item.softone-dynamic-menu-item';

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
		var context = scope && scope.querySelectorAll ? scope : document;
		var menuItems = context.querySelectorAll( DYNAMIC_ITEM_SELECTOR );

		if ( ! menuItems.length ) {
			return;
		}

		for ( var i = 0; i < menuItems.length; i++ ) {
			disableMenuItemFields( menuItems[ i ] );
		}
	}

	function bindFormSubmissionGuard() {
		document.addEventListener( 'submit', function( event ) {
			var target = event && event.target;

			if ( target && target.querySelector ) {
				disableDynamicMenuItems( target );
			}
		}, true );
	}

	function observeDomChanges() {
		if ( ! window.MutationObserver ) {
			return;
		}

		var observer = new window.MutationObserver( function( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var addedNodes = mutations[ i ].addedNodes;

				for ( var nodeIndex = 0; nodeIndex < addedNodes.length; nodeIndex++ ) {
					var node = addedNodes[ nodeIndex ];

					if ( ! node || 1 !== node.nodeType ) {
						continue;
					}

					if ( node.matches && node.matches( DYNAMIC_ITEM_SELECTOR ) ) {
						disableMenuItemFields( node );
					}

					if ( node.querySelectorAll ) {
						disableDynamicMenuItems( node );
					}
				}
			}
		} );

		if ( document.body ) {
			observer.observe( document.body, { childList: true, subtree: true } );
		}
	}

	function bindAjaxRefreshHandler() {
		if ( ! window.jQuery ) {
			return;
		}

		window.jQuery( document ).ajaxComplete( function() {
			disableDynamicMenuItems( document );
		} );
	}

	function bindCustomizerEvents() {
		if ( ! window.wp || ! wp.customize || 'function' !== typeof wp.customize.bind ) {
			return;
		}

		wp.customize.bind( 'ready', function() {
			disableDynamicMenuItems( document );
		} );
	}

	function init() {
		disableDynamicMenuItems( document );
		bindFormSubmissionGuard();
		observeDomChanges();
		bindAjaxRefreshHandler();
		bindCustomizerEvents();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

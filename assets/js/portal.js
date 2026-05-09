/**
 * Partner Portal — copy buttons and tagged-link builder.
 *
 * Loaded by Frontend\Portal::enqueue() on portal pageviews.
 * Configuration (REST URL + nonce) is provided via wp_localize_script
 * as window.partnerProgramPortal.
 */
( function () {
	'use strict';

	/**
	 * Brief inline feedback on the trigger button: swap its label for
	 * `message` for ~1.4s, then restore. Avoids a heavy toast component
	 * for what is effectively a click confirmation.
	 */
	function flash( button, message ) {
		if ( ! button || button.dataset.ppFlashing === '1' ) {
			return;
		}
		var original                = button.textContent;
		button.dataset.ppFlashing   = '1';
		button.textContent          = message;
		button.disabled             = true;
		setTimeout( function () {
			button.textContent        = original;
			button.disabled           = false;
			delete button.dataset.ppFlashing;
		}, 1400 );
	}

	function copyFromInput( inputId, button ) {
		var el = document.getElementById( inputId );
		if ( ! el ) {
			return;
		}
		var text = el.value;
		var done = function ( ok ) {
			flash( button, ok ? 'Copied!' : 'Copy failed' );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then(
				function () { done( true ); },
				function () { done( false ); }
			);
			return;
		}
		// Fallback for older browsers / non-secure contexts where the
		// async Clipboard API isn't available.
		try {
			el.focus();
			el.select();
			var ok = document.execCommand( 'copy' );
			done( ok );
		} catch ( e ) {
			done( false );
		}
	}

	async function buildTaggedLink( button ) {
		var cfg     = window.partnerProgramPortal || {};
		var input   = document.getElementById( 'pp-builder-url' );
		var output  = document.getElementById( 'pp-builder-result' );
		if ( ! input || ! output || ! cfg.restUrl ) {
			return;
		}
		var originalLabel = button ? button.textContent : '';
		if ( button ) {
			button.disabled    = true;
			button.textContent = 'Building…';
		}
		try {
			var response = await fetch( cfg.restUrl, {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   cfg.nonce || ''
				},
				body: JSON.stringify( { url: input.value } )
			} );
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			var data = await response.json();
			if ( data && data.link ) {
				output.value = data.link;
			} else {
				throw new Error( 'No link returned' );
			}
		} catch ( e ) {
			output.value = '';
			output.setAttribute( 'placeholder', 'Could not build link. Check the URL and try again.' );
		} finally {
			if ( button ) {
				button.disabled    = false;
				button.textContent = originalLabel;
			}
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var el = e.target;
		if ( ! ( el instanceof Element ) ) {
			return;
		}
		var copyTarget = el.getAttribute( 'data-pp-copy' );
		if ( copyTarget ) {
			copyFromInput( copyTarget, el );
			return;
		}
		if ( el.hasAttribute( 'data-pp-build-link' ) ) {
			buildTaggedLink( el );
		}
	} );
} )();

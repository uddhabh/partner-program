/**
 * Partner Portal — copy buttons and tagged-link builder.
 *
 * Loaded by Frontend\Portal::enqueue() on portal pageviews.
 * Configuration (REST URL + nonce) is provided via wp_localize_script
 * as window.partnerProgramPortal.
 */
( function () {
	'use strict';

	function copyFromInput( inputId ) {
		var el = document.getElementById( inputId );
		if ( ! el || ! navigator.clipboard ) {
			return;
		}
		navigator.clipboard.writeText( el.value );
	}

	async function buildTaggedLink() {
		var cfg     = window.partnerProgramPortal || {};
		var input   = document.getElementById( 'pp-builder-url' );
		var output  = document.getElementById( 'pp-builder-result' );
		if ( ! input || ! output || ! cfg.restUrl ) {
			return;
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
			var data = await response.json();
			output.value = data && data.link ? data.link : '';
		} catch ( e ) {
			output.value = '';
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var el = e.target;
		if ( ! ( el instanceof Element ) ) {
			return;
		}
		var copyTarget = el.getAttribute( 'data-pp-copy' );
		if ( copyTarget ) {
			copyFromInput( copyTarget );
			return;
		}
		if ( el.hasAttribute( 'data-pp-build-link' ) ) {
			buildTaggedLink();
		}
	} );
} )();

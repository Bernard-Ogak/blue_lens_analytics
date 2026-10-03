/*! Blue Lens Analytics: Local Ads bridge | GPL-2.0-or-later */
/**
 * Records Local Ads popups as Blue Lens events. Local Ads dispatches "localads:track" on the
 * document with { id, type } when it shows an ad ("impression") or a visitor clicks it ("click").
 */
( function ( w, d ) {
	'use strict';

	var EVENTS = { impression: 'ad_impression', click: 'ad_click' };

	d.addEventListener( 'localads:track', function ( e ) {
		var detail = ( e && e.detail ) || {};
		var name = EVENTS[ detail.type ];
		var id = parseInt( detail.id, 10 );
		if ( ! name || ! ( id > 0 ) || ! w.BlueLens || typeof w.BlueLens.track !== 'function' ) {
			return;
		}
		w.BlueLens.track( name, { entity_type: 'local_ad', entity_id: String( id ) } );
		// A click usually navigates away at once: send it now, like outbound-link clicks.
		if ( name === 'ad_click' && typeof w.BlueLens.use === 'function' ) {
			w.BlueLens.use( function ( bl ) {
				bl.flush();
			} );
		}
	} );
}( window, document ) );

/**
 * Boots the tracker (and optional chunks) in an isolated jsdom window and records every payload sent.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM } = require( 'jsdom' );

const read = ( name ) =>
	fs.readFileSync( path.join( __dirname, '../../assets/tracker/src', name ), 'utf8' );

const SOURCES = {
	tracker: read( 'tracker.js' ),
	auto: read( 'auto.js' ),
	capture: read( 'capture.js' ),
};

function boot( {
	cfg = {},
	ctx = { k: 'singular', p: 12, t: 'post' },
	url = 'https://example.org/start/',
	body = '',
	beacon = true,
	chunks = [],
	before,
} = {} ) {
	const dom = new JSDOM( `<!doctype html><html><head></head><body>${ body }</body></html>`, {
		url,
		referrer: 'https://www.google.com/',
		runScripts: 'outside-only',
		pretendToBeVisual: true,
	} );
	const w = dom.window;
	const sent = [];

	// Route the page's timers through Jest's fake timers.
	w.setTimeout = ( fn, ms ) => setTimeout( fn, ms );
	w.clearTimeout = ( id ) => clearTimeout( id );
	w.setInterval = ( fn, ms ) => setInterval( fn, ms );

	w.Blob = function ( parts, opts ) {
		this.body = parts.join( '' );
		this.type = opts && opts.type;
	};
	if ( beacon === true ) {
		w.navigator.sendBeacon = ( u, blob ) => {
			sent.push( { via: 'beacon', type: blob.type, body: JSON.parse( blob.body ) } );
			return true;
		};
	} else if ( beacon === 'refuse' ) {
		w.navigator.sendBeacon = () => false;
	}
	w.fetch = ( u, opts ) => {
		sent.push( { via: 'fetch', opts, body: JSON.parse( opts.body ) } );
		return Promise.resolve();
	};

	if ( before ) {
		before( w );
	}

	const el = w.document.createElement( 'script' );
	el.type = 'application/json';
	el.id = 'bla-data';
	el.textContent = JSON.stringify( {
		cfg: {
			url: 'https://example.org/wp-json/blue-lens/v1/collect',
			mode: 'cookieless',
			gpc: 1,
			ga: 'cookieless',
			dnt: 0,
			hb: 30,
			spa: 1,
			xp: [],
			ch: [],
			lk: 1,
			fm: 1,
			vd: 1,
			er: 1,
			wv: 0,
			ac: 1,
			hm: 1,
			dx: [ 'pdf', 'zip' ],
			cs: [],
			...cfg,
		},
		ctx,
	} );
	w.document.head.appendChild( el );
	w.eval( SOURCES.tracker );
	chunks.forEach( ( name ) => w.eval( SOURCES[ name ] ) );

	const events = () => sent.flatMap( ( s ) => s.body.e || [] );
	const flushAll = () => jest.advanceTimersByTime( 1100 );
	const click = ( selector, init = {} ) => {
		const target = w.document.querySelector( selector );
		// detail: 1 marks a real pointer click (keyboard/scripted clicks have detail 0).
		target.dispatchEvent( new w.MouseEvent( 'click', { bubbles: true, cancelable: true, detail: 1, ...init } ) );
		return target;
	};

	return { w, sent, events, flushAll, click, SOURCES };
}

module.exports = { boot, SOURCES };

/**
 * Tracker tests. Each test boots the tracker in a fresh jsdom window so listeners and history
 * patches never leak between tests.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM } = require( 'jsdom' );

const SRC = fs.readFileSync(
	path.join( __dirname, '../../assets/tracker/src/tracker.js' ),
	'utf8'
);

function boot( {
	cfg = {},
	ctx = { k: 'singular', p: 12, t: 'post' },
	url = 'https://example.org/start/',
	beacon = true,
	before,
} = {} ) {
	const dom = new JSDOM( '<!doctype html><html><head></head><body></body></html>', {
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
			dnt: 0,
			hb: 30,
			spa: 1,
			xp: [],
			ch: [],
			...cfg,
		},
		ctx,
	} );
	w.document.head.appendChild( el );
	w.eval( SRC );

	return { w, sent, events: () => sent.flatMap( ( s ) => s.body.e ) };
}

beforeEach( () => {
	jest.useFakeTimers();
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'page views', () => {
	test( 'sends a page_view immediately with URL, referrer and context', () => {
		const { sent } = boot();

		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].via ).toBe( 'beacon' );
		expect( sent[ 0 ].type ).toBe( 'text/plain' );

		const p = sent[ 0 ].body;
		expect( p.u ).toBe( 'https://example.org/start/' );
		expect( p.r ).toBe( 'https://www.google.com/' );
		expect( p.c ).toEqual( { k: 'singular', p: 12, t: 'post' } );
		expect( p.e[ 0 ].n ).toBe( 'page_view' );
		expect( p.e[ 0 ].a.pv ).toMatch( /^[a-f0-9]{8}$/ );
		expect( p.e[ 0 ].r ).toBeUndefined();
		expect( p.vid ).toBeUndefined();
	} );

	test( 'does not load twice', () => {
		const { w, sent } = boot();
		w.eval( SRC );

		expect( sent ).toHaveLength( 1 );
	} );
} );

describe( 'batching', () => {
	test( 'custom events are batched for one second', () => {
		const { w, sent } = boot();
		w.BlueLens.track( 'brochure_download', { entity_type: 'tour', entity_id: 7, file: 'kenya.pdf' } );
		w.BlueLens.track( 'map_interaction' );

		expect( sent ).toHaveLength( 1 );
		jest.advanceTimersByTime( 1000 );
		expect( sent ).toHaveLength( 2 );

		const [ first, second ] = sent[ 1 ].body.e;
		expect( first ).toMatchObject( { n: 'brochure_download', et: 'tour', ei: 7, a: { file: 'kenya.pdf' } } );
		expect( typeof first.d ).toBe( 'number' );
		expect( second.n ).toBe( 'map_interaction' );
	} );

	test( 'a full batch of 25 is sent without waiting', () => {
		const { w, sent } = boot();
		for ( let i = 0; i < 25; i++ ) {
			w.BlueLens.track( 'tick' );
		}

		expect( sent ).toHaveLength( 2 );
		expect( sent[ 1 ].body.e ).toHaveLength( 25 );
	} );

	test( 'calls queued by an inline stub are replayed', () => {
		const { events } = boot( {
			before: ( w ) => {
				w.BlueLens = { q: [ [ 'track', 'early_event', { value: 5 } ] ] };
			},
		} );

		jest.advanceTimersByTime( 1000 );
		expect( events().map( ( e ) => e.n ) ).toEqual( [ 'early_event', 'page_view' ] );
	} );
} );

describe( 'transport', () => {
	test( 'falls back to fetch keepalive when sendBeacon is unavailable', () => {
		const { sent } = boot( { beacon: false } );

		expect( sent[ 0 ].via ).toBe( 'fetch' );
		expect( sent[ 0 ].opts ).toMatchObject( { method: 'POST', keepalive: true, credentials: 'omit' } );
	} );

	test( 'falls back to fetch when sendBeacon refuses the payload', () => {
		const { sent } = boot( { beacon: 'refuse' } );

		expect( sent[ 0 ].via ).toBe( 'fetch' );
	} );
} );

describe( 'privacy and consent', () => {
	test( 'enhanced mode sends no ID and sets no cookie before consent', () => {
		const { w, sent } = boot( { cfg: { mode: 'enhanced' } } );

		expect( sent[ 0 ].body.vid ).toBeUndefined();
		expect( w.document.cookie ).toBe( '' );
	} );

	test( 'enhanced mode uses a first-party ID after consent and clears it on withdrawal', () => {
		const { w, sent } = boot( { cfg: { mode: 'enhanced' } } );

		w.BlueLens.consent( true );
		w.BlueLens.track( 'after_consent' );
		jest.advanceTimersByTime( 1000 );
		expect( sent[ 1 ].body.vid ).toMatch( /^[a-f0-9]{32}$/ );
		expect( w.document.cookie ).toMatch( /bla_vid=[a-f0-9]{32}/ );

		w.BlueLens.consent( false );
		w.BlueLens.track( 'after_withdrawal' );
		jest.advanceTimersByTime( 1000 );
		expect( sent[ 2 ].body.vid ).toBeUndefined();
		expect( w.document.cookie ).toBe( '' );
	} );

	test( 'WP Consent API statistics consent is honoured', () => {
		const { sent } = boot( {
			cfg: { mode: 'enhanced' },
			before: ( w ) => {
				w.wp_has_consent = ( category ) => category === 'statistics';
			},
		} );

		expect( sent[ 0 ].body.vid ).toMatch( /^[a-f0-9]{32}$/ );
	} );

	test( 'Global Privacy Control forces cookieless mode even with consent', () => {
		const { sent } = boot( {
			cfg: { mode: 'enhanced' },
			before: ( w ) => {
				Object.defineProperty( w.navigator, 'globalPrivacyControl', { value: true } );
				w.wp_has_consent = () => true;
			},
		} );

		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].body.vid ).toBeUndefined();
	} );

	test( 'Do Not Track stops tracking when respected', () => {
		const { sent } = boot( {
			cfg: { dnt: 1 },
			before: ( w ) => {
				Object.defineProperty( w.navigator, 'doNotTrack', { value: '1' } );
			},
		} );

		expect( sent ).toHaveLength( 0 );
	} );

	test( 'excluded paths are not tracked', () => {
		const { sent } = boot( { cfg: { xp: [ '/start/*' ] } } );

		expect( sent ).toHaveLength( 0 );
	} );

	test( 'opt-out persists and blocks sending', () => {
		const first = boot();
		first.w.BlueLens.optOut();
		first.w.BlueLens.track( 'ignored' );
		jest.advanceTimersByTime( 1000 );

		expect( first.sent ).toHaveLength( 1 );
	} );
} );

describe( 'single-page navigation', () => {
	test( 'pushState to a new path sends a page_view for the new URL', () => {
		const { w, sent } = boot();
		w.BlueLens.track( 'before_nav' );
		w.history.pushState( {}, '', '/next/?page=2' );

		const urls = sent.map( ( s ) => s.body.u );
		expect( urls ).toContain( 'https://example.org/start/' );
		expect( sent[ sent.length - 1 ].body.u ).toBe( 'https://example.org/next/?page=2' );
		expect( sent[ sent.length - 1 ].body.e.map( ( e ) => e.n ) ).toContain( 'page_view' );

		// Events from the old page are sent under the old URL.
		const oldBatch = sent.find( ( s ) => s.body.e.some( ( e ) => e.n === 'before_nav' ) );
		expect( oldBatch.body.u ).toBe( 'https://example.org/start/' );

		// Only the first page view carries the server-embedded post context.
		expect( sent[ sent.length - 1 ].body.c.p ).toBeUndefined();
	} );

	test( 'replaceState on the same path does not create a page view', () => {
		const { w, sent } = boot();
		w.history.replaceState( { scroll: 1 }, '', '/start/#section' );

		expect( sent ).toHaveLength( 1 );
	} );
} );

describe( 'engagement', () => {
	test( 'page_engagement is sent when the page is hidden', () => {
		const { w, events } = boot();
		jest.advanceTimersByTime( 600 ); // Initial scroll-depth measurement.
		Object.defineProperty( w.document, 'visibilityState', { value: 'hidden', configurable: true } );
		w.document.dispatchEvent( new w.Event( 'visibilitychange' ) );

		const engagement = events().find( ( e ) => e.n === 'page_engagement' );
		const pageView = events().find( ( e ) => e.n === 'page_view' );
		expect( engagement ).toBeDefined();
		expect( engagement.a.pv ).toBe( pageView.a.pv );
		expect( engagement.a.scroll_depth ).toBe( 100 );
	} );
} );

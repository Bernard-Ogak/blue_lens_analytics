/**
 * Tests for the automatic-event and autocapture chunks.
 */
const { boot } = require( './helpers' );

beforeEach( () => {
	jest.useFakeTimers();
} );

afterEach( () => {
	jest.useRealTimers();
} );

// Links would navigate jsdom away; cancel default actions while still letting capture listeners run.
function preventNavigation( w ) {
	w.document.addEventListener( 'click', ( e ) => e.preventDefault() );
}

const names = ( events ) => events().map( ( e ) => e.n );

describe( 'links and contact', () => {
	const body = `
		<a id="out" href="https://partner.example/tours/?ref=1">Partner tours</a>
		<a id="pdf" href="/files/kenya-brochure.pdf?v=2">Brochure</a>
		<a id="tel" href="tel:+254712345678">+254 712 345 678</a>
		<a id="wa" href="https://wa.me/254712345678">Chat on WhatsApp</a>
		<a id="int" href="/about/">About</a>
		<button id="cta" data-bla-event="brochure_request" data-bla-props='{"tour":"mara-3-day"}'>Get brochure</button>
		<a id="sel" class="book-now" href="/book/">Book now</a>`;

	test( 'outbound, download, phone and WhatsApp clicks are classified', () => {
		const { w, events, click, flushAll } = boot( { body, chunks: [ 'auto' ], cfg: { ac: 0, hm: 0 }, before: preventNavigation } );

		click( '#out' );
		click( '#pdf' );
		click( '#tel' );
		click( '#wa' );
		click( '#int' );
		flushAll();

		const all = events();
		expect( all.find( ( e ) => e.n === 'outbound_click' ).a ).toMatchObject( { domain: 'partner.example', url: 'partner.example/tours/' } );
		expect( all.find( ( e ) => e.n === 'file_download' ).a ).toMatchObject( { file: 'kenya-brochure.pdf', extension: 'pdf' } );

		const contacts = all.filter( ( e ) => e.n === 'contact_click' ).map( ( e ) => e.a );
		expect( contacts ).toEqual( [
			{ method: 'phone', label: '[phone]' },
			{ method: 'whatsapp', label: 'Chat on WhatsApp' },
		] );

		// Internal links produce no automatic event when autocapture is off.
		expect( names( events ).filter( ( n ) => n !== 'page_view' ) ).toHaveLength( 4 );
		expect( w.BlueLens ).toBeDefined();
	} );

	test( 'data-bla-event and configured selectors become CTA events', () => {
		const { events, click, flushAll } = boot( { body, chunks: [ 'auto' ], cfg: { ac: 0, hm: 0, cs: [ '.book-now' ] }, before: preventNavigation } );

		click( '#cta' );
		click( '#sel' );
		flushAll();

		const cta = events().find( ( e ) => e.n === 'brochure_request' );
		expect( cta.a ).toEqual( { tour: 'mara-3-day', label: 'Get brochure' } );
		expect( events().find( ( e ) => e.n === 'cta_click' ).a.label ).toBe( 'Book now' );
	} );
} );

describe( 'page-level events', () => {
	test( 'site search reports the query and zero results', () => {
		const { events, flushAll } = boot( { chunks: [ 'auto' ], ctx: { k: 'search', sq: 'gorilla trek', sr: 0 } } );
		flushAll();

		expect( events().find( ( e ) => e.n === 'site_search' ).a ).toEqual( { query: 'gorilla trek', results: 0, zero_results: true } );
	} );

	test( '404 pages report where the visitor came from', () => {
		const { events, flushAll } = boot( { chunks: [ 'auto' ], ctx: { k: '404' } } );
		flushAll();

		expect( events().find( ( e ) => e.n === 'page_not_found' ).a ).toEqual( { from: 'google.com' } );
	} );
} );

describe( 'forms', () => {
	const body = `
		<div class="wpcf7"><form id="cf" class="wpcf7-form"><input type="hidden" name="_wpcf7" value="42">
			<input name="your-name"><input name="arrival" type="date"><input name="your-email" type="email">
			<input type="password" name="pw"><button type="submit">Send</button></form></div>
		<form id="plain"><input name="q"><button type="submit">Go</button></form>`;

	test( 'start and abandon carry field names only, never values', () => {
		const { w, events, flushAll } = boot( { body, chunks: [ 'auto' ], cfg: { ac: 0, hm: 0 } } );
		const name = w.document.querySelector( '[name="your-name"]' );
		name.value = 'Jane Doe';
		name.dispatchEvent( new w.FocusEvent( 'focusin', { bubbles: true } ) );
		w.document.querySelector( '[name="arrival"]' ).dispatchEvent( new w.FocusEvent( 'focusin', { bubbles: true } ) );
		w.document.querySelector( '[name="pw"]' ).dispatchEvent( new w.FocusEvent( 'focusin', { bubbles: true } ) );

		Object.defineProperty( w.document, 'visibilityState', { value: 'hidden', configurable: true } );
		w.document.dispatchEvent( new w.Event( 'visibilitychange' ) );
		flushAll();

		const start = events().find( ( e ) => e.n === 'form_start' );
		expect( start ).toMatchObject( { et: 'form', ei: 'cf7:42', a: { first_field: 'your-name' } } );

		const abandon = events().find( ( e ) => e.n === 'form_abandon' );
		expect( abandon ).toMatchObject( { ei: 'cf7:42', a: { last_field: 'arrival', fields_touched: 2 } } );
		expect( JSON.stringify( events() ) ).not.toContain( 'Jane' );
	} );

	test( 'plain HTML forms report submit; plugin forms leave it to the server', () => {
		const { w, events, flushAll } = boot( { body, chunks: [ 'auto' ], cfg: { ac: 0, hm: 0 }, before: preventNavigation } );
		w.document.addEventListener( 'submit', ( e ) => e.preventDefault() );

		w.document.querySelector( '#cf [name="your-name"]' ).dispatchEvent( new w.FocusEvent( 'focusin', { bubbles: true } ) );
		w.document.querySelector( '#cf' ).dispatchEvent( new w.Event( 'submit', { bubbles: true, cancelable: true } ) );
		w.document.querySelector( '#plain' ).dispatchEvent( new w.Event( 'submit', { bubbles: true, cancelable: true } ) );
		w.dispatchEvent( new w.Event( 'pagehide' ) );
		flushAll();

		const submits = events().filter( ( e ) => e.n === 'form_submit' );
		expect( submits ).toHaveLength( 1 );
		expect( submits[ 0 ].ei ).toBe( 'html:plain' );
		expect( names( events ) ).not.toContain( 'form_abandon' );
	} );
} );

describe( 'autocapture and heatmaps', () => {
	const body = `
		<nav id="main-nav"><a class="menu-link" href="/safaris/">Safaris</a></nav>
		<button class="btn primary" id="noop">Check availability</button>
		<div id="box" style="height:100px">Plain text</div>
		<input id="secret" type="password" name="pw">`;

	test( 'clicks on controls are captured with a stable selector and label', () => {
		const { events, click, flushAll } = boot( { body, chunks: [ 'capture' ], before: preventNavigation } );

		click( '#main-nav a' );
		click( '#box' );
		click( '#secret' );
		flushAll();

		const clicks = events().filter( ( e ) => e.n === 'click' );
		expect( clicks ).toHaveLength( 1 );
		expect( clicks[ 0 ].a ).toEqual( { tag: 'a', sel: '#main-nav > a.menu-link', label: 'Safaris', target: '/safaris/' } );
	} );

	test( 'three quick clicks in one spot are a rage click; an unresponsive button is a dead click', () => {
		const { events, click, flushAll } = boot( { body, chunks: [ 'capture' ] } );

		for ( let i = 0; i < 3; i++ ) {
			click( '#noop', { clientX: 10, clientY: 10 } );
		}
		jest.advanceTimersByTime( 1100 );
		flushAll();

		expect( events().find( ( e ) => e.n === 'rage_click' ).a.sel ).toBe( '#noop' );
		expect( events().find( ( e ) => e.n === 'dead_click' ).a.label ).toBe( 'Check availability' );
	} );

	test( 'every click adds a heatmap cell to the next payload', () => {
		const { sent, click, flushAll } = boot( { body, chunks: [ 'capture' ], cfg: { ac: 0 } } );

		click( '#box', { clientX: 5, clientY: 45 } );
		flushAll();

		const withHeat = sent.find( ( s ) => s.body.hm );
		expect( withHeat.body.hm ).toHaveLength( 1 );
		expect( withHeat.body.hm[ 0 ][ 1 ] ).toBe( 2 ); // 45px -> row 2 of 20px rows.
	} );

	test( 'copy records kind and length, never the copied text', () => {
		const { w, events, flushAll } = boot( { body: '<p id="p">Call +254 712 345 678</p>', chunks: [ 'capture' ] } );
		const range = w.document.createRange();
		range.selectNodeContents( w.document.querySelector( '#p' ) );
		w.getSelection().removeAllRanges();
		w.getSelection().addRange( range );
		w.document.dispatchEvent( new w.Event( 'copy', { bubbles: true } ) );
		flushAll();

		const copy = events().find( ( e ) => e.n === 'copy_text' );
		expect( copy.a ).toMatchObject( { kind: 'phone', chars: 21 } );
		expect( JSON.stringify( copy ) ).not.toContain( '712' );
	} );

	test( 'keyboard-activated clicks never count as rage clicks or heatmap points', () => {
		const { sent, events, click, flushAll } = boot( { body, chunks: [ 'capture' ] } );

		for ( let i = 0; i < 3; i++ ) {
			click( '#noop', { detail: 0 } );
		}
		flushAll();

		expect( events().map( ( e ) => e.n ) ).not.toContain( 'rage_click' );
		expect( sent.some( ( s ) => s.body.hm ) ).toBe( false );
	} );

	test( 'GPC set to "stop" disables everything', () => {
		const { sent } = boot( {
			body,
			chunks: [ 'capture' ],
			cfg: { ga: 'stop' },
			before: ( w ) => Object.defineProperty( w.navigator, 'globalPrivacyControl', { value: true } ),
		} );

		expect( sent ).toHaveLength( 0 );
	} );
} );

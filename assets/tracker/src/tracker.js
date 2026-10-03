/*! Blue Lens Analytics tracker | GPL-2.0-or-later */
( function ( w, d ) {
	'use strict';

	var n = w.navigator;
	var loc = w.location;
	var dataEl = d.getElementById( 'bla-data' );
	if ( ! dataEl || w.__blaLoaded ) {
		return;
	}
	w.__blaLoaded = 1;

	var data;
	try {
		data = JSON.parse( dataEl.textContent || '{}' );
	} catch ( e ) {
		return;
	}

	var cfg = data.cfg || {};
	var ctx = data.ctx || {};
	var stub = w.BlueLens || {};
	var COOKIE = 'bla_vid';
	var IDLE_MS = 60000;
	var MAX_BATCH = 25;
	var MAX_BEATS = 40;

	var queue = [];
	var heat = []; // Heatmap cells [x%, 20px row], sent with the next batch.
	var timer = 0;
	var consent = null;
	var curUrl = loc.href;
	var pv = '';
	var engagedMs = 0; // Unsent engaged time (session heartbeat).
	var pageMs = 0; // Engaged time on the current page.
	var lastTick = null;
	var lastActivity = Date.now();
	var maxScroll = 0;
	var reported = '';
	var beats = 0;
	var listeners = {};

	function storageGet( key ) {
		try {
			return w.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function storageSet( key, value ) {
		try {
			if ( value === null ) {
				w.localStorage.removeItem( key );
			} else {
				w.localStorage.setItem( key, value );
			}
		} catch ( e ) {}
	}

	function randomHex( bytes ) {
		var a = new Uint8Array( bytes );
		( w.crypto || w.msCrypto ).getRandomValues( a );
		return Array.prototype.map.call( a, function ( b ) {
			return ( b < 16 ? '0' : '' ) + b.toString( 16 );
		} ).join( '' );
	}

	function gpc() {
		return !! cfg.gpc && n.globalPrivacyControl === true;
	}

	function dnt() {
		return !! cfg.dnt && ( n.doNotTrack === '1' || w.doNotTrack === '1' );
	}

	function excludedPath( path ) {
		return ( cfg.xp || [] ).some( function ( p ) {
			var re = new RegExp( '^' + p.replace( /[.+?^${}()|[\]\\]/g, '\\$&' ).replace( /\*/g, '.*' ) + '$', 'i' );
			return re.test( path );
		} );
	}

	function disabled() {
		return dnt() || ( gpc() && cfg.ga === 'stop' ) || n.webdriver === true || storageGet( 'bla_optout' ) === '1' || excludedPath( loc.pathname );
	}

	// Consent: explicit BlueLens.consent() wins, else the WP Consent API "statistics" category.
	function hasConsent() {
		if ( consent !== null ) {
			return consent;
		}
		if ( typeof w.wp_has_consent === 'function' ) {
			try {
				return !! w.wp_has_consent( 'statistics' );
			} catch ( e ) {}
		}
		return false;
	}

	function setCookie( value, maxAge ) {
		d.cookie = COOKIE + '=' + value + ';path=/;max-age=' + maxAge + ';samesite=lax' + ( loc.protocol === 'https:' ? ';secure' : '' );
	}

	// First-party ID: only in enhanced mode, with consent, and without a respected GPC signal.
	function visitorId() {
		if ( cfg.mode !== 'enhanced' || gpc() || ! hasConsent() ) {
			return '';
		}
		var m = d.cookie.match( /(?:^|;\s*)bla_vid=([a-f0-9]{32})/ );
		var id = m ? m[ 1 ] : randomHex( 16 );
		setCookie( id, 34128000 ); // 395 days, refreshed on use.
		return id;
	}

	function setConsent( granted ) {
		consent = !! granted;
		if ( ! consent ) {
			setCookie( '', 0 );
		}
	}

	function send( payload ) {
		var body = JSON.stringify( payload );
		try {
			if ( n.sendBeacon && n.sendBeacon( cfg.url, new Blob( [ body ], { type: 'text/plain' } ) ) ) {
				return;
			}
		} catch ( e ) {}
		try {
			w.fetch( cfg.url, {
				method: 'POST',
				body: body,
				keepalive: true,
				credentials: 'omit',
				headers: { 'Content-Type': 'text/plain' },
			} );
		} catch ( e ) {}
	}

	function accrue() {
		var now = Date.now();
		if ( lastTick !== null && d.visibilityState === 'visible' ) {
			var dt = Math.min( now, lastActivity + IDLE_MS ) - lastTick;
			if ( dt > 0 ) {
				engagedMs += dt;
				pageMs += dt;
			}
		}
		lastTick = d.visibilityState === 'visible' ? now : null;
	}

	function takeEngaged() {
		accrue();
		var s = Math.floor( engagedMs / 1000 );
		engagedMs -= s * 1000;
		return s;
	}

	function flush() {
		clearTimeout( timer );
		timer = 0;
		var hb = takeEngaged();
		if ( ! queue.length && ! hb && ! heat.length ) {
			return;
		}
		var now = Date.now();
		var events = queue.splice( 0, MAX_BATCH ).map( function ( e ) {
			e.d = now - e.t;
			delete e.t;
			return e;
		} );
		var p = {
			v: 1,
			u: curUrl,
			ti: d.title,
			lg: n.language,
			vw: w.innerWidth,
			c: ctx,
			e: events,
		};
		try {
			p.tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
		} catch ( e ) {}
		if ( hb ) {
			p.hb = hb;
		}
		if ( heat.length ) {
			p.hm = heat.splice( 0, 300 );
		}
		if ( n.maxTouchPoints > 1 && /Mac/.test( n.platform || '' ) ) {
			p.tp = 1;
		}
		var vid = visitorId();
		if ( vid ) {
			p.vid = vid;
		}
		events.forEach( function ( e ) {
			if ( e.r !== undefined ) {
				if ( e.r ) {
					p.r = e.r;
				}
				delete e.r;
			}
		} );
		send( p );
		if ( queue.length ) {
			flush();
		}
	}

	function push( ev ) {
		if ( disabled() ) {
			return;
		}
		ev.t = Date.now();
		queue.push( ev );
		emit( 'event', ev );
		if ( queue.length >= MAX_BATCH ) {
			flush();
		} else if ( ! timer ) {
			timer = setTimeout( flush, 1000 );
		}
	}

	// Maps the public props shape to the compact wire format; unknown keys become attributes.
	function track( name, props ) {
		props = props || {};
		var map = { category: 'c', module: 'm', entity_type: 'et', entity_id: 'ei', value: 'v', currency: 'cu', lead_ref: 'lr' };
		var ev = { n: String( name ), a: {} };
		Object.keys( props ).forEach( function ( k ) {
			if ( map[ k ] ) {
				ev[ map[ k ] ] = props[ k ];
			} else if ( k === 'attributes' && props[ k ] && typeof props[ k ] === 'object' ) {
				Object.keys( props[ k ] ).forEach( function ( a ) {
					ev.a[ a ] = props[ k ][ a ];
				} );
			} else {
				ev.a[ k ] = props[ k ];
			}
		} );
		push( ev );
	}

	function scrollDepth() {
		var h = Math.max( d.documentElement.scrollHeight, d.body ? d.body.scrollHeight : 0 );
		var seen = ( w.scrollY || w.pageYOffset || 0 ) + w.innerHeight;
		var pct = h <= w.innerHeight ? 100 : ( seen / h ) * 100;
		var milestone = pct >= 99 ? 100 : pct >= 75 ? 75 : pct >= 50 ? 50 : pct >= 25 ? 25 : 0;
		if ( milestone > maxScroll ) {
			maxScroll = milestone;
		}
	}

	// Cumulative per page view; reported again only when it grows (aggregation keeps the max per pv).
	function reportEngagement() {
		accrue();
		var secs = Math.round( pageMs / 1000 );
		var key = secs + ':' + maxScroll;
		if ( ! pv || key === reported || ( secs < 1 && ! maxScroll ) ) {
			return;
		}
		reported = key;
		push( { n: 'page_engagement', a: { pv: pv, engaged_seconds: secs, scroll_depth: maxScroll } } );
	}

	function pageView( first ) {
		pv = randomHex( 4 );
		pageMs = 0;
		maxScroll = 0;
		reported = '';
		beats = 0;
		var ev = { n: 'page_view', a: { pv: pv } };
		ev.r = first ? d.referrer : '';
		push( ev );
		flush();
		setTimeout( scrollDepth, 500 );
		emit( 'page', { first: first } );
	}

	// Lets chunks record final state (e.g. abandoned forms) before the page's last flush.
	function leaving() {
		emit( 'hide' );
		reportEngagement();
		flush();
	}

	function pathOf( url ) {
		return url.split( '#' )[ 0 ];
	}

	// Payloads carry curUrl, which still holds the previous page until the old page's events are flushed.
	function routeChanged() {
		if ( pathOf( loc.href ) === pathOf( curUrl ) ) {
			return;
		}
		leaving();
		curUrl = loc.href;
		ctx = { li: ctx.li, r: ctx.r, l: ctx.l }; // The embedded context described the first page only.
		pageView( false );
	}

	function patchHistory( method ) {
		var orig = w.history[ method ];
		if ( typeof orig !== 'function' ) {
			return;
		}
		w.history[ method ] = function () {
			var result = orig.apply( this, arguments );
			routeChanged();
			return result;
		};
	}

	function on( type, fn ) {
		( listeners[ type ] = listeners[ type ] || [] ).push( fn );
	}

	function emit( type, payload ) {
		( listeners[ type ] || [] ).forEach( function ( fn ) {
			try {
				fn( payload );
			} catch ( e ) {}
		} );
	}

	function activity() {
		accrue();
		lastActivity = Date.now();
		if ( lastTick === null && d.visibilityState === 'visible' ) {
			lastTick = lastActivity;
		}
	}

	// Handed to module chunks via BlueLens.use().
	var internal = {
		track: track,
		flush: flush,
		cfg: cfg,
		on: on,
		disabled: disabled,
		ctx: function () {
			return ctx;
		},
		heat: function ( x, y ) {
			if ( heat.length < 300 ) {
				heat.push( [ x, y ] );
				if ( ! timer ) {
					timer = setTimeout( flush, 1000 );
				}
			}
		},
	};

	var api = {
		track: track,
		pageview: function () {
			pageView( false );
		},
		consent: setConsent,
		optOut: function () {
			storageSet( 'bla_optout', '1' );
			setCookie( '', 0 );
		},
		optIn: function () {
			storageSet( 'bla_optout', null );
		},
		use: function ( fn ) {
			try {
				fn( internal );
			} catch ( e ) {}
		},
		v: 1,
	};
	w.BlueLens = api;

	// Replay calls queued by an inline stub: window.BlueLens.q = [ [ 'track', name, props ], ... ].
	( stub.q || [] ).forEach( function ( call ) {
		if ( api[ call[ 0 ] ] ) {
			api[ call[ 0 ] ].apply( null, Array.prototype.slice.call( call, 1 ) );
		}
	} );

	if ( ! cfg.url || disabled() ) {
		return;
	}

	d.addEventListener( 'wp_listen_for_consent_change', function ( e ) {
		var c = e.detail || {};
		if ( 'statistics' in c ) {
			setConsent( c.statistics === 'allow' );
		}
	} );

	[ 'mousedown', 'keydown', 'touchstart', 'scroll', 'mousemove' ].forEach( function ( type ) {
		w.addEventListener( type, activity, { passive: true } );
	} );
	w.addEventListener( 'scroll', scrollDepth, { passive: true } );

	d.addEventListener( 'visibilitychange', function () {
		if ( d.visibilityState === 'hidden' ) {
			leaving();
		} else {
			activity();
		}
	} );
	w.addEventListener( 'pagehide', leaving );

	if ( cfg.hb > 0 ) {
		setInterval( function () {
			accrue();
			if ( d.visibilityState === 'visible' && engagedMs >= 1000 && beats < MAX_BEATS ) {
				beats++;
				flush();
			}
		}, cfg.hb * 1000 );
	}

	if ( cfg.spa ) {
		patchHistory( 'pushState' );
		patchHistory( 'replaceState' );
		w.addEventListener( 'popstate', routeChanged );
	}

	lastTick = d.visibilityState === 'visible' ? Date.now() : null;
	pageView( true );

	// Module listener chunks are fetched after load so they never compete with page rendering.
	w.addEventListener( 'load', function () {
		( cfg.ch || [] ).forEach( function ( src ) {
			var s = d.createElement( 'script' );
			s.src = src;
			s.async = true;
			d.head.appendChild( s );
		} );
	} );
}( window, document ) );

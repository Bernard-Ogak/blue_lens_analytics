/*! Blue Lens Analytics admin app | GPL-2.0-or-later */
/**
 * The dashboard, built on WordPress's bundled React (wp.element) and REST client (wp.apiFetch).
 * All user- and API-supplied text is rendered through React text nodes (never innerHTML).
 */
( function ( wp, boot ) {
	'use strict';

	if ( ! boot || ! document.getElementById( 'blue-lens-app' ) ) {
		return;
	}

	const { createElement: h, useState, useEffect, useMemo, useRef, useCallback, Fragment } = wp.element;
	const { __, _n, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const { addQueryArgs } = wp.url;
	const C = window.BlueLensCharts;
	const fmt = C.fmt;

	fmt.init( boot.site.locale, boot.site.currency );

	const API = boot.restPath;

	/* ================================================================== */
	/* Icons: simple 24px line icons, drawn for this plugin                */
	/* ================================================================== */

	const ICONS = {
		lens: 'M11 4a7 7 0 1 0 0 14a7 7 0 0 0 0-14zM20 20l-4-4M8 11a3 3 0 0 1 3-3',
		users: 'M9 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8zM2 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1M17 3.5a4 4 0 0 1 0 7.5M22 21v-1a6 6 0 0 0-4-5.6',
		activity: 'M3 12h4l3-8l4 16l3-8h4',
		eye: 'M2 12s4-7 10-7s10 7 10 7s-4 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6a3 3 0 0 0 0 6z',
		clock: 'M12 21a9 9 0 1 0 0-18a9 9 0 0 0 0 18zM12 7v5l3 2',
		target: 'M12 21a9 9 0 1 0 0-18a9 9 0 0 0 0 18zM12 16a4 4 0 1 0 0-8a4 4 0 0 0 0 8zM12 12h.01',
		percent: 'M19 5L5 19M7 9a2 2 0 1 0 0-4a2 2 0 0 0 0 4zM17 19a2 2 0 1 0 0-4a2 2 0 0 0 0 4z',
		bounce: 'M4 14l5-5l4 4l7-7M15 6h5v5',
		layers: 'M12 3l9 5l-9 5l-9-5l9-5zM3 13l9 5l9-5M3 17.5l9 5l9-5',
		cash: 'M3 6h18v12H3zM12 15a3 3 0 1 0 0-6a3 3 0 0 0 0 6zM6 9v.01M18 15v.01',
		userPlus: 'M9 11a4 4 0 1 0 0-8a4 4 0 0 0 0 8zM2 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1M19 8v6M16 11h6',
		repeat: 'M17 2l3 3l-3 3M4 11V9a4 4 0 0 1 4-4h12M7 22l-3-3l3-3M20 13v2a4 4 0 0 1-4 4H4',
		spark: 'M13 2L4 14h7l-1 8l9-12h-7z',
		refresh: 'M21 12a9 9 0 1 1-2.6-6.4M21 3v6h-6',
		calendar: 'M4 6h16v15H4zM4 10h16M8 3v4M16 3v4',
		sliders: 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0M14 4v4M8 10v4M16 16v4',
		sun: 'M12 17a5 5 0 1 0 0-10a5 5 0 0 0 0 10zM12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
		moon: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z',
		table: 'M3 5h18v14H3zM3 10h18M3 15h18M9 5v14',
		chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
		download: 'M12 3v12M7 10l5 5l5-5M4 21h16',
		x: 'M6 6l12 12M18 6L6 18',
		up: 'M12 19V5M5 12l7-7l7 7',
		down: 'M12 5v14M19 12l-7 7l-7-7',
		globe: 'M12 21a9 9 0 1 0 0-18a9 9 0 0 0 0 18zM3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18',
		file: 'M14 3H6v18h12V7zM14 3v4h4M9 13h6M9 17h6',
		search: 'M11 18a7 7 0 1 0 0-14a7 7 0 0 0 0 14zM21 21l-5-5',
		alert: 'M12 3l10 18H2L12 3zM12 10v5M12 18h.01',
		pointer: 'M5 3l14 7l-6 2l-2 6z',
		form: 'M5 3h14v18H5zM8 8h8M8 12h8M8 16h5',
		message: 'M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12z',
		play: 'M12 21a9 9 0 1 0 0-18a9 9 0 0 0 0 18zM10 8l6 4l-6 4z',
		link: 'M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1',
		bot: 'M5 8h14v11H5zM12 4v4M9 13h.01M15 13h.01M2 13v3M22 13v3',
		settings: 'M12 15a3 3 0 1 0 0-6a3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0-1.2-2.9H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.2-2.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 2.9-1.2V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
		check: 'M5 12l5 5l9-10',
		home: 'M3 11l9-8l9 8M5 9.5V21h14V9.5M10 21v-6h4v6',
		shield: 'M12 3l8 3v6c0 5-3.5 8-8 9c-4.5-1-8-4-8-9V6l8-3zM8.5 12l2.5 2.5l4.5-5',
		bulb: 'M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z',
		megaphone: 'M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1zM15 8a5 5 0 0 1 0 8M18 5a9 9 0 0 1 0 14',
		chevron: 'M9 6l6 6l-6 6',
		external: 'M14 4h6v6M20 4l-9 9M18 14v6H4V6h6',
		flame: 'M12 21a7 7 0 0 0 7-7c0-4-3-6-4-10c-2 2-3 4-3 6c-1-1-2-2-2-4c-2 2-5 5-5 8a7 7 0 0 0 7 7z',
	};

	function Icon( { name, size = 18 } ) {
		return h(
			'svg',
			{ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true', focusable: 'false' },
			h( 'path', { d: ICONS[ name ] || ICONS.spark } )
		);
	}

	/* ================================================================== */
	/* Dates                                                               */
	/* ================================================================== */

	const addDays = ( iso, n ) => {
		const d = new Date( iso + 'T00:00:00Z' );
		d.setUTCDate( d.getUTCDate() + n );
		return d.toISOString().slice( 0, 10 );
	};

	const PRESETS = [
		[ 'today', __( 'Today', 'blue-lens-analytics' ) ],
		[ 'yesterday', __( 'Yesterday', 'blue-lens-analytics' ) ],
		[ 'last7', __( 'Last 7 days', 'blue-lens-analytics' ) ],
		[ 'last28', __( 'Last 28 days', 'blue-lens-analytics' ) ],
		[ 'last30', __( 'Last 30 days', 'blue-lens-analytics' ) ],
		[ 'last90', __( 'Last 90 days', 'blue-lens-analytics' ) ],
		[ 'this_month', __( 'This month', 'blue-lens-analytics' ) ],
		[ 'last_month', __( 'Last month', 'blue-lens-analytics' ) ],
		[ 'year_to_date', __( 'Year to date', 'blue-lens-analytics' ) ],
		[ 'last12m', __( 'Last 12 months', 'blue-lens-analytics' ) ],
	];

	function resolveRange( key ) {
		const t = boot.site.today;
		const month = t.slice( 0, 8 ) + '01';
		switch ( key ) {
			case 'today':
				return { key, from: t, to: t };
			case 'yesterday':
				return { key, from: addDays( t, -1 ), to: addDays( t, -1 ) };
			case 'last7':
				return { key, from: addDays( t, -6 ), to: t };
			case 'last30':
				return { key, from: addDays( t, -29 ), to: t };
			case 'last90':
				return { key, from: addDays( t, -89 ), to: t };
			case 'this_month':
				return { key, from: month, to: t };
			case 'last_month': {
				const end = addDays( month, -1 );
				return { key, from: end.slice( 0, 8 ) + '01', to: end };
			}
			case 'year_to_date':
				return { key, from: t.slice( 0, 4 ) + '-01-01', to: t };
			case 'last12m':
				return { key, from: addDays( t, -364 ), to: t };
			default:
				return { key: 'last28', from: addDays( t, -27 ), to: t };
		}
	}

	const presetLabel = ( key ) => ( PRESETS.find( ( p ) => p[ 0 ] === key ) || [ null, __( 'Custom range', 'blue-lens-analytics' ) ] )[ 1 ];

	const rangeText = ( r ) => ( r.from === r.to ? fmt.date( r.from, true ) : fmt.date( r.from, r.from.slice( 0, 4 ) !== r.to.slice( 0, 4 ) ) + ' – ' + fmt.date( r.to, true ) );

	function relativeTime( ts ) {
		if ( ! ts ) {
			return __( 'not yet', 'blue-lens-analytics' );
		}
		const diff = Math.round( ( ts * 1000 - Date.now() ) / 60000 );
		try {
			const rtf = new Intl.RelativeTimeFormat( boot.site.locale, { numeric: 'auto' } );
			return Math.abs( diff ) < 60 ? rtf.format( diff, 'minute' ) : rtf.format( Math.round( diff / 60 ), 'hour' );
		} catch ( e ) {
			return Math.abs( diff ) + ' min';
		}
	}

	/* ================================================================== */
	/* Data                                                                */
	/* ================================================================== */

	function useApi( path, params, refreshKey ) {
		const [ state, setState ] = useState( { data: null, error: null, loading: !! path } );
		const key = path ? path + JSON.stringify( params || {} ) + ':' + refreshKey : '';

		useEffect( () => {
			if ( ! path ) {
				return undefined;
			}
			let alive = true;
			const ctrl = window.AbortController ? new window.AbortController() : null;
			setState( ( s ) => ( { data: s.data, error: null, loading: true } ) );
			apiFetch( { path: addQueryArgs( API + path, params || {} ), signal: ctrl ? ctrl.signal : undefined } )
				.then( ( data ) => alive && setState( { data, error: null, loading: false } ) )
				.catch( ( err ) => {
					if ( ! alive || ( err && err.name === 'AbortError' ) ) {
						return;
					}
					setState( ( s ) => ( { data: s.data, error: ( err && err.message ) || __( 'This report could not be loaded.', 'blue-lens-analytics' ), loading: false } ) );
				} );
			return () => {
				alive = false;
				if ( ctrl ) {
					ctrl.abort();
				}
			};
		}, [ key ] );

		return state;
	}

	const ratio = ( a, b ) => ( b > 0 ? a / b : 0 );

	const METRICS = {
		visitors: { label: __( 'Visitors', 'blue-lens-analytics' ), icon: 'users' },
		sessions: { label: __( 'Sessions', 'blue-lens-analytics' ), icon: 'activity' },
		pageviews: { label: __( 'Pageviews', 'blue-lens-analytics' ), icon: 'eye' },
		engagement_rate: { label: __( 'Engagement rate', 'blue-lens-analytics' ), kind: 'percent', icon: 'spark', derive: ( d ) => ratio( d.engaged_sessions, d.sessions ) },
		avg_engaged_time: { label: __( 'Avg. engaged time', 'blue-lens-analytics' ), kind: 'duration', icon: 'clock', derive: ( d ) => ratio( d.engaged_seconds, d.sessions ) },
		bounce_rate: { label: __( 'Bounce rate', 'blue-lens-analytics' ), kind: 'percent', icon: 'bounce', lowerIsBetter: true, derive: ( d ) => ratio( d.bounces, d.sessions ) },
		pages_per_session: { label: __( 'Pages per session', 'blue-lens-analytics' ), kind: 'decimal', icon: 'layers', derive: ( d ) => ratio( d.pageviews, d.sessions ) },
		conversions: { label: __( 'Conversions', 'blue-lens-analytics' ), icon: 'target' },
		conversion_rate: { label: __( 'Conversion rate', 'blue-lens-analytics' ), kind: 'percent', icon: 'percent', derive: ( d ) => ratio( d.conversions, d.sessions ) },
		revenue: { label: __( 'Revenue', 'blue-lens-analytics' ), kind: 'money', icon: 'cash' },
		new_visitors: { label: __( 'New visitors', 'blue-lens-analytics' ), icon: 'userPlus' },
		returning_visitors: { label: __( 'Returning visitors', 'blue-lens-analytics' ), icon: 'repeat' },
	};

	const metricValue = ( key, d ) => ( METRICS[ key ].derive ? METRICS[ key ].derive( d ) : d[ key ] || 0 );
	const metricFormat = ( key ) => fmt.of( METRICS[ key ].kind || 'compact' );

	// Colour follows the entity: each channel keeps its slot everywhere.
	const CHANNELS = {
		organic_search: [ __( 'Organic search', 'blue-lens-analytics' ), 's1' ],
		social: [ __( 'Social', 'blue-lens-analytics' ), 's2' ],
		referral: [ __( 'Referral', 'blue-lens-analytics' ), 's3' ],
		paid_search: [ __( 'Paid search', 'blue-lens-analytics' ), 's4' ],
		ai_assistant: [ __( 'AI assistants', 'blue-lens-analytics' ), 's5' ],
		email: [ __( 'Email', 'blue-lens-analytics' ), 's6' ],
		direct: [ __( 'Direct', 'blue-lens-analytics' ), 's7' ],
		ota_listing: [ __( 'OTA & listings', 'blue-lens-analytics' ), 's8' ],
		paid_social: [ __( 'Paid social', 'blue-lens-analytics' ), null ],
		display: [ __( 'Display ads', 'blue-lens-analytics' ), null ],
		affiliate: [ __( 'Affiliates', 'blue-lens-analytics' ), null ],
	};
	const channelLabel = ( k ) => ( CHANNELS[ k ] ? CHANNELS[ k ][ 0 ] : k );
	const channelColor = ( k ) => ( CHANNELS[ k ] && CHANNELS[ k ][ 1 ] ? 'var(--bla-' + CHANNELS[ k ][ 1 ] + ')' : 'var(--bla-other)' );

	const DEVICE = { desktop: [ __( 'Desktop', 'blue-lens-analytics' ), 's1' ], mobile: [ __( 'Mobile', 'blue-lens-analytics' ), 's2' ], tablet: [ __( 'Tablet', 'blue-lens-analytics' ), 's3' ], tv: [ __( 'TV', 'blue-lens-analytics' ), 's4' ] };

	const VIEWPORTS = {
		xs: __( 'Phones (under 576px)', 'blue-lens-analytics' ),
		sm: __( 'Large phones (576–767px)', 'blue-lens-analytics' ),
		md: __( 'Tablets (768–991px)', 'blue-lens-analytics' ),
		lg: __( 'Laptops (992–1199px)', 'blue-lens-analytics' ),
		xl: __( 'Desktops (1200–1599px)', 'blue-lens-analytics' ),
		xxl: __( 'Large screens (1600px+)', 'blue-lens-analytics' ),
	};

	const EVENTS = {
		page_view: __( 'Page views', 'blue-lens-analytics' ),
		page_engagement: __( 'Engagement updates', 'blue-lens-analytics' ),
		outbound_click: __( 'Outbound link clicks', 'blue-lens-analytics' ),
		file_download: __( 'File downloads', 'blue-lens-analytics' ),
		contact_click: __( 'Phone, email & WhatsApp taps', 'blue-lens-analytics' ),
		cta_click: __( 'Call-to-action clicks', 'blue-lens-analytics' ),
		site_search: __( 'Site searches', 'blue-lens-analytics' ),
		page_not_found: __( '404 pages', 'blue-lens-analytics' ),
		js_error: __( 'JavaScript errors', 'blue-lens-analytics' ),
		web_vitals: __( 'Page-speed samples', 'blue-lens-analytics' ),
		video_start: __( 'Video plays', 'blue-lens-analytics' ),
		video_progress: __( 'Video progress', 'blue-lens-analytics' ),
		video_complete: __( 'Videos completed', 'blue-lens-analytics' ),
		form_start: __( 'Forms started', 'blue-lens-analytics' ),
		form_abandon: __( 'Forms abandoned', 'blue-lens-analytics' ),
		form_submit: __( 'Forms submitted', 'blue-lens-analytics' ),
		click: __( 'Clicks', 'blue-lens-analytics' ),
		rage_click: __( 'Rage clicks', 'blue-lens-analytics' ),
		dead_click: __( 'Dead clicks', 'blue-lens-analytics' ),
		copy_text: __( 'Text copied', 'blue-lens-analytics' ),
		ad_impression: __( 'Ad impressions', 'blue-lens-analytics' ),
		ad_click: __( 'Ad clicks', 'blue-lens-analytics' ),
	};
	const eventLabel = ( n ) => EVENTS[ n ] || n.replace( /_/g, ' ' ).replace( /^./, ( c ) => c.toUpperCase() );

	let regionNames = null;
	let languageNames = null;
	try {
		regionNames = new Intl.DisplayNames( [ boot.site.locale ], { type: 'region' } );
		languageNames = new Intl.DisplayNames( [ boot.site.locale ], { type: 'language' } );
	} catch ( e ) {}

	const flag = ( code ) => ( /^[A-Z]{2}$/.test( code ) ? String.fromCodePoint( ...[ ...code ].map( ( c ) => 127397 + c.charCodeAt( 0 ) ) ) : '🌐' );
	const countryName = ( code ) => {
		if ( ! /^[A-Z]{2}$/.test( code ) ) {
			return __( 'Unknown', 'blue-lens-analytics' );
		}
		try {
			return regionNames ? regionNames.of( code ) : code;
		} catch ( e ) {
			return code;
		}
	};

	const DIMENSION_LABELS = {
		channel: ( v ) => channelLabel( v ),
		country: ( v ) => countryName( v ),
		region: ( v ) => {
			const [ c, rest ] = v.split( ' · ' );
			return ( rest || v ) + ( rest ? ', ' + countryName( c ) : '' );
		},
		city: ( v ) => {
			const [ c, rest ] = v.split( ' · ' );
			return ( rest || v ) + ( rest ? ', ' + countryName( c ) : '' );
		},
		device: ( v ) => ( DEVICE[ v ] ? DEVICE[ v ][ 0 ] : v === '(unknown)' ? __( 'Unknown', 'blue-lens-analytics' ) : v ),
		viewport: ( v ) => VIEWPORTS[ v ] || __( 'Unknown', 'blue-lens-analytics' ),
		visitor_type: ( v ) => ( { new: __( 'New visitors', 'blue-lens-analytics' ), returning: __( 'Returning visitors', 'blue-lens-analytics' ), unknown: __( 'Not identified (cookieless)', 'blue-lens-analytics' ) }[ v ] || v ),
		language: ( v ) => {
			if ( v === '(unknown)' ) {
				return __( 'Unknown', 'blue-lens-analytics' );
			}
			try {
				return languageNames ? languageNames.of( v ) : v;
			} catch ( e ) {
				return v;
			}
		},
		source: ( v ) => ( v === '(direct)' ? __( '(direct)', 'blue-lens-analytics' ) : v ),
		medium: ( v ) => ( v === '(none)' ? __( '(none)', 'blue-lens-analytics' ) : v ),
	};
	const dimensionLabel = ( dim, v ) => ( DIMENSION_LABELS[ dim ] ? DIMENSION_LABELS[ dim ]( v ) : v );

	const pageUrl = ( path ) => {
		try {
			return new URL( path, boot.site.home ).toString();
		} catch ( e ) {
			return boot.site.home;
		}
	};

	const exportUrl = ( report, arg, range ) =>
		addQueryArgs( boot.export.url, { action: 'blue_lens_export', _wpnonce: boot.export.nonce, report, arg: arg || '', from: range.from, to: range.to } );

	/* ================================================================== */
	/* Pages and widgets                                                   */
	/* ================================================================== */

	// group: sidebar section; dated: whether the date range applies.
	const PAGES = [
		{ id: 'overview', group: 'analytics', icon: 'home', label: __( 'Overview', 'blue-lens-analytics' ), desc: __( 'How your website is performing at a glance.', 'blue-lens-analytics' ) },
		{ id: 'acquisition', group: 'analytics', icon: 'bounce', label: __( 'Acquisition', 'blue-lens-analytics' ), desc: __( 'Where your visitors come from and which sources bring results.', 'blue-lens-analytics' ) },
		{ id: 'audience', group: 'analytics', icon: 'globe', label: __( 'Audience', 'blue-lens-analytics' ), desc: __( 'Who your visitors are: markets, devices and languages.', 'blue-lens-analytics' ) },
		{ id: 'content', group: 'analytics', icon: 'file', label: __( 'Content', 'blue-lens-analytics' ), desc: __( 'Which pages, tours and posts attract and convert visitors.', 'blue-lens-analytics' ) },
		{ id: 'engagement', group: 'analytics', icon: 'pointer', label: __( 'Engagement', 'blue-lens-analytics' ), desc: __( 'What visitors do: forms, contacts, downloads, videos and clicks.', 'blue-lens-analytics' ) },
		{ id: 'heatmaps', group: 'analytics', icon: 'flame', label: __( 'Heatmaps', 'blue-lens-analytics' ), desc: __( 'Where people click on each page.', 'blue-lens-analytics' ) },
		{ id: 'audit', group: 'seo', icon: 'shield', dated: false, label: __( 'Site Audit', 'blue-lens-analytics' ), desc: __( 'Technical health of your website, with clear fixes.', 'blue-lens-analytics' ) },
		{ id: 'onpage', group: 'seo', icon: 'bulb', dated: false, label: __( 'On-Page SEO', 'blue-lens-analytics' ), desc: __( 'Ideas to improve each page, prioritised by real visits.', 'blue-lens-analytics' ) },
	];
	if ( boot.localAds && boot.localAds.active ) {
		PAGES.push( { id: 'ads', group: 'advertising', icon: 'megaphone', label: __( 'Ads', 'blue-lens-analytics' ), desc: __( 'Local Ads impressions, clicks and the visits behind them.', 'blue-lens-analytics' ) } );
	}
	if ( boot.canManage ) {
		PAGES.push( { id: 'settings', group: 'manage', icon: 'settings', dated: false, label: __( 'Settings', 'blue-lens-analytics' ), desc: __( 'Tracking, privacy, audit and data options.', 'blue-lens-analytics' ) } );
	}

	const NAV_GROUPS = [
		[ 'analytics', __( 'Analytics', 'blue-lens-analytics' ) ],
		[ 'seo', __( 'SEO', 'blue-lens-analytics' ) ],
		[ 'advertising', __( 'Advertising', 'blue-lens-analytics' ) ],
		[ 'manage', __( 'Manage', 'blue-lens-analytics' ) ],
	];

	const WIDGETS = {
		overview: [
			{ id: 'kpis', label: __( 'Key metrics', 'blue-lens-analytics' ), span: 12 },
			{ id: 'trend', label: __( 'Traffic trend', 'blue-lens-analytics' ), span: 8 },
			{ id: 'realtime', label: __( 'Real-time', 'blue-lens-analytics' ), span: 4 },
			{ id: 'channels', label: __( 'Channels', 'blue-lens-analytics' ), span: 6 },
			{ id: 'sources', label: __( 'Top sources', 'blue-lens-analytics' ), span: 6 },
			{ id: 'top_pages', label: __( 'Top pages', 'blue-lens-analytics' ), span: 6 },
			{ id: 'countries', label: __( 'Top countries', 'blue-lens-analytics' ), span: 6 },
			{ id: 'devices', label: __( 'Devices', 'blue-lens-analytics' ), span: 6 },
			{ id: 'goals', label: __( 'Conversions', 'blue-lens-analytics' ), span: 6 },
			{ id: 'audit', label: __( 'Site Audit', 'blue-lens-analytics' ), span: 6 },
		],
		acquisition: [
			{ id: 'channels_table', label: __( 'Channels', 'blue-lens-analytics' ), span: 12 },
			{ id: 'sources', label: __( 'Sources', 'blue-lens-analytics' ), span: 6 },
			{ id: 'campaigns', label: __( 'Campaigns', 'blue-lens-analytics' ), span: 6 },
			{ id: 'referrers', label: __( 'Referring websites', 'blue-lens-analytics' ), span: 6 },
			{ id: 'landing', label: __( 'Landing pages', 'blue-lens-analytics' ), span: 6 },
			{ id: 'crawlers', label: __( 'Search & AI crawlers', 'blue-lens-analytics' ), span: 12 },
		],
		audience: [
			{ id: 'geo', label: __( 'Locations', 'blue-lens-analytics' ), span: 12 },
			{ id: 'devices', label: __( 'Devices', 'blue-lens-analytics' ), span: 6 },
			{ id: 'screens', label: __( 'Screen sizes', 'blue-lens-analytics' ), span: 6 },
			{ id: 'browsers', label: __( 'Browsers', 'blue-lens-analytics' ), span: 6 },
			{ id: 'os', label: __( 'Operating systems', 'blue-lens-analytics' ), span: 6 },
			{ id: 'languages', label: __( 'Languages', 'blue-lens-analytics' ), span: 6 },
			{ id: 'visitor_type', label: __( 'New vs returning', 'blue-lens-analytics' ), span: 6 },
		],
		content: [
			{ id: 'content_table', label: __( 'Content performance', 'blue-lens-analytics' ), span: 12 },
			{ id: 'entry_pages', label: __( 'Entry pages', 'blue-lens-analytics' ), span: 6 },
			{ id: 'exit_pages', label: __( 'Exit pages', 'blue-lens-analytics' ), span: 6 },
		],
		engagement: [
			{ id: 'summary', label: __( 'Interaction summary', 'blue-lens-analytics' ), span: 12 },
			{ id: 'events_table', label: __( 'All events', 'blue-lens-analytics' ), span: 12 },
		],
		ads: [
			{ id: 'ads_kpis', label: __( 'Ad performance', 'blue-lens-analytics' ), span: 12 },
			{ id: 'ads_trend', label: __( 'Ad trend', 'blue-lens-analytics' ), span: 12 },
			{ id: 'ads_table', label: __( 'Advertisements', 'blue-lens-analytics' ), span: 12 },
			{ id: 'ads_campaigns', label: __( 'Campaigns', 'blue-lens-analytics' ), span: 6 },
			{ id: 'ads_channels', label: __( 'Ad clicks by channel', 'blue-lens-analytics' ), span: 6 },
			{ id: 'ads_pages', label: __( 'Pages where ads are seen', 'blue-lens-analytics' ), span: 12 },
		],
	};

	function layoutFor( page, prefs ) {
		const defs = WIDGETS[ page ] || [];
		const saved = ( prefs.layout && prefs.layout[ page ] ) || [];
		const order = [];
		saved.forEach( ( s ) => {
			const def = defs.find( ( d ) => d.id === s.id );
			if ( def ) {
				order.push( { ...def, on: s.on } );
			}
		} );
		defs.forEach( ( d ) => {
			if ( ! order.find( ( o ) => o.id === d.id ) ) {
				order.push( { ...d, on: true } );
			}
		} );
		return order;
	}

	/* ================================================================== */
	/* Generic UI                                                          */
	/* ================================================================== */

	function Card( { title, sub, actions, children, span, className } ) {
		return h(
			'section',
			{ className: 'bla-card' + ( span === null ? '' : ' bla-span-' + ( span || 12 ) ) + ( className ? ' ' + className : '' ) },
			( title || actions ) &&
				h(
					'div',
					{ className: 'bla-card__head' },
					h( 'div', null, title && h( 'h2', { className: 'bla-card__title' }, title ), sub && h( 'p', { className: 'bla-card__sub' }, sub ) ),
					actions && h( 'div', { className: 'bla-card__actions' }, actions )
				),
			h( 'div', { className: 'bla-card__body' }, children )
		);
	}

	function IconButton( { icon, label, onClick, pressed, href } ) {
		const props = { className: 'bla-btn bla-btn--sm bla-btn--icon bla-btn--ghost', 'aria-label': label, title: label };
		if ( href ) {
			return h( 'a', { ...props, href }, h( Icon, { name: icon, size: 16 } ) );
		}
		return h( 'button', { ...props, type: 'button', onClick, 'aria-pressed': pressed === undefined ? undefined : !! pressed }, h( Icon, { name: icon, size: 16 } ) );
	}

	function Segment( { value, options, onChange, label } ) {
		return h(
			'div',
			{ className: 'bla-segment', role: 'group', 'aria-label': label },
			options.map( ( [ v, text ] ) => h( 'button', { key: v, type: 'button', 'aria-pressed': value === v, onClick: () => onChange( v ) }, text ) )
		);
	}

	function Empty( { title, children, icon } ) {
		return h(
			'div',
			{ className: 'bla-empty' },
			h( 'div', { className: 'bla-empty__art' }, h( Icon, { name: icon || 'lens', size: 30 } ) ),
			h( 'strong', null, title || __( 'No data for this period yet', 'blue-lens-analytics' ) ),
			children && h( 'div', null, children )
		);
	}

	function ErrorBox( { message } ) {
		return h( 'div', { className: 'bla-error', role: 'alert' }, message );
	}

	/** Card with a chart/table toggle and optional CSV export. */
	function ViewCard( { title, sub, span, exportHref, chart, table, extraActions, hasData, emptyText } ) {
		const [ tableView, setTableView ] = useState( false );
		const actions = h(
			Fragment,
			null,
			extraActions,
			table && hasData && h( IconButton, { icon: tableView ? 'chart' : 'table', label: tableView ? __( 'Show chart', 'blue-lens-analytics' ) : __( 'Show as table', 'blue-lens-analytics' ), onClick: () => setTableView( ! tableView ), pressed: tableView } ),
			exportHref && hasData && h( IconButton, { icon: 'download', label: __( 'Export CSV', 'blue-lens-analytics' ), href: exportHref } )
		);
		return h( Card, { title, sub, span, actions }, hasData ? ( tableView && table ? table() : chart() ) : h( Empty, { title: emptyText } ) );
	}

	function Toast( { message } ) {
		return message ? h( 'div', { className: 'bla-toast', role: 'status' }, message ) : null;
	}

	/* ================================================================== */
	/* Top bar                                                             */
	/* ================================================================== */

	function DateRange( { range, onChange } ) {
		const [ open, setOpen ] = useState( false );
		const [ from, setFrom ] = useState( range.from );
		const [ to, setTo ] = useState( range.to );
		const ref = useRef( null );

		useEffect( () => {
			if ( ! open ) {
				return undefined;
			}
			const onDoc = ( e ) => ref.current && ! ref.current.contains( e.target ) && setOpen( false );
			const onKey = ( e ) => e.key === 'Escape' && setOpen( false );
			document.addEventListener( 'mousedown', onDoc );
			document.addEventListener( 'keydown', onKey );
			return () => {
				document.removeEventListener( 'mousedown', onDoc );
				document.removeEventListener( 'keydown', onKey );
			};
		}, [ open ] );

		useEffect( () => {
			setFrom( range.from );
			setTo( range.to );
		}, [ range.from, range.to ] );

		const pick = ( key ) => {
			onChange( resolveRange( key ) );
			setOpen( false );
		};

		return h(
			'div',
			{ className: 'bla-pop', ref },
			h(
				'button',
				{ type: 'button', className: 'bla-btn', 'aria-haspopup': 'dialog', 'aria-expanded': open, onClick: () => setOpen( ! open ) },
				h( Icon, { name: 'calendar', size: 16 } ),
				h( 'span', null, range.key === 'custom' ? rangeText( range ) : presetLabel( range.key ) ),
				range.key !== 'custom' && h( 'span', { style: { color: 'var(--bla-muted)', fontWeight: 400 } }, rangeText( range ) )
			),
			open &&
				h(
					'div',
					{ className: 'bla-pop__panel', role: 'dialog', 'aria-label': __( 'Choose a date range', 'blue-lens-analytics' ) },
					PRESETS.map( ( [ key, label ] ) =>
						h(
							'button',
							{ key, type: 'button', className: 'bla-menu__item', onClick: () => pick( key ) },
							h( 'span', { className: 'bla-menu__check' }, range.key === key ? '✓' : '' ),
							label
						)
					),
					h(
						'div',
						{ className: 'bla-pop__footer' },
						h( 'strong', { style: { fontSize: 12 } }, __( 'Custom range', 'blue-lens-analytics' ) ),
						h(
							'div',
							{ className: 'bla-row' },
							h( 'label', { className: 'bla-sr', htmlFor: 'bla-from' }, __( 'From', 'blue-lens-analytics' ) ),
							h( 'input', { id: 'bla-from', className: 'bla-input', type: 'date', value: from, max: boot.site.today, onChange: ( e ) => setFrom( e.target.value ) } ),
							h( 'label', { className: 'bla-sr', htmlFor: 'bla-to' }, __( 'To', 'blue-lens-analytics' ) ),
							h( 'input', { id: 'bla-to', className: 'bla-input', type: 'date', value: to, max: boot.site.today, onChange: ( e ) => setTo( e.target.value ) } )
						),
						h(
							'button',
							{
								type: 'button',
								className: 'bla-btn bla-btn--primary bla-btn--sm',
								disabled: ! from || ! to || from > to,
								onClick: () => {
									onChange( { key: 'custom', from, to } );
									setOpen( false );
								},
							},
							__( 'Apply', 'blue-lens-analytics' )
						)
					)
				)
		);
	}

	function Sidebar( { route, setRoute } ) {
		return h(
			'aside',
			{ className: 'bla-side' },
			h(
				'div',
				{ className: 'bla-brand' },
				h( 'span', { className: 'bla-brand__mark' }, h( Icon, { name: 'lens', size: 18 } ) ),
				h( 'span', { className: 'bla-brand__text' }, h( 'strong', null, 'Blue Lens' ), h( 'span', { className: 'bla-brand__site' }, boot.site.name ) )
			),
			h(
				'nav',
				{ className: 'bla-side__nav', 'aria-label': __( 'Blue Lens sections', 'blue-lens-analytics' ) },
				NAV_GROUPS.map( ( [ group, label ] ) => {
					const items = PAGES.filter( ( p ) => p.group === group );
					if ( ! items.length ) {
						return null;
					}
					return h(
						'div',
						{ key: group, className: 'bla-side__group' },
						h( 'div', { className: 'bla-side__label' }, label ),
						items.map( ( p ) =>
							h(
								'button',
								{ key: p.id, type: 'button', className: 'bla-side__item', 'aria-current': route === p.id ? 'page' : undefined, onClick: () => setRoute( p.id ) },
								h( Icon, { name: p.icon, size: 17 } ),
								h( 'span', null, p.label )
							)
						)
					);
				} )
			),
			h(
				'div',
				{ className: 'bla-side__foot' },
				'Blue Lens ' + boot.version,
				h( 'br' ),
				__( 'by Bernard Ogak', 'blue-lens-analytics' ),
				' · ',
				h( 'a', { href: 'https://www.creativebay.co.ke', target: '_blank', rel: 'noopener noreferrer' }, 'Creative Bay' )
			)
		);
	}

	function TopBar( { page, range, setRange, compare, setCompare, lastAggregated, onRefresh, refreshing, prefs, savePrefs, openCustomize } ) {
		const showFilters = page.dated !== false;
		return h(
			'header',
			{ className: 'bla-topbar' },
			h(
				'div',
				{ className: 'bla-head' },
				h( 'div', { className: 'bla-crumbs' }, h( 'span', null, 'Blue Lens' ), h( 'span', { 'aria-hidden': 'true' }, ' / ' ), h( 'span', null, page.label ) ),
				h( 'h1', null, page.label ),
				h( 'p', null, page.desc )
			),
			h( 'div', { className: 'bla-topbar__spacer' } ),
			h(
				'div',
				{ className: 'bla-topbar__tools' },
				showFilters &&
					h(
						Fragment,
						null,
						h( 'span', { className: 'bla-fresh' }, sprintf( /* translators: %s: relative time. */ __( 'Updated %s', 'blue-lens-analytics' ), relativeTime( lastAggregated ) ) ),
						h(
							'button',
							{ type: 'button', className: 'bla-btn bla-btn--icon', onClick: onRefresh, disabled: refreshing, 'aria-label': __( 'Refresh today’s data', 'blue-lens-analytics' ), title: __( 'Refresh today’s data', 'blue-lens-analytics' ) },
							h( Icon, { name: 'refresh', size: 16 } )
						),
						h( DateRange, { range, onChange: setRange } ),
						h(
							'select',
							{ className: 'bla-select', value: compare, onChange: ( e ) => setCompare( e.target.value ), 'aria-label': __( 'Comparison', 'blue-lens-analytics' ) },
							h( 'option', { value: 'previous' }, __( 'vs previous period', 'blue-lens-analytics' ) ),
							h( 'option', { value: 'year' }, __( 'vs same period last year', 'blue-lens-analytics' ) ),
							h( 'option', { value: 'none' }, __( 'No comparison', 'blue-lens-analytics' ) )
						)
					),
				h(
					'button',
					{ type: 'button', className: 'bla-btn bla-btn--icon', onClick: () => savePrefs( { theme: prefs.theme === 'dark' ? 'light' : 'dark' } ), 'aria-label': prefs.theme === 'dark' ? __( 'Switch to light theme', 'blue-lens-analytics' ) : __( 'Switch to dark theme', 'blue-lens-analytics' ), title: prefs.theme === 'dark' ? __( 'Light theme', 'blue-lens-analytics' ) : __( 'Dark theme', 'blue-lens-analytics' ) },
					h( Icon, { name: prefs.theme === 'dark' ? 'sun' : 'moon', size: 16 } )
				),
				h( 'button', { type: 'button', className: 'bla-btn', onClick: openCustomize }, h( Icon, { name: 'sliders', size: 16 } ), __( 'Customize', 'blue-lens-analytics' ) )
			)
		);
	}

	/* ================================================================== */
	/* Overview widgets                                                    */
	/* ================================================================== */

	function KpiTiles( { data, prefs } ) {
		const totals = data.totals;
		const prev = data.compare && data.compare.totals;
		return h(
			'div',
			{ className: 'bla-kpis bla-span-12 ' + ( prefs.tiles === 'subtle' ? 'is-subtle' : 'is-vivid' ), 'data-count': prefs.kpis.length },
			prefs.kpis.map( ( key, i ) => {
				const def = METRICS[ key ];
				if ( ! def ) {
					return null;
				}
				const value = metricValue( key, totals );
				const f = metricFormat( key );
				let delta = null;
				if ( prev ) {
					const before = metricValue( key, prev );
					if ( before > 0 ) {
						const change = ( value - before ) / before;
						const good = def.lowerIsBetter ? change < 0 : change > 0;
						delta = { change, good, flat: Math.abs( change ) < 0.005 };
					}
				}
				return h(
					'div',
					{ className: 'bla-kpi bla-kpi--' + ( ( i % 8 ) + 1 ), key },
					h( 'span', { className: 'bla-kpi__icon' }, h( Icon, { name: def.icon, size: 18 } ) ),
					h( 'div', { className: 'bla-kpi__label' }, def.label ),
					h( 'div', { className: 'bla-kpi__value' }, f( value ) ),
					delta
						? h(
							'div',
							{ style: { display: 'flex', alignItems: 'center', gap: 8 } },
							h(
								'span',
								{ className: 'bla-kpi__delta' + ( delta.flat ? '' : delta.good ? ' is-good' : ' is-bad' ) },
								h( Icon, { name: delta.flat ? 'activity' : delta.change > 0 ? 'up' : 'down', size: 12 } ),
								fmt.percent( Math.abs( delta.change ) ),
								h( 'span', { className: 'bla-sr' }, delta.flat ? __( 'unchanged', 'blue-lens-analytics' ) : delta.good ? __( 'improvement', 'blue-lens-analytics' ) : __( 'decline', 'blue-lens-analytics' ) )
							),
							h( 'span', { className: 'bla-kpi__vs' }, data.compare.range && prefs.compare === 'year' ? __( 'vs last year', 'blue-lens-analytics' ) : __( 'vs previous period', 'blue-lens-analytics' ) )
						)
						: h( 'span', { className: 'bla-kpi__vs' }, prev ? __( 'No earlier data to compare', 'blue-lens-analytics' ) : ' ' ),
					h( C.Sparkline, { values: data.series.map( ( d ) => metricValue( key, d ) ) } )
				);
			} )
		);
	}

	function bucket( series, days ) {
		if ( days <= 92 ) {
			return series;
		}
		const size = days > 400 ? 'month' : 'week';
		const out = [];
		let cur = null;
		series.forEach( ( d, i ) => {
			const k = size === 'month' ? d.day.slice( 0, 7 ) : Math.floor( i / 7 );
			if ( ! cur || cur.k !== k ) {
				cur = { k, day: d.day };
				out.push( cur );
			}
			Object.keys( d ).forEach( ( m ) => {
				if ( m !== 'day' ) {
					cur[ m ] = ( cur[ m ] || 0 ) + d[ m ];
				}
			} );
		} );
		return out;
	}

	function TrendCard( { data, range } ) {
		const [ metric, setMetric ] = useState( 'visitors' );
		const days = data.series.length;
		const cur = bucket( data.series, days );
		const prev = data.compare ? bucket( data.compare.series, days ) : null;
		const def = METRICS[ metric ];
		const f = metricFormat( metric );
		const hasData = data.series.some( ( d ) => d.sessions > 0 || d.pageviews > 0 );
		const granularity = days > 400 ? __( 'Monthly', 'blue-lens-analytics' ) : days > 92 ? __( 'Weekly', 'blue-lens-analytics' ) : __( 'Daily', 'blue-lens-analytics' );

		return h( ViewCard, {
			title: __( 'Traffic trend', 'blue-lens-analytics' ),
			sub: granularity + ' · ' + def.label.toLowerCase(),
			span: 8,
			hasData,
			exportHref: exportUrl( 'daily', '', range ),
			extraActions: h( Segment, {
				label: __( 'Metric', 'blue-lens-analytics' ),
				value: metric,
				onChange: setMetric,
				options: [
					[ 'visitors', __( 'Visitors', 'blue-lens-analytics' ) ],
					[ 'sessions', __( 'Sessions', 'blue-lens-analytics' ) ],
					[ 'pageviews', __( 'Pageviews', 'blue-lens-analytics' ) ],
					[ 'conversions', __( 'Conversions', 'blue-lens-analytics' ) ],
				],
			} ),
			chart: () =>
				h( C.AreaChart, {
					labels: cur.map( ( d ) => d.day ),
					series: [ { name: def.label, color: 'var(--bla-s1)', values: cur.map( ( d ) => metricValue( metric, d ) ) } ],
					compare: prev ? { name: __( 'Comparison period', 'blue-lens-analytics' ), values: prev.map( ( d ) => metricValue( metric, d ) ), labels: prev.map( ( d ) => d.day ) } : null,
					format: f,
					ariaLabel: sprintf( /* translators: 1: metric, 2: date range. */ __( '%1$s trend, %2$s', 'blue-lens-analytics' ), def.label, rangeText( range ) ),
				} ),
			table: () =>
				h( C.DataTable, {
					caption: __( 'Traffic by date', 'blue-lens-analytics' ),
					columns: [
						{ key: 'day', label: __( 'Date', 'blue-lens-analytics' ), format: ( v ) => fmt.date( v, true ), sortValue: ( r ) => r.day },
						{ key: 'visitors', label: __( 'Visitors', 'blue-lens-analytics' ), num: true, format: fmt.number },
						{ key: 'sessions', label: __( 'Sessions', 'blue-lens-analytics' ), num: true, format: fmt.number },
						{ key: 'pageviews', label: __( 'Pageviews', 'blue-lens-analytics' ), num: true, format: fmt.number },
						{ key: 'conversions', label: __( 'Conversions', 'blue-lens-analytics' ), num: true, format: fmt.number },
					],
					rows: cur.slice().reverse(),
					rowKey: ( r ) => r.day,
				} ),
		} );
	}

	function RealtimeCard() {
		const [ tick, setTick ] = useState( 0 );
		useEffect( () => {
			const id = setInterval( () => document.visibilityState === 'visible' && setTick( ( t ) => t + 1 ), 30000 );
			return () => clearInterval( id );
		}, [] );
		const { data, error } = useApi( '/reports/realtime', {}, tick );

		const feedIcon = ( e ) => ( { page_view: 'eye', form_submit: 'form', form_start: 'form', contact_click: 'message', file_download: 'download', outbound_click: 'link', video_start: 'play', site_search: 'search', rage_click: 'alert', dead_click: 'pointer', ad_impression: 'megaphone', ad_click: 'megaphone' }[ e ] || 'spark' );

		return h(
			Card,
			{ title: __( 'Real-time', 'blue-lens-analytics' ), sub: __( 'Visitors active in the last 5 minutes', 'blue-lens-analytics' ), span: 4 },
			error && ! data && h( ErrorBox, { message: error } ),
			data &&
				h(
					'div',
					{ style: { display: 'grid', gap: 16 } },
					h(
						'div',
						{ className: 'bla-live' },
						h( 'span', { className: 'bla-live__dot', 'aria-hidden': 'true' } ),
						h( 'span', { className: 'bla-live__value' }, fmt.number( data.active_visitors ) ),
						h( 'span', { className: 'bla-live__label' }, sprintf( /* translators: %s: number of pageviews. */ __( '%s pageviews in the last 30 minutes', 'blue-lens-analytics' ), fmt.number( data.pageviews_30m ) ) )
					),
					h( C.Columns, { values: data.pageviews_per_minute, label: __( 'Pageviews per minute, last 30 minutes', 'blue-lens-analytics' ) } ),
					data.feed.length
						? h(
							'ul',
							{ className: 'bla-feed', 'aria-label': __( 'Recent activity', 'blue-lens-analytics' ) },
							data.feed.map( ( e, i ) =>
								h(
									'li',
									{ key: i },
									h( 'span', { className: 'bla-feed__icon' }, h( Icon, { name: feedIcon( e.event ), size: 14 } ) ),
									h( 'span', { className: 'bla-feed__what' }, eventLabel( e.event ).replace( /s$/, '' ), h( 'small', null, ( e.country ? flag( e.country ) + ' ' : '' ) + e.path ) ),
									h( 'span', { className: 'bla-feed__when' }, e.seconds < 60 ? __( 'now', 'blue-lens-analytics' ) : sprintf( /* translators: %d: minutes. */ __( '%dm ago', 'blue-lens-analytics' ), Math.floor( e.seconds / 60 ) ) )
								)
							)
						)
						: h( 'p', { className: 'bla-card__sub' }, __( 'Waiting for visitors…', 'blue-lens-analytics' ) )
				)
		);
	}

	function ChannelsCard( { range, refreshKey, span } ) {
		const { data, error } = useApi( '/reports/dimension', { dimension: 'channel', from: range.from, to: range.to, limit: 20 }, refreshKey );
		const rows = data ? data.rows : [];
		const items = useMemo( () => {
			const coloured = [];
			let other = 0;
			rows.forEach( ( r ) => {
				if ( CHANNELS[ r.value ] && CHANNELS[ r.value ][ 1 ] && coloured.length < 5 ) {
					coloured.push( { name: channelLabel( r.value ), value: r.sessions, color: channelColor( r.value ) } );
				} else {
					other += r.sessions;
				}
			} );
			if ( other > 0 ) {
				coloured.push( { name: __( 'Other', 'blue-lens-analytics' ), value: other, color: 'var(--bla-other)' } );
			}
			return coloured;
		}, [ data ] );

		return h(
			Fragment,
			null,
			error && ! data && h( Card, { title: __( 'Channels', 'blue-lens-analytics' ), span }, h( ErrorBox, { message: error } ) ),
			( data || ! error ) &&
				h( ViewCard, {
					title: __( 'Channels', 'blue-lens-analytics' ),
					sub: __( 'Sessions by traffic source type', 'blue-lens-analytics' ),
					span,
					hasData: rows.length > 0,
					exportHref: exportUrl( 'dimension', 'channel', range ),
					chart: () => h( C.Donut, { items, format: fmt.compact, centerLabel: __( 'sessions', 'blue-lens-analytics' ), ariaLabel: __( 'Sessions by channel', 'blue-lens-analytics' ) } ),
					table: () => h( C.DataTable, { caption: __( 'Sessions by channel', 'blue-lens-analytics' ), shareKey: 'sessions', columns: channelColumns(), rows: rows.map( ( r ) => ( { ...r, color: channelColor( r.value ) } ) ), rowKey: ( r ) => r.value } ),
				} )
		);
	}

	function channelColumns() {
		return [
			{ key: 'value', label: __( 'Channel', 'blue-lens-analytics' ), render: ( r ) => h( Fragment, null, h( 'span', { className: 'bla-table__swatch', style: { background: channelColor( r.value ) } } ), channelLabel( r.value ) ), sortValue: ( r ) => channelLabel( r.value ) },
			...sessionColumns(),
		];
	}

	function sessionColumns() {
		return [
			{ key: 'sessions', label: __( 'Sessions', 'blue-lens-analytics' ), num: true, format: fmt.number },
			{ key: 'visitors', label: __( 'Visitors', 'blue-lens-analytics' ), num: true, format: fmt.number },
			{ key: 'engagement_rate', label: __( 'Engaged', 'blue-lens-analytics' ), num: true, format: fmt.percent },
			{ key: 'avg_engaged_time', label: __( 'Avg. time', 'blue-lens-analytics' ), num: true, format: fmt.duration },
			{ key: 'conversions', label: __( 'Conversions', 'blue-lens-analytics' ), num: true, format: fmt.number },
			{ key: 'conversion_rate', label: __( 'Conv. rate', 'blue-lens-analytics' ), num: true, format: fmt.percent },
			{ key: 'revenue', label: __( 'Revenue', 'blue-lens-analytics' ), num: true, format: fmt.money },
		];
	}

	/** Top values of a session dimension as a bar list, with a full table view. */
	function DimensionCard( { dimension, title, sub, range, refreshKey, span, limit = 8, icon, emptyText, extraActions, full } ) {
		const [ more, setMore ] = useState( false );
		const { data, error } = useApi( '/reports/dimension', { dimension, from: range.from, to: range.to, limit: more ? 100 : full ? 25 : limit }, refreshKey );
		const rows = data ? data.rows : [];
		const label = ( v ) => dimensionLabel( dimension, v );

		if ( error && ! data ) {
			return h( Card, { title, span }, h( ErrorBox, { message: error } ) );
		}

		const table = () =>
			h(
				Fragment,
				null,
				h( C.DataTable, {
					caption: title,
					shareKey: 'sessions',
					columns: [ { key: 'value', label: title, render: ( r ) => ( icon ? icon( r.value ) + ' ' : '' ) + label( r.value ), sortValue: ( r ) => label( r.value ) }, ...sessionColumns() ],
					rows,
					rowKey: ( r ) => r.value,
				} ),
				data && data.total > rows.length && h( 'div', { className: 'bla-card__foot' }, h( 'span', null, sprintf( /* translators: 1: shown, 2: total. */ __( 'Showing %1$s of %2$s', 'blue-lens-analytics' ), fmt.number( rows.length ), fmt.number( data.total ) ) ), ! more && h( 'button', { type: 'button', className: 'bla-link', onClick: () => setMore( true ) }, __( 'Show more', 'blue-lens-analytics' ) ) )
			);

		if ( full ) {
			return h( Card, { title, sub, span, actions: h( Fragment, null, extraActions, rows.length > 0 && h( IconButton, { icon: 'download', label: __( 'Export CSV', 'blue-lens-analytics' ), href: exportUrl( 'dimension', dimension, range ) } ) ) }, rows.length ? table() : h( Empty, { title: emptyText } ) );
		}

		return h( ViewCard, {
			title,
			sub,
			span,
			hasData: rows.length > 0,
			emptyText,
			extraActions,
			exportHref: exportUrl( 'dimension', dimension, range ),
			chart: () =>
				h(
					Fragment,
					null,
					h( C.BarList, { rows: rows.slice( 0, limit ).map( ( r ) => ( { key: r.value, label: label( r.value ), icon: icon ? icon( r.value ) : '', value: r.sessions } ) ), total: data ? rows.reduce( ( a, b ) => a + b.sessions, 0 ) : 0 } ),
					h( 'div', { className: 'bla-card__foot', style: { marginTop: 12 } }, h( 'span', null, __( 'Sessions', 'blue-lens-analytics' ) ), data && data.total > limit && h( 'span', null, sprintf( /* translators: %s: number of items. */ __( '%s in total', 'blue-lens-analytics' ), fmt.number( data.total ) ) ) )
				),
			table,
		} );
	}

	function TopPagesCard( { range, refreshKey, span, limit = 8, sortBy, title, sub } ) {
		const { data, error } = useApi( '/reports/content', { group: 'page', from: range.from, to: range.to, limit: sortBy ? 100 : limit }, refreshKey );
		let rows = data ? data.rows : [];
		if ( sortBy ) {
			rows = rows.slice().sort( ( a, b ) => b[ sortBy ] - a[ sortBy ] ).filter( ( r ) => r[ sortBy ] > 0 );
		}
		const metric = sortBy || 'pageviews';

		if ( error && ! data ) {
			return h( Card, { title, span }, h( ErrorBox, { message: error } ) );
		}

		return h( ViewCard, {
			title: title || __( 'Top pages', 'blue-lens-analytics' ),
			sub: sub || __( 'Most viewed pages', 'blue-lens-analytics' ),
			span,
			hasData: rows.length > 0,
			exportHref: exportUrl( 'content', 'page', range ),
			chart: () => h( C.BarList, { rows: rows.slice( 0, limit ).map( ( r ) => ( { key: r.key, label: r.label, title: r.path, href: pageUrl( r.path ), value: r[ metric ] } ) ), total: rows.reduce( ( a, b ) => a + b[ metric ], 0 ) } ),
			table: () => h( C.DataTable, { caption: title || __( 'Top pages', 'blue-lens-analytics' ), shareKey: metric, columns: contentColumns( true ), rows: rows.map( ( r ) => ( { ...r, sub: r.path } ) ), rowKey: ( r ) => r.key } ),
		} );
	}

	function contentColumns( isPage ) {
		return [
			{ key: 'label', label: isPage ? __( 'Page', 'blue-lens-analytics' ) : __( 'Group', 'blue-lens-analytics' ) },
			{ key: 'pageviews', label: __( 'Views', 'blue-lens-analytics' ), num: true, format: fmt.number },
			{ key: 'visitors', label: __( 'Visitors', 'blue-lens-analytics' ), num: true, format: fmt.number },
			{ key: 'avg_engaged_time', label: __( 'Avg. time', 'blue-lens-analytics' ), num: true, format: fmt.duration },
			{ key: 'entrances', label: __( 'Entrances', 'blue-lens-analytics' ), num: true, format: fmt.number },
			{ key: 'exit_rate', label: __( 'Exit rate', 'blue-lens-analytics' ), num: true, format: fmt.percent },
			{ key: 'conversions', label: __( 'Conversions', 'blue-lens-analytics' ), num: true, format: fmt.number },
		];
	}

	function DevicesCard( { range, refreshKey, span } ) {
		const { data, error } = useApi( '/reports/dimension', { dimension: 'device', from: range.from, to: range.to, limit: 6 }, refreshKey );
		const rows = data ? data.rows : [];
		const total = rows.reduce( ( a, b ) => a + b.sessions, 0 );
		if ( error && ! data ) {
			return h( Card, { title: __( 'Devices', 'blue-lens-analytics' ), span }, h( ErrorBox, { message: error } ) );
		}
		return h( ViewCard, {
			title: __( 'Devices', 'blue-lens-analytics' ),
			sub: __( 'Share of sessions', 'blue-lens-analytics' ),
			span,
			hasData: rows.length > 0,
			exportHref: exportUrl( 'dimension', 'device', range ),
			chart: () =>
				h(
					'div',
					{ className: 'bla-rings' },
					[ 'desktop', 'mobile', 'tablet' ].map( ( k ) => {
						const row = rows.find( ( r ) => r.value === k );
						return h( C.Ring, { key: k, value: row ? row.sessions / total : 0, label: DEVICE[ k ][ 0 ], sub: sprintf( /* translators: %s: sessions. */ __( '%s sessions', 'blue-lens-analytics' ), fmt.compact( row ? row.sessions : 0 ) ), color: 'var(--bla-' + DEVICE[ k ][ 1 ] + ')' } );
					} )
				),
			table: () => h( C.DataTable, { caption: __( 'Sessions by device', 'blue-lens-analytics' ), shareKey: 'sessions', columns: [ { key: 'value', label: __( 'Device', 'blue-lens-analytics' ), render: ( r ) => dimensionLabel( 'device', r.value ) }, ...sessionColumns() ], rows, rowKey: ( r ) => r.value } ),
		} );
	}

	function GoalsCard( { range, refreshKey, span } ) {
		const { data, error } = useApi( '/reports/events', { from: range.from, to: range.to }, refreshKey );
		const rows = data ? data.rows.filter( ( r ) => r.is_conversion ) : [];
		if ( error && ! data ) {
			return h( Card, { title: __( 'Conversions', 'blue-lens-analytics' ), span }, h( ErrorBox, { message: error } ) );
		}
		return h( ViewCard, {
			title: __( 'Conversions', 'blue-lens-analytics' ),
			sub: __( 'Actions that count as results', 'blue-lens-analytics' ),
			span,
			hasData: rows.length > 0,
			emptyText: __( 'No conversions in this period. Successful form submissions count as conversions.', 'blue-lens-analytics' ),
			exportHref: exportUrl( 'events', '', range ),
			chart: () => h( C.BarList, { rows: rows.map( ( r ) => ( { key: r.event, label: eventLabel( r.event ), value: r.events } ) ) } ),
			table: () => h( C.DataTable, { caption: __( 'Conversions', 'blue-lens-analytics' ), columns: [ { key: 'event', label: __( 'Conversion', 'blue-lens-analytics' ), render: ( r ) => eventLabel( r.event ) }, { key: 'events', label: __( 'Count', 'blue-lens-analytics' ), num: true, format: fmt.number }, { key: 'sessions', label: __( 'Sessions', 'blue-lens-analytics' ), num: true, format: fmt.number }, { key: 'value', label: __( 'Value', 'blue-lens-analytics' ), num: true, format: fmt.money } ], rows, rowKey: ( r ) => r.event } ),
		} );
	}

	function Welcome( { onRefresh } ) {
		return h(
			Card,
			{ span: 12, className: 'bla-welcome-card' },
			h(
				'div',
				{ className: 'bla-welcome' },
				h(
					'div',
					null,
					h( 'h2', null, __( 'Welcome to Blue Lens Analytics', 'blue-lens-analytics' ) ),
					h( 'p', null, __( 'Your dashboard fills up as visitors arrive. Summaries update every hour, or straight away when you press Refresh.', 'blue-lens-analytics' ) )
				),
				h(
					'ol',
					{ className: 'bla-steps' },
					h( 'li', { className: boot.trackingEnabled ? 'is-done' : '' }, h( 'div', null, h( 'strong', null, __( 'Tracking is on', 'blue-lens-analytics' ) ), __( 'Visitors are being measured, cookie-free by default.', 'blue-lens-analytics' ) ) ),
					h( 'li', null, h( 'div', null, h( 'strong', null, __( 'Visit your website in a private window', 'blue-lens-analytics' ) ), __( 'Administrators are not tracked, so use a private or incognito window to see yourself arrive.', 'blue-lens-analytics' ) ) ),
					h( 'li', null, h( 'div', null, h( 'strong', null, __( 'Refresh the dashboard', 'blue-lens-analytics' ) ), h( 'button', { type: 'button', className: 'bla-link', onClick: onRefresh }, __( 'Refresh now', 'blue-lens-analytics' ) ) ) ),
					boot.canManage && h( 'li', null, h( 'div', null, h( 'strong', null, __( 'Add location data (optional)', 'blue-lens-analytics' ) ), h( 'a', { className: 'bla-link', href: boot.links.settings + '#location' }, __( 'Set up a free GeoIP database', 'blue-lens-analytics' ) ) ) )
				)
			)
		);
	}

	/* ================================================================== */
	/* Pages                                                               */
	/* ================================================================== */

	function Grid( { busy, children } ) {
		return h( 'div', { className: 'bla-grid' + ( busy ? ' is-refreshing' : '' ) }, children );
	}

	function OverviewPage( { range, compare, refreshKey, prefs, onRefresh, go } ) {
		const { data, error, loading } = useApi( '/reports/overview', { from: range.from, to: range.to, compare }, refreshKey );
		const layout = layoutFor( 'overview', prefs ).filter( ( w ) => w.on );
		const common = { range, refreshKey };

		if ( error && ! data ) {
			return h( Grid, null, h( Card, { span: 12 }, h( ErrorBox, { message: error } ) ) );
		}
		if ( ! data ) {
			return h( Grid, { busy: true }, h( Card, { span: 12 }, h( 'div', { className: 'bla-boot' }, __( 'Loading…', 'blue-lens-analytics' ) ) ) );
		}

		const empty = ! boot.firstDay && data.totals.sessions === 0 && data.totals.events === 0;

		return h(
			Grid,
			{ busy: loading },
			empty && h( Welcome, { onRefresh } ),
			layout.map( ( w ) => {
				switch ( w.id ) {
					case 'kpis':
						return h( KpiTiles, { key: w.id, data, prefs } );
					case 'trend':
						return h( TrendCard, { key: w.id, data, range } );
					case 'realtime':
						return h( RealtimeCard, { key: w.id } );
					case 'channels':
						return h( ChannelsCard, { key: w.id, ...common, span: w.span } );
					case 'sources':
						return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'source', title: __( 'Top sources', 'blue-lens-analytics' ), sub: __( 'Websites and campaigns sending visitors', 'blue-lens-analytics' ) } );
					case 'top_pages':
						return h( TopPagesCard, { key: w.id, ...common, span: w.span } );
					case 'countries':
						return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'country', title: __( 'Top countries', 'blue-lens-analytics' ), sub: __( 'Your source markets', 'blue-lens-analytics' ), icon: flag, emptyText: __( 'No country data yet. Add a GeoIP database in Settings for locations.', 'blue-lens-analytics' ) } );
					case 'devices':
						return h( DevicesCard, { key: w.id, ...common, span: w.span } );
					case 'goals':
						return h( GoalsCard, { key: w.id, ...common, span: w.span } );
					case 'audit':
						return h( AuditSummaryCard, { key: w.id, span: w.span, refreshKey, go } );
				}
				return null;
			} )
		);
	}

	function AcquisitionPage( { range, refreshKey, prefs } ) {
		const common = { range, refreshKey };
		return h(
			Grid,
			null,
			layoutFor( 'acquisition', prefs )
				.filter( ( w ) => w.on )
				.map( ( w ) => {
					switch ( w.id ) {
						case 'channels_table':
							return h( ChannelsTableCard, { key: w.id, ...common } );
						case 'sources':
							return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'source', title: __( 'Sources', 'blue-lens-analytics' ), sub: __( 'utm_source, or the referring domain', 'blue-lens-analytics' ) } );
						case 'campaigns':
							return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'campaign', title: __( 'Campaigns', 'blue-lens-analytics' ), sub: __( 'From utm_campaign tags', 'blue-lens-analytics' ), emptyText: __( 'No tagged campaigns yet. Add ?utm_campaign= to links in newsletters, ads and social posts.', 'blue-lens-analytics' ) } );
						case 'referrers':
							return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'referrer', title: __( 'Referring websites', 'blue-lens-analytics' ), sub: __( 'Other sites linking to you', 'blue-lens-analytics' ) } );
						case 'landing':
							return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'landing_page', title: __( 'Landing pages', 'blue-lens-analytics' ), sub: __( 'Where visits start', 'blue-lens-analytics' ) } );
						case 'crawlers':
							return h( CrawlersCard, { key: w.id, ...common } );
					}
					return null;
				} )
		);
	}

	function ChannelsTableCard( { range, refreshKey } ) {
		const { data, error } = useApi( '/reports/dimension', { dimension: 'channel', from: range.from, to: range.to, limit: 20 }, refreshKey );
		const rows = data ? data.rows.map( ( r ) => ( { ...r, color: channelColor( r.value ) } ) ) : [];
		return h(
			Card,
			{ title: __( 'Channels', 'blue-lens-analytics' ), sub: __( 'How each type of traffic performs', 'blue-lens-analytics' ), span: 12, actions: rows.length > 0 && h( IconButton, { icon: 'download', label: __( 'Export CSV', 'blue-lens-analytics' ), href: exportUrl( 'dimension', 'channel', range ) } ) },
			error && ! data ? h( ErrorBox, { message: error } ) : rows.length ? h( C.DataTable, { caption: __( 'Channels', 'blue-lens-analytics' ), shareKey: 'sessions', columns: channelColumns(), rows, rowKey: ( r ) => r.value, initialSort: { key: 'sessions', dir: 'desc' } } ) : h( Empty )
		);
	}

	function CrawlersCard( { range, refreshKey } ) {
		const { data, error } = useApi( '/reports/crawlers', { from: range.from, to: range.to }, refreshKey );
		const types = { search: __( 'Search engine', 'blue-lens-analytics' ), ai: __( 'AI', 'blue-lens-analytics' ), seo: __( 'SEO tool', 'blue-lens-analytics' ), social: __( 'Link preview', 'blue-lens-analytics' ) };
		return h(
			Card,
			{ title: __( 'Search & AI crawlers', 'blue-lens-analytics' ), sub: __( 'Bots that read your site (excluded from visitor numbers)', 'blue-lens-analytics' ), span: 12 },
			error && ! data && h( ErrorBox, { message: error } ),
			data &&
				( data.crawlers.length
					? h(
						Fragment,
						null,
						h( C.DataTable, {
							caption: __( 'Crawlers', 'blue-lens-analytics' ),
							shareKey: 'hits',
							columns: [
								{ key: 'crawler', label: __( 'Crawler', 'blue-lens-analytics' ) },
								{ key: 'type', label: __( 'Type', 'blue-lens-analytics' ), render: ( r ) => h( 'span', { className: 'bla-chip' + ( r.type === 'ai' ? ' bla-chip--accent' : '' ) }, types[ r.type ] || r.type ) },
								{ key: 'hits', label: __( 'Visits', 'blue-lens-analytics' ), num: true, format: fmt.number },
								{ key: 'pages', label: __( 'Pages', 'blue-lens-analytics' ), num: true, format: fmt.number },
								{ key: 'not_found', label: __( '404s', 'blue-lens-analytics' ), num: true, format: fmt.number },
							],
							rows: data.crawlers,
							rowKey: ( r ) => r.crawler,
						} ),
						data.not_found.length > 0 &&
							h(
								'div',
								{ style: { marginTop: 18 } },
								h( 'h3', { className: 'bla-card__title', style: { fontSize: 13, marginBottom: 10 } }, __( 'Broken pages crawlers found (404)', 'blue-lens-analytics' ) ),
								h( C.BarList, { rows: data.not_found.slice( 0, 8 ).map( ( r ) => ( { key: r.path, label: r.path, value: r.hits } ) ) } )
							)
					)
					: h( Empty, { title: __( 'No crawler visits recorded in this period', 'blue-lens-analytics' ), icon: 'bot' } ) )
		);
	}

	function AudiencePage( { range, refreshKey, prefs } ) {
		const [ geo, setGeo ] = useState( 'country' );
		const common = { range, refreshKey };
		const langIcon = () => '';
		return h(
			Grid,
			null,
			layoutFor( 'audience', prefs )
				.filter( ( w ) => w.on )
				.map( ( w ) => {
					switch ( w.id ) {
						case 'geo':
							return h( DimensionCard, {
								key: w.id + geo,
								...common,
								span: 12,
								full: true,
								dimension: geo,
								title: { country: __( 'Countries', 'blue-lens-analytics' ), region: __( 'Regions', 'blue-lens-analytics' ), city: __( 'Cities', 'blue-lens-analytics' ) }[ geo ],
								sub: __( 'Where your visitors are, from an anonymised location lookup', 'blue-lens-analytics' ),
								icon: geo === 'country' ? flag : ( v ) => flag( v.split( ' · ' )[ 0 ] ),
								emptyText: __( 'No location data yet. Add a free GeoIP database in Settings → Location.', 'blue-lens-analytics' ),
								extraActions: h( Segment, { label: __( 'Location level', 'blue-lens-analytics' ), value: geo, onChange: setGeo, options: [ [ 'country', __( 'Country', 'blue-lens-analytics' ) ], [ 'region', __( 'Region', 'blue-lens-analytics' ) ], [ 'city', __( 'City', 'blue-lens-analytics' ) ] ] } ),
							} );
						case 'devices':
							return h( DevicesCard, { key: w.id, ...common, span: w.span } );
						case 'screens':
							return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'viewport', title: __( 'Screen sizes', 'blue-lens-analytics' ), sub: __( 'Browser window width groups', 'blue-lens-analytics' ) } );
						case 'browsers':
							return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'browser', title: __( 'Browsers', 'blue-lens-analytics' ) } );
						case 'os':
							return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'os', title: __( 'Operating systems', 'blue-lens-analytics' ) } );
						case 'languages':
							return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'language', title: __( 'Languages', 'blue-lens-analytics' ), sub: __( 'Browser language settings', 'blue-lens-analytics' ), icon: langIcon } );
						case 'visitor_type':
							return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'visitor_type', title: __( 'New vs returning', 'blue-lens-analytics' ), sub: __( 'Only measurable in enhanced mode, after visitor consent', 'blue-lens-analytics' ) } );
					}
					return null;
				} )
		);
	}

	function ContentPage( { range, refreshKey, prefs } ) {
		const common = { range, refreshKey };
		return h(
			Grid,
			null,
			layoutFor( 'content', prefs )
				.filter( ( w ) => w.on )
				.map( ( w ) => {
					switch ( w.id ) {
						case 'content_table':
							return h( ContentTableCard, { key: w.id, ...common } );
						case 'entry_pages':
							return h( DimensionCard, { key: w.id, ...common, span: w.span, dimension: 'landing_page', title: __( 'Entry pages', 'blue-lens-analytics' ), sub: __( 'First page of each visit (sessions)', 'blue-lens-analytics' ) } );
						case 'exit_pages':
							return h( TopPagesCard, { key: w.id, ...common, span: w.span, sortBy: 'exits', title: __( 'Exit pages', 'blue-lens-analytics' ), sub: __( 'Last page before visitors left (exits)', 'blue-lens-analytics' ) } );
					}
					return null;
				} )
		);
	}

	function ContentTableCard( { range, refreshKey } ) {
		const [ group, setGroup ] = useState( 'page' );
		const [ limit, setLimit ] = useState( 25 );
		const { data, error } = useApi( '/reports/content', { group, from: range.from, to: range.to, limit }, refreshKey );
		const rows = data ? data.rows.map( ( r ) => ( group === 'page' ? { ...r, sub: r.path } : r ) ) : [];
		const groups = [ [ 'page', __( 'Pages', 'blue-lens-analytics' ) ], [ 'post_type', __( 'Content types', 'blue-lens-analytics' ) ], [ 'author', __( 'Authors', 'blue-lens-analytics' ) ], ...boot.taxonomies.map( ( t ) => [ 'tax:' + t.name, t.label ] ), [ 'age', __( 'Content age', 'blue-lens-analytics' ) ] ];

		return h(
			Card,
			{
				title: __( 'Content performance', 'blue-lens-analytics' ),
				sub: __( 'Views, attention and results for each piece of content', 'blue-lens-analytics' ),
				span: 12,
				actions: h(
					Fragment,
					null,
					h(
						'select',
						{ className: 'bla-select', value: group, onChange: ( e ) => setGroup( e.target.value ), 'aria-label': __( 'Group content by', 'blue-lens-analytics' ) },
						groups.map( ( [ v, l ] ) => h( 'option', { key: v, value: v }, l ) )
					),
					rows.length > 0 && h( IconButton, { icon: 'download', label: __( 'Export CSV', 'blue-lens-analytics' ), href: exportUrl( 'content', group, range ) } )
				),
			},
			error && ! data
				? h( ErrorBox, { message: error } )
				: rows.length
					? h(
						Fragment,
						null,
						h( C.DataTable, { caption: __( 'Content performance', 'blue-lens-analytics' ), shareKey: 'pageviews', columns: contentColumns( group === 'page' ), rows, rowKey: ( r ) => r.key } ),
						rows.length >= limit && limit < 500 && h( 'div', { className: 'bla-card__foot', style: { marginTop: 10 } }, h( 'span', null ), h( 'button', { type: 'button', className: 'bla-link', onClick: () => setLimit( 200 ) }, __( 'Show more', 'blue-lens-analytics' ) ) )
					)
					: h( Empty )
		);
	}

	function EngagementPage( { range, refreshKey, prefs } ) {
		const { data, error, loading } = useApi( '/reports/events', { from: range.from, to: range.to }, refreshKey );
		const allRows = data ? data.rows : [];
		const rows = allRows.filter( ( r ) => r.event !== 'page_engagement' ); // Internal reading-time updates.
		const count = ( n ) => ( rows.find( ( r ) => r.event === n ) || { events: 0 } ).events;

		const stat = ( label, value, sub, icon ) =>
			h(
				'div',
				{ className: 'bla-stat', key: label },
				h( 'div', { className: 'bla-stat__label' }, h( Icon, { name: icon, size: 14 } ), ' ', label ),
				h( 'div', { className: 'bla-stat__value' }, value ),
				sub && h( 'div', { className: 'bla-stat__sub' }, sub )
			);

		const started = count( 'form_start' );
		const submitted = count( 'form_submit' );
		const plays = count( 'video_start' );

		return h(
			Grid,
			{ busy: loading },
			error && ! data && h( Card, { span: 12 }, h( ErrorBox, { message: error } ) ),
			layoutFor( 'engagement', prefs )
				.filter( ( w ) => w.on )
				.map( ( w ) => {
					if ( w.id === 'summary' ) {
						return h(
							Card,
							{ key: w.id, title: __( 'Interaction summary', 'blue-lens-analytics' ), sub: __( 'What visitors did on your pages', 'blue-lens-analytics' ), span: 12 },
							h(
								'div',
								{ className: 'bla-stats' },
								stat( __( 'Forms submitted', 'blue-lens-analytics' ), fmt.number( submitted ), started ? sprintf( /* translators: %s: percentage. */ __( '%s of started forms', 'blue-lens-analytics' ), fmt.percent( ratio( submitted, started ) ) ) : __( 'No forms started', 'blue-lens-analytics' ), 'form' ),
								stat( __( 'Forms abandoned', 'blue-lens-analytics' ), fmt.number( count( 'form_abandon' ) ), __( 'Started but not sent', 'blue-lens-analytics' ), 'alert' ),
								stat( __( 'Contact taps', 'blue-lens-analytics' ), fmt.number( count( 'contact_click' ) ), __( 'Phone, email, WhatsApp', 'blue-lens-analytics' ), 'message' ),
								stat( __( 'Downloads', 'blue-lens-analytics' ), fmt.number( count( 'file_download' ) ), __( 'Brochures and files', 'blue-lens-analytics' ), 'download' ),
								stat( __( 'Outbound clicks', 'blue-lens-analytics' ), fmt.number( count( 'outbound_click' ) ), __( 'Links to other sites', 'blue-lens-analytics' ), 'link' ),
								stat( __( 'Video plays', 'blue-lens-analytics' ), fmt.number( plays ), plays ? sprintf( /* translators: %s: percentage. */ __( '%s watched to the end', 'blue-lens-analytics' ), fmt.percent( ratio( count( 'video_complete' ), plays ) ) ) : __( 'No plays', 'blue-lens-analytics' ), 'play' ),
								stat( __( 'Site searches', 'blue-lens-analytics' ), fmt.number( count( 'site_search' ) ), null, 'search' ),
								stat( __( 'Rage clicks', 'blue-lens-analytics' ), fmt.number( count( 'rage_click' ) ), __( 'Signs of frustration', 'blue-lens-analytics' ), 'alert' ),
								stat( __( 'Dead clicks', 'blue-lens-analytics' ), fmt.number( count( 'dead_click' ) ), __( 'Clicks with no effect', 'blue-lens-analytics' ), 'pointer' ),
								stat( __( '404 pages', 'blue-lens-analytics' ), fmt.number( count( 'page_not_found' ) ), __( 'Broken links visited', 'blue-lens-analytics' ), 'alert' )
							)
						);
					}
					if ( w.id === 'events_table' ) {
						return h(
							Card,
							{ key: w.id, title: __( 'All events', 'blue-lens-analytics' ), sub: __( 'Every tracked action in this period', 'blue-lens-analytics' ), span: 12, actions: rows.length > 0 && h( IconButton, { icon: 'download', label: __( 'Export CSV', 'blue-lens-analytics' ), href: exportUrl( 'events', '', range ) } ) },
							rows.length
								? h( C.DataTable, {
									caption: __( 'Events', 'blue-lens-analytics' ),
									shareKey: 'events',
									rows: rows.map( ( r ) => ( { ...r, label: eventLabel( r.event ), sub: r.event } ) ),
									rowKey: ( r ) => r.event,
									columns: [
										{ key: 'label', label: __( 'Event', 'blue-lens-analytics' ) },
										{ key: 'category', label: __( 'Category', 'blue-lens-analytics' ), render: ( r ) => h( 'span', { className: 'bla-chip' }, r.category || '—' ) },
										{ key: 'events', label: __( 'Count', 'blue-lens-analytics' ), num: true, format: fmt.number },
										{ key: 'sessions', label: __( 'Sessions', 'blue-lens-analytics' ), num: true, format: fmt.number },
										{ key: 'value', label: __( 'Value', 'blue-lens-analytics' ), num: true, format: ( v ) => ( v ? fmt.money( v ) : '—' ) },
										{ key: 'is_conversion', label: __( 'Conversion', 'blue-lens-analytics' ), render: ( r ) => ( r.is_conversion ? h( 'span', { className: 'bla-chip bla-chip--good' }, h( Icon, { name: 'check', size: 12 } ), __( 'Yes', 'blue-lens-analytics' ) ) : '' ), sortValue: ( r ) => ( r.is_conversion ? 1 : 0 ) },
									],
								} )
								: h( Empty )
						);
					}
					return null;
				} )
		);
	}

	/* ================================================================== */
	/* Shared blocks for Ads and Site Audit                               */
	/* ================================================================== */

	/** A row of headline numbers inside one card, separated by dividers. */
	function MetricStrip( { items } ) {
		return h(
			'div',
			{ className: 'bla-metrics', 'data-count': items.length },
			items.map( ( it ) =>
				h(
					'div',
					{ className: 'bla-metric', key: it.label },
					h( 'div', { className: 'bla-metric__label' }, it.label ),
					h( 'div', { className: 'bla-metric__value' + ( it.tone ? ' is-' + it.tone : '' ) }, it.value ),
					it.sub && h( 'div', { className: 'bla-metric__sub' }, it.sub )
				)
			)
		);
	}

	/** Side panel used for page details. */
	function SidePanel( { title, onClose, children } ) {
		const ref = useRef( null );
		useEffect( () => {
			const onKey = ( e ) => e.key === 'Escape' && onClose();
			document.addEventListener( 'keydown', onKey );
			const first = ref.current && ref.current.querySelector( 'button' );
			if ( first ) {
				first.focus();
			}
			return () => document.removeEventListener( 'keydown', onKey );
		}, [] );
		return h(
			Fragment,
			null,
			h( 'div', { className: 'bla-drawer-backdrop', onClick: onClose } ),
			h(
				'aside',
				{ className: 'bla-drawer bla-drawer--wide', role: 'dialog', 'aria-modal': 'true', 'aria-label': title, ref },
				h( 'div', { className: 'bla-drawer__head' }, h( 'h2', null, title ), h( IconButton, { icon: 'x', label: __( 'Close', 'blue-lens-analytics' ), onClick: onClose } ) ),
				h( 'div', { className: 'bla-drawer__body' }, children )
			)
		);
	}

	const pct = ( v ) => fmt.percent( v / 100 );

	/* ================================================================== */
	/* Ads (Local Ads by Bernard)                                          */
	/* ================================================================== */

	function AdsPage( { range, refreshKey, prefs } ) {
		const { data, error, loading } = useApi( '/reports/local-ads', { from: range.from, to: range.to }, refreshKey );
		const [ metric, setMetric ] = useState( 'impressions' );

		if ( error && ! data ) {
			return h( Grid, null, h( Card, { span: 12 }, h( ErrorBox, { message: error } ) ) );
		}
		if ( ! data ) {
			return h( Grid, { busy: true }, h( Card, { span: 12 }, h( 'div', { className: 'bla-boot' }, __( 'Loading…', 'blue-lens-analytics' ) ) ) );
		}
		if ( ! data.available ) {
			return h(
				Grid,
				null,
				h( Card, { span: 12 }, h( Empty, { title: __( 'Local Ads by Bernard is not active', 'blue-lens-analytics' ), icon: 'megaphone' }, __( 'Install and activate Local Ads by Bernard to see your popup ads’ impressions, clicks and visitor insights here.', 'blue-lens-analytics' ) ) )
			);
		}

		const t = data.totals;
		const v = data.visits;
		const hasData = t.impressions > 0 || t.clicks > 0;
		const series = bucket( data.daily, data.daily.length );
		const linkedHint = hasData && v.impressions === 0 && v.clicks === 0;

		const adColumns = [
			{ key: 'name', label: __( 'Advertisement', 'blue-lens-analytics' ) },
			{ key: 'status', label: __( 'Status', 'blue-lens-analytics' ), render: ( r ) => h( 'span', { className: 'bla-chip' + ( r.status === 'active' ? ' bla-chip--good' : '' ) }, r.status || '—' ) },
			{ key: 'impressions', label: __( 'Impressions', 'blue-lens-analytics' ), num: true, format: fmt.number },
			{ key: 'clicks', label: __( 'Clicks', 'blue-lens-analytics' ), num: true, format: fmt.number },
			{ key: 'ctr', label: __( 'CTR', 'blue-lens-analytics' ), num: true, format: pct },
		];
		const rateColumns = ( first ) => [
			first,
			{ key: 'impressions', label: __( 'Impressions', 'blue-lens-analytics' ), num: true, format: fmt.number },
			{ key: 'clicks', label: __( 'Clicks', 'blue-lens-analytics' ), num: true, format: fmt.number },
			{ key: 'ctr', label: __( 'CTR', 'blue-lens-analytics' ), num: true, format: pct },
		];

		return h(
			Grid,
			{ busy: loading },
			layoutFor( 'ads', prefs )
				.filter( ( w ) => w.on )
				.map( ( w ) => {
					switch ( w.id ) {
						case 'ads_kpis':
							return h(
								Card,
								{ key: w.id, span: 12, title: __( 'Ad performance', 'blue-lens-analytics' ), sub: __( 'From Local Ads by Bernard, for the selected period', 'blue-lens-analytics' ), actions: h( 'a', { className: 'bla-btn bla-btn--sm', href: boot.localAds.url }, __( 'Open Local Ads', 'blue-lens-analytics' ) ) },
								h( MetricStrip, {
									items: [
										{ label: __( 'Impressions', 'blue-lens-analytics' ), value: fmt.number( t.impressions ), tone: 'accent' },
										{ label: __( 'Clicks', 'blue-lens-analytics' ), value: fmt.number( t.clicks ), tone: 'accent' },
										{ label: __( 'Click-through rate', 'blue-lens-analytics' ), value: pct( t.ctr ), tone: 'accent' },
										{ label: __( 'Visits with an ad click', 'blue-lens-analytics' ), value: fmt.number( v.click_sessions ), sub: __( 'Measured by Blue Lens', 'blue-lens-analytics' ) },
										{
											label: __( 'Of those, converted', 'blue-lens-analytics' ),
											value: fmt.number( v.converting_sessions ),
											sub: v.click_sessions ? sprintf( /* translators: %s: percentage. */ __( '%s conversion rate', 'blue-lens-analytics' ), fmt.percent( v.conversion_rate ) ) : __( 'No ad clicks yet', 'blue-lens-analytics' ),
										},
									],
								} ),
								linkedHint && h( 'p', { className: 'bla-note' }, __( 'Visit insights start from the moment Blue Lens 0.5 and Local Ads 1.0.1 (or newer) are both active. Earlier ad views appear in the totals above only.', 'blue-lens-analytics' ) )
							);
						case 'ads_trend':
							return h( ViewCard, {
								key: w.id,
								title: __( 'Ad trend', 'blue-lens-analytics' ),
								sub: __( 'Impressions and clicks per day', 'blue-lens-analytics' ),
								span: 12,
								hasData,
								emptyText: __( 'No ad impressions in this period.', 'blue-lens-analytics' ),
								exportHref: exportUrl( 'local_ads', '', range ),
								extraActions: h( Segment, {
									label: __( 'Metric', 'blue-lens-analytics' ),
									value: metric,
									onChange: setMetric,
									options: [
										[ 'impressions', __( 'Impressions', 'blue-lens-analytics' ) ],
										[ 'clicks', __( 'Clicks', 'blue-lens-analytics' ) ],
									],
								} ),
								chart: () =>
									h( C.AreaChart, {
										labels: series.map( ( d ) => d.day ),
										series: [ { name: metric === 'clicks' ? __( 'Clicks', 'blue-lens-analytics' ) : __( 'Impressions', 'blue-lens-analytics' ), color: metric === 'clicks' ? 'var(--bla-s2)' : 'var(--bla-s1)', values: series.map( ( d ) => d[ metric ] ) } ],
										format: fmt.number,
										ariaLabel: __( 'Ad trend', 'blue-lens-analytics' ),
									} ),
								table: () =>
									h( C.DataTable, {
										caption: __( 'Ads by date', 'blue-lens-analytics' ),
										columns: [
											{ key: 'day', label: __( 'Date', 'blue-lens-analytics' ), format: ( x ) => fmt.date( x, true ), sortValue: ( r ) => r.day },
											{ key: 'impressions', label: __( 'Impressions', 'blue-lens-analytics' ), num: true, format: fmt.number },
											{ key: 'clicks', label: __( 'Clicks', 'blue-lens-analytics' ), num: true, format: fmt.number },
										],
										rows: series.slice().reverse(),
										rowKey: ( r ) => r.day,
									} ),
							} );
						case 'ads_table':
							return h(
								Card,
								{ key: w.id, span: 12, title: __( 'Advertisements', 'blue-lens-analytics' ), sub: __( 'Performance of each ad', 'blue-lens-analytics' ), actions: data.ads.length > 0 && h( IconButton, { icon: 'download', label: __( 'Export CSV', 'blue-lens-analytics' ), href: exportUrl( 'local_ads', '', range ) } ) },
								data.ads.length ? h( C.DataTable, { caption: __( 'Advertisements', 'blue-lens-analytics' ), shareKey: 'impressions', columns: adColumns, rows: data.ads.map( ( r ) => ( { ...r, sub: r.campaign } ) ), rowKey: ( r ) => r.ad_id, initialSort: { key: 'impressions', dir: 'desc' } } ) : h( Empty, { icon: 'megaphone' } )
							);
						case 'ads_campaigns':
							return h(
								Card,
								{ key: w.id, span: w.span, title: __( 'Campaigns', 'blue-lens-analytics' ), sub: __( 'Grouped as in Local Ads', 'blue-lens-analytics' ) },
								data.campaigns.length ? h( C.DataTable, { caption: __( 'Campaigns', 'blue-lens-analytics' ), shareKey: 'clicks', columns: rateColumns( { key: 'name', label: __( 'Campaign', 'blue-lens-analytics' ) } ), rows: data.campaigns, rowKey: ( r ) => r.campaign_id } ) : h( Empty, { title: __( 'No campaign activity in this period', 'blue-lens-analytics' ) } )
							);
						case 'ads_channels':
							return h(
								Card,
								{ key: w.id, span: w.span, title: __( 'Ad clicks by channel', 'blue-lens-analytics' ), sub: __( 'Which traffic sources click your ads', 'blue-lens-analytics' ) },
								v.channels.length
									? h( C.DataTable, { caption: __( 'Ad clicks by channel', 'blue-lens-analytics' ), shareKey: 'clicks', columns: rateColumns( { key: 'channel', label: __( 'Channel', 'blue-lens-analytics' ), render: ( r ) => h( Fragment, null, h( 'span', { className: 'bla-table__swatch', style: { background: channelColor( r.channel ) } } ), channelLabel( r.channel ) ) } ), rows: v.channels.map( ( r ) => ( { ...r, color: channelColor( r.channel ) } ) ), rowKey: ( r ) => r.channel } )
									: h( Empty, { title: __( 'No ad visits recorded by Blue Lens yet', 'blue-lens-analytics' ) } )
							);
						case 'ads_pages':
							return h(
								Card,
								{ key: w.id, span: 12, title: __( 'Pages where ads are seen', 'blue-lens-analytics' ), sub: __( 'Impressions and clicks per page', 'blue-lens-analytics' ) },
								v.pages.length
									? h( C.DataTable, {
										caption: __( 'Pages where ads are seen', 'blue-lens-analytics' ),
										shareKey: 'impressions',
										columns: rateColumns( { key: 'title', label: __( 'Page', 'blue-lens-analytics' ), render: ( r ) => h( 'a', { href: pageUrl( r.path ), target: '_blank', rel: 'noopener noreferrer', className: 'bla-plain-link' }, r.title || r.path ) } ),
										rows: v.pages.map( ( r ) => ( { ...r, sub: r.path } ) ),
										rowKey: ( r ) => r.path,
									} )
									: h( Empty, { title: __( 'No ad visits recorded by Blue Lens yet', 'blue-lens-analytics' ) } )
							);
					}
					return null;
				} )
		);
	}

	/* ================================================================== */
	/* Site Audit and On-Page SEO                                          */
	/* ================================================================== */

	const SEVERITY = {
		error: { label: __( 'Errors', 'blue-lens-analytics' ), one: __( 'Error', 'blue-lens-analytics' ), color: 'var(--bla-critical)' },
		warning: { label: __( 'Warnings', 'blue-lens-analytics' ), one: __( 'Warning', 'blue-lens-analytics' ), color: 'var(--bla-warning)' },
		notice: { label: __( 'Notices', 'blue-lens-analytics' ), one: __( 'Notice', 'blue-lens-analytics' ), color: 'var(--bla-s1)' },
	};

	const IDEA_CATEGORIES = {
		strategy: { label: __( 'Strategy', 'blue-lens-analytics' ), color: 'var(--bla-s7)' },
		technical: { label: __( 'Technical SEO', 'blue-lens-analytics' ), color: 'var(--bla-s2)' },
		content: { label: __( 'Content', 'blue-lens-analytics' ), color: 'var(--bla-s6)' },
		ux: { label: __( 'User experience', 'blue-lens-analytics' ), color: 'var(--bla-s1)' },
		semantic: { label: __( 'Semantic', 'blue-lens-analytics' ), color: 'var(--bla-s5)' },
		serp: { label: __( 'SERP features', 'blue-lens-analytics' ), color: 'var(--bla-s4)' },
	};

	const CRAWL = {
		healthy: { label: __( 'Healthy', 'blue-lens-analytics' ), color: 'var(--bla-s3)' },
		warnings: { label: __( 'Have warnings', 'blue-lens-analytics' ), color: 'var(--bla-warning)' },
		errors: { label: __( 'Have errors', 'blue-lens-analytics' ), color: 'var(--bla-critical)' },
		redirects: { label: __( 'Redirects', 'blue-lens-analytics' ), color: 'var(--bla-s1)' },
		broken: { label: __( 'Broken', 'blue-lens-analytics' ), color: 'var(--bla-other)' },
	};

	const auditDate = ( utc ) => {
		if ( ! utc ) {
			return '';
		}
		const d = new Date( utc.replace( ' ', 'T' ) + 'Z' );
		try {
			return d.toLocaleString( boot.site.locale, { dateStyle: 'medium', timeStyle: 'short' } );
		} catch ( e ) {
			return d.toLocaleString();
		}
	};

	/** Loads the audit overview and drives a running audit forward while the screen is open. */
	function useAudit( refreshKey ) {
		const [ tick, setTick ] = useState( 0 );
		const { data, error, loading } = useApi( '/audit', {}, refreshKey + ':' + tick );
		const [ progress, setProgress ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const runningId = data && data.running ? data.running.id : 0;

		useEffect( () => {
			if ( ! runningId ) {
				setProgress( null );
				return undefined;
			}
			let alive = true;
			const loop = () => {
				const req = boot.canManage
					? apiFetch( { path: API + '/audit/step', method: 'POST', data: { run: runningId } } )
					: apiFetch( { path: API + '/audit' } ).then( ( d ) => d.running || { status: 'complete' } );
				req.then( ( p ) => {
					if ( ! alive ) {
						return;
					}
					setProgress( p );
					if ( p.status === 'running' ) {
						setTimeout( loop, boot.canManage ? 250 : 4000 );
					} else {
						setTick( ( t ) => t + 1 );
					}
				} ).catch( () => alive && setTimeout( loop, 5000 ) );
			};
			loop();
			return () => {
				alive = false;
			};
		}, [ runningId ] );

		const start = () => {
			setBusy( true );
			apiFetch( { path: API + '/audit/start', method: 'POST' } )
				.then( () => setTick( ( t ) => t + 1 ) )
				.finally( () => setBusy( false ) );
		};
		const cancel = () =>
			apiFetch( { path: API + '/audit/cancel', method: 'POST', data: { run: runningId } } ).then( () => setTick( ( t ) => t + 1 ) );

		return { data, error, loading, progress: progress || ( data && data.running ), start, cancel, busy };
	}

	/** Half-circle health gauge. */
	function Gauge( { value, label, size = 200 } ) {
		const stroke = Math.round( size * 0.09 );
		const r = ( size - stroke ) / 2;
		const cx = size / 2;
		const cy = size / 2;
		const at = ( p ) => {
			const a = Math.PI * ( 1 - p );
			return [ cx + r * Math.cos( a ), cy - r * Math.sin( a ) ];
		};
		const [ x0, y0 ] = at( 0 );
		const [ x1, y1 ] = at( 1 );
		const [ xv, yv ] = at( Math.max( 0.0001, Math.min( 1, value / 100 ) ) );
		const color = value >= 80 ? 'var(--bla-s3)' : value >= 60 ? 'var(--bla-warning)' : 'var(--bla-critical)';
		const height = cy + stroke / 2 + 4;
		return h(
			'svg',
			{ className: 'bla-gauge', width: size, height, viewBox: '0 0 ' + size + ' ' + height, role: 'img', 'aria-label': sprintf( /* translators: %d: score. */ __( 'Site health %d%%', 'blue-lens-analytics' ), value ) },
			h( 'path', { d: `M ${ x0 } ${ y0 } A ${ r } ${ r } 0 0 1 ${ x1 } ${ y1 }`, stroke: 'var(--bla-grid)', strokeWidth: stroke, fill: 'none', strokeLinecap: 'round' } ),
			h( 'path', { d: `M ${ x0 } ${ y0 } A ${ r } ${ r } 0 0 1 ${ xv } ${ yv }`, stroke: color, strokeWidth: stroke, fill: 'none', strokeLinecap: 'round' } ),
			h( 'text', { x: cx, y: cy - size * 0.06, textAnchor: 'middle', className: 'bla-gauge__value', style: { fontSize: size * 0.15 } }, value + '%' ),
			label && h( 'text', { x: cx, y: cy + size * 0.03, textAnchor: 'middle', className: 'bla-gauge__label' }, label )
		);
	}

	/** Crawled pages as one stacked bar with a clickable legend. */
	function CrawlBar( { crawl, onPick, active } ) {
		const total = Object.keys( CRAWL ).reduce( ( a, k ) => a + ( crawl[ k ] || 0 ), 0 );
		return h(
			'div',
			{ className: 'bla-crawl' },
			h(
				'div',
				{ className: 'bla-stack', role: 'img', 'aria-label': __( 'Crawled pages by status', 'blue-lens-analytics' ) },
				Object.keys( CRAWL ).map( ( k ) => ( crawl[ k ] ? h( 'span', { key: k, title: CRAWL[ k ].label + ': ' + crawl[ k ], style: { width: ( crawl[ k ] / total ) * 100 + '%', background: CRAWL[ k ].color } } ) : null ) )
			),
			h(
				'ul',
				{ className: 'bla-crawl__legend' },
				Object.keys( CRAWL ).map( ( k ) =>
					h(
						'li',
						{ key: k },
						h(
							'button',
							{ type: 'button', className: 'bla-crawl__item', 'aria-pressed': active === k, onClick: onPick ? () => onPick( active === k ? 'all' : k ) : undefined, disabled: ! onPick },
							h( 'span', { className: 'bla-legend__swatch', style: { background: CRAWL[ k ].color } } ),
							h( 'span', null, CRAWL[ k ].label ),
							h( 'strong', null, fmt.number( crawl[ k ] || 0 ) )
						)
					)
				)
			)
		);
	}

	const detailText = ( code, d ) => {
		if ( Array.isArray( d ) ) {
			return sprintf( /* translators: %d: number of links. */ __( '%d broken links', 'blue-lens-analytics' ), d.length );
		}
		switch ( code ) {
			case 'slow_response':
				return sprintf( /* translators: %s: milliseconds. */ __( '%s ms', 'blue-lens-analytics' ), fmt.number( d ) );
			case 'large_html':
				return sprintf( /* translators: %s: kilobytes. */ __( '%s KB', 'blue-lens-analytics' ), fmt.number( Math.round( d / 1024 ) ) );
			case 'title_too_long':
			case 'title_too_short':
			case 'meta_description_length':
				return sprintf( /* translators: %d: characters. */ __( '%d characters', 'blue-lens-analytics' ), d );
			case 'low_word_count':
			case 'no_subheadings':
				return sprintf( /* translators: %s: words. */ __( '%s words', 'blue-lens-analytics' ), fmt.number( d ) );
			case 'images_missing_alt':
				return sprintf( /* translators: %d: images. */ __( '%d images', 'blue-lens-analytics' ), d );
			case 'title_duplicate':
			case 'meta_description_duplicate':
				return sprintf( /* translators: %d: pages. */ __( 'shared by %d pages', 'blue-lens-analytics' ), d );
			case 'http_4xx':
			case 'http_5xx':
			case 'redirect':
				return 'HTTP ' + d;
			case 'canonical_elsewhere':
				return String( d );
		}
		return '';
	};

	/** Pages affected by one issue, loaded when the issue is expanded. */
	function IssuePages( { code, runId, openPage } ) {
		const { data, error } = useApi( '/audit/issue', { code }, runId );
		if ( error && ! data ) {
			return h( ErrorBox, { message: error } );
		}
		if ( ! data ) {
			return h( 'p', { className: 'bla-card__sub' }, __( 'Loading pages…', 'blue-lens-analytics' ) );
		}
		const rows = data.rows.slice( 0, 100 );
		return h(
			'ul',
			{ className: 'bla-issue__pages' },
			rows.map( ( r ) =>
				h(
					'li',
					{ key: r.id },
					h( 'button', { type: 'button', className: 'bla-plain-link', onClick: () => openPage( r.id ) }, r.title || r.path ),
					h( 'small', null, r.path ),
					detailText( code, r.detail ) && h( 'span', { className: 'bla-chip' }, detailText( code, r.detail ) ),
					Array.isArray( r.detail ) && h( 'ul', { className: 'bla-issue__links' }, r.detail.slice( 0, 10 ).map( ( u ) => h( 'li', { key: u }, u ) ) )
				)
			),
			data.rows.length > rows.length && h( 'li', { className: 'bla-card__sub' }, sprintf( /* translators: %d: number of pages. */ __( 'and %d more — export the crawl for the full list', 'blue-lens-analytics' ), data.rows.length - rows.length ) )
		);
	}

	/** Expandable issue row: what is wrong, how to fix it, and where. */
	function IssueRow( { issue, runId, openPage, showSeverity } ) {
		const [ open, setOpen ] = useState( false );
		const cat = IDEA_CATEGORIES[ issue.category ];
		return h(
			'li',
			{ className: 'bla-issue' + ( open ? ' is-open' : '' ) },
			h(
				'button',
				{ type: 'button', className: 'bla-issue__head', 'aria-expanded': open, onClick: () => setOpen( ! open ) },
				h( 'span', { className: 'bla-issue__chev', 'aria-hidden': 'true' }, h( Icon, { name: 'chevron', size: 14 } ) ),
				showSeverity && h( 'span', { className: 'bla-dot', style: { background: SEVERITY[ issue.severity ].color }, title: SEVERITY[ issue.severity ].one } ),
				h( 'span', { className: 'bla-issue__title' }, issue.title ),
				issue.scope === 'site' && h( 'span', { className: 'bla-chip bla-chip--accent' }, __( 'Site-wide', 'blue-lens-analytics' ) ),
				cat && h( 'span', { className: 'bla-chip' }, cat.label ),
				h( 'span', { className: 'bla-issue__count' }, issue.scope === 'site' ? '' : sprintf( /* translators: %s: number of pages. */ _n( '%s page', '%s pages', issue.pages, 'blue-lens-analytics' ), fmt.number( issue.pages ) ) )
			),
			open &&
				h(
					'div',
					{ className: 'bla-issue__body' },
					h( 'p', { className: 'bla-issue__fix' }, h( 'strong', null, __( 'How to fix: ', 'blue-lens-analytics' ) ), issue.fix ),
					issue.scope !== 'site' && h( IssuePages, { code: issue.code, runId, openPage } )
				)
		);
	}

	function AuditProgress( { progress, onCancel } ) {
		const pagesPct = progress.pages_total ? progress.pages_done / progress.pages_total : 0;
		const linksPct = progress.links_total ? progress.links_done / progress.links_total : 0;
		const phaseText =
			progress.phase === 'pages'
				? sprintf( /* translators: 1: pages done, 2: pages total. */ __( 'Crawling pages: %1$s of %2$s', 'blue-lens-analytics' ), fmt.number( progress.pages_done ), fmt.number( progress.pages_total ) )
				: progress.phase === 'links'
					? sprintf( /* translators: 1: links done, 2: links total. */ __( 'Checking internal links: %1$s of %2$s', 'blue-lens-analytics' ), fmt.number( progress.links_done ), fmt.number( progress.links_total ) )
					: __( 'Scoring your site…', 'blue-lens-analytics' );
		const value = progress.phase === 'pages' ? pagesPct * 0.8 : progress.phase === 'links' ? 0.8 + linksPct * 0.18 : 0.98;
		return h(
			Card,
			{ span: 12, title: __( 'Audit in progress', 'blue-lens-analytics' ), sub: sprintf( /* translators: %s: date. */ __( 'Started %s', 'blue-lens-analytics' ), auditDate( progress.started_at ) ), actions: boot.canManage && h( 'button', { type: 'button', className: 'bla-btn bla-btn--sm', onClick: onCancel }, __( 'Cancel', 'blue-lens-analytics' ) ) },
			h( 'div', { className: 'bla-progress', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': Math.round( value * 100 ) }, h( 'i', { style: { width: value * 100 + '%' } } ) ),
			h( 'p', { className: 'bla-card__sub' }, phaseText, ' · ', boot.canManage ? __( 'Keep this tab open to finish faster; it also continues in the background.', 'blue-lens-analytics' ) : __( 'Running in the background.', 'blue-lens-analytics' ) )
		);
	}

	function AuditEmpty( { start, busy } ) {
		return h(
			Card,
			{ span: 12, className: 'bla-welcome-card' },
			h(
				'div',
				{ className: 'bla-welcome' },
				h(
					'div',
					null,
					h( 'h2', null, __( 'Audit your website', 'blue-lens-analytics' ) ),
					h( 'p', null, sprintf( /* translators: %s: number of pages. */ __( 'Blue Lens visits up to %s of your pages the way a search engine does, checks more than 35 technical and on-page factors, and tells you exactly what to fix. Nothing is sent to outside services.', 'blue-lens-analytics' ), fmt.number( boot.audit.maxPages ) ) ),
					boot.canManage
						? h( 'p', null, h( 'button', { type: 'button', className: 'bla-btn bla-btn--primary', onClick: start, disabled: busy }, h( Icon, { name: 'shield', size: 16 } ), __( 'Run first audit', 'blue-lens-analytics' ) ) )
						: h( 'p', null, __( 'Ask an administrator to run the first audit.', 'blue-lens-analytics' ) )
				),
				h(
					'ol',
					{ className: 'bla-steps' },
					h( 'li', null, h( 'div', null, h( 'strong', null, __( 'Site health', 'blue-lens-analytics' ) ), __( 'Broken pages and links, redirects, duplicate titles, mixed content, sitemap and robots.txt.', 'blue-lens-analytics' ) ) ),
					h( 'li', null, h( 'div', null, h( 'strong', null, __( 'On-page SEO ideas', 'blue-lens-analytics' ) ), __( 'Titles, descriptions, headings, word count, alt text, structured data and internal links.', 'blue-lens-analytics' ) ) ),
					h( 'li', null, h( 'div', null, h( 'strong', null, __( 'Priorities from real visits', 'blue-lens-analytics' ) ), __( 'Pages are ranked by your Blue Lens traffic so you fix what matters first.', 'blue-lens-analytics' ) ) )
				)
			)
		);
	}

	function AuditPageDetail( { id, onClose } ) {
		const { data, error } = useApi( '/audit/page', { id }, 0 );
		const f = data ? data.facts || {} : {};
		const row = ( label, value ) => h( 'div', { className: 'bla-kv__row', key: label }, h( 'dt', null, label ), h( 'dd', null, value === '' || value === undefined || value === null ? '—' : value ) );
		return h(
			SidePanel,
			{ title: __( 'Page details', 'blue-lens-analytics' ), onClose },
			error && ! data && h( ErrorBox, { message: error } ),
			! data && ! error && h( 'div', { className: 'bla-boot' }, __( 'Loading…', 'blue-lens-analytics' ) ),
			data &&
				h(
					Fragment,
					null,
					h(
						'div',
						null,
						h( 'h3', { className: 'bla-detail__title' }, data.title || data.path ),
						h( 'a', { href: data.url, target: '_blank', rel: 'noopener noreferrer', className: 'bla-link' }, data.url, ' ', h( Icon, { name: 'external', size: 12 } ) ),
						data.edit_url && h( Fragment, null, ' · ', h( 'a', { href: data.edit_url, className: 'bla-link' }, __( 'Edit in WordPress', 'blue-lens-analytics' ) ) )
					),
					h(
						'div',
						{ className: 'bla-stats' },
						[ [ 'error', data.errors ], [ 'warning', data.warnings ], [ 'notice', data.notices ] ].map( ( [ k, n ] ) =>
							h( 'div', { className: 'bla-stat', key: k }, h( 'div', { className: 'bla-stat__label' }, h( 'span', { className: 'bla-dot', style: { background: SEVERITY[ k ].color } } ), ' ', SEVERITY[ k ].label ), h( 'div', { className: 'bla-stat__value' }, fmt.number( n ) ) )
						)
					),
					data.issues.length
						? h(
							'ul',
							{ className: 'bla-issues' },
							data.issues.map( ( i ) =>
								h(
									'li',
									{ key: i.code, className: 'bla-issue is-open' },
									h(
										'div',
										{ className: 'bla-issue__head is-static' },
										h( 'span', { className: 'bla-dot', style: { background: SEVERITY[ i.severity ].color } } ),
										h( 'span', { className: 'bla-issue__title' }, i.title ),
										detailText( i.code, i.detail ) && h( 'span', { className: 'bla-chip' }, detailText( i.code, i.detail ) )
									),
									h(
										'div',
										{ className: 'bla-issue__body' },
										h( 'p', { className: 'bla-issue__fix' }, i.fix ),
										Array.isArray( i.detail ) && h( 'ul', { className: 'bla-issue__links' }, i.detail.map( ( u ) => h( 'li', { key: u }, u ) ) )
									)
								)
							)
						)
						: h( Empty, { title: __( 'No issues on this page', 'blue-lens-analytics' ), icon: 'check' } ),
					h(
						'dl',
						{ className: 'bla-kv' },
						row( __( 'HTTP status', 'blue-lens-analytics' ), data.status_code || __( 'No response', 'blue-lens-analytics' ) ),
						row( __( 'Server response', 'blue-lens-analytics' ), sprintf( /* translators: %s: milliseconds. */ __( '%s ms', 'blue-lens-analytics' ), fmt.number( data.response_ms ) ) ),
						row( __( 'Title', 'blue-lens-analytics' ), f.title ? f.title + ' (' + f.title_len + ')' : '' ),
						row( __( 'Meta description', 'blue-lens-analytics' ), f.description ? f.description + ' (' + f.desc_len + ')' : '' ),
						row( __( 'H1', 'blue-lens-analytics' ), f.h1 ),
						row( __( 'Words', 'blue-lens-analytics' ), fmt.number( data.words ) ),
						row( __( 'Images without alt text', 'blue-lens-analytics' ), f.images !== undefined ? f.images_no_alt + ' / ' + f.images : '' ),
						row( __( 'Links to other pages here', 'blue-lens-analytics' ), fmt.number( data.internal_links ) ),
						row( __( 'Crawled pages linking here', 'blue-lens-analytics' ), fmt.number( data.inlinks ) ),
						row( __( 'Canonical', 'blue-lens-analytics' ), f.canonical ),
						row( __( 'Structured data blocks', 'blue-lens-analytics' ), f.schema !== undefined ? fmt.number( f.schema ) : '' ),
						row( __( 'Page views, last 28 days', 'blue-lens-analytics' ), fmt.number( data.pageviews ) )
					)
				)
		);
	}

	/** Shared shell of the two audit pages: progress, empty state and page details. */
	function useAuditScreen( refreshKey ) {
		const audit = useAudit( refreshKey );
		const [ pageId, setPageId ] = useState( 0 );
		const detail = pageId ? h( AuditPageDetail, { id: pageId, onClose: () => setPageId( 0 ) } ) : null;
		return { ...audit, openPage: setPageId, detail };
	}

	function SiteAuditPage( { refreshKey } ) {
		const { data, error, loading, progress, start, cancel, busy, openPage, detail } = useAuditScreen( refreshKey );
		const [ severity, setSeverity ] = useState( 'error' );
		const [ filter, setFilter ] = useState( 'all' );
		const latest = data && data.latest;
		const pages = useApi( latest ? '/audit/pages' : '', { filter }, latest ? latest.id : 0 );

		if ( error && ! data ) {
			return h( Grid, null, h( Card, { span: 12 }, h( ErrorBox, { message: error } ) ) );
		}
		if ( ! data ) {
			return h( Grid, { busy: true }, h( Card, { span: 12 }, h( 'div', { className: 'bla-boot' }, __( 'Loading…', 'blue-lens-analytics' ) ) ) );
		}

		const history = data.history || [];
		const prev = history.length > 1 ? history[ history.length - 2 ] : null;
		const delta = ( key, lowerIsBetter ) => {
			if ( ! prev || ! latest ) {
				return null;
			}
			const d = latest[ key ] - prev[ key ];
			if ( d === 0 ) {
				return h( 'span', { className: 'bla-delta' }, __( 'no change', 'blue-lens-analytics' ) );
			}
			const good = lowerIsBetter ? d < 0 : d > 0;
			return h( 'span', { className: 'bla-delta ' + ( good ? 'is-good' : 'is-bad' ) }, ( d > 0 ? '+' : '' ) + fmt.number( d ) );
		};
		const issues = latest ? latest.issues.filter( ( i ) => i.severity === severity ) : [];
		const runButton = boot.canManage && ! progress && h( 'button', { type: 'button', className: 'bla-btn bla-btn--primary bla-btn--sm', onClick: start, disabled: busy }, h( Icon, { name: 'refresh', size: 14 } ), __( 'Re-run audit', 'blue-lens-analytics' ) );

		const pageRows = pages.data ? pages.data.rows : [];

		return h(
			Grid,
			{ busy: loading },
			progress && h( AuditProgress, { progress, onCancel: cancel } ),
			! latest && ! progress && h( AuditEmpty, { start, busy } ),
			latest &&
				h(
					Fragment,
					null,
					h(
						Card,
						{ span: 4, title: __( 'Site health', 'blue-lens-analytics' ), sub: sprintf( /* translators: %s: date. */ __( 'Updated: %s', 'blue-lens-analytics' ), auditDate( latest.finished_at ) ), actions: runButton },
						h( 'div', { className: 'bla-health' }, h( Gauge, { value: latest.health, label: prev ? ( latest.health === prev.health ? __( 'no changes', 'blue-lens-analytics' ) : sprintf( /* translators: %s: change in points. */ __( '%s since last audit', 'blue-lens-analytics' ), ( latest.health > prev.health ? '+' : '' ) + ( latest.health - prev.health ) ) ) : __( 'first audit', 'blue-lens-analytics' ) } ) )
					),
					h(
						Card,
						{ span: 4, title: __( 'Issues found', 'blue-lens-analytics' ), sub: __( 'Across all crawled pages and site-wide checks', 'blue-lens-analytics' ) },
						h(
							'div',
							{ className: 'bla-sevs' },
							[ [ 'error', 'errors' ], [ 'warning', 'warnings' ], [ 'notice', 'notices' ] ].map( ( [ k, key ] ) =>
								h(
									'button',
									{ type: 'button', key: k, className: 'bla-sev', 'aria-pressed': severity === k, onClick: () => setSeverity( k ) },
									h( 'span', { className: 'bla-sev__label' }, SEVERITY[ k ].label ),
									h( 'span', { className: 'bla-sev__value', style: { color: SEVERITY[ k ].color } }, fmt.number( latest[ key ] ) ),
									delta( key, true )
								)
							)
						)
					),
					h(
						Card,
						{ span: 4, title: __( 'Crawled pages', 'blue-lens-analytics' ), sub: latest.links ? sprintf( /* translators: %s: number of links. */ __( 'Plus %s other internal links checked', 'blue-lens-analytics' ), fmt.number( latest.links ) ) : __( 'Every internal link points to a crawled page', 'blue-lens-analytics' ) },
						h( 'div', { className: 'bla-bignum' }, fmt.number( latest.pages ) ),
						h( CrawlBar, { crawl: latest.crawl, onPick: setFilter, active: filter } )
					),
					h(
						Card,
						{
							span: 12,
							title: __( 'Issues', 'blue-lens-analytics' ),
							sub: __( 'Expand an issue to see why it matters, how to fix it and which pages are affected.', 'blue-lens-analytics' ),
							actions: h( Segment, {
								label: __( 'Severity', 'blue-lens-analytics' ),
								value: severity,
								onChange: setSeverity,
								options: Object.keys( SEVERITY ).map( ( k ) => [ k, SEVERITY[ k ].label + ' · ' + latest.issues.filter( ( i ) => i.severity === k ).length ] ),
							} ),
						},
						issues.length
							? h( 'ul', { className: 'bla-issues' }, issues.map( ( i ) => h( IssueRow, { key: i.code, issue: i, runId: latest.id, openPage } ) ) )
							: h( Empty, { title: sprintf( /* translators: %s: severity, e.g. "errors". */ __( 'No %s. Nice work!', 'blue-lens-analytics' ), SEVERITY[ severity ].label.toLowerCase() ), icon: 'check' } )
					),
					h(
						Card,
						{
							span: 12,
							title: __( 'Crawled pages', 'blue-lens-analytics' ),
							sub: filter === 'all' ? __( 'Every page in the latest audit', 'blue-lens-analytics' ) : CRAWL[ filter ].label,
							actions: h(
								Fragment,
								null,
								h(
									'select',
									{ className: 'bla-select', value: filter, onChange: ( e ) => setFilter( e.target.value ), 'aria-label': __( 'Show pages', 'blue-lens-analytics' ) },
									h( 'option', { value: 'all' }, __( 'All pages', 'blue-lens-analytics' ) ),
									Object.keys( CRAWL ).map( ( k ) => h( 'option', { key: k, value: k }, CRAWL[ k ].label ) )
								),
								h( IconButton, { icon: 'download', label: __( 'Export CSV', 'blue-lens-analytics' ), href: exportUrl( 'audit', '', { from: '', to: '' } ) } )
							),
						},
						pageRows.length
							? h( C.DataTable, {
								caption: __( 'Crawled pages', 'blue-lens-analytics' ),
								rows: pageRows.map( ( r ) => ( { ...r, sub: r.path } ) ),
								rowKey: ( r ) => r.id,
								initialSort: { key: 'ideas', dir: 'desc' },
								columns: [
									{ key: 'title', label: __( 'Page', 'blue-lens-analytics' ), render: ( r ) => h( 'button', { type: 'button', className: 'bla-plain-link', onClick: () => openPage( r.id ) }, r.title || r.path ) },
									{ key: 'status_code', label: __( 'Status', 'blue-lens-analytics' ), render: ( r ) => h( 'span', { className: 'bla-chip', style: { color: CRAWL[ r.bucket ].color } }, r.status_code || '—' ), sortValue: ( r ) => r.status_code },
									{ key: 'response_ms', label: __( 'Response', 'blue-lens-analytics' ), num: true, format: ( x ) => fmt.number( x ) + ' ms' },
									{ key: 'words', label: __( 'Words', 'blue-lens-analytics' ), num: true, format: fmt.number },
									{ key: 'inlinks', label: __( 'Links in', 'blue-lens-analytics' ), num: true, format: fmt.number },
									{ key: 'ideas', label: __( 'Issues', 'blue-lens-analytics' ), num: true, render: ( r ) => h( 'span', { className: 'bla-counts' }, h( 'b', { style: { color: SEVERITY.error.color } }, r.errors ), h( 'b', { style: { color: SEVERITY.warning.color } }, r.warnings ), h( 'b', { style: { color: SEVERITY.notice.color } }, r.notices ) ), sortValue: ( r ) => r.errors * 10000 + r.warnings * 100 + r.notices },
									{ key: 'pageviews', label: __( 'Views (28d)', 'blue-lens-analytics' ), num: true, format: fmt.number },
								],
							} )
							: h( Empty, { title: pages.data ? __( 'No pages in this group', 'blue-lens-analytics' ) : __( 'Loading…', 'blue-lens-analytics' ) } )
					),
					history.length > 1 &&
						h(
							Card,
							{ span: 12, title: __( 'Health over time', 'blue-lens-analytics' ), sub: __( 'Your last audits', 'blue-lens-analytics' ) },
							h( C.AreaChart, {
								labels: history.map( ( r ) => r.finished_at.slice( 0, 10 ) ),
								series: [ { name: __( 'Site health', 'blue-lens-analytics' ), color: 'var(--bla-s3)', values: history.map( ( r ) => r.health ) } ],
								format: ( x ) => Math.round( x ) + '%',
								ariaLabel: __( 'Site health over time', 'blue-lens-analytics' ),
							} )
						)
				),
			detail
		);
	}

	function OnPageSeoPage( { refreshKey } ) {
		const { data, error, loading, progress, start, cancel, busy, openPage, detail } = useAuditScreen( refreshKey );
		if ( error && ! data ) {
			return h( Grid, null, h( Card, { span: 12 }, h( ErrorBox, { message: error } ) ) );
		}
		if ( ! data ) {
			return h( Grid, { busy: true }, h( Card, { span: 12 }, h( 'div', { className: 'bla-boot' }, __( 'Loading…', 'blue-lens-analytics' ) ) ) );
		}
		const latest = data.latest;
		if ( ! latest ) {
			return h( Grid, null, progress && h( AuditProgress, { progress, onCancel: cancel } ), ! progress && h( AuditEmpty, { start, busy } ) );
		}
		const ideas = latest.ideas;
		const items = Object.keys( IDEA_CATEGORIES ).map( ( k ) => ( { name: IDEA_CATEGORIES[ k ].label, value: ideas.categories[ k ] || 0, color: IDEA_CATEGORIES[ k ].color } ) );

		return h(
			Grid,
			{ busy: loading },
			progress && h( AuditProgress, { progress, onCancel: cancel } ),
			h(
				Card,
				{ span: 6, title: __( 'On-page SEO ideas', 'blue-lens-analytics' ), sub: sprintf( /* translators: 1: number of ideas, 2: number of pages, 3: date. */ __( '%1$s ideas for %2$s pages · Updated: %3$s', 'blue-lens-analytics' ), fmt.number( ideas.total ), fmt.number( ideas.pages ), auditDate( latest.finished_at ) ) },
				ideas.total ? h( C.Donut, { items, format: fmt.number, centerLabel: __( 'ideas', 'blue-lens-analytics' ), ariaLabel: __( 'Ideas by category', 'blue-lens-analytics' ) } ) : h( Empty, { title: __( 'No ideas — every page passed', 'blue-lens-analytics' ), icon: 'check' } ),
				ideas.total > 0 &&
					h(
						'div',
						{ className: 'bla-common' },
						h( 'h3', null, __( 'Most common ideas', 'blue-lens-analytics' ) ),
						h(
							'ul',
							null,
							latest.issues
								.filter( ( i ) => i.scope !== 'site' )
								.slice()
								.sort( ( a, b ) => b.pages - a.pages )
								.slice( 0, 6 )
								.map( ( i ) =>
									h(
										'li',
										{ key: i.code },
										h( 'span', { className: 'bla-dot', style: { background: ( IDEA_CATEGORIES[ i.category ] || {} ).color } } ),
										h( 'span', { className: 'bla-common__title' }, i.title ),
										h( 'span', { className: 'bla-common__count' }, sprintf( /* translators: %s: number of pages. */ _n( '%s page', '%s pages', i.pages, 'blue-lens-analytics' ), fmt.number( i.pages ) ) )
									)
								)
						)
					)
			),
			h(
				Card,
				{ span: 6, title: __( 'Top pages to optimise', 'blue-lens-analytics' ), sub: __( 'Your most visited pages that have ideas', 'blue-lens-analytics' ) },
				latest.top_pages.length
					? h(
						'ol',
						{ className: 'bla-toplist' },
						latest.top_pages.map( ( p ) =>
							h(
								'li',
								{ key: p.id },
								h( 'span', { className: 'bla-toplist__page' }, h( 'button', { type: 'button', className: 'bla-plain-link', onClick: () => openPage( p.id ) }, p.title || p.path ), h( 'small', null, p.path + ' · ' + sprintf( /* translators: %s: views. */ __( '%s views in 28 days', 'blue-lens-analytics' ), fmt.number( p.pageviews ) ) ) ),
								h( 'button', { type: 'button', className: 'bla-link', onClick: () => openPage( p.id ) }, sprintf( /* translators: %d: number of ideas. */ _n( '%d idea', '%d ideas', p.ideas, 'blue-lens-analytics' ), p.ideas ) )
							)
						)
					)
					: h( Empty, { title: __( 'Nothing to optimise', 'blue-lens-analytics' ), icon: 'check' } )
			),
			Object.keys( IDEA_CATEGORIES ).map( ( k ) => {
				const list = latest.issues.filter( ( i ) => i.category === k );
				if ( ! list.length ) {
					return null;
				}
				return h(
					Card,
					{ key: k, span: 6, title: IDEA_CATEGORIES[ k ].label, sub: sprintf( /* translators: %s: number of ideas. */ __( '%s ideas', 'blue-lens-analytics' ), fmt.number( ideas.categories[ k ] || 0 ) ) },
					h( 'ul', { className: 'bla-issues' }, list.map( ( i ) => h( IssueRow, { key: i.code, issue: i, runId: latest.id, openPage, showSeverity: true } ) ) )
				);
			} ),
			detail
		);
	}

	/** Compact site audit summary for the Overview page. */
	function AuditSummaryCard( { span, refreshKey, go } ) {
		const { data, error } = useApi( '/audit', {}, refreshKey );
		const latest = data && data.latest;
		return h(
			Card,
			{ span, title: __( 'Site Audit', 'blue-lens-analytics' ), sub: latest ? sprintf( /* translators: %s: date. */ __( 'Updated: %s', 'blue-lens-analytics' ), auditDate( latest.finished_at ) ) : __( 'Technical and on-page health', 'blue-lens-analytics' ) },
			error && ! data && h( ErrorBox, { message: error } ),
			data && ! latest && h( Empty, { title: __( 'No audit yet', 'blue-lens-analytics' ), icon: 'shield' }, h( 'button', { type: 'button', className: 'bla-btn bla-btn--primary bla-btn--sm', onClick: () => go( 'audit' ) }, __( 'Open Site Audit', 'blue-lens-analytics' ) ) ),
			latest &&
				h(
					Fragment,
					null,
					h(
						'div',
						{ className: 'bla-audit-mini' },
						h( Gauge, { value: latest.health, label: __( 'site health', 'blue-lens-analytics' ), size: 170 } ),
						h(
							'div',
							{ className: 'bla-audit-mini__nums' },
							h( 'div', null, h( 'span', null, SEVERITY.error.label ), h( 'strong', { style: { color: SEVERITY.error.color } }, fmt.number( latest.errors ) ) ),
							h( 'div', null, h( 'span', null, SEVERITY.warning.label ), h( 'strong', { style: { color: SEVERITY.warning.color } }, fmt.number( latest.warnings ) ) ),
							h( 'div', null, h( 'span', null, __( 'Crawled pages', 'blue-lens-analytics' ) ), h( 'strong', null, fmt.number( latest.pages ) ) )
						)
					),
					h( CrawlBar, { crawl: latest.crawl } ),
					h( 'div', { className: 'bla-card__foot' }, h( 'button', { type: 'button', className: 'bla-btn bla-btn--sm', onClick: () => go( 'audit' ) }, __( 'View full report', 'blue-lens-analytics' ) ) )
				)
		);
	}

	/* ================================================================== */
	/* Heatmaps                                                            */
	/* ================================================================== */

	const DEVICE_WIDTH = { desktop: 1280, tablet: 820, mobile: 390 };

	function heatPalette() {
		const c = document.createElement( 'canvas' );
		c.width = 256;
		c.height = 1;
		const ctx = c.getContext( '2d' );
		const g = ctx.createLinearGradient( 0, 0, 256, 0 );
		[ [ 0, '#2c7bb6' ], [ 0.3, '#abd9e9' ], [ 0.55, '#ffffbf' ], [ 0.78, '#fdae61' ], [ 1, '#d7191c' ] ].forEach( ( [ o, col ] ) => g.addColorStop( o, col ) );
		ctx.fillStyle = g;
		ctx.fillRect( 0, 0, 256, 1 );
		return ctx.getImageData( 0, 0, 256, 1 ).data;
	}

	function HeatmapViewer( { path, device, range, refreshKey } ) {
		const { data, error } = useApi( '/reports/heatmap', { path, device, from: range.from, to: range.to }, refreshKey );
		const stageRef = useRef( null );
		const frameRef = useRef( null );
		const canvasRef = useRef( null );
		const stageWidth = C.useWidth( stageRef );
		const [ docHeight, setDocHeight ] = useState( 900 );
		const [ blocked, setBlocked ] = useState( false );
		const width = DEVICE_WIDTH[ device ] || 1280;
		const scale = stageWidth ? Math.min( 1, ( stageWidth - 2 ) / width ) : 1;
		const src = useMemo( () => {
			const u = new URL( path, boot.site.home );
			u.searchParams.set( 'bla_heatmap', '1' );
			return u.toString();
		}, [ path ] );

		const onLoad = useCallback( () => {
			try {
				const doc = frameRef.current.contentDocument;
				const hgt = Math.min( 20000, Math.max( doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0 ) );
				setBlocked( false );
				setDocHeight( hgt || 900 );
			} catch ( e ) {
				setBlocked( true );
			}
		}, [] );

		useEffect( () => {
			const canvas = canvasRef.current;
			if ( ! canvas || ! data ) {
				return;
			}
			canvas.width = width;
			canvas.height = docHeight;
			const ctx = canvas.getContext( '2d' );
			ctx.clearRect( 0, 0, width, docHeight );
			if ( ! data.cells.length ) {
				return;
			}
			const radius = device === 'mobile' ? 22 : 30;
			data.cells.forEach( ( [ x, y, clicks ] ) => {
				const cx = ( ( x + 0.5 ) / 100 ) * width;
				const cy = y * 20 + 10;
				const g = ctx.createRadialGradient( cx, cy, 0, cx, cy, radius );
				const a = Math.min( 1, 0.2 + Math.sqrt( clicks / data.max ) * 0.8 ); // sqrt lifts mid-range areas.
				g.addColorStop( 0, 'rgba(0,0,0,' + a + ')' );
				g.addColorStop( 1, 'rgba(0,0,0,0)' );
				ctx.fillStyle = g;
				ctx.fillRect( cx - radius, cy - radius, radius * 2, radius * 2 );
			} );
			const img = ctx.getImageData( 0, 0, width, docHeight );
			const pal = heatPalette();
			for ( let i = 3; i < img.data.length; i += 4 ) {
				const alpha = img.data[ i ];
				if ( alpha ) {
					const o = alpha * 4;
					img.data[ i - 3 ] = pal[ o ];
					img.data[ i - 2 ] = pal[ o + 1 ];
					img.data[ i - 1 ] = pal[ o + 2 ];
					img.data[ i ] = Math.min( 220, alpha * 1.4 );
				}
			}
			ctx.putImageData( img, 0, 0 );
		}, [ data, docHeight, width ] );

		return h(
			Fragment,
			null,
			h(
				'div',
				{ className: 'bla-card__head', style: { marginBottom: 12 } },
				h(
					'div',
					{ className: 'bla-heat__scale' },
					__( 'Fewer clicks', 'blue-lens-analytics' ),
					h( 'span', { className: 'bla-heat__ramp', 'aria-hidden': 'true' } ),
					__( 'More clicks', 'blue-lens-analytics' )
				),
				data && h( 'span', { className: 'bla-chip' }, sprintf( /* translators: %s: number of clicks. */ __( '%s clicks', 'blue-lens-analytics' ), fmt.number( data.total ) ) )
			),
			error && ! data && h( ErrorBox, { message: error } ),
			blocked && h( ErrorBox, { message: __( 'This page could not be shown here, possibly because it blocks being embedded. The click counts above are still correct.', 'blue-lens-analytics' ) } ),
			h(
				'div',
				{ className: 'bla-heat__stage', ref: stageRef, role: 'img', 'aria-label': sprintf( /* translators: 1: page path, 2: clicks. */ __( 'Click heatmap for %1$s: %2$s clicks', 'blue-lens-analytics' ), path, data ? fmt.number( data.total ) : '0' ) },
				h(
					'div',
					{ style: { width: width * scale, height: docHeight * scale, margin: '0 auto', position: 'relative' } },
					h(
						'div',
						{ className: 'bla-heat__frame', style: { width, height: docHeight, transform: 'scale(' + scale + ')' } },
						h( 'iframe', { ref: frameRef, src, width, height: docHeight, title: __( 'Page preview', 'blue-lens-analytics' ), onLoad, loading: 'lazy', sandbox: 'allow-same-origin allow-scripts' } ),
						h( 'canvas', { ref: canvasRef, style: { width, height: docHeight } } )
					)
				)
			)
		);
	}

	function HeatmapsPage( { range, refreshKey } ) {
		const [ device, setDevice ] = useState( 'desktop' );
		const [ selected, setSelected ] = useState( null );
		const { data, error } = useApi( '/reports/heatmap-pages', { device, from: range.from, to: range.to }, refreshKey );
		const pages = data ? data.pages : [];
		const current = selected && pages.find( ( p ) => p.path === selected ) ? selected : pages[ 0 ] && pages[ 0 ].path;

		return h(
			'div',
			{ className: 'bla-heat' },
			h(
				Card,
				{ title: __( 'Pages', 'blue-lens-analytics' ), sub: __( 'Ranked by clicks', 'blue-lens-analytics' ), span: null },
				h( 'div', { style: { marginBottom: 12 } }, h( Segment, { label: __( 'Device', 'blue-lens-analytics' ), value: device, onChange: setDevice, options: [ [ 'desktop', __( 'Desktop', 'blue-lens-analytics' ) ], [ 'tablet', __( 'Tablet', 'blue-lens-analytics' ) ], [ 'mobile', __( 'Mobile', 'blue-lens-analytics' ) ] ] } ) ),
				error && ! data && h( ErrorBox, { message: error } ),
				pages.length
					? h(
						'ul',
						{ className: 'bla-heat__pages' },
						pages.map( ( p ) =>
							h(
								'li',
								{ key: p.path },
								h( 'button', { type: 'button', className: 'bla-heat__page', 'aria-current': p.path === current, onClick: () => setSelected( p.path ) }, h( 'span', null, p.path ), h( 'span', { className: 'bla-chip' }, fmt.compact( p.clicks ) ) )
							)
						)
					)
					: data && h( Empty, { title: __( 'No clicks recorded for this device yet', 'blue-lens-analytics' ), icon: 'pointer' } )
			),
			h(
				Card,
				{ title: current || __( 'Heatmap', 'blue-lens-analytics' ), sub: current ? __( 'Brighter, warmer areas received more clicks', 'blue-lens-analytics' ) : null, span: null },
				current ? h( HeatmapViewer, { key: current + device, path: current, device, range, refreshKey } ) : h( Empty, { title: __( 'Choose a page to see its heatmap', 'blue-lens-analytics' ), icon: 'pointer' } )
			)
		);
	}

	/* ================================================================== */
	/* Customize drawer                                                    */
	/* ================================================================== */

	const ACCENTS = { blue: '#2a78d6', teal: '#12a58a', violet: '#6d5ce7', orange: '#e8692f', magenta: '#d6457f', green: '#1f9d55' };

	function CustomizeDrawer( { prefs, savePrefs, route, onClose, resetPrefs } ) {
		const panelRef = useRef( null );
		useEffect( () => {
			const onKey = ( e ) => e.key === 'Escape' && onClose();
			document.addEventListener( 'keydown', onKey );
			const first = panelRef.current && panelRef.current.querySelector( 'button' );
			if ( first ) {
				first.focus();
			}
			return () => document.removeEventListener( 'keydown', onKey );
		}, [] );

		const pageId = WIDGETS[ route ] ? route : 'overview';
		const layout = layoutFor( pageId, prefs );
		const saveLayout = ( list ) => savePrefs( { layout: { ...( prefs.layout || {} ), [ pageId ]: list.map( ( w ) => ( { id: w.id, on: w.on } ) ) } } );
		const move = ( i, dir ) => {
			const list = layout.slice();
			const j = i + dir;
			if ( j < 0 || j >= list.length ) {
				return;
			}
			[ list[ i ], list[ j ] ] = [ list[ j ], list[ i ] ];
			saveLayout( list );
		};

		const choice = ( key, value, label, preview ) =>
			h( 'button', { type: 'button', className: 'bla-choice', role: 'radio', 'aria-checked': prefs[ key ] === value, onClick: () => savePrefs( { [ key ]: value } ) }, h( 'span', { className: 'bla-choice__preview', style: preview } ), label );

		return h(
			Fragment,
			null,
			h( 'div', { className: 'bla-drawer-backdrop', onClick: onClose } ),
			h(
				'aside',
				{ className: 'bla-drawer', role: 'dialog', 'aria-modal': 'true', 'aria-label': __( 'Customize dashboard', 'blue-lens-analytics' ), ref: panelRef },
				h( 'div', { className: 'bla-drawer__head' }, h( 'h2', null, __( 'Customize', 'blue-lens-analytics' ) ), h( IconButton, { icon: 'x', label: __( 'Close', 'blue-lens-analytics' ), onClick: onClose } ) ),
				h(
					'div',
					{ className: 'bla-drawer__body' },
					h(
						'div',
						{ className: 'bla-drawer__section' },
						h( 'h3', null, __( 'Theme', 'blue-lens-analytics' ) ),
						h(
							'div',
							{ className: 'bla-choices', role: 'radiogroup', 'aria-label': __( 'Theme', 'blue-lens-analytics' ) },
							choice( 'theme', 'dark', __( 'Dark', 'blue-lens-analytics' ), { background: 'linear-gradient(135deg,#11151e 50%,#1b2130 50%)', border: '1px solid rgba(255,255,255,.1)' } ),
							choice( 'theme', 'light', __( 'Light', 'blue-lens-analytics' ), { background: 'linear-gradient(135deg,#f3f5f9 50%,#fff 50%)', border: '1px solid rgba(0,0,0,.1)' } )
						)
					),
					h(
						'div',
						{ className: 'bla-drawer__section' },
						h( 'h3', null, __( 'Accent colour', 'blue-lens-analytics' ) ),
						h(
							'div',
							{ className: 'bla-swatches', role: 'radiogroup', 'aria-label': __( 'Accent colour', 'blue-lens-analytics' ) },
							Object.keys( ACCENTS ).map( ( k ) => h( 'button', { key: k, type: 'button', className: 'bla-swatch', role: 'radio', 'aria-checked': prefs.accent === k, 'aria-label': k, title: k, style: { background: ACCENTS[ k ] }, onClick: () => savePrefs( { accent: k } ) } ) )
						)
					),
					h(
						'div',
						{ className: 'bla-drawer__section' },
						h( 'h3', null, __( 'Metric tiles', 'blue-lens-analytics' ) ),
						h(
							'div',
							{ className: 'bla-choices', role: 'radiogroup', 'aria-label': __( 'Metric tile style', 'blue-lens-analytics' ) },
							choice( 'tiles', 'vivid', __( 'Vivid', 'blue-lens-analytics' ), { background: 'linear-gradient(90deg,#1c5cab 25%,#0f7a5c 25% 50%,#4a3aa7 50% 75%,#b4461a 75%)' } ),
							choice( 'tiles', 'subtle', __( 'Subtle', 'blue-lens-analytics' ), { background: 'var(--bla-surface-2)', border: '1px solid var(--bla-border)' } )
						)
					),
					h(
						'div',
						{ className: 'bla-drawer__section' },
						h( 'h3', null, __( 'Density', 'blue-lens-analytics' ) ),
						h( Segment, { label: __( 'Density', 'blue-lens-analytics' ), value: prefs.density, onChange: ( v ) => savePrefs( { density: v } ), options: [ [ 'comfortable', __( 'Comfortable', 'blue-lens-analytics' ) ], [ 'compact', __( 'Compact', 'blue-lens-analytics' ) ] ] } )
					),
					h(
						'div',
						{ className: 'bla-drawer__section' },
						h( 'h3', null, __( 'Defaults', 'blue-lens-analytics' ) ),
						h(
							'div',
							{ style: { display: 'grid', gap: 10 } },
							h(
								'label',
								{ style: { display: 'grid', gap: 6, fontSize: 13 } },
								__( 'Date range when the dashboard opens', 'blue-lens-analytics' ),
								h( 'select', { className: 'bla-select', value: prefs.range, onChange: ( e ) => savePrefs( { range: e.target.value } ) }, PRESETS.map( ( [ k, l ] ) => h( 'option', { key: k, value: k }, l ) ) )
							),
							h(
								'label',
								{ style: { display: 'grid', gap: 6, fontSize: 13 } },
								__( 'Comparison', 'blue-lens-analytics' ),
								h(
									'select',
									{ className: 'bla-select', value: prefs.compare, onChange: ( e ) => savePrefs( { compare: e.target.value } ) },
									h( 'option', { value: 'previous' }, __( 'Previous period', 'blue-lens-analytics' ) ),
									h( 'option', { value: 'year' }, __( 'Same period last year', 'blue-lens-analytics' ) ),
									h( 'option', { value: 'none' }, __( 'No comparison', 'blue-lens-analytics' ) )
								)
							)
						)
					),
					h(
						'div',
						{ className: 'bla-drawer__section' },
						h( 'h3', null, __( 'Key metric tiles', 'blue-lens-analytics' ) ),
						h(
							'div',
							{ className: 'bla-kpi-picks' },
							Object.keys( METRICS ).map( ( k ) => {
								const on = prefs.kpis.includes( k );
								return h(
									'button',
									{
										key: k,
										type: 'button',
										className: 'bla-kpi-pick',
										'aria-pressed': on,
										disabled: ! on && prefs.kpis.length >= 8,
										onClick: () => {
											const next = on ? prefs.kpis.filter( ( x ) => x !== k ) : prefs.kpis.concat( k );
											if ( next.length ) {
												savePrefs( { kpis: next } );
											}
										},
									},
									METRICS[ k ].label
								);
							} )
						),
						h( 'p', { className: 'bla-card__sub', style: { marginTop: 8 } }, __( 'Choose up to 8. Tiles appear in the order you pick them.', 'blue-lens-analytics' ) )
					),
					WIDGETS[ pageId ] &&
						h(
							'div',
							{ className: 'bla-drawer__section' },
							h( 'h3', null, sprintf( /* translators: %s: page name. */ __( 'Cards on %s', 'blue-lens-analytics' ), ( PAGES.find( ( p ) => p.id === pageId ) || {} ).label ) ),
							h(
								'ul',
								{ className: 'bla-widgets' },
								layout.map( ( w, i ) =>
									h(
										'li',
										{ key: w.id, className: 'bla-widget-row' + ( w.on ? '' : ' is-off' ) },
										h( 'button', {
											type: 'button',
											className: 'bla-switch',
											role: 'switch',
											'aria-checked': w.on,
											'aria-label': sprintf( /* translators: %s: card name. */ __( 'Show %s', 'blue-lens-analytics' ), w.label ),
											onClick: () => saveLayout( layout.map( ( x ) => ( x.id === w.id ? { ...x, on: ! x.on } : x ) ) ),
										} ),
										h( 'span', null, w.label ),
										h( IconButton, { icon: 'up', label: sprintf( /* translators: %s: card name. */ __( 'Move %s up', 'blue-lens-analytics' ), w.label ), onClick: () => move( i, -1 ) } ),
										h( IconButton, { icon: 'down', label: sprintf( /* translators: %s: card name. */ __( 'Move %s down', 'blue-lens-analytics' ), w.label ), onClick: () => move( i, 1 ) } )
									)
								)
							)
						),
					h( 'div', null, h( 'button', { type: 'button', className: 'bla-btn', onClick: resetPrefs }, h( Icon, { name: 'refresh', size: 16 } ), __( 'Reset to defaults', 'blue-lens-analytics' ) ) )
				)
			)
		);
	}

	/* ================================================================== */
	/* Settings                                                            */
	/* ================================================================== */

	const SETTINGS_SECTIONS = [
		{ id: 'general', label: __( 'General', 'blue-lens-analytics' ) },
		{ id: 'privacy', label: __( 'Privacy', 'blue-lens-analytics' ) },
		{ id: 'features', label: __( 'What to track', 'blue-lens-analytics' ) },
		{ id: 'exclusions', label: __( 'Exclusions', 'blue-lens-analytics' ) },
		{ id: 'location', label: __( 'Location', 'blue-lens-analytics' ) },
		{ id: 'audit', label: __( 'Site audit', 'blue-lens-analytics' ) },
		{ id: 'advanced', label: __( 'Advanced', 'blue-lens-analytics' ) },
		{ id: 'data', label: __( 'Data & uninstall', 'blue-lens-analytics' ) },
	];

	function Field( { label, help, children, stack } ) {
		return h(
			'div',
			{ className: 'bla-field' + ( stack ? ' bla-field--stack' : '' ) },
			h( 'div', null, h( 'div', { className: 'bla-field__label' }, label ), help && h( 'div', { className: 'bla-field__help' }, help ) ),
			h( 'div', { className: 'bla-field__control' }, children )
		);
	}

	function SettingsPage( { showToast } ) {
		const [ saved, setSaved ] = useState( null );
		const [ draft, setDraft ] = useState( null );
		const [ error, setError ] = useState( null );
		const [ saving, setSaving ] = useState( false );
		const [ section, setSection ] = useState( ( window.location.hash.match( /#([a-z]+)$/ ) || [] )[ 1 ] || 'general' );

		useEffect( () => {
			apiFetch( { path: API + '/settings' } )
				.then( ( s ) => {
					setSaved( s );
					setDraft( s );
				} )
				.catch( ( e ) => setError( e.message ) );
		}, [] );

		const dirtyKeys = saved && draft ? Object.keys( draft ).filter( ( k ) => JSON.stringify( draft[ k ] ) !== JSON.stringify( saved[ k ] ) ) : [];

		useEffect( () => {
			const warn = ( e ) => {
				if ( dirtyKeys.length ) {
					e.preventDefault();
					e.returnValue = '';
				}
			};
			window.addEventListener( 'beforeunload', warn );
			return () => window.removeEventListener( 'beforeunload', warn );
		}, [ dirtyKeys.length ] );

		if ( error && ! draft ) {
			return h( ErrorBox, { message: error } );
		}
		if ( ! draft ) {
			return h( 'div', { className: 'bla-boot' }, __( 'Loading settings…', 'blue-lens-analytics' ) );
		}

		const set = ( k, v ) => setDraft( { ...draft, [ k ]: v } );
		const save = () => {
			const body = {};
			dirtyKeys.forEach( ( k ) => ( body[ k ] = draft[ k ] ) );
			setSaving( true );
			setError( null );
			apiFetch( { path: API + '/settings', method: 'POST', data: body } )
				.then( ( s ) => {
					setSaved( s );
					setDraft( s );
					showToast( __( 'Settings saved', 'blue-lens-analytics' ) );
				} )
				.catch( ( e ) => setError( e.message ) )
				.finally( () => setSaving( false ) );
		};

		const toggle = ( k, label, help ) =>
			h( Field, { label, help }, h( 'button', { type: 'button', className: 'bla-switch', role: 'switch', 'aria-checked': !! draft[ k ], 'aria-label': label, onClick: () => set( k, ! draft[ k ] ) } ) );
		const select = ( k, label, help, options ) =>
			h( Field, { label, help }, h( 'select', { className: 'bla-select', value: draft[ k ], 'aria-label': label, onChange: ( e ) => set( k, e.target.value ) }, options.map( ( [ v, l ] ) => h( 'option', { key: v, value: v }, l ) ) ) );
		const number = ( k, label, help, min, max ) =>
			h( Field, { label, help }, h( 'input', { className: 'bla-input', style: { width: 110 }, type: 'number', min, max, value: draft[ k ], 'aria-label': label, onChange: ( e ) => set( k, parseInt( e.target.value || '0', 10 ) ) } ) );
		const text = ( k, label, help, type, placeholder ) =>
			h( Field, { label, help }, h( 'input', { className: 'bla-input', style: { width: 280 }, type: type || 'text', value: draft[ k ], placeholder, autoComplete: 'off', 'aria-label': label, onChange: ( e ) => set( k, e.target.value ) } ) );
		const list = ( k, label, help, placeholder ) =>
			h(
				Field,
				{ label, help, stack: true },
				h( 'textarea', {
					className: 'bla-textarea',
					placeholder,
					'aria-label': label,
					value: ( draft[ k ] || [] ).join( '\n' ),
					onChange: ( e ) => set( k, e.target.value.split( /\n/ ).map( ( s ) => s.trim() ).filter( Boolean ) ),
				} )
			);

		const panels = {
			general: h(
				Card,
				{ title: __( 'General', 'blue-lens-analytics' ), span: null },
				toggle( 'tracking_enabled', __( 'Tracking', 'blue-lens-analytics' ), __( 'Master switch. When off, nothing is collected and the tracking script is not loaded.', 'blue-lens-analytics' ) ),
				text( 'base_currency', __( 'Reporting currency', 'blue-lens-analytics' ), __( 'Three-letter code (e.g. USD, KES, EUR). Values in other currencies are converted when an exchange rate is available.', 'blue-lens-analytics' ), 'text', 'USD' ),
				toggle( 'spa_tracking', __( 'Single-page app navigation', 'blue-lens-analytics' ), __( 'Count page changes on sites that load pages without a full reload.', 'blue-lens-analytics' ) )
			),
			privacy: h(
				Card,
				{ title: __( 'Privacy', 'blue-lens-analytics' ), sub: __( 'Cookieless by default. You stay in control of how visitors are counted.', 'blue-lens-analytics' ), span: null },
				h(
					Field,
					{ label: __( 'Visitor counting mode', 'blue-lens-analytics' ), stack: true },
					h(
						'div',
						{ className: 'bla-radio-cards', role: 'radiogroup', 'aria-label': __( 'Visitor counting mode', 'blue-lens-analytics' ) },
						h( 'button', { type: 'button', className: 'bla-radio-card', role: 'radio', 'aria-checked': draft.privacy_mode === 'cookieless', onClick: () => set( 'privacy_mode', 'cookieless' ) }, h( 'strong', null, __( 'Cookieless (recommended)', 'blue-lens-analytics' ) ), h( 'small', null, __( 'No cookies, no consent banner needed for analytics in most places. Visitors get an anonymous code that changes daily, so returning visitors are not recognised.', 'blue-lens-analytics' ) ) ),
						h( 'button', { type: 'button', className: 'bla-radio-card', role: 'radio', 'aria-checked': draft.privacy_mode === 'enhanced', onClick: () => set( 'privacy_mode', 'enhanced' ) }, h( 'strong', null, __( 'Enhanced, with consent', 'blue-lens-analytics' ) ), h( 'small', null, __( 'After a visitor accepts statistics cookies, a first-party cookie recognises return visits for 13 months. Requires a consent banner (WP Consent API supported).', 'blue-lens-analytics' ) ) )
					)
				),
				toggle( 'respect_gpc', __( 'Honour Global Privacy Control', 'blue-lens-analytics' ), __( 'Respect the browser signal meaning “do not sell or share my data”.', 'blue-lens-analytics' ) ),
				draft.respect_gpc && select( 'gpc_action', __( 'When a visitor sends GPC', 'blue-lens-analytics' ), null, [ [ 'cookieless', __( 'Count them cookie-free (recommended)', 'blue-lens-analytics' ) ], [ 'stop', __( 'Do not track them at all', 'blue-lens-analytics' ) ] ] ),
				toggle( 'respect_dnt', __( 'Honour Do Not Track', 'blue-lens-analytics' ), __( 'Stop tracking visitors whose browser sends the older Do Not Track signal.', 'blue-lens-analytics' ) )
			),
			features: h(
				Card,
				{ title: __( 'What to track', 'blue-lens-analytics' ), sub: __( 'Each group loads a small extra script only when switched on.', 'blue-lens-analytics' ), span: null },
				toggle( 'track_links', __( 'Links, contacts, downloads and CTAs', 'blue-lens-analytics' ), __( 'Outbound links, phone/email/WhatsApp taps, file downloads, tagged buttons, site search and 404 pages.', 'blue-lens-analytics' ) ),
				toggle( 'track_forms', __( 'Forms', 'blue-lens-analytics' ), __( 'Form starts, abandonment (last field reached) and successful submissions. Typed values are never recorded.', 'blue-lens-analytics' ) ),
				toggle( 'track_video', __( 'Videos', 'blue-lens-analytics' ), __( 'YouTube, Vimeo and HTML5 video plays and progress.', 'blue-lens-analytics' ) ),
				toggle( 'autocapture', __( 'All clicks (autocapture)', 'blue-lens-analytics' ), __( 'Clicks on every button and link, rage clicks, dead clicks and copied text (never the text itself).', 'blue-lens-analytics' ) ),
				toggle( 'heatmaps', __( 'Click heatmaps', 'blue-lens-analytics' ), __( 'Where people click on each page, stored as daily totals per page area.', 'blue-lens-analytics' ) ),
				toggle( 'track_errors', __( 'JavaScript errors', 'blue-lens-analytics' ), __( 'Useful to spot broken pages. Off by default.', 'blue-lens-analytics' ) ),
				toggle( 'web_vitals', __( 'Page speed (Core Web Vitals)', 'blue-lens-analytics' ), __( 'Loading, responsiveness and layout stability as real visitors experience them.', 'blue-lens-analytics' ) ),
				toggle( 'log_crawlers', __( 'Search engine & AI crawler log', 'blue-lens-analytics' ), __( 'Count Googlebot, Bingbot, GPTBot, ClaudeBot and others visiting your pages.', 'blue-lens-analytics' ) ),
				h(
					Field,
					{ label: __( 'File types counted as downloads', 'blue-lens-analytics' ), help: __( 'Separate with commas.', 'blue-lens-analytics' ) },
					h( 'input', { className: 'bla-input', style: { width: 280 }, value: ( draft.download_extensions || [] ).join( ', ' ), onChange: ( e ) => set( 'download_extensions', e.target.value.split( /[\s,]+/ ).filter( Boolean ) ) } )
				),
				list( 'cta_selectors', __( 'Extra call-to-action selectors', 'blue-lens-analytics' ), __( 'One CSS selector per line, e.g. .book-now or #enquire. You can also add data-bla-event="name" to any button.', 'blue-lens-analytics' ), '.book-now' )
			),
			exclusions: h(
				Card,
				{ title: __( 'Exclusions', 'blue-lens-analytics' ), sub: __( 'Keep staff and test visits out of your numbers.', 'blue-lens-analytics' ), span: null },
				h(
					Field,
					{ label: __( 'Roles not tracked', 'blue-lens-analytics' ), help: __( 'Logged-in users with these roles are ignored.', 'blue-lens-analytics' ), stack: true },
					h(
						'div',
						{ className: 'bla-checks' },
						Object.keys( boot.roles ).map( ( role ) => {
							const on = ( draft.excluded_roles || [] ).includes( role );
							return h(
								'label',
								{ key: role, className: 'bla-check' },
								h( 'input', { type: 'checkbox', checked: on, onChange: () => set( 'excluded_roles', on ? draft.excluded_roles.filter( ( r ) => r !== role ) : ( draft.excluded_roles || [] ).concat( role ) ) } ),
								boot.roles[ role ]
							);
						} )
					)
				),
				list( 'excluded_ips', __( 'IP addresses not tracked', 'blue-lens-analytics' ), __( 'One per line. Ranges allowed, e.g. 10.0.0.0/8. Use this for your office.', 'blue-lens-analytics' ), '197.232.10.4' ),
				list( 'excluded_paths', __( 'Pages not tracked', 'blue-lens-analytics' ), __( 'One path per line; * matches anything, e.g. /checkout/*', 'blue-lens-analytics' ), '/my-account/*' )
			),
			location: h(
				Card,
				{ title: __( 'Location (GeoIP)', 'blue-lens-analytics' ), sub: __( 'Country, region and city from a free database stored on your server. IP addresses are never saved.', 'blue-lens-analytics' ), span: null },
				select( 'geoip_provider', __( 'Provider', 'blue-lens-analytics' ), __( 'MaxMind needs a free account and licence key; DB-IP needs nothing but must be credited.', 'blue-lens-analytics' ), [ [ 'none', __( 'None (country only behind Cloudflare)', 'blue-lens-analytics' ) ], [ 'maxmind', 'MaxMind GeoLite2' ], [ 'dbip', 'DB-IP Lite' ] ] ),
				draft.geoip_provider === 'maxmind' && text( 'maxmind_account_id', __( 'MaxMind account ID', 'blue-lens-analytics' ), null, 'text', '123456' ),
				draft.geoip_provider === 'maxmind' && text( 'maxmind_license_key', __( 'MaxMind licence key', 'blue-lens-analytics' ), __( 'Stored on your site only; shown masked.', 'blue-lens-analytics' ), 'password', '' ),
				h( Field, { label: __( 'Database status', 'blue-lens-analytics' ), help: __( 'Downloads run in the background after saving. Check progress or update manually on the Status page.', 'blue-lens-analytics' ) }, h( 'a', { className: 'bla-btn bla-btn--sm', href: boot.links.status }, __( 'Open Status', 'blue-lens-analytics' ) ) )
			),
			audit: h(
				Card,
				{ title: __( 'Site audit', 'blue-lens-analytics' ), sub: __( 'Blue Lens fetches your own pages from your server, like a search engine would. Nothing is sent to outside services.', 'blue-lens-analytics' ), span: null },
				number( 'audit_max_pages', __( 'Most pages per audit', 'blue-lens-analytics' ), __( 'The home page, your most recently updated content and the busiest category and tag archives, up to this number. Larger audits take longer.', 'blue-lens-analytics' ), 10, 2000 ),
				toggle( 'audit_weekly', __( 'Run an audit every week', 'blue-lens-analytics' ), __( 'Keeps the health score and the On-Page SEO ideas current without you pressing a button.', 'blue-lens-analytics' ) )
			),
			advanced: h(
				Card,
				{ title: __( 'Advanced', 'blue-lens-analytics' ), span: null },
				select( 'ip_header', __( 'Visitor IP source', 'blue-lens-analytics' ), __( 'Change only if your site is behind a proxy or CDN you trust (e.g. Cloudflare). A wrong value lets visitors fake their address.', 'blue-lens-analytics' ), [ [ 'remote_addr', __( 'Direct connection (default)', 'blue-lens-analytics' ) ], [ 'http_cf_connecting_ip', 'Cloudflare (CF-Connecting-IP)' ], [ 'http_x_forwarded_for', 'X-Forwarded-For' ], [ 'http_x_real_ip', 'X-Real-IP' ], [ 'http_true_client_ip', 'True-Client-IP' ] ] ),
				number( 'session_timeout_minutes', __( 'Session timeout (minutes)', 'blue-lens-analytics' ), __( 'A visit ends after this much inactivity.', 'blue-lens-analytics' ), 5, 240 ),
				number( 'heartbeat_seconds', __( 'Engaged-time heartbeat (seconds)', 'blue-lens-analytics' ), __( 'How often reading time is sent while a page is open. 0 sends it only when leaving.', 'blue-lens-analytics' ), 0, 300 )
			),
			data: h(
				Fragment,
				null,
				h(
					Card,
					{ title: __( 'Data retention', 'blue-lens-analytics' ), span: null },
					number( 'retention_raw_months', __( 'Keep detailed data for (months)', 'blue-lens-analytics' ), __( 'Individual visits and events are deleted automatically after this period. Default 13.', 'blue-lens-analytics' ), 1, 120 ),
					number( 'retention_aggregate_months', __( 'Keep daily summaries for (months)', 'blue-lens-analytics' ), __( '0 keeps summaries forever.', 'blue-lens-analytics' ), 0, 600 )
				),
				h(
					Card,
					{ title: __( 'Uninstall', 'blue-lens-analytics' ), span: null, className: 'bla-danger-zone' },
					toggle( 'delete_data_on_uninstall', __( 'Delete all data when the plugin is deleted', 'blue-lens-analytics' ), __( 'Removes every Blue Lens table, setting and file. This cannot be undone. Leave off to keep your history.', 'blue-lens-analytics' ) )
				)
			),
		};

		return h(
			'div',
			{ className: 'bla-settings' },
			h(
				'nav',
				{ className: 'bla-settings__nav', 'aria-label': __( 'Settings sections', 'blue-lens-analytics' ) },
				SETTINGS_SECTIONS.map( ( s ) => h( 'button', { key: s.id, type: 'button', 'aria-current': section === s.id, onClick: () => setSection( s.id ) }, s.label ) )
			),
			h(
				'div',
				{ className: 'bla-settings__panel' },
				error && h( ErrorBox, { message: error } ),
				panels[ section ],
				h(
					'div',
					{ className: 'bla-savebar' },
					h( 'span', { className: 'bla-savebar__msg' + ( dirtyKeys.length ? ' is-dirty' : '' ) }, dirtyKeys.length ? __( 'You have unsaved changes', 'blue-lens-analytics' ) : __( 'All changes saved', 'blue-lens-analytics' ) ),
					h( 'button', { type: 'button', className: 'bla-btn', disabled: ! dirtyKeys.length || saving, onClick: () => setDraft( saved ) }, __( 'Discard', 'blue-lens-analytics' ) ),
					h( 'button', { type: 'button', className: 'bla-btn bla-btn--primary', disabled: ! dirtyKeys.length || saving, onClick: save }, saving ? __( 'Saving…', 'blue-lens-analytics' ) : __( 'Save changes', 'blue-lens-analytics' ) )
				)
			)
		);
	}

	/* ================================================================== */
	/* App                                                                 */
	/* ================================================================== */

	function applyBodyClasses( prefs ) {
		const b = document.body.classList;
		[ ...b ].forEach( ( c ) => /^bla-(theme|accent|density)-/.test( c ) && b.remove( c ) );
		b.add( 'bla-theme-' + prefs.theme, 'bla-accent-' + prefs.accent, 'bla-density-' + prefs.density );
	}

	function initialRange( prefs ) {
		try {
			const saved = JSON.parse( window.sessionStorage.getItem( 'bla_range' ) || 'null' );
			if ( saved && saved.from && saved.to ) {
				return saved.key === 'custom' ? saved : resolveRange( saved.key );
			}
		} catch ( e ) {}
		return resolveRange( prefs.range );
	}

	function App( { initialRoute } ) {
		const [ prefs, setPrefs ] = useState( boot.prefs );
		const [ route, setRouteState ] = useState( () => {
			const fromHash = ( window.location.hash.match( /^#\/([a-z]+)/ ) || [] )[ 1 ];
			return PAGES.find( ( p ) => p.id === fromHash ) ? fromHash : initialRoute;
		} );
		const [ range, setRangeState ] = useState( () => initialRange( boot.prefs ) );
		const [ compare, setCompare ] = useState( boot.prefs.compare );
		const [ refreshKey, setRefreshKey ] = useState( 0 );
		const [ lastAggregated, setLastAggregated ] = useState( boot.lastAggregated );
		const [ refreshing, setRefreshing ] = useState( false );
		const [ drawer, setDrawer ] = useState( false );
		const [ toast, setToast ] = useState( '' );
		const saveTimer = useRef( null );

		const showToast = useCallback( ( msg ) => {
			setToast( msg );
			setTimeout( () => setToast( '' ), 2600 );
		}, [] );

		const setRoute = ( id ) => {
			setRouteState( id );
			window.history.replaceState( null, '', '#/' + id );
			window.scrollTo( 0, 0 );
		};

		const setRange = ( r ) => {
			setRangeState( r );
			try {
				window.sessionStorage.setItem( 'bla_range', JSON.stringify( r ) );
			} catch ( e ) {}
		};

		const persist = ( next ) => {
			clearTimeout( saveTimer.current );
			saveTimer.current = setTimeout( () => {
				apiFetch( { path: API + '/preferences', method: 'POST', data: next } ).catch( () => showToast( __( 'Your preferences could not be saved.', 'blue-lens-analytics' ) ) );
			}, 500 );
		};

		const savePrefs = ( partial ) => {
			setPrefs( ( p ) => {
				const next = { ...p, ...partial };
				applyBodyClasses( next );
				persist( next );
				return next;
			} );
		};

		const resetPrefs = () => {
			const defaults = { theme: 'light', accent: 'blue', density: 'comfortable', tiles: 'subtle', range: 'last28', compare: 'previous', kpis: [ 'visitors', 'sessions', 'pageviews', 'engagement_rate', 'conversions', 'avg_engaged_time' ], layout: {} };
			savePrefs( defaults );
			showToast( __( 'Dashboard reset to defaults', 'blue-lens-analytics' ) );
		};

		const onRefresh = () => {
			setRefreshing( true );
			apiFetch( { path: API + '/reports/refresh', method: 'POST' } )
				.then( ( r ) => {
					setLastAggregated( r.last_aggregated );
					setRefreshKey( ( k ) => k + 1 );
					showToast( __( 'Today’s data refreshed', 'blue-lens-analytics' ) );
				} )
				.catch( ( e ) => showToast( e.message ) )
				.finally( () => setRefreshing( false ) );
		};

		useEffect( () => {
			const onHash = () => {
				const id = ( window.location.hash.match( /^#\/([a-z]+)/ ) || [] )[ 1 ];
				if ( id && PAGES.find( ( p ) => p.id === id ) ) {
					setRouteState( id );
				}
			};
			window.addEventListener( 'hashchange', onHash );
			return () => window.removeEventListener( 'hashchange', onHash );
		}, [] );

		const page = PAGES.find( ( p ) => p.id === route ) || PAGES[ 0 ];
		const props = { range, compare, refreshKey, prefs, onRefresh, go: setRoute };

		let body;
		switch ( route ) {
			case 'acquisition':
				body = h( AcquisitionPage, props );
				break;
			case 'audience':
				body = h( AudiencePage, props );
				break;
			case 'content':
				body = h( ContentPage, props );
				break;
			case 'engagement':
				body = h( EngagementPage, props );
				break;
			case 'heatmaps':
				body = h( HeatmapsPage, props );
				break;
			case 'audit':
				body = h( SiteAuditPage, props );
				break;
			case 'onpage':
				body = h( OnPageSeoPage, props );
				break;
			case 'ads':
				body = boot.localAds && boot.localAds.active ? h( AdsPage, props ) : h( OverviewPage, props );
				break;
			case 'settings':
				body = boot.canManage ? h( SettingsPage, { showToast } ) : h( ErrorBox, { message: __( 'You do not have permission to change settings.', 'blue-lens-analytics' ) } );
				break;
			default:
				body = h( OverviewPage, props );
		}

		return h(
			'div',
			{ className: 'bla-shell' },
			h( Sidebar, { route, setRoute } ),
			h(
				'div',
				{ className: 'bla-shell__main' },
				h( TopBar, { page, range, setRange, compare, setCompare, lastAggregated, onRefresh, refreshing, prefs, savePrefs, openCustomize: () => setDrawer( true ) } ),
				h( 'main', { className: 'bla-main' }, body )
			),
			drawer && h( CustomizeDrawer, { prefs, savePrefs, route, onClose: () => setDrawer( false ), resetPrefs } ),
			h( Toast, { message: toast } )
		);
	}

	const node = document.getElementById( 'blue-lens-app' );
	const app = h( App, { initialRoute: node.getAttribute( 'data-route' ) || 'overview' } );
	if ( wp.element.createRoot ) {
		wp.element.createRoot( node ).render( app );
	} else {
		wp.element.render( app, node );
	}
}( window.wp, window.BlueLensAdmin ) );

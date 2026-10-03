/*! Blue Lens Analytics automatic events | GPL-2.0-or-later */
( function ( w, d ) {
	'use strict';

	if ( ! w.BlueLens || ! w.BlueLens.use ) {
		return;
	}

	w.BlueLens.use( function ( bl ) {
		var cfg = bl.cfg;
		var loc = w.location;
		var track = bl.track;

		function matches( el, sel ) {
			try {
				return ( el.matches || el.msMatchesSelector ).call( el, sel );
			} catch ( e ) {
				return false;
			}
		}

		function closest( el, sel ) {
			while ( el && el.nodeType === 1 ) {
				if ( matches( el, sel ) ) {
					return el;
				}
				el = el.parentElement;
			}
			return null;
		}

		// Visible text never includes typed values; emails and phone numbers are masked before sending.
		function scrub( s ) {
			return String( s || '' )
				.replace( /[^\s@]+@[^\s@]+/g, '[email]' )
				.replace( /\+?\d[\d\s().-]{7,}\d/g, '[phone]' );
		}

		function label( el ) {
			var t = el.getAttribute( 'aria-label' ) || el.getAttribute( 'title' ) || el.textContent || '';
			return scrub( t.replace( /\s+/g, ' ' ).trim() ).slice( 0, 60 );
		}

		function parseUrl( href ) {
			try {
				return new URL( href, loc.href );
			} catch ( e ) {
				return null;
			}
		}

		function referrerSource() {
			var r = parseUrl( d.referrer );
			if ( ! r ) {
				return 'direct';
			}
			return r.hostname === loc.hostname ? r.pathname.slice( 0, 120 ) : r.hostname.replace( /^www\./, '' );
		}

		/* ---- Page-level events ------------------------------------------------ */

		var ctx = bl.ctx();
		if ( ctx.k === 'search' && ctx.sq !== undefined ) {
			track( 'site_search', { query: ctx.sq, results: ctx.sr, zero_results: ctx.sr === 0 } );
		}
		if ( ctx.k === '404' ) {
			track( 'page_not_found', { from: referrerSource() } );
		}

		/* ---- Links, contact, downloads, CTAs ---------------------------------- */

		if ( cfg.lk ) {
			d.addEventListener( 'click', function ( e ) {
				var cta = closest( e.target, '[data-bla-event]' ) || ( cfg.cs && cfg.cs.length ? closest( e.target, cfg.cs.join( ',' ) ) : null );
				if ( cta ) {
					var name = cta.getAttribute( 'data-bla-event' ) || 'cta_click';
					var props = {};
					try {
						props = JSON.parse( cta.getAttribute( 'data-bla-props' ) || '{}' ) || {};
					} catch ( err ) {}
					if ( cta.getAttribute( 'data-bla-category' ) ) {
						props.category = cta.getAttribute( 'data-bla-category' );
					}
					props.label = props.label || label( cta );
					track( name, props );
				}

				var a = closest( e.target, 'a[href]' );
				if ( ! a ) {
					return;
				}
				var href = a.getAttribute( 'href' ) || '';
				var scheme = href.split( ':' )[ 0 ].toLowerCase();
				var methods = { tel: 'phone', mailto: 'email', sms: 'sms', whatsapp: 'whatsapp', skype: 'skype', viber: 'viber' };

				if ( methods[ scheme ] ) {
					track( 'contact_click', { method: methods[ scheme ], label: label( a ) } );
					return;
				}

				var u = parseUrl( href );
				if ( ! u || ! /^https?:$/.test( u.protocol ) ) {
					return;
				}
				if ( /(^|\.)(wa\.me|whatsapp\.com|m\.me|t\.me)$/.test( u.hostname ) ) {
					track( 'contact_click', { method: /m\.me$/.test( u.hostname ) ? 'messenger' : /t\.me$/.test( u.hostname ) ? 'telegram' : 'whatsapp', label: label( a ) } );
					bl.flush();
					return;
				}

				var file = u.pathname.split( '/' ).pop();
				var ext = file.indexOf( '.' ) > -1 ? file.split( '.' ).pop().toLowerCase() : '';
				if ( ext && ( cfg.dx || [] ).indexOf( ext ) > -1 ) {
					track( 'file_download', { file: file.slice( 0, 100 ), extension: ext, url: ( u.hostname + u.pathname ).slice( 0, 200 ) } );
					bl.flush();
					return;
				}

				if ( u.hostname && u.hostname !== loc.hostname ) {
					track( 'outbound_click', {
						domain: u.hostname.replace( /^www\./, '' ),
						url: ( u.hostname + u.pathname ).slice( 0, 200 ),
						label: label( a ),
					} );
					bl.flush();
				}
			}, true );
		}

		/* ---- Forms: start, abandon, submit (no field values, ever) ------------- */

		if ( cfg.fm ) {
			var forms = {};
			var SKIP = /^(password|hidden|submit|button|reset|image|file)$/;

			var formId = function ( f ) {
				var v;
				var nf = closest( f, '.nf-form-cont' );
				if ( ( v = f.querySelector( 'input[name="_wpcf7"]' ) ) ) {
					return 'cf7:' + v.value;
				}
				if ( /^gform_(\d+)$/.test( f.id ) ) {
					return 'gf:' + f.id.slice( 6 );
				}
				if ( f.getAttribute( 'data-formid' ) ) {
					return 'wpforms:' + f.getAttribute( 'data-formid' );
				}
				if ( f.getAttribute( 'data-form_id' ) ) {
					return 'fluent:' + f.getAttribute( 'data-form_id' );
				}
				if ( ( v = f.querySelector( 'input[name="form_id"]' ) ) ) {
					if ( f.classList.contains( 'elementor-form' ) ) {
						return 'elementor:' + v.value;
					}
					if ( f.classList.contains( 'frm-show-form' ) ) {
						return 'frm:' + v.value;
					}
				}
				if ( nf && /\d+/.test( nf.id ) ) {
					return 'nf:' + nf.id.match( /\d+/ )[ 0 ];
				}
				return 'html:' + ( f.id || f.getAttribute( 'name' ) || 'form' + Array.prototype.indexOf.call( d.forms, f ) );
			};

			var stateFor = function ( f ) {
				var id = formId( f ).replace( /[^A-Za-z0-9_\-.:]/g, '' ).slice( 0, 64 );
				if ( ! forms[ id ] ) {
					forms[ id ] = { id: id, started: false, submitted: false, reported: false, last: '', fields: {} };
				}
				return forms[ id ];
			};

			var fieldName = function ( el ) {
				return String( el.getAttribute( 'name' ) || el.id || el.type || 'field' )
					.replace( /[^A-Za-z0-9_\-[\]]/g, '' )
					.slice( 0, 40 );
			};

			d.addEventListener( 'focusin', function ( e ) {
				var el = e.target;
				var f = el && el.form;
				if ( ! f || SKIP.test( el.type || '' ) ) {
					return;
				}
				var s = stateFor( f );
				s.last = fieldName( el );
				s.fields[ s.last ] = 1;
				if ( ! s.started ) {
					s.started = true;
					track( 'form_start', { entity_type: 'form', entity_id: s.id, first_field: s.last } );
				}
			}, true );

			d.addEventListener( 'submit', function ( e ) {
				var s = stateFor( e.target );
				s.submitted = true;
				// Supported plugins report successful submissions server-side; plain HTML forms are counted here.
				if ( /^html:/.test( s.id ) ) {
					track( 'form_submit', { entity_type: 'form', entity_id: s.id, form_plugin: 'html' } );
					bl.flush();
				}
			}, true );

			bl.on( 'hide', function () {
				Object.keys( forms ).forEach( function ( id ) {
					var s = forms[ id ];
					if ( s.started && ! s.submitted && ! s.reported ) {
						s.reported = true;
						track( 'form_abandon', {
							entity_type: 'form',
							entity_id: s.id,
							last_field: s.last,
							fields_touched: Object.keys( s.fields ).length,
						} );
					}
				} );
			} );
			bl.on( 'page', function () {
				forms = {};
			} );
		}

		/* ---- Video: HTML5, YouTube, Vimeo ------------------------------------- */

		if ( cfg.vd ) {
			var videos = {};

			var progress = function ( key, meta, pct, state ) {
				var v = videos[ key ] || ( videos[ key ] = { started: false, marks: {}, done: false } );
				var base = { provider: meta.provider, video_id: meta.id, title: scrub( meta.title || '' ).slice( 0, 100 ) };
				if ( state === 'play' && ! v.started ) {
					v.started = true;
					track( 'video_start', base );
				}
				[ 25, 50, 75 ].forEach( function ( m ) {
					if ( v.started && pct >= m && ! v.marks[ m ] ) {
						v.marks[ m ] = 1;
						base.percent = m;
						track( 'video_progress', base );
					}
				} );
				if ( state === 'ended' && v.started && ! v.done ) {
					v.done = true;
					delete base.percent;
					track( 'video_complete', base );
				}
			};

			var html5 = function ( e ) {
				var el = e.target;
				if ( ! el || el.tagName !== 'VIDEO' ) {
					return;
				}
				var src = String( el.currentSrc || el.src || '' ).split( '?' )[ 0 ];
				var meta = { provider: 'html5', id: src.split( '/' ).pop().slice( 0, 64 ), title: el.getAttribute( 'title' ) || el.getAttribute( 'aria-label' ) };
				var pct = el.duration ? ( el.currentTime / el.duration ) * 100 : 0;
				progress( 'h5:' + src, meta, pct, e.type === 'play' ? 'play' : e.type === 'ended' ? 'ended' : 'time' );
			};
			[ 'play', 'timeupdate', 'ended' ].forEach( function ( t ) {
				d.addEventListener( t, html5, true );
			} );

			var frames = [];
			Array.prototype.forEach.call( d.querySelectorAll( 'iframe[src]' ), function ( f, i ) {
				var src = f.getAttribute( 'src' );
				var yt = src.match( /youtube(?:-nocookie)?\.com\/embed\/([\w-]{6,})/ );
				var vm = src.match( /player\.vimeo\.com\/video\/(\d+)/ );
				if ( yt ) {
					if ( ! /[?&]enablejsapi=1/.test( src ) ) {
						// The YouTube player only reports state when the JS API is enabled; this reloads the embed once.
						f.setAttribute( 'src', src + ( src.indexOf( '?' ) > -1 ? '&' : '?' ) + 'enablejsapi=1&origin=' + encodeURIComponent( loc.origin ) );
					}
					frames.push( { el: f, provider: 'youtube', id: yt[ 1 ], key: i } );
				} else if ( vm ) {
					frames.push( { el: f, provider: 'vimeo', id: vm[ 1 ], key: i } );
				}
			} );

			var hello = function ( fr ) {
				try {
					if ( fr.provider === 'youtube' ) {
						fr.el.contentWindow.postMessage( JSON.stringify( { event: 'listening', id: fr.key, channel: 'widget' } ), '*' );
					} else {
						[ 'play', 'timeupdate', 'playProgress', 'ended', 'finish' ].forEach( function ( ev ) {
							fr.el.contentWindow.postMessage( JSON.stringify( { method: 'addEventListener', value: ev } ), 'https://player.vimeo.com' );
						} );
					}
				} catch ( e ) {}
			};

			frames.forEach( function ( fr ) {
				fr.el.addEventListener( 'load', function () {
					hello( fr );
				} );
				hello( fr );
			} );

			if ( frames.length ) {
				w.addEventListener( 'message', function ( e ) {
					if ( ! /youtube(-nocookie)?\.com$|vimeo\.com$/.test( String( e.origin ).replace( /^https?:\/\//, '' ) ) ) {
						return;
					}
					var msg;
					try {
						msg = typeof e.data === 'string' ? JSON.parse( e.data ) : e.data;
					} catch ( err ) {
						return;
					}
					var fr = frames.filter( function ( f ) {
						return f.el.contentWindow === e.source;
					} )[ 0 ];
					if ( ! fr || ! msg ) {
						return;
					}
					var meta = { provider: fr.provider, id: fr.id, title: fr.el.getAttribute( 'title' ) };
					if ( fr.provider === 'youtube' ) {
						var info = msg.info;
						if ( msg.event === 'onStateChange' ) {
							info = { playerState: msg.info };
						}
						if ( ! info || typeof info !== 'object' ) {
							return;
						}
						fr.dur = info.duration || fr.dur;
						fr.t = info.currentTime !== undefined ? info.currentTime : fr.t;
						if ( info.videoData && info.videoData.title ) {
							fr.title = info.videoData.title;
						}
						meta.title = fr.title || meta.title;
						var st = info.playerState === 1 ? 'play' : info.playerState === 0 ? 'ended' : 'time';
						progress( 'yt:' + fr.key, meta, fr.dur ? ( fr.t / fr.dur ) * 100 : 0, st );
					} else {
						if ( msg.event === 'ready' ) {
							hello( fr );
							return;
						}
						var data = msg.data || {};
						var vst = msg.event === 'play' ? 'play' : msg.event === 'ended' || msg.event === 'finish' ? 'ended' : 'time';
						progress( 'vm:' + fr.key, meta, ( data.percent || 0 ) * 100, vst );
					}
				} );
			}

			bl.on( 'page', function () {
				videos = {};
			} );
		}

		/* ---- JavaScript errors ------------------------------------------------ */

		if ( cfg.er ) {
			var seen = {};
			var errors = 0;
			var report = function ( message, file, line ) {
				message = scrub( String( message || 'Unknown error' ) ).slice( 0, 150 );
				if ( errors >= 5 || seen[ message ] ) {
					return;
				}
				seen[ message ] = 1;
				errors++;
				var u = parseUrl( file || '' );
				track( 'js_error', { message: message, source: u ? ( u.hostname + u.pathname ).slice( 0, 150 ) : '', line: line || 0 } );
			};
			w.addEventListener( 'error', function ( e ) {
				if ( e.message ) {
					report( e.message, e.filename, e.lineno );
				}
			} );
			w.addEventListener( 'unhandledrejection', function ( e ) {
				var r = e.reason;
				report( 'Unhandled rejection: ' + ( r && r.message ? r.message : r ), '', 0 );
			} );
		}

		/* ---- Core Web Vitals (simplified LCP / CLS / INP) --------------------- */

		if ( cfg.wv && w.PerformanceObserver ) {
			var lcp = 0;
			var cls = 0;
			var inp = 0;
			var vitalsSent = false;
			var observe = function ( type, fn, extra ) {
				try {
					var opts = { type: type, buffered: true };
					Object.keys( extra || {} ).forEach( function ( k ) {
						opts[ k ] = extra[ k ];
					} );
					new w.PerformanceObserver( function ( list ) {
						list.getEntries().forEach( fn );
					} ).observe( opts );
				} catch ( e ) {}
			};
			observe( 'largest-contentful-paint', function ( e ) {
				lcp = e.startTime;
			} );
			observe( 'layout-shift', function ( e ) {
				if ( ! e.hadRecentInput ) {
					cls += e.value;
				}
			} );
			observe( 'event', function ( e ) {
				if ( e.interactionId && e.duration > inp ) {
					inp = e.duration;
				}
			}, { durationThreshold: 40 } );

			bl.on( 'hide', function () {
				if ( vitalsSent || ( ! lcp && ! cls && ! inp ) ) {
					return;
				}
				vitalsSent = true;
				track( 'web_vitals', { lcp_ms: Math.round( lcp ), cls: Math.round( cls * 1000 ) / 1000, inp_ms: Math.round( inp ) } );
			} );
		}
	} );
}( window, document ) );

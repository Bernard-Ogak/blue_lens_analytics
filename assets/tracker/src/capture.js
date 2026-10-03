/*! Blue Lens Analytics autocapture and heatmaps | GPL-2.0-or-later */
( function ( w, d ) {
	'use strict';

	if ( ! w.BlueLens || ! w.BlueLens.use ) {
		return;
	}

	w.BlueLens.use( function ( bl ) {
		var cfg = bl.cfg;
		var loc = w.location;
		var MAX_EVENTS = 100; // Autocapture events per page view.
		var INTERACTIVE = 'a,button,summary,label,select,[role="button"],[role="link"],[role="tab"],[role="menuitem"],[role="checkbox"],[role="switch"],input[type="submit"],input[type="button"],input[type="checkbox"],input[type="radio"],[onclick],[data-bla-id]';
		var NATIVE_EFFECT = /^(input|select|label|summary|option)$/;
		var count = 0;
		var recent = [];

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

		function scrub( s ) {
			return String( s || '' )
				.replace( /[^\s@]+@[^\s@]+/g, '[email]' )
				.replace( /\+?\d[\d\s().-]{7,}\d/g, '[phone]' );
		}

		// Visible label only: aria-label, title, image alt or text content. Never an input's value.
		function label( el ) {
			if ( ! el || el.nodeType !== 1 ) {
				return '';
			}
			var img = el.querySelector && el.querySelector( 'img[alt]' );
			var t = el.getAttribute( 'aria-label' ) || el.getAttribute( 'title' ) || ( el.tagName === 'INPUT' ? '' : el.textContent ) || ( img ? img.getAttribute( 'alt' ) : '' ) || '';
			return scrub( t.replace( /\s+/g, ' ' ).trim() ).slice( 0, 60 );
		}

		// Short, stable selector: nearest meaningful id or data-bla-id, else tag.class path (max 4 levels).
		function selector( el ) {
			var parts = [];
			var node = el;
			var depth = 0;
			while ( node && node.nodeType === 1 && depth < 4 ) {
				var marker = node.getAttribute( 'data-bla-id' );
				if ( marker ) {
					parts.unshift( '[data-bla-id="' + marker.slice( 0, 40 ) + '"]' );
					break;
				}
				if ( node.id && ! /\d{3,}|^[a-f0-9-]{16,}$/i.test( node.id ) ) {
					parts.unshift( '#' + node.id.slice( 0, 40 ) );
					break;
				}
				var classes = Array.prototype.filter.call( node.classList || [], function ( c ) {
					return ! /\d{2,}|^(is-|has-)|active|hover|focus|open|selected/.test( c );
				} ).slice( 0, 2 );
				parts.unshift( node.tagName.toLowerCase() + ( classes.length ? '.' + classes.join( '.' ) : '' ) );
				node = node.parentElement;
				depth++;
			}
			return parts.join( ' > ' ).slice( 0, 120 );
		}

		function capture( name, props ) {
			if ( count >= MAX_EVENTS ) {
				return;
			}
			count++;
			bl.track( name, props );
		}

		// Dead click: no DOM change, navigation or URL change within one second of clicking a control.
		function watchDead( el, props ) {
			if ( ! w.MutationObserver || NATIVE_EFFECT.test( el.tagName.toLowerCase() ) ) {
				return;
			}
			var changed = false;
			var start = loc.href;
			var mo = new w.MutationObserver( function () {
				changed = true;
			} );
			mo.observe( d.body || d.documentElement, { childList: true, subtree: true, attributes: true, characterData: true } );
			setTimeout( function () {
				mo.disconnect();
				if ( ! changed && loc.href === start && d.visibilityState === 'visible' ) {
					capture( 'dead_click', { sel: props.sel, label: props.label } );
				}
			}, 1000 );
		}

		d.addEventListener( 'click', function ( e ) {
			if ( bl.disabled() ) {
				return;
			}
			var target = e.target;
			// detail is 0 for keyboard-activated and scripted clicks: they have no real position.
			var pointer = e.detail > 0;

			if ( cfg.hm && pointer && e.pageX !== undefined ) {
				var de = d.documentElement;
				var width = Math.max( de.scrollWidth, de.clientWidth ) || 1;
				var x = Math.floor( ( e.pageX / width ) * 100 );
				var y = Math.floor( e.pageY / 20 );
				if ( x >= 0 && y >= 0 ) {
					bl.heat( Math.min( 99, x ), Math.min( 999, y ) );
				}
			}

			if ( ! cfg.ac || closest( target, '[data-bla-ignore]' ) ) {
				return;
			}

			var el = closest( target, INTERACTIVE );

			// Rage click: three pointer clicks on the same element within 800 ms inside a 30 px radius.
			if ( pointer ) {
				var now = Date.now();
				recent = recent.filter( function ( r ) {
					return now - r.t < 800;
				} );
				recent.push( { t: now, x: e.clientX, y: e.clientY, el: el || target } );
				var near = recent.filter( function ( r ) {
					return r.el === ( el || target ) && Math.abs( r.x - e.clientX ) < 30 && Math.abs( r.y - e.clientY ) < 30;
				} );
				if ( near.length === 3 ) {
					capture( 'rage_click', { sel: selector( el || target ), label: label( el || target ) } );
				}
			}

			if ( ! el || /^(password|hidden)$/.test( el.type || '' ) ) {
				return;
			}

			var props = { tag: el.tagName.toLowerCase(), sel: selector( el ), label: label( el ) };
			var href = el.getAttribute( 'href' );
			if ( href && href.charAt( 0 ) !== '#' ) {
				try {
					var u = new URL( href, loc.href );
					props.target = u.hostname === loc.hostname ? u.pathname.slice( 0, 120 ) : u.hostname;
				} catch ( err ) {}
			}
			capture( 'click', props );

			if ( ! href || href.charAt( 0 ) === '#' ) {
				watchDead( el, props );
			}
		}, true );

		// Copy: records how much and what kind (phone, email, text) — never the copied text itself.
		d.addEventListener( 'copy', function () {
			if ( ! cfg.ac || bl.disabled() || ! w.getSelection ) {
				return;
			}
			var sel = w.getSelection();
			var text = String( sel || '' );
			if ( ! text ) {
				return;
			}
			var node = sel.anchorNode;
			var el = node && ( node.nodeType === 1 ? node : node.parentElement );
			var kind = /[^\s@]+@[^\s@]+\.[a-z]{2,}/i.test( text ) ? 'email' : /\+?\d[\d\s().-]{7,}\d/.test( text ) ? 'phone' : 'text';
			capture( 'copy_text', { chars: text.length, kind: kind, sel: el ? selector( el ) : '' } );
		}, true );

		bl.on( 'page', function () {
			count = 0;
			recent = [];
		} );
	} );
}( window, document ) );

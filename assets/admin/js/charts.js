/*! Blue Lens Analytics charts | GPL-2.0-or-later */
/**
 * Lightweight SVG charts on WordPress's bundled React (wp.element). No third-party chart library.
 *
 * Conventions (see the data-viz spec): 2px lines, area fills as a faint wash, hairline solid
 * gridlines, 4px rounded bar ends, 2px surface gaps between touching marks, surface rings on
 * markers, text in text tokens (never series colours), tooltips that enhance but never gate
 * (every chart has a table view), and keyboard access matching hover.
 */
( function ( wp ) {
	'use strict';

	const { createElement: h, useState, useRef, useEffect, useMemo, Fragment } = wp.element;
	const { __, sprintf } = wp.i18n;

	/* ------------------------------------------------------------------ */
	/* Formatting                                                          */
	/* ------------------------------------------------------------------ */

	const cfg = { locale: 'en', currency: 'USD' };

	const nf = ( opts ) => {
		try {
			return new Intl.NumberFormat( cfg.locale, opts );
		} catch ( e ) {
			return new Intl.NumberFormat( 'en', opts );
		}
	};

	const fmt = {
		init( locale, currency ) {
			cfg.locale = locale || 'en';
			cfg.currency = currency || 'USD';
		},
		number: ( n ) => nf( { maximumFractionDigits: 0 } ).format( Math.round( n || 0 ) ),
		compact: ( n ) =>
			Math.abs( n || 0 ) >= 10000
				? nf( { notation: 'compact', maximumFractionDigits: 1 } ).format( n )
				: fmt.number( n ),
		decimal: ( n ) => nf( { maximumFractionDigits: 2 } ).format( n || 0 ),
		percent: ( r ) => nf( { style: 'percent', maximumFractionDigits: 1 } ).format( r || 0 ),
		money: ( n ) => {
			const big = Math.abs( n || 0 ) >= 100000;
			try {
				return nf( { style: 'currency', currency: cfg.currency, notation: big ? 'compact' : 'standard', maximumFractionDigits: big ? 1 : 0 } ).format( n || 0 );
			} catch ( e ) {
				return cfg.currency + ' ' + fmt.number( n );
			}
		},
		duration( s ) {
			s = Math.round( s || 0 );
			if ( s < 60 ) {
				return sprintf( /* translators: %d: seconds. */ __( '%ds', 'blue-lens-analytics' ), s );
			}
			if ( s < 3600 ) {
				return sprintf( /* translators: 1: minutes, 2: seconds. */ __( '%1$dm %2$02ds', 'blue-lens-analytics' ), Math.floor( s / 60 ), s % 60 );
			}
			return sprintf( /* translators: 1: hours, 2: minutes. */ __( '%1$dh %2$02dm', 'blue-lens-analytics' ), Math.floor( s / 3600 ), Math.floor( ( s % 3600 ) / 60 ) );
		},
		date( iso, withYear ) {
			const d = new Date( iso + 'T00:00:00' );
			try {
				return d.toLocaleDateString( cfg.locale, withYear ? { day: 'numeric', month: 'short', year: 'numeric' } : { day: 'numeric', month: 'short' } );
			} catch ( e ) {
				return iso;
			}
		},
		/** Formatter for a metric kind. */
		of( kind ) {
			return (
				{
					percent: fmt.percent,
					duration: fmt.duration,
					money: fmt.money,
					decimal: fmt.decimal,
					compact: fmt.compact,
				}[ kind ] || fmt.number
			);
		},
	};

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function useWidth( ref ) {
		const [ width, setWidth ] = useState( 0 );
		useEffect( () => {
			const node = ref.current;
			if ( ! node ) {
				return undefined;
			}
			setWidth( node.clientWidth );
			if ( ! window.ResizeObserver ) {
				return undefined;
			}
			const ro = new window.ResizeObserver( ( entries ) => setWidth( Math.floor( entries[ 0 ].contentRect.width ) ) );
			ro.observe( node );
			return () => ro.disconnect();
		}, [] );
		return width;
	}

	/**
	 * Clean axis: the smallest round step (1, 2, 2.5 or 5 × 10^n) that covers max in at most 5 steps.
	 *
	 * @return {{max: number, ticks: number[]}}
	 */
	function niceScale( max ) {
		if ( ! ( max > 0 ) ) {
			return { max: 4, ticks: [ 0, 1, 2, 3, 4 ] };
		}
		const exp = Math.pow( 10, Math.floor( Math.log10( max / 5 ) ) );
		let step = exp;
		for ( const m of [ 1, 2, 2.5, 5, 10 ] ) {
			step = m * exp;
			if ( max / step <= 5 ) {
				break;
			}
		}
		if ( max < 5 && step < 1 ) {
			step = 1; // Counts are whole numbers.
		}
		const count = Math.max( 1, Math.ceil( max / step - 1e-9 ) );
		const ticks = [];
		for ( let i = 0; i <= count; i++ ) {
			ticks.push( Math.round( i * step * 1e6 ) / 1e6 );
		}
		return { max: step * count, ticks };
	}

	const niceMax = ( max ) => niceScale( max ).max;

	// Monotone cubic interpolation (Fritsch–Carlson): smooth, never overshoots below zero.
	function smoothPath( pts ) {
		const n = pts.length;
		if ( ! n ) {
			return '';
		}
		if ( n === 1 ) {
			return 'M' + pts[ 0 ][ 0 ] + ',' + pts[ 0 ][ 1 ];
		}
		const d = [];
		const m = [];
		for ( let i = 0; i < n - 1; i++ ) {
			const dx = pts[ i + 1 ][ 0 ] - pts[ i ][ 0 ];
			d.push( dx ? ( pts[ i + 1 ][ 1 ] - pts[ i ][ 1 ] ) / dx : 0 );
		}
		m[ 0 ] = d[ 0 ];
		m[ n - 1 ] = d[ n - 2 ];
		for ( let i = 1; i < n - 1; i++ ) {
			m[ i ] = d[ i - 1 ] * d[ i ] <= 0 ? 0 : ( d[ i - 1 ] + d[ i ] ) / 2;
		}
		for ( let i = 0; i < n - 1; i++ ) {
			if ( d[ i ] === 0 ) {
				m[ i ] = 0;
				m[ i + 1 ] = 0;
				continue;
			}
			const a = m[ i ] / d[ i ];
			const b = m[ i + 1 ] / d[ i ];
			const s = a * a + b * b;
			if ( s > 9 ) {
				const t = 3 / Math.sqrt( s );
				m[ i ] = t * a * d[ i ];
				m[ i + 1 ] = t * b * d[ i ];
			}
		}
		let p = 'M' + pts[ 0 ][ 0 ] + ',' + pts[ 0 ][ 1 ];
		for ( let i = 0; i < n - 1; i++ ) {
			const [ x0, y0 ] = pts[ i ];
			const [ x1, y1 ] = pts[ i + 1 ];
			const k = ( x1 - x0 ) / 3;
			p += 'C' + ( x0 + k ) + ',' + ( y0 + m[ i ] * k ) + ',' + ( x1 - k ) + ',' + ( y1 - m[ i + 1 ] * k ) + ',' + x1 + ',' + y1;
		}
		return p;
	}

	let uid = 0;
	const useId = ( prefix ) => useMemo( () => prefix + '-' + ++uid, [] );

	/* ------------------------------------------------------------------ */
	/* Tooltip: value leads, series name follows; line keys, not boxes.   */
	/* ------------------------------------------------------------------ */

	function Tip( { x, y, title, rows, width } ) {
		if ( x === null || x === undefined ) {
			return null;
		}
		const left = width ? Math.min( Math.max( x, 90 ), width - 90 ) : x;
		return h(
			'div',
			{ className: 'bla-tip', style: { left, top: y } },
			title && h( 'div', { className: 'bla-tip__title' }, title ),
			rows.map( ( r, i ) =>
				h(
					'div',
					{ className: 'bla-tip__row', key: i },
					r.color && h( 'span', { className: 'bla-tip__key', style: { background: r.color } } ),
					h( 'span', { className: 'bla-tip__value' }, r.value ),
					r.name && h( 'span', { className: 'bla-tip__name' }, r.name )
				)
			)
		);
	}

	function Legend( { items } ) {
		return h(
			'div',
			{ className: 'bla-legend' },
			items.map( ( it, i ) =>
				h(
					'span',
					{ className: 'bla-legend__item', key: i },
					h( 'span', { className: it.line ? 'bla-legend__line' : 'bla-legend__swatch', style: { background: it.color, opacity: it.faded ? 0.7 : 1 } } ),
					it.name
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Area / line chart with crosshair                                    */
	/* ------------------------------------------------------------------ */

	function AreaChart( { labels, series, compare, format, height = 260, ariaLabel } ) {
		const ref = useRef( null );
		const width = useWidth( ref );
		const [ hover, setHover ] = useState( null );
		const gradId = useId( 'bla-grad' );
		const f = format || fmt.number;

		const pad = { l: 46, r: 14, t: 14, b: 30 };
		const n = labels.length;
		const plotW = Math.max( 10, width - pad.l - pad.r );
		const plotH = height - pad.t - pad.b;

		const all = [];
		series.forEach( ( s ) => all.push( ...s.values ) );
		if ( compare ) {
			all.push( ...compare.values );
		}
		const scale = niceScale( Math.max( 0, ...all ) );
		const max = scale.max;

		const x = ( i ) => pad.l + ( n <= 1 ? plotW / 2 : ( i * plotW ) / ( n - 1 ) );
		const y = ( v ) => pad.t + plotH - ( ( v || 0 ) / max ) * plotH;
		const ticks = scale.ticks;
		const step = Math.max( 1, Math.ceil( n / Math.max( 2, Math.floor( plotW / 90 ) ) ) );

		const pick = ( clientX ) => {
			const box = ref.current.getBoundingClientRect();
			const rel = clientX - box.left - pad.l;
			const i = n <= 1 ? 0 : Math.round( ( rel / plotW ) * ( n - 1 ) );
			setHover( Math.max( 0, Math.min( n - 1, i ) ) );
		};

		const onKey = ( e ) => {
			if ( e.key === 'ArrowRight' || e.key === 'ArrowLeft' ) {
				e.preventDefault();
				const cur = hover === null ? ( e.key === 'ArrowRight' ? -1 : n ) : hover;
				setHover( Math.max( 0, Math.min( n - 1, cur + ( e.key === 'ArrowRight' ? 1 : -1 ) ) ) );
			} else if ( e.key === 'Escape' ) {
				setHover( null );
			}
		};

		const legendItems = series.map( ( s ) => ( { name: s.name, color: s.color, line: true } ) );
		if ( compare ) {
			legendItems.push( { name: compare.name, color: 'var(--bla-muted)', line: true, faded: true } );
		}

		return h(
			'div',
			{ className: 'bla-chart' },
			legendItems.length > 1 && h( Legend, { items: legendItems } ),
			h(
				'div',
				{
					ref,
					className: 'bla-chart__focus',
					style: { position: 'relative', height, marginTop: legendItems.length > 1 ? 10 : 0 },
					tabIndex: 0,
					role: 'img',
					'aria-label': ariaLabel,
					onPointerMove: ( e ) => width && pick( e.clientX ),
					onPointerLeave: () => setHover( null ),
					onKeyDown: onKey,
					onBlur: () => setHover( null ),
				},
				width > 0 &&
					h(
						'svg',
						{ width, height, 'aria-hidden': 'true' },
						h(
							'defs',
							null,
							series.map( ( s, si ) =>
								h(
									'linearGradient',
									{ id: gradId + si, key: si, x1: 0, x2: 0, y1: 0, y2: 1 },
									h( 'stop', { offset: '0%', stopColor: s.color, stopOpacity: 0.26 } ),
									h( 'stop', { offset: '100%', stopColor: s.color, stopOpacity: 0 } )
								)
							)
						),
						ticks.map( ( t, i ) =>
							h(
								Fragment,
								{ key: 'g' + i },
								i > 0 && h( 'line', { className: 'bla-chart__grid', x1: pad.l, x2: width - pad.r, y1: Math.round( y( t ) ) + 0.5, y2: Math.round( y( t ) ) + 0.5 } ),
								h( 'text', { className: 'bla-chart__tick', x: pad.l - 8, y: y( t ) + 4, textAnchor: 'end' }, f === fmt.number ? fmt.compact( t ) : f( t ) )
							)
						),
						h( 'line', { className: 'bla-chart__axis', x1: pad.l, x2: width - pad.r, y1: Math.round( y( 0 ) ) + 0.5, y2: Math.round( y( 0 ) ) + 0.5 } ),
						labels.map( ( l, i ) => {
							// Every `step`-th date, plus the last one when it is not crowded by the previous label.
							const show = i % step === 0 || ( i === n - 1 && ( n - 1 ) % step >= step / 2 );
							if ( ! show ) {
								return null;
							}
							const anchor = n === 1 ? 'middle' : i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle';
							return h( 'text', { key: 'x' + i, className: 'bla-chart__tick', x: x( i ), y: height - 8, textAnchor: anchor }, fmt.date( l ) );
						} ),
						compare &&
							h( 'path', {
								d: smoothPath( compare.values.slice( 0, n ).map( ( v, i ) => [ x( i ), y( v ) ] ) ),
								fill: 'none',
								stroke: 'var(--bla-muted)',
								strokeOpacity: 0.7,
								strokeWidth: 2,
								strokeLinecap: 'round',
								strokeLinejoin: 'round',
							} ),
						series.map( ( s, si ) => {
							const pts = s.values.map( ( v, i ) => [ x( i ), y( v ) ] );
							const line = smoothPath( pts );
							return h(
								'g',
								{ key: 's' + si },
								pts.length > 1 && h( 'path', { d: line + 'L' + x( n - 1 ) + ',' + y( 0 ) + 'L' + x( 0 ) + ',' + y( 0 ) + 'Z', fill: 'url(#' + gradId + si + ')' } ),
								h( 'path', { d: line, fill: 'none', stroke: s.color, strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' } ),
								n === 1 && h( 'circle', { cx: x( 0 ), cy: y( s.values[ 0 ] ), r: 4, fill: s.color, stroke: 'var(--bla-surface)', strokeWidth: 2 } )
							);
						} ),
						hover !== null &&
							h(
								'g',
								null,
								h( 'line', { className: 'bla-chart__cross', x1: x( hover ), x2: x( hover ), y1: pad.t, y2: y( 0 ) } ),
								compare && h( 'circle', { cx: x( hover ), cy: y( compare.values[ hover ] ), r: 4, fill: 'var(--bla-muted)', stroke: 'var(--bla-surface)', strokeWidth: 2 } ),
								series.map( ( s, si ) => h( 'circle', { key: si, cx: x( hover ), cy: y( s.values[ hover ] ), r: 4.5, fill: s.color, stroke: 'var(--bla-surface)', strokeWidth: 2 } ) )
							)
					),
				hover !== null &&
					h( Tip, {
						x: x( hover ),
						y: Math.min( ...series.map( ( s ) => y( s.values[ hover ] ) ) ),
						width,
						title: fmt.date( labels[ hover ], true ),
						rows: series
							.map( ( s ) => ( { color: s.color, value: f( s.values[ hover ] ), name: s.name } ) )
							.concat( compare ? [ { color: 'var(--bla-muted)', value: f( compare.values[ hover ] ), name: compare.name + ( compare.labels ? ' · ' + fmt.date( compare.labels[ hover ] ) : '' ) } ] : [] ),
					} )
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Donut: part-to-whole, <= 6 segments incl. "Other"                   */
	/* ------------------------------------------------------------------ */

	function Donut( { items, format, centerLabel, ariaLabel } ) {
		const [ active, setActive ] = useState( null );
		const f = format || fmt.number;
		const total = items.reduce( ( a, b ) => a + b.value, 0 );
		const size = 180;
		const stroke = 22;
		const r = ( size - stroke ) / 2;
		const c = 2 * Math.PI * r;
		let offset = 0;

		const shown = active !== null ? items[ active ] : null;

		return h(
			'div',
			{ className: 'bla-donut' },
			h(
				'svg',
				{ width: size, height: size, viewBox: '0 0 ' + size + ' ' + size, role: 'img', 'aria-label': ariaLabel },
				h( 'circle', { cx: size / 2, cy: size / 2, r, fill: 'none', stroke: 'var(--bla-surface-2)', strokeWidth: stroke } ),
				total > 0 &&
					items.map( ( it, i ) => {
						const len = ( it.value / total ) * c;
						const gap = items.length > 1 && len > 4 ? 2 : 0;
						const seg = h( 'circle', {
							key: i,
							cx: size / 2,
							cy: size / 2,
							r,
							fill: 'none',
							stroke: it.color,
							strokeWidth: active === i ? stroke + 4 : stroke,
							strokeDasharray: Math.max( 0, len - gap ) + ' ' + ( c - Math.max( 0, len - gap ) ),
							strokeDashoffset: -offset,
							transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')',
							opacity: active === null || active === i ? 1 : 0.4,
							style: { transition: 'opacity .15s, stroke-width .15s', cursor: 'pointer' },
							onPointerEnter: () => setActive( i ),
							onPointerLeave: () => setActive( null ),
						} );
						offset += len;
						return seg;
					} ),
				h( 'text', { className: 'bla-donut__center-value', x: size / 2, y: size / 2 + 4, textAnchor: 'middle' }, shown ? f( shown.value ) : f( total ) ),
				h( 'text', { className: 'bla-donut__center-label', x: size / 2, y: size / 2 + 22, textAnchor: 'middle' }, shown ? fmt.percent( total ? shown.value / total : 0 ) : centerLabel )
			),
			h(
				'ul',
				{ className: 'bla-donut__list' },
				items.map( ( it, i ) =>
					h(
						'li',
						{
							key: i,
							className: 'bla-donut__row' + ( active === i ? ' is-active' : '' ),
							tabIndex: 0,
							onMouseEnter: () => setActive( i ),
							onMouseLeave: () => setActive( null ),
							onFocus: () => setActive( i ),
							onBlur: () => setActive( null ),
						},
						h( 'span', { className: 'bla-legend__swatch', style: { background: it.color } } ),
						h( 'span', { className: 'bla-donut__name' }, it.name ),
						h( 'span', { className: 'bla-donut__val' }, f( it.value ) ),
						h( 'span', { className: 'bla-donut__pct' }, fmt.percent( total ? it.value / total : 0 ) )
					)
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Bar list: one series, one colour; value at the tip + share          */
	/* ------------------------------------------------------------------ */

	function BarList( { rows, format, total, max } ) {
		const f = format || fmt.number;
		const top = max || Math.max( 1, ...rows.map( ( r ) => r.value ) );
		const sum = total || rows.reduce( ( a, b ) => a + b.value, 0 );
		return h(
			'ul',
			{ className: 'bla-bars' },
			rows.map( ( r, i ) =>
				h(
					'li',
					{ className: 'bla-bars__row', key: r.key || i },
					h(
						'div',
						{ className: 'bla-bars__top' },
						h( 'span', { className: 'bla-bars__label', title: r.title || r.label }, r.icon ? r.icon + ' ' : '', r.href ? h( 'a', { href: r.href, target: '_blank', rel: 'noopener noreferrer' }, r.label ) : r.label ),
						h( 'span', { className: 'bla-bars__value' }, f( r.value ) ),
						sum > 0 && h( 'span', { className: 'bla-bars__share' }, fmt.percent( r.value / sum ) )
					),
					h( 'div', { className: 'bla-bars__track', 'aria-hidden': 'true' }, h( 'div', { className: 'bla-bars__fill', style: { width: Math.max( 1.5, ( r.value / top ) * 100 ) + '%', background: r.color || undefined } } ) )
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Columns (real-time per-minute), rounded data ends, 2px gaps         */
	/* ------------------------------------------------------------------ */

	function Columns( { values, height = 72, label } ) {
		const ref = useRef( null );
		const width = useWidth( ref );
		const [ hover, setHover ] = useState( null );
		const n = values.length;
		const gap = 2;
		const bw = n ? Math.max( 2, Math.min( 14, ( width - gap * ( n - 1 ) ) / n ) ) : 0;
		const total = n * bw + ( n - 1 ) * gap;
		const x0 = Math.max( 0, ( width - total ) / 2 );
		const max = Math.max( 1, ...values );
		const r = Math.min( 3, bw / 2 );

		return h(
			'div',
			{ ref, className: 'bla-chart', style: { height } },
			width > 0 &&
				h(
					'svg',
					{ width, height, role: 'img', 'aria-label': label },
					values.map( ( v, i ) => {
						const bh = v > 0 ? Math.max( 3, ( v / max ) * ( height - 4 ) ) : 2;
						const x = x0 + i * ( bw + gap );
						const y = height - bh;
						const d = v > 0
							? 'M' + x + ',' + height + 'V' + ( y + r ) + 'Q' + x + ',' + y + ' ' + ( x + r ) + ',' + y + 'H' + ( x + bw - r ) + 'Q' + ( x + bw ) + ',' + y + ' ' + ( x + bw ) + ',' + ( y + r ) + 'V' + height + 'Z'
							: 'M' + x + ',' + ( height - 2 ) + 'h' + bw + 'v2h-' + bw + 'Z';
						return h(
							'g',
							{ key: i, onPointerEnter: () => setHover( i ), onPointerLeave: () => setHover( null ) },
							h( 'rect', { x: x - 1, y: 0, width: bw + gap, height, fill: 'transparent' } ),
							h( 'path', { d, fill: v > 0 ? 'var(--bla-s1)' : 'var(--bla-grid)', opacity: hover === null || hover === i ? 1 : 0.55 } )
						);
					} )
				),
			hover !== null &&
				h( Tip, {
					x: x0 + hover * ( bw + gap ) + bw / 2,
					y: 0,
					width,
					rows: [
						{
							color: 'var(--bla-s1)',
							value: fmt.number( values[ hover ] ),
							name:
								n - 1 - hover === 0
									? __( 'views this minute', 'blue-lens-analytics' )
									: sprintf( /* translators: %d: minutes ago. */ __( 'views, %d min ago', 'blue-lens-analytics' ), n - 1 - hover ),
						},
					],
				} )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Ring gauge and sparkline                                            */
	/* ------------------------------------------------------------------ */

	function Ring( { value, label, sub, color } ) {
		const size = 96;
		const stroke = 10;
		const r = ( size - stroke ) / 2;
		const c = 2 * Math.PI * r;
		const v = Math.max( 0, Math.min( 1, value || 0 ) );
		return h(
			'div',
			{ className: 'bla-ring' },
			h(
				'svg',
				{ width: size, height: size, role: 'img', 'aria-label': label + ': ' + fmt.percent( v ) },
				h( 'circle', { cx: size / 2, cy: size / 2, r, fill: 'none', stroke: 'var(--bla-surface-2)', strokeWidth: stroke } ),
				h( 'circle', {
					cx: size / 2,
					cy: size / 2,
					r,
					fill: 'none',
					stroke: color,
					strokeWidth: stroke,
					strokeLinecap: v > 0.02 ? 'round' : 'butt',
					strokeDasharray: v * c + ' ' + c,
					transform: 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')',
				} ),
				h( 'text', { className: 'bla-ring__value', x: size / 2, y: size / 2 + 6, textAnchor: 'middle' }, fmt.percent( v ) )
			),
			h( 'div', { className: 'bla-ring__label' }, label ),
			sub && h( 'div', { className: 'bla-ring__sub' }, sub )
		);
	}

	function Sparkline( { values, height = 34 } ) {
		const ref = useRef( null );
		const width = useWidth( ref );
		const n = values.length;
		const max = Math.max( 1, ...values );
		const pts = values.map( ( v, i ) => [ n <= 1 ? width / 2 : ( i * width ) / ( n - 1 ), height - 3 - ( v / max ) * ( height - 6 ) ] );
		const line = smoothPath( pts );
		return h(
			'div',
			{ ref, className: 'bla-kpi__spark', 'aria-hidden': 'true' },
			width > 0 &&
				n > 1 &&
				h(
					'svg',
					{ width, height },
					h( 'path', { d: line + 'L' + width + ',' + height + 'L0,' + height + 'Z', fill: 'currentColor', opacity: 0.14 } ),
					h( 'path', { d: line, fill: 'none', stroke: 'currentColor', strokeWidth: 1.75, strokeLinecap: 'round', strokeLinejoin: 'round' } )
				)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Sortable data table (the table view twin of every chart)            */
	/* ------------------------------------------------------------------ */

	function DataTable( { columns, rows, caption, initialSort, shareKey, rowKey, empty } ) {
		const [ sort, setSort ] = useState( initialSort || null );
		const sorted = useMemo( () => {
			if ( ! sort ) {
				return rows;
			}
			const col = columns.find( ( c ) => c.key === sort.key );
			const get = col && col.sortValue ? col.sortValue : ( r ) => r[ sort.key ];
			return rows.slice().sort( ( a, b ) => {
				const va = get( a );
				const vb = get( b );
				const cmp = typeof va === 'number' && typeof vb === 'number' ? va - vb : String( va ).localeCompare( String( vb ) );
				return sort.dir === 'asc' ? cmp : -cmp;
			} );
		}, [ rows, sort ] );

		const shareMax = shareKey ? Math.max( 1, ...rows.map( ( r ) => r[ shareKey ] || 0 ) ) : 0;

		if ( ! rows.length ) {
			return empty || null;
		}

		return h(
			'div',
			{ className: 'bla-table-wrap' },
			h(
				'table',
				{ className: 'bla-table' },
				caption && h( 'caption', { className: 'bla-sr' }, caption ),
				h(
					'thead',
					null,
					h(
						'tr',
						null,
						columns.map( ( c ) => {
							const active = sort && sort.key === c.key;
							return h(
								'th',
								{ key: c.key, className: c.num ? 'is-num' : '', scope: 'col', 'aria-sort': active ? ( sort.dir === 'asc' ? 'ascending' : 'descending' ) : 'none' },
								c.sortable === false
									? c.label
									: h(
										'button',
										{
											type: 'button',
											onClick: () => setSort( { key: c.key, dir: active && sort.dir === 'desc' ? 'asc' : 'desc' } ),
										},
										c.label,
										active ? ( sort.dir === 'asc' ? ' ↑' : ' ↓' ) : ''
									)
							);
						} )
					)
				),
				h(
					'tbody',
					null,
					sorted.map( ( r, i ) =>
						h(
							'tr',
							{ key: rowKey ? rowKey( r ) : i },
							columns.map( ( c, ci ) => {
								const raw = r[ c.key ];
								const shown = c.render ? c.render( r ) : c.format ? c.format( raw ) : raw;
								if ( ci === 0 ) {
									return h(
										'td',
										{ key: c.key },
										h(
											'div',
											{ className: 'bla-table__label' },
											h( 'span', { title: typeof shown === 'string' ? shown : undefined }, shown ),
											r.sub && h( 'small', null, r.sub ),
											shareKey && h( 'div', { className: 'bla-table__share', 'aria-hidden': 'true' }, h( 'i', { style: { width: ( ( r[ shareKey ] || 0 ) / shareMax ) * 100 + '%', background: r.color || undefined } } ) )
										)
									);
								}
								return h( 'td', { key: c.key, className: c.num ? 'is-num' : '' }, shown );
							} )
						)
					)
				)
			)
		);
	}

	window.BlueLensCharts = { fmt, niceMax, AreaChart, Donut, BarList, Columns, Ring, Sparkline, DataTable, Legend, Tip, useWidth, smoothPath };
}( window.wp ) );

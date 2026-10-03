/**
 * Fails the build when a tracker script exceeds its gzipped size budget.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const zlib = require( 'zlib' );

// The core loads on every page; chunks load after the page only when their features are enabled.
const BUDGETS = {
	'tracker.min.js': 5 * 1024,
	'auto.min.js': 5 * 1024,
	'capture.min.js': 3 * 1024,
};

for ( const [ name, budget ] of Object.entries( BUDGETS ) ) {
	const file = path.join( __dirname, '../assets/tracker/build', name );
	const gzipped = zlib.gzipSync( fs.readFileSync( file ), { level: 9 } ).length;

	// eslint-disable-next-line no-console
	console.log( `${ name }: ${ gzipped } bytes gzipped (budget ${ budget })` );

	if ( gzipped > budget ) {
		process.exitCode = 1;
	}
}

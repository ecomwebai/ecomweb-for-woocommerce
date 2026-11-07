/**
 * The connected page's copy poller in assets/js/admin.js, run in a VM with a stub DOM, fetch and
 * timers. Run with `npm run test:js` (node --test, no dependencies).
 */
'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );

const SOURCE = fs.readFileSync( path.join( __dirname, '../../assets/js/admin.js' ), 'utf8' );

function element( attrs = {} ) {
	return {
		textContent: '',
		hidden: false,
		getAttribute: ( name ) => ( name in attrs ? attrs[ name ] : null ),
	};
}

// Loads admin.js on a running copy page. `answers` is a list of what each poll gets: an object
// (the JSON body), 'reject' (network failure) or 'not-json' (e.g. admin-ajax's "-1").
function page( answers ) {
	const timers = [];
	const requests = [];
	const elements = {
		'ecomweb-copy': element( { 'data-status': 'running' } ),
		'ecomweb-progress': element(),
		'ecomweb-counts': element(),
		'ecomweb-poll-note': element(),
	};
	elements[ 'ecomweb-poll-note' ].hidden = true;
	const win = {
		ecomwebWoo: {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			nonce: 'N1',
			i18n: {
				progress: 'Copying %1$d of %2$d…',
				counts: '%1$d created, %2$d updated, %3$d skipped (already copied), %4$d failed.',
				pollFailed: 'Could not check.',
			},
		},
		setTimeout: ( fn, ms ) => timers.push( { fn, ms } ),
		clearTimeout: () => {},
		location: { reloads: 0, reload() { this.reloads++; } },
		confirm: () => true,
		open: () => {},
	};
	class FormData {
		constructor() {
			this.fields = {};
		}
		append( key, value ) {
			this.fields[ key ] = value;
		}
	}
	const fetch = ( url, options ) => {
		requests.push( { url, fields: options.body.fields } );
		const answer = answers.length ? answers.shift() : { success: true, data: { status: 'running', done: 0, total: 0, counts: {} } };
		if ( 'reject' === answer ) {
			return Promise.reject( new Error( 'offline' ) );
		}
		return Promise.resolve( {
			json: () => ( 'not-json' === answer ? Promise.reject( new SyntaxError( 'bad json' ) ) : Promise.resolve( answer ) ),
		} );
	};
	const doc = {
		getElementById: ( id ) => elements[ id ] || null,
		querySelectorAll: () => [],
	};
	vm.runInNewContext( SOURCE, { window: win, document: doc, fetch, FormData } );

	return {
		elements,
		requests,
		win,
		timers,
		// Runs the next timer and lets its promises settle; returns the delay it was scheduled with.
		async step() {
			const timer = timers.shift();
			assert.ok( timer, 'a poll is scheduled' );
			timer.fn();
			await new Promise( ( resolve ) => setImmediate( resolve ) );
			return timer.ms;
		},
	};
}

function running( done, total, counts ) {
	return { success: true, data: { status: 'running', done, total, counts } };
}

test( 'each poll rewrites "Copying X of Y" and the counts', async () => {
	const p = page( [
		running( 20, 45, { created: 12, updated: 3, skipped: 3, failed: 2 } ),
		running( 40, 45, { created: 30, updated: 5, skipped: 3, failed: 2 } ),
	] );

	assert.equal( await p.step(), 5000 );
	assert.equal( p.elements[ 'ecomweb-progress' ].textContent, 'Copying 20 of 45…' );
	assert.equal( p.elements[ 'ecomweb-counts' ].textContent, '12 created, 3 updated, 3 skipped (already copied), 2 failed.' );
	assert.deepEqual( p.requests[ 0 ].fields, { action: 'ecomweb_copy_status', nonce: 'N1' } );

	assert.equal( await p.step(), 5000 );
	assert.equal( p.elements[ 'ecomweb-progress' ].textContent, 'Copying 40 of 45…' );
	assert.equal( p.elements[ 'ecomweb-counts' ].textContent, '30 created, 5 updated, 3 skipped (already copied), 2 failed.' );
	assert.equal( p.timers.length, 1, 'the next poll is scheduled' );
	assert.equal( p.win.location.reloads, 0 );
} );

test( 'a finished copy reloads the page and stops polling', async () => {
	const p = page( [ { success: true, data: { status: 'completed', done: 45, total: 45, counts: {} } } ] );

	await p.step();

	assert.equal( p.win.location.reloads, 1 );
	assert.equal( p.timers.length, 0 );
} );

test( 'failed polls back off 5, 10, 20, 40, 60 s, then stay at 60 s', async () => {
	const p = page( [ { success: false }, 'not-json', 'reject', { success: false, data: null }, 'reject', 'reject', 'reject' ] );

	const delays = [];
	for ( let i = 0; i < 7; i++ ) {
		delays.push( await p.step() );
	}

	assert.deepEqual( delays, [ 5000, 10000, 20000, 40000, 60000, 60000, 60000 ] );
	assert.equal( p.elements[ 'ecomweb-poll-note' ].hidden, false );
	assert.equal( p.elements[ 'ecomweb-poll-note' ].textContent, 'Could not check.' );
	assert.equal( p.win.location.reloads, 0 );
} );

test( 'a good answer after failures resets the delay and hides the note', async () => {
	const p = page( [ 'reject', 'reject', running( 5, 10, { created: 5 } ) ] );

	await p.step();
	await p.step();
	assert.equal( p.elements[ 'ecomweb-poll-note' ].hidden, false );
	await p.step();

	assert.equal( p.elements[ 'ecomweb-poll-note' ].hidden, true );
	assert.equal( p.elements[ 'ecomweb-counts' ].textContent, '5 created, 0 updated, 0 skipped (already copied), 0 failed.' );
	assert.equal( p.timers[ 0 ].ms, 5000 );
} );

test( 'no polling when the copy is not running', () => {
	const timers = [];
	vm.runInNewContext( SOURCE, {
		window: { ecomwebWoo: { i18n: {} }, setTimeout: ( fn, ms ) => timers.push( ms ) },
		document: {
			getElementById: ( id ) => ( 'ecomweb-copy' === id ? element( { 'data-status': 'completed' } ) : null ),
			querySelectorAll: () => [],
		},
	} );
	assert.equal( timers.length, 0 );
} );

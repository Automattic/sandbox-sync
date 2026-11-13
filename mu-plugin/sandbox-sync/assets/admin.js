		( function () {
// Relative URL: resolves on the same origin (scheme+host+port) the
// page was loaded from — admin_url() would drop a nonstandard port.
const ajaxUrl = 'admin-ajax.php';
const config = window.SandboxSyncAdmin || {};
const nonce = config.nonce || '';
const initialJob = config.initialJob || null;

function post( data ) {
	const body = new URLSearchParams( Object.assign( { action: 'sandbox_sync', _ajax_nonce: nonce }, data ) );
	return fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body } )
		.then( ( r ) =>
			r.text().then( ( text ) => {
				try {
					return JSON.parse( text );
				} catch ( e ) {
					throw new Error(
						'HTTP ' + r.status + ' — unexpected response: ' + text.slice( 0, 120 ) +
						( 403 === r.status || '-1' === text ? ' (session/nonce expired? reload the page)' : '' )
					);
				}
			} )
		);
}

function notice( message, ok ) {
	const el = document.getElementById( 'sbs-notice' );
	el.className = 'notice ' + ( ok ? 'notice-success' : 'notice-error' );
	el.style.display = '';
	el.querySelector( 'p' ).textContent = message;
}

function fail( err ) {
	console.error( 'sandbox-sync:', err );
	notice( String( err && err.message ? err.message : err ), false );
}

function onClick( id, fn ) {
	const el = document.getElementById( id );
	if ( el ) {
		el.addEventListener( 'click', fn );
	}
}

// -- Pairing / connection ------------------------------------------

onClick( 'sbs-generate-key', () =>
	post( { op: 'generate_key' } ).then( ( res ) => {
		if ( ! res.success ) { return notice( res.data.message, false ); }
		document.getElementById( 'sbs-key' ).textContent = res.data.pairing_key;
		document.getElementById( 'sbs-key-box' ).style.display = '';
		notice( res.data.message, true );
	} ).catch( fail )
);

function revoke( hash ) {
	post( { op: 'revoke', hash: hash || '' } ).then( ( res ) => {
		notice( res.data.message, res.success );
		if ( res.success ) { setTimeout( () => location.reload(), 800 ); }
	} ).catch( fail );
}

onClick( 'sbs-revoke-all', () => revoke( '' ) );
document.querySelectorAll( '.sbs-revoke-one' ).forEach( ( btn ) =>
	btn.addEventListener( 'click', () => revoke( btn.dataset.hash ) )
);

onClick( 'sbs-connect', () => {
	const source_url  = document.getElementById( 'sbs-source-url' ).value.trim();
	const pairing_key = document.getElementById( 'sbs-pairing-key' ).value.trim();
	if ( ! source_url || ! pairing_key ) { return notice( 'Both the live site URL and the pairing key are required.', false ); }
	post( { op: 'connect', source_url, pairing_key } ).then( ( res ) => {
		notice( res.data.message, res.success );
		if ( res.success ) { setTimeout( () => location.reload(), 800 ); }
	} ).catch( fail );
} );

onClick( 'sbs-disconnect', () =>
	post( { op: 'disconnect' } ).then( ( res ) => {
		notice( res.data.message, res.success );
		if ( res.success ) { setTimeout( () => location.reload(), 800 ); }
	} ).catch( fail )
);

const flagEl = document.getElementById( 'sbs-sandbox-flag' );
if ( flagEl ) {
	flagEl.addEventListener( 'change', () =>
		post( { op: 'set_sandbox_flag', enabled: flagEl.checked ? '1' : '0' } ).then( ( res ) => {
			notice( res.data.message, res.success );
			document.getElementById( 'sbs-start' ).disabled = ! flagEl.checked;
			document.getElementById( 'sbs-flag-warning' ).style.display = flagEl.checked ? 'none' : '';
		} ).catch( fail )
	);
}

// -- Source meta pickers (plugins / constants) --------------------------

function esc( text ) {
	const div = document.createElement( 'div' );
	div.textContent = String( text );
	return div.innerHTML;
}

function updateHints() {
	const pluginBoxes = document.querySelectorAll( '.sbs-plugin' );
	const pluginsChecked = document.querySelectorAll( '.sbs-plugin:checked' ).length;
	const pluginsHint = document.getElementById( 'sbs-plugins-hint' );
	if ( pluginBoxes.length ) {
		pluginsHint.textContent = pluginsChecked === pluginBoxes.length ? 'all (' + pluginBoxes.length + ')' : pluginsChecked + ' of ' + pluginBoxes.length;
	}
	const uploadBoxes = document.querySelectorAll( '.sbs-uploads-month, .sbs-uploads-year:not([data-has-months]), #sbs-uploads-other' );
	if ( uploadBoxes.length ) {
		const uploadsChecked = Array.from( uploadBoxes ).filter( ( el ) => el.checked ).length;
		document.getElementById( 'sbs-uploads-hint' ).textContent =
			uploadsChecked === uploadBoxes.length ? 'all' : ( uploadsChecked ? uploadsChecked + ' of ' + uploadBoxes.length + ' periods' : 'none' );
	}
	syncYearBoxes();
	const constChecked = document.querySelectorAll( '.sbs-constant:checked' ).length;
	document.getElementById( 'sbs-constants-hint' ).textContent = constChecked ? constChecked + ' selected' : 'none';
	const excluded = document.getElementById( 'sbs-opt-excluded' ).value.split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean );
	document.getElementById( 'sbs-excluded-hint' ).textContent = excluded.length ? excluded.length + ' table(s)' : 'none';
	const schemaOnly = document.getElementById( 'sbs-opt-schema-only' ).value.split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean );
	document.getElementById( 'sbs-schema-only-hint' ).textContent = schemaOnly.length ? schemaOnly.length + ' table(s)' : 'none';
	const safetyChecked = document.querySelectorAll( '.sbs-safety:checked' ).length;
	document.getElementById( 'sbs-safety-hint' ).textContent = safetyChecked + ' of ' + document.querySelectorAll( '.sbs-safety' ).length + ' rules';
}

// Year checkboxes mirror their months (indeterminate on a mix),
// and each year row shows a "n of m months" summary.
function syncYearBoxes() {
	document.querySelectorAll( '.sbs-uploads-year[data-has-months]' ).forEach( ( yearBox ) => {
		const months = document.querySelectorAll( '.sbs-uploads-month[data-year="' + yearBox.dataset.year + '"]' );
		const checked = Array.from( months ).filter( ( el ) => el.checked ).length;
		yearBox.checked = checked === months.length && months.length > 0;
		yearBox.indeterminate = checked > 0 && checked < months.length;

		const sum = document.querySelector( '.sbs-year-sum[data-year="' + yearBox.dataset.year + '"]' );
		if ( sum ) {
			sum.textContent = checked === months.length
				? 'all ' + months.length + ' month' + ( months.length > 1 ? 's' : '' )
				: ( checked ? checked + ' of ' + months.length + ' months' : 'none' );
		}
	} );
}

function renderUploads( data ) {
	const list = document.getElementById( 'sbs-uploads-list' );
	const years = ( data && data.years ) || [];
	if ( ! years.length && ! ( data && data.other ) ) {
		list.textContent = 'No uploads found on the live site.';
		return;
	}
	let html = years.map( ( y ) => {
		const months = ( y.months || [] ).map( ( m ) =>
			'<label class="sbs-chip"><input type="checkbox" class="sbs-uploads-month" data-year="' + esc( y.year ) + '" value="' + esc( y.year + '/' + m ) + '" checked> ' + esc( m ) + '</label>'
		).join( '' );
		return '<div class="sbs-year" data-year="' + esc( y.year ) + '">'
			+ '<div class="sbs-year-row">'
			+ '<label><input type="checkbox" class="sbs-uploads-year" data-year="' + esc( y.year ) + '"' + ( y.months.length ? ' data-has-months="1"' : '' ) + ' checked> ' + esc( y.year ) + '</label>'
			+ '<span class="sbs-year-sum" data-year="' + esc( y.year ) + '"></span>'
			+ ( months ? '<span class="sbs-year-chevron dashicons dashicons-arrow-down-alt2"></span>' : '' )
			+ '</div>'
			+ ( months ? '<div class="sbs-year-months">' + months + '</div>' : '' )
			+ '</div>';
	} ).join( '' );
	if ( data.other ) {
		html += '<div class="sbs-year"><label class="sbs-other-row"><input type="checkbox" id="sbs-uploads-other" checked> Other files <span class="sbs-meta">(root files, non-dated folders)</span></label></div>';
	}
	list.innerHTML = html;

	// Row click folds/unfolds the months; the checkbox (and its
	// label) keeps its normal behavior.
	list.querySelectorAll( '.sbs-year-row' ).forEach( ( row ) =>
		row.addEventListener( 'click', ( event ) => {
			if ( event.target.closest( 'label' ) ) { return; }
			if ( ! row.parentElement.querySelector( '.sbs-year-months' ) ) { return; }
			row.parentElement.classList.toggle( 'open' );
		} )
	);

	// Year checkbox toggles its months (and unfolds on partial
	// selections so the mix is visible).
	list.querySelectorAll( '.sbs-uploads-year[data-has-months]' ).forEach( ( yearBox ) =>
		yearBox.addEventListener( 'change', () => {
			list.querySelectorAll( '.sbs-uploads-month[data-year="' + yearBox.dataset.year + '"]' )
				.forEach( ( m ) => { m.checked = yearBox.checked; } );
			updateHints();
		} )
	);
	document.getElementById( 'sbs-uploads-all' ).addEventListener( 'click', () => {
		list.querySelectorAll( 'input[type=checkbox]' ).forEach( ( el ) => { el.checked = true; el.indeterminate = false; } );
		updateHints();
	} );
	document.getElementById( 'sbs-uploads-none' ).addEventListener( 'click', () => {
		list.querySelectorAll( 'input[type=checkbox]' ).forEach( ( el ) => { el.checked = false; el.indeterminate = false; } );
		updateHints();
	} );

	updateHints();
}

// null = everything selected (also covers periods added on live
// later); otherwise an array of 'YYYY', 'YYYY/MM', 'other'.
function collectUploadsScope() {
	const list = document.getElementById( 'sbs-uploads-list' );
	if ( ! list || ! list.querySelector( 'input' ) ) { return null; }
	const scopes = [];
	let everything = true;
	list.querySelectorAll( '.sbs-uploads-year' ).forEach( ( yearBox ) => {
		const months = Array.from( list.querySelectorAll( '.sbs-uploads-month[data-year="' + yearBox.dataset.year + '"]' ) );
		if ( ! months.length ) {
			if ( yearBox.checked ) { scopes.push( yearBox.dataset.year ); } else { everything = false; }
			return;
		}
		const checked = months.filter( ( el ) => el.checked );
		if ( checked.length === months.length ) {
			scopes.push( yearBox.dataset.year );
		} else {
			everything = false;
			checked.forEach( ( el ) => scopes.push( el.value ) );
		}
	} );
	const other = document.getElementById( 'sbs-uploads-other' );
	if ( other ) {
		if ( other.checked ) { scopes.push( 'other' ); } else { everything = false; }
	}
	return everything ? null : scopes;
}

function loadSourceMeta() {
	const pluginsList = document.getElementById( 'sbs-plugins-list' );
	if ( ! pluginsList ) { return; }
	post( { op: 'fetch_source_meta' } ).then( ( res ) => {
		if ( ! res.success ) {
			pluginsList.textContent = 'Could not load from live: ' + res.data.message;
			document.getElementById( 'sbs-constants-list' ).textContent = 'Could not load from live: ' + res.data.message;
			document.getElementById( 'sbs-uploads-list' ).textContent = 'Could not load from live: ' + res.data.message;
			return;
		}

		renderUploads( res.data.uploads );

		const plugins = res.data.plugins || [];
		pluginsList.innerHTML = plugins.length ? plugins.map( ( p ) => {
			const meta = 'v' + esc( p.version || '?' ) + ( p.active ? ' · active' : ' · inactive' );
			// Symlinked plugins never travel (the file sync skips
			// symlinks on both sides): shown for transparency,
			// not selectable.
			if ( p.symlink ) {
				return '<label class="sbs-symlinked"><input type="checkbox" disabled> '
					+ '<span>' + esc( p.name || p.top )
					+ ' <span class="sbs-meta">' + meta + ' · symlinked on live — not synced</span></span></label>';
			}
			return '<label><input type="checkbox" class="sbs-plugin" value="' + esc( p.top ) + '" checked> '
				+ '<span>' + esc( p.name || p.top ) + ' <span class="sbs-meta">' + meta + '</span></span></label>';
		} ).join( '' ) : 'No plugins found on the live site.';

		const constants = res.data.constants || [];
		document.getElementById( 'sbs-constants-list' ).innerHTML = constants.length ? constants.map( ( c ) =>
			'<label><input type="checkbox" class="sbs-constant" value="' + esc( c.name ) + '"> '
			+ '<span><code>' + esc( c.name ) + '</code></span></label>'
		).join( '' ) : 'No replicable constants found in the live wp-config.php.';

		updateHints();
	} ).catch( ( err ) => {
		pluginsList.textContent = 'Could not load from live: ' + err.message;
	} );
}

document.querySelectorAll( '.sbs-check-all, .sbs-check-none' ).forEach( ( btn ) =>
	btn.addEventListener( 'click', () => {
		const checked = btn.classList.contains( 'sbs-check-all' );
		document.querySelectorAll( '.' + btn.dataset.target ).forEach( ( box ) => { box.checked = checked; } );
		updateHints();
	} )
);

document.addEventListener( 'change', ( event ) => {
	if ( event.target.matches( '.sbs-plugin, .sbs-constant, .sbs-safety, .sbs-uploads-month, #sbs-uploads-other' ) ) { updateHints(); }
} );
const excludedEl = document.getElementById( 'sbs-opt-excluded' );
if ( excludedEl ) { excludedEl.addEventListener( 'input', updateHints ); }
const schemaOnlyEl = document.getElementById( 'sbs-opt-schema-only' );
if ( schemaOnlyEl ) { schemaOnlyEl.addEventListener( 'input', updateHints ); }

// -- Job panel -------------------------------------------------------

let pollTimer = null;
let pollInFlight = false;
let jobIsActive = false;
let renderedLogSignature = '';

function duration( seconds ) {
	seconds = Math.max( 0, Math.floor( Number( seconds ) || 0 ) );
	if ( seconds < 60 ) { return seconds + 's'; }
	const hours = Math.floor( seconds / 3600 );
	const minutes = Math.floor( ( seconds % 3600 ) / 60 );
	const secs = seconds % 60;
	if ( hours > 0 ) { return hours + 'h ' + minutes + 'm ' + secs + 's'; }
	return minutes + 'm ' + secs + 's';
}

function fileSize( bytes ) {
	bytes = Math.max( 0, Number( bytes ) || 0 );
	if ( bytes < 1024 ) { return Math.round( bytes ) + ' B'; }
	const units = [ 'KiB', 'MiB', 'GiB', 'TiB' ];
	let value = bytes / 1024;
	let unit = 0;
	while ( value >= 1024 && unit < units.length - 1 ) { value /= 1024; unit++; }
	return value.toFixed( value >= 100 ? 0 : ( value >= 10 ? 1 : 2 ) ) + ' ' + units[ unit ];
}

function renderJob( job ) {
	if ( ! job || ! document.getElementById( 'sbs-job' ) ) { return; }
	document.getElementById( 'sbs-job' ).style.display = '';
	document.getElementById( 'sbs-job-id' ).textContent = job.id;

	const active  = 'running' === job.status;
	jobIsActive = active;
	const current = job.steps[ job.current ];
	const uploadsActive = active && current && (
		( 'dir_plan' === current.type && current.label.includes( 'uploads/' ) )
		|| 'uploads_finalize' === current.type
	);
	// Structured, multiline status: a bold headline, the current step, then
	// any step detail (which may itself span several lines), then a muted note.
	let headline = 'Status: ' + job.status;
	const detailLines = [];
	let note = '';
	if ( active ) {
		headline += ' — step ' + ( job.current + 1 ) + ' of ' + job.steps_total + ' — elapsed ' + duration( job.elapsed_s );
		if ( current ) { detailLines.push( current.label ); }
		if ( ! uploadsActive && job.live_progress && ! job.live_progress.done ) {
			detailLines.push(
				( 'source_hashes' === job.live_progress.phase ? 'Hashing transfer candidates from ' : 'Scanning live ' )
				+ job.live_progress.dir + '/: '
				+ Number( job.live_progress.files ).toLocaleString() + ' files, '
				+ fileSize( job.live_progress.bytes ) + ( 'source_hashes' === job.live_progress.phase ? ' read' : ' represented' )
			);
		} else if ( ! uploadsActive && current && current.message ) {
			String( current.message ).split( '\n' ).forEach( ( line ) => {
				if ( line.trim() ) { detailLines.push( line ); }
			} );
		}
		note = job.background
			? ( job.heartbeat_age > 30
				? ( job.lease_active
					? 'The worker is busy with a long operation — this can take a while.'
					: 'The background worker is idle; this page is keeping the job moving.' )
				: 'Running in the background — you can safely close this tab.' )
			: 'Running in the foreground — keep this tab open until it finishes.';
	} else if ( 'failed' === job.status ) {
		detailLines.push( job.error );
		if ( job.error_info ) {
			detailLines.push( 'Error code: ' + job.error_info.category + '/' + job.error_info.code );
			note = job.error_info.remediation;
		}
	}
	document.getElementById( 'sbs-job-statusline' ).innerHTML =
		'<strong>' + esc( headline ) + '</strong>'
		+ detailLines.map( ( line ) => '<span class="sbs-status-line">' + esc( line ) + '</span>' ).join( '' )
		+ ( note ? '<span class="sbs-status-note">' + esc( note ) + '</span>' : '' );

	document.getElementById( 'sbs-progress-bar' ).style.width =
		job.steps_total ? Math.round( ( job.steps_done / job.steps_total ) * 100 ) + '%' : '0';
	document.getElementById( 'sbs-progress-bar' ).style.background =
		'failed' === job.status ? '#b32d2e' : ( 'cancelled' === job.status ? '#996800' : '#2271b1' );

	// The server returns a sliding log window. Reconcile against the newest
	// entry rather than array length: once the window is full its length stays
	// constant while old lines fall off and new lines arrive.
	const logDiv = document.getElementById( 'sbs-log' );
	const newest = job.log.length ? job.log[ job.log.length - 1 ] : null;
	const signature = job.log.length + ':' + ( newest ? newest[ 0 ] + ':' + newest[ 1 ] : '' );
	if ( signature !== renderedLogSignature ) {
		logDiv.textContent = job.log.map( ( entry ) => {
			const ts = new Date( entry[ 0 ] * 1000 ).toLocaleTimeString();
			return '[' + ts + '] ' + entry[ 1 ];
		} ).join( '\n' ) + ( job.log.length ? '\n' : '' );
		renderedLogSignature = signature;
		logDiv.scrollTop = logDiv.scrollHeight;
	}

	document.getElementById( 'sbs-start' ).disabled  = active || ( flagEl && ! flagEl.checked );
	document.getElementById( 'sbs-cancel' ).style.display = active ? '' : 'none';

	if ( ! active && pollTimer ) {
		clearTimeout( pollTimer );
		pollTimer = null;
		if ( 'done' === job.status ) { notice( 'Sync complete.', true ); }
		if ( 'failed' === job.status ) {
			notice( 'Sync failed: ' + job.error + ( job.error_info ? ' — ' + job.error_info.remediation : '' ), false );
		}
	}
}

function startPolling() {
	if ( pollTimer || pollInFlight ) { return; }
	pollTimer = setTimeout( () => {
		pollTimer = null;
		if ( pollInFlight || ! jobIsActive ) { return; }
		pollInFlight = true;
		// job_tick advances the job when the slice lock is free
		// (no-op if the cron worker is processing), then returns
		// the fresh state. Works for both foreground and background.
		post( { op: 'job_tick' } )
			.then( ( res ) => { if ( res.success ) { renderJob( res.data.job ); } } )
			.catch( () => {} ) // Transient poll errors: keep polling.
			.finally( () => {
				pollInFlight = false;
				if ( jobIsActive ) { startPolling(); }
			} );
	}, 2000 );
}

onClick( 'sbs-start', () => {
	const dirs = Array.from( document.querySelectorAll( '.sbs-opt-dir:checked' ) ).map( ( el ) => el.value );
	const safety = Array.from( document.querySelectorAll( '.sbs-safety:checked' ) ).map( ( el ) => el.value );
	const constants = Array.from( document.querySelectorAll( '.sbs-constant:checked' ) ).map( ( el ) => el.value );
	const options = {
		db: document.getElementById( 'sbs-opt-db' ).checked,
		dirs: dirs,
		incremental: document.getElementById( 'sbs-opt-incremental' ).checked,
		uploads_fast: true,
		uploads_full_audit: document.getElementById( 'sbs-opt-uploads-audit' ).checked,
		uploads_adopt: !! ( document.getElementById( 'sbs-opt-uploads-adopt' ) && document.getElementById( 'sbs-opt-uploads-adopt' ).checked ),
		excluded_tables: document.getElementById( 'sbs-opt-excluded' ).value.split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean ),
		schema_only_tables: document.getElementById( 'sbs-opt-schema-only' ).value.split( '\n' ).map( ( s ) => s.trim() ).filter( Boolean ),
		safety: safety,
		config_constants: constants,
	};

	// Plugins: omit when everything (or nothing loaded yet) is
	// checked, so new plugins on live are included by default.
	const pluginBoxes = Array.from( document.querySelectorAll( '.sbs-plugin' ) );
	const pluginsChecked = pluginBoxes.filter( ( el ) => el.checked ).map( ( el ) => el.value );
	if ( pluginBoxes.length && pluginsChecked.length < pluginBoxes.length ) {
		options.plugins = pluginsChecked;
	}

	const uploadsScope = collectUploadsScope();
	if ( uploadsScope !== null ) {
		options.uploads_scope = uploadsScope;
	}

	const background = document.getElementById( 'sbs-opt-background' ).checked;

	// The mode sentences come from PHP (Start_Confirmation) so the CLI prompt
	// uses the same words; the conditions below mirror its mode_lines(),
	// outro() and safety_line(). They are missing when PHP still serves an
	// older copy of the plugin than this script, right after an update.
	const text = config.confirm;
	if ( ! text ) { return notice( 'The plugin was just updated and this page is not consistent yet. Wait a moment, reload, and start the sync again.', false ); }
	const incremental = options.incremental;
	// Tracked uploads (the default on an incremental run) are compared folder
	// by folder and get their own lines. Any other run treats uploads like the
	// other directories and never reads rescan or adopt.
	const uploadsSelected = dirs.includes( 'uploads' );
	const uploadsTracked = uploadsSelected && incremental && options.uploads_fast;
	const listedDirs = uploadsTracked ? dirs.filter( ( dir ) => 'uploads' !== dir ) : dirs;
	const uploadsIgnored = uploadsSelected && ! uploadsTracked && ( options.uploads_full_audit || options.uploads_adopt );
	const summary = text.intro + '\n\n'
		+ ( options.db ? '• ' + text[ incremental ? 'db_incremental' : 'db_full' ] + '\n' : '' )
		+ ( options.db && options.schema_only_tables.length ? '• schema-only tables recreated empty: ' + options.schema_only_tables.join( ', ' ) + '\n' : '' )
		+ ( options.db ? '• the live site stays fully online during the export; busy tables are captured as of their own export moment\n' : '' )
		+ ( listedDirs.length ? '• ' + text[ incremental ? 'files_incremental' : 'files_full' ].replace( '%s', listedDirs.join( ', ' ) ) + '\n' : '' )
		+ ( dirs.includes( 'plugins' ) && options.plugins ? '• plugins limited to: ' + options.plugins.length + ' selected\n' : '' )
		+ ( uploadsSelected && options.uploads_scope ? '• uploads limited to: ' + ( options.uploads_scope.length ? options.uploads_scope.join( ', ' ) : 'nothing' ) + '\n' : '' )
		+ ( uploadsTracked ? '• ' + text[ options.uploads_full_audit ? 'uploads_audit' : 'uploads_tracked' ] + '\n' : '' )
		+ ( uploadsTracked ? '• ' + text[ options.uploads_adopt ? 'uploads_adopt' : 'uploads_baseline' ] + '\n' : '' )
		+ ( uploadsIgnored ? '• ' + text.uploads_ignored + '\n' : '' )
		+ ( constants.length ? '• wp-config constants replicated: ' + constants.join( ', ' ) + '\n' : '' )
		+ '• ' + text.safety.replace( '%s', safety.length ? safety.join( ', ' ) : text.safety_none ) + '\n'
		+ '\n' + text[ incremental ? 'outro_incremental' : 'outro_full' ];
	if ( ! window.confirm( summary ) ) { return; }

	post( { op: 'start_job', options: JSON.stringify( options ), background: background ? '1' : '0' } )
		.then( ( res ) => {
			if ( ! res.success ) { return notice( res.data.message, false ); }
			renderedLogLines = 0;
			document.getElementById( 'sbs-log' ).textContent = '';
			renderJob( res.data.job );
			startPolling();
		} ).catch( fail );
} );

onClick( 'sbs-cancel', () => {
	if ( ! window.confirm( 'Request cancellation? The worker will stop at a safe checkpoint and roll back activated database/files.' ) ) { return; }
	post( { op: 'cancel_job' } ).then( ( res ) => {
		notice( res.data.message, res.success );
		if ( res.success ) { renderJob( res.data.job ); }
	} ).catch( fail );
} );

document.addEventListener( 'click', ( event ) => {
	const button = event.target.closest( '.sbs-view-log' );
	if ( ! button ) { return; }
	const jobId = button.getAttribute( 'data-job-id' ) || '';
	const dialog = document.getElementById( 'sbs-log-dialog' );
	const title = document.getElementById( 'sbs-log-dialog-title' );
	const body = document.getElementById( 'sbs-log-dialog-body' );
	if ( ! dialog || ! body ) { return; }
	title.textContent = 'Job log — ' + jobId;
	body.textContent = 'Loading…';
	if ( typeof dialog.showModal === 'function' ) {
		dialog.showModal();
	} else {
		dialog.setAttribute( 'open', 'open' );
	}
	post( { op: 'job_log', job_id: jobId } ).then( ( res ) => {
		if ( ! res.success ) {
			body.textContent = res.data && res.data.message ? res.data.message : 'Could not load the log.';
			return;
		}
		body.textContent = res.data.log ? res.data.log : '(empty log file)';
	} ).catch( ( err ) => {
		body.textContent = err && err.message ? err.message : 'Could not load the log.';
	} );
} );

// Reattach to an existing job on page load.
if ( initialJob ) {
	renderJob( initialJob );
	if ( 'running' === initialJob.status ) {
		startPolling();
	}
}

if ( document.getElementById( 'sbs-plugins-list' ) ) {
	updateHints();
	loadSourceMeta();
}
		} )();

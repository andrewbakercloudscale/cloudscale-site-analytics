/**
 * CloudScale Page Views - beacon.js  v3.0.0
 *
 * MODE: 'record' (singular post/page)
 *   Single POST to record the view. The response contains the updated
 *   count which is stored in window.cspvViews and used to update any
 *   .cspv-views-count elements on the page.
 *   NO second API call is made — the record response IS the count.
 *
 * MODE: 'fetch' (home / archive / search listing pages)
 *   Does NOT increment anything. Reads [data-cspv-id] attributes from
 *   the DOM, fetches all counts in one GET, injects them back.
 *
 * THEME INTEGRATION
 * -----------------
 * Single post template — just call the PHP helper, nothing else needed:
 *   <?php cspv_the_views(); ?>
 *
 * Archive/listing template — add data-cspv-id to your count element:
 *   <span class="cspv-views-count" data-cspv-id="<?php the_ID(); ?>">
 *       <?php echo cspv_get_view_count(); ?>
 *   </span>
 *
 * The beacon handles both automatically. You never need both on the
 * same page — on singular pages data-cspv-id is ignored in favour of
 * the record response.
 */

( function () {
    'use strict';

    if ( typeof cspvData === 'undefined' ) { return; }

    var debug   = cspvData.debug === true;
    var postId  = cspvData.postId ? String( cspvData.postId ) : null;

    function log() {
        if ( debug && typeof console !== 'undefined' ) {
            console.log.apply( console, ['[CloudScale PV]'].concat(
                Array.prototype.slice.call( arguments ) ) );
        }
    }

    // ------------------------------------------------------------------
    // Update DOM elements with a counts map { "postId": count, ... }
    // ------------------------------------------------------------------
    function updateDOM( counts ) {
        Object.keys( counts ).forEach( function( id ) {
            var val = Number( counts[ id ] ).toLocaleString();

            // Archive-style elements with explicit data-cspv-id
            document.querySelectorAll( '[data-cspv-id="' + id + '"]' )
                .forEach( function( el ) { el.textContent = val; } );
        } );

        // On singular pages also update plain .cspv-views-count elements
        // (the ones output by cspv_the_views() with no data-cspv-id)
        if ( cspvData.mode === 'record' && postId && counts[ postId ] !== undefined ) {
            var val = Number( counts[ postId ] ).toLocaleString();
            document.querySelectorAll( '.cspv-views-count:not([data-cspv-id])' )
                .forEach( function( el ) { el.textContent = val; } );

            // Also update auto-display counters
            document.querySelectorAll( '.cspv-ad-num' )
                .forEach( function( el ) { el.textContent = val; } );

            // Expose as a global so advanced templates can read it
            window.cspvViews = counts[ postId ];
            log( 'window.cspvViews set to', window.cspvViews );
        }
    }

    // ------------------------------------------------------------------
    // MODE: record
    // ------------------------------------------------------------------
    // ------------------------------------------------------------------
    // Deduplication via localStorage with 24 hour TTL
    // Unlike sessionStorage, localStorage persists across tabs and
    // browser restarts, preventing double counts when a link opens
    // first in an in app browser (WhatsApp, Facebook, etc.) and then
    // again in a real browser tab on the same device.
    // ------------------------------------------------------------------
    var DEDUP_TTL_MS = 24 * 60 * 60 * 1000; // 24 hours

    function isDuplicate( pid ) {
        try {
            var raw = localStorage.getItem( 'cspv_seen_' + pid );
            if ( ! raw ) { return false; }
            var ts = parseInt( raw, 10 );
            if ( isNaN( ts ) ) { return false; }
            if ( Date.now() - ts > DEDUP_TTL_MS ) {
                localStorage.removeItem( 'cspv_seen_' + pid );
                return false;
            }
            return true;
        } catch(e) { return false; } // localStorage blocked — allow recording
    }

    function markSeen( pid ) {
        try { localStorage.setItem( 'cspv_seen_' + pid, String( Date.now() ) ); } catch(e) {}
    }

    // Prune expired dedup keys periodically (max once per page load)
    function pruneDedup() {
        try {
            var now = Date.now();
            var i = localStorage.length;
            while ( i-- ) {
                var key = localStorage.key( i );
                if ( key && key.indexOf( 'cspv_seen_' ) === 0 ) {
                    var ts = parseInt( localStorage.getItem( key ), 10 );
                    if ( isNaN( ts ) || now - ts > DEDUP_TTL_MS ) {
                        localStorage.removeItem( key );
                    }
                }
            }
        } catch(e) {}
    }
    pruneDedup();

    function recordView() {
        log( 'record mode — post', postId );

        // Deduplicate: only record once per 24 hours per post per browser
        // Respects the server-side dedup setting — when dedup is off, always record
        // wp_localize_script converts booleans: true -> "1", false -> ""
        var dedupOn = !!cspvData.dedupOn && cspvData.dedupOn !== '0' && cspvData.dedupOn !== '';
        if ( dedupOn && isDuplicate( postId ) ) {
            log( 'already recorded within 24h — skipping beacon, fetching count' );
            // Still fetch the current count so the display stays fresh
            fetch( cspvData.apiUrl.replace( '/record/', '/counts?ids=' ) + postId, {
                method: 'GET', credentials: 'same-origin'
            } )
            .then( function( r ) { return r.ok ? r.json() : null; } )
            .then( function( counts ) { if ( counts ) updateDOM( counts ); } )
            .catch( function() {} );
            return;
        }

        fetch( cspvData.apiUrl, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': cspvData.nonce },
            body:        JSON.stringify( { referrer: document.referrer || '' } ),
            credentials: 'same-origin',
            keepalive:   true,
        } )
        .then( function( r ) {
            if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
            return r.json();
        } )
        .then( function( data ) {
            if ( ! data || data.views === undefined ) { return; }
            log( 'recorded. count now:', data.views, '| logged:', data.logged );
            var counts = {};
            counts[ postId ] = data.views;
            updateDOM( counts );
            // Mark as seen for 24 hours (only when dedup is enabled)
            if ( dedupOn ) { markSeen( postId ); }
        } )
        .catch( function( err ) { log( 'beacon error:', err ); } );
    }

    // ------------------------------------------------------------------
    // MODE: fetch (archive / listing)
    // ------------------------------------------------------------------
    function fetchCounts() {
        var els  = document.querySelectorAll( '[data-cspv-id]' );
        var ids  = [];
        var seen = {};

        els.forEach( function( el ) {
            var id = el.getAttribute( 'data-cspv-id' );
            if ( id && ! seen[ id ] ) { ids.push( id ); seen[ id ] = true; }
        } );

        if ( ids.length === 0 ) {
            log( 'fetch mode — no [data-cspv-id] elements found.' );
            return;
        }

        log( 'fetch mode — fetching counts for', ids.length, 'posts' );

        fetch( cspvData.countsUrl + '?ids=' + ids.join( ',' ), {
            method: 'GET', credentials: 'same-origin',
        } )
        .then( function( r ) {
            if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
            return r.json();
        } )
        .then( function( counts ) {
            if ( ! counts ) { return; }
            log( 'received counts:', counts );
            updateDOM( counts );
        } )
        .catch( function( err ) { log( 'fetch error:', err ); } );
    }

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------
    function boot() {
        if ( cspvData.mode === 'record' ) {
            recordView();
        } else {
            fetchCounts();
        }
    }

    if ( document.readyState === 'complete' ) {
        boot();
    } else {
        window.addEventListener( 'load', boot );
    }

} )();

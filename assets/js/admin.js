/* global jQuery, lgpData */
( function ( $ ) {
    'use strict';

    // ── Chunked Scan with Real-time Progress ──────────────────────────────────

    var scanState = {
        postIds  : [],
        total    : 0,
        current  : 0,
        broken   : 0,
        running  : false,
    };

    $( '#lgp-scan-btn' ).on( 'click', function () {
        if ( scanState.running ) return;

        var $btn = $( this ).prop( 'disabled', true );
        $( '#lgp-scan-message' ).hide().removeClass( 'lgp-scan-message--success lgp-scan-message--error' );

        // Step 1: Init — get post IDs and clear DB.
        $.post( lgpData.ajaxUrl, { action: 'lgp_scan_init', nonce: lgpData.scanNonce } )
            .done( function ( res ) {
                if ( ! res.success ) {
                    $btn.prop( 'disabled', false );
                    showMsg( errText( res ), 'error' );
                    return;
                }

                scanState.postIds = res.data.post_ids;
                scanState.total   = res.data.total;
                scanState.current = 0;
                scanState.broken  = 0;
                scanState.running = true;

                if ( scanState.total === 0 ) {
                    finishScan( $btn );
                    return;
                }

                showProgress();
                updateProgress( 0, scanState.total, 'Initialising…', 0, 0 );
                scanNextPost( $btn );
            } )
            .fail( function () {
                $btn.prop( 'disabled', false );
                showMsg( lgpData.i18n.scanError, 'error' );
            } );
    } );

    // Step 2: Scan posts one-by-one.
    function scanNextPost( $btn ) {
        if ( scanState.current >= scanState.total ) {
            finishScan( $btn );
            return;
        }

        var postId = scanState.postIds[ scanState.current ];

        $.post( lgpData.ajaxUrl, {
            action  : 'lgp_scan_post',
            nonce   : lgpData.scanNonce,
            post_id : postId,
        } )
        .done( function ( res ) {
            scanState.current++;

            if ( res.success ) {
                scanState.broken += res.data.broken;
                var label = res.data.post_title || ( 'Post #' + postId );
                updateProgress(
                    scanState.current,
                    scanState.total,
                    label,
                    scanState.broken,
                    scanState.current
                );
            }

            // Yield to browser briefly, then continue.
            setTimeout( function () { scanNextPost( $btn ); }, 0 );
        } )
        .fail( function () {
            // On failure, skip this post and continue.
            scanState.current++;
            setTimeout( function () { scanNextPost( $btn ); }, 200 );
        } );
    }

    // Step 3: Mark complete, fetch final table HTML.
    function finishScan( $btn ) {
        scanState.running = false;

        $.post( lgpData.ajaxUrl, {
            action  : 'lgp_scan_complete',
            nonce   : lgpData.scanNonce,
            scanned : scanState.total,
        } )
        .done( function ( res ) {
            $btn.prop( 'disabled', false );
            hideProgress();

            if ( res.success ) {
                var d = res.data;

                // Update counters.
                $( '#lgp-broken-count' ).text( d.broken );
                var $card = $( '#lgp-broken-count' ).closest( '.lgp-stat-card' );
                $card.removeClass( 'lgp-stat-card--alert lgp-stat-card--ok' )
                     .addClass( d.broken > 0 ? 'lgp-stat-card--alert' : 'lgp-stat-card--ok' );

                if ( d.last_scan ) $( '#lgp-last-scan' ).text( d.last_scan );
                if ( d.affected  ) $( '#lgp-affected-count' ).text( d.affected );

                $( '#lgp-links-container' ).html( d.html );
                bindTableEvents();

                showMsg(
                    lgpData.i18n.scanDone + ' — ' +
                    scanState.total + ' post(s) scanned, ' +
                    d.broken + ' broken item(s) found.',
                    'success'
                );
            } else {
                showMsg( errText( res ), 'error' );
            }
        } )
        .fail( function () {
            $btn.prop( 'disabled', false );
            hideProgress();
            showMsg( lgpData.i18n.scanError, 'error' );
        } );
    }

    // ── Progress helpers ──────────────────────────────────────────────────────

    function showProgress() {
        $( '#lgp-progress-wrap' ).slideDown( 200 );
    }

    function hideProgress() {
        // Mark bar 100% green briefly before hiding.
        $( '#lgp-progress-fill' ).css( 'width', '100%' ).addClass( 'lgp-progress-done' );
        setTimeout( function () {
            $( '#lgp-progress-wrap' ).slideUp( 300 );
            $( '#lgp-progress-fill' ).removeClass( 'lgp-progress-done' ).css( 'width', '0%' );
        }, 800 );
    }

    function updateProgress( current, total, postTitle, broken, scanned ) {
        var pct = total > 0 ? Math.round( ( current / total ) * 100 ) : 0;

        $( '#lgp-progress-fill' ).css( 'width', pct + '%' );
        $( '#lgp-progress-counter' ).text( current + ' ' + lgpData.i18n.postOf + ' ' + total );
        $( '#lgp-progress-label' ).text( postTitle ? 'Scanning: ' + postTitle : 'Scanning…' );
        $( '#lgp-progress-broken' ).text( broken );
        $( '#lgp-progress-scanned' ).text( scanned );
    }

    // ── Table event bindings ──────────────────────────────────────────────────

    function bindTableEvents() {

        // Select-all.
        $( '#lgp-select-all' ).off( 'change.lgp' ).on( 'change.lgp', function () {
            $( '.lgp-row-cb' ).prop( 'checked', $( this ).is( ':checked' ) );
        } );

        // Bulk dismiss.
        $( '#lgp-bulk-dismiss' ).off( 'click.lgp' ).on( 'click.lgp', function () {
            var ids = $( '.lgp-row-cb:checked' ).map( function () { return $( this ).val(); } ).get();

            if ( ids.length === 0 ) { alert( lgpData.i18n.noneSelected ); return; }
            if ( ! window.confirm( lgpData.i18n.bulkConfirm ) ) return;

            $( this ).prop( 'disabled', true );

            $.post( lgpData.ajaxUrl, {
                action : 'lgp_bulk_delete',
                nonce  : lgpData.bulkDeleteNonce,
                ids    : ids,
            } ).done( function ( res ) {
                $( '#lgp-bulk-dismiss' ).prop( 'disabled', false );
                if ( res.success ) {
                    $.each( res.data.ids, function ( i, id ) { $( '#lgp-link-row-' + id ).remove(); } );
                    updateCountAfterDelete( res.data.deleted );
                    checkEmptyTable();
                } else { alert( errText( res ) ); }
            } ).fail( function () { $( '#lgp-bulk-dismiss' ).prop( 'disabled', false ); } );
        } );

        // Single dismiss.
        $( '.lgp-delete-link-btn' ).off( 'click.lgp' ).on( 'click.lgp', function () {
            var $btn = $( this ).prop( 'disabled', true );
            var id   = $btn.data( 'id' );

            if ( ! window.confirm( lgpData.i18n.confirm ) ) { $btn.prop( 'disabled', false ); return; }

            $.post( lgpData.ajaxUrl, { action: 'lgp_delete_link', nonce: lgpData.deleteNonce, id: id } )
                .done( function ( res ) {
                    if ( res.success ) {
                        $( '#lgp-link-row-' + id ).fadeOut( 250, function () {
                            $( this ).remove();
                            updateCountAfterDelete( 1 );
                            checkEmptyTable();
                        } );
                    } else { $btn.prop( 'disabled', false ); alert( errText( res ) ); }
                } ).fail( function () { $btn.prop( 'disabled', false ); } );
        } );

        // Ignore URL.
        $( '.lgp-ignore-btn' ).off( 'click.lgp' ).on( 'click.lgp', function () {
            var $btn = $( this ).prop( 'disabled', true );
            var url  = $btn.data( 'url' );
            var id   = $btn.data( 'id' );

            if ( ! window.confirm( lgpData.i18n.ignoreConfirm ) ) { $btn.prop( 'disabled', false ); return; }

            $.post( lgpData.ajaxUrl, {
                action  : 'lgp_ignore_url',
                nonce   : lgpData.ignoreNonce,
                url     : url,
                link_id : id,
            } ).done( function ( res ) {
                if ( res.success ) {
                    $( '#lgp-link-row-' + id ).fadeOut( 250, function () {
                        $( this ).remove();
                        updateCountAfterDelete( 1 );
                        checkEmptyTable();
                    } );
                } else { $btn.prop( 'disabled', false ); alert( errText( res ) ); }
            } ).fail( function () { $btn.prop( 'disabled', false ); } );
        } );

        // Open redirect modal.
        $( '.lgp-add-redirect-btn' ).off( 'click.lgp' ).on( 'click.lgp', function () {
            $( '#lgp-modal-source' ).val( $( this ).data( 'source' ) );
            $( '#lgp-modal-target' ).val( '' );
            $( '#lgp-modal-link-id' ).val( $( this ).data( 'link-id' ) );
            $( '#lgp-modal-error' ).hide().text( '' );
            $( '#lgp-redirect-modal' ).show();
            setTimeout( function () { $( '#lgp-modal-target' ).trigger( 'focus' ); }, 80 );
        } );

        // Pagination.
        $( '.lgp-page-btn' ).off( 'click.lgp' ).on( 'click.lgp', function () {
            loadPage( $( this ).data( 'page' ) );
        } );
    }

    bindTableEvents();

    // ── Pagination ────────────────────────────────────────────────────────────

    function loadPage( page ) {
        $( '#lgp-links-container' ).css( 'opacity', .5 );

        $.post( lgpData.ajaxUrl, { action: 'lgp_get_page', nonce: lgpData.scanNonce, page: page } )
            .done( function ( res ) {
                $( '#lgp-links-container' ).css( 'opacity', 1 );
                if ( res.success ) {
                    $( '#lgp-links-container' ).html( res.data.html );
                    bindTableEvents();
                    $( 'html, body' ).animate( { scrollTop: $( '#lgp-links-container' ).offset().top - 80 }, 200 );
                }
            } )
            .fail( function () { $( '#lgp-links-container' ).css( 'opacity', 1 ); } );
    }

    // ── Modal ─────────────────────────────────────────────────────────────────

    $( document ).on( 'click', '#lgp-modal-cancel, .lgp-modal-cancel-btn, .lgp-modal-backdrop', closeModal );
    $( document ).on( 'keydown', function ( e ) { if ( e.key === 'Escape' ) closeModal(); } );

    function closeModal() {
        $( '#lgp-redirect-modal' ).hide();
        $( '#lgp-modal-error' ).hide().text( '' );
    }

    $( '#lgp-modal-save' ).on( 'click', function () {
        var $btn   = $( this ).prop( 'disabled', true );
        var source = $( '#lgp-modal-source' ).val().trim();
        var target = $( '#lgp-modal-target' ).val().trim();
        var $err   = $( '#lgp-modal-error' );

        if ( ! target ) {
            $err.text( 'Please enter a target URL.' ).show();
            $( '#lgp-modal-target' ).trigger( 'focus' );
            $btn.prop( 'disabled', false );
            return;
        }

        $err.hide();

        $.post( lgpData.ajaxUrl, { action: 'lgp_add_redirect', nonce: lgpData.addRedNonce, source: source, target: target } )
            .done( function ( res ) {
                $btn.prop( 'disabled', false );
                if ( res.success ) {
                    closeModal();
                    showMsg( 'Redirect saved! Taking you to Redirects page…', 'success' );
                    setTimeout( function () { window.location.href = lgpData.redirectsUrl; }, 1400 );
                } else { $err.text( errText( res ) ).show(); }
            } )
            .fail( function () { $btn.prop( 'disabled', false ); $err.text( 'Request failed.' ).show(); } );
    } );

    $( '#lgp-modal-target' ).on( 'keydown', function ( e ) {
        if ( e.key === 'Enter' ) { e.preventDefault(); $( '#lgp-modal-save' ).trigger( 'click' ); }
    } );

    // ── Redirects page: delete ────────────────────────────────────────────────

    $( document ).on( 'click', '.lgp-delete-redirect-btn', function () {
        var $btn = $( this ).prop( 'disabled', true );
        var id   = $btn.data( 'id' );

        if ( ! window.confirm( lgpData.i18n.confirm ) ) { $btn.prop( 'disabled', false ); return; }

        $.post( lgpData.ajaxUrl, { action: 'lgp_delete_redirect', nonce: lgpData.delRedNonce, id: id } )
            .done( function ( res ) {
                if ( res.success ) {
                    $( '#lgp-redir-row-' + id ).fadeOut( 250, function () {
                        $( this ).remove();
                        if ( $( '#lgp-redirects-table tbody tr' ).length === 0 ) {
                            $( '#lgp-redirects-table' ).replaceWith( '<p class="lgp-no-results">No redirects configured yet.</p>' );
                        }
                    } );
                } else { $btn.prop( 'disabled', false ); alert( errText( res ) ); }
            } )
            .fail( function () { $btn.prop( 'disabled', false ); } );
    } );

    // ── Settings page: unignore URL ───────────────────────────────────────────

    $( document ).on( 'click', '.lgp-unignore-btn', function () {
        var $btn = $( this ).prop( 'disabled', true );
        var id   = $btn.data( 'id' );

        if ( ! window.confirm( lgpData.i18n.confirm ) ) { $btn.prop( 'disabled', false ); return; }

        $.post( lgpData.ajaxUrl, { action: 'lgp_unignore_url', nonce: lgpData.ignoreNonce, id: id } )
            .done( function ( res ) {
                if ( res.success ) {
                    $( '#lgp-ignored-row-' + id ).fadeOut( 250, function () {
                        $( this ).remove();
                        if ( $( '#lgp-ignored-table tbody tr' ).length === 0 ) {
                            $( '#lgp-ignored-table' ).replaceWith( '<p class="lgp-no-results">No URLs ignored yet.</p>' );
                        }
                    } );
                } else { $btn.prop( 'disabled', false ); alert( errText( res ) ); }
            } )
            .fail( function () { $btn.prop( 'disabled', false ); } );
    } );

    // ── Helpers ───────────────────────────────────────────────────────────────

    function showMsg( text, type ) {
        var $msg = $( '#lgp-scan-message' );
        $msg.text( text )
            .removeClass( 'lgp-scan-message--success lgp-scan-message--error' )
            .addClass( 'lgp-scan-message--' + type )
            .show();

        if ( type === 'success' ) {
            setTimeout( function () { $msg.fadeOut( 400 ); }, 7000 );
        }
    }

    function errText( res ) {
        return ( res.data && res.data.message ) ? res.data.message : 'An unexpected error occurred.';
    }

    function updateCountAfterDelete( removed ) {
        var $el     = $( '#lgp-broken-count' );
        var updated = Math.max( 0, ( parseInt( $el.text(), 10 ) || 0 ) - removed );
        $el.text( updated );

        if ( updated === 0 ) {
            $el.closest( '.lgp-stat-card' ).removeClass( 'lgp-stat-card--alert' ).addClass( 'lgp-stat-card--ok' );
        }
    }

    function checkEmptyTable() {
        if ( $( '.lgp-links-table tbody tr' ).length === 0 ) {
            $( '#lgp-links-container' ).html( '<p class="lgp-no-results">No broken links found. Your site looks healthy!</p>' );
        }
    }

} )( jQuery );

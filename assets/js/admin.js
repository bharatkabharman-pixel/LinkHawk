/* global jQuery, lgpData */
( function ( $ ) {
    'use strict';

    // ── Scan Now ──────────────────────────────────────────────────────────────

    $( '#lgp-scan-btn' ).on( 'click', function () {
        var $btn     = $( this );
        var $spinner = $( '#lgp-spinner' );
        var $msg     = $( '#lgp-scan-message' );

        $btn.prop( 'disabled', true );
        $spinner.show();
        $msg.hide().removeClass( 'lgp-scan-message--success lgp-scan-message--error' );

        $.post( lgpData.ajaxUrl, { action: 'lgp_scan_now', nonce: lgpData.scanNonce } )
            .done( function ( res ) {
                $btn.prop( 'disabled', false );
                $spinner.hide();

                if ( res.success ) {
                    var d = res.data;

                    // Update broken count badge.
                    $( '#lgp-broken-count' ).text( d.broken );
                    var $card = $( '#lgp-broken-count' ).closest( '.lgp-stat-card' );
                    $card.removeClass( 'lgp-stat-card--alert lgp-stat-card--ok' )
                         .addClass( d.broken > 0 ? 'lgp-stat-card--alert' : 'lgp-stat-card--ok' );

                    // Update last scan time.
                    if ( d.last_scan ) {
                        $( '#lgp-last-scan' ).text( d.last_scan );
                    }

                    // Replace table.
                    $( '#lgp-links-container' ).html( d.html );
                    bindTableEvents();

                    showMsg(
                        lgpData.i18n.scanDone + ' — ' +
                        d.scanned + ' post(s) scanned, ' +
                        d.broken  + ' broken link(s) found.',
                        'success'
                    );
                } else {
                    showMsg( errText( res ), 'error' );
                }
            } )
            .fail( function () {
                $btn.prop( 'disabled', false );
                $spinner.hide();
                showMsg( lgpData.i18n.scanError, 'error' );
            } );
    } );

    // ── Table event bindings (re-run after AJAX table replacement) ────────────

    function bindTableEvents() {

        // Select-all checkbox.
        $( '#lgp-select-all' ).off( 'change.lgp' ).on( 'change.lgp', function () {
            $( '.lgp-row-cb' ).prop( 'checked', $( this ).is( ':checked' ) );
        } );

        // Bulk dismiss.
        $( '#lgp-bulk-dismiss' ).off( 'click.lgp' ).on( 'click.lgp', function () {
            var ids = [];
            $( '.lgp-row-cb:checked' ).each( function () {
                ids.push( $( this ).val() );
            } );

            if ( ids.length === 0 ) {
                alert( lgpData.i18n.noneSelected );
                return;
            }

            if ( ! window.confirm( lgpData.i18n.bulkConfirm ) ) return;

            var $btn = $( this ).prop( 'disabled', true );

            $.post( lgpData.ajaxUrl, {
                action : 'lgp_bulk_delete',
                nonce  : lgpData.bulkDeleteNonce,
                ids    : ids,
            } ).done( function ( res ) {
                $btn.prop( 'disabled', false );
                if ( res.success ) {
                    $.each( res.data.ids, function ( i, id ) {
                        $( '#lgp-link-row-' + id ).remove();
                    } );
                    updateCountAfterDelete( res.data.deleted );
                    checkEmptyTable();
                } else {
                    alert( errText( res ) );
                }
            } ).fail( function () {
                $btn.prop( 'disabled', false );
            } );
        } );

        // Single row dismiss.
        $( '.lgp-delete-link-btn' ).off( 'click.lgp' ).on( 'click.lgp', function () {
            var $btn = $( this );
            var id   = $btn.data( 'id' );

            if ( ! window.confirm( lgpData.i18n.confirm ) ) return;

            $btn.prop( 'disabled', true );

            $.post( lgpData.ajaxUrl, {
                action : 'lgp_delete_link',
                nonce  : lgpData.deleteNonce,
                id     : id,
            } ).done( function ( res ) {
                if ( res.success ) {
                    $( '#lgp-link-row-' + id ).fadeOut( 250, function () {
                        $( this ).remove();
                        updateCountAfterDelete( 1 );
                        checkEmptyTable();
                    } );
                } else {
                    $btn.prop( 'disabled', false );
                    alert( errText( res ) );
                }
            } ).fail( function () {
                $btn.prop( 'disabled', false );
            } );
        } );

        // Open "Add 301 Redirect" modal.
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
            var page = $( this ).data( 'page' );
            loadPage( page );
        } );
    }

    bindTableEvents();

    // ── Pagination loader ─────────────────────────────────────────────────────

    function loadPage( page ) {
        $( '#lgp-links-container' ).css( 'opacity', 0.5 );

        $.post( lgpData.ajaxUrl, {
            action : 'lgp_get_page',
            nonce  : lgpData.scanNonce,
            page   : page,
        } ).done( function ( res ) {
            $( '#lgp-links-container' ).css( 'opacity', 1 );
            if ( res.success ) {
                $( '#lgp-links-container' ).html( res.data.html );
                bindTableEvents();
                $( 'html, body' ).animate( { scrollTop: $( '#lgp-links-container' ).offset().top - 80 }, 200 );
            }
        } ).fail( function () {
            $( '#lgp-links-container' ).css( 'opacity', 1 );
        } );
    }

    // ── Modal ─────────────────────────────────────────────────────────────────

    $( document ).on( 'click', '#lgp-modal-cancel, .lgp-modal-cancel-btn, .lgp-modal-backdrop', closeModal );

    $( document ).on( 'keydown', function ( e ) {
        if ( e.key === 'Escape' ) closeModal();
    } );

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

        $err.hide().text( '' );

        $.post( lgpData.ajaxUrl, {
            action : 'lgp_add_redirect',
            nonce  : lgpData.addRedNonce,
            source : source,
            target : target,
        } ).done( function ( res ) {
            $btn.prop( 'disabled', false );
            if ( res.success ) {
                closeModal();
                showMsg( 'Redirect saved! Taking you to the Redirects page…', 'success' );
                setTimeout( function () {
                    window.location.href = lgpData.redirectsUrl;
                }, 1400 );
            } else {
                $err.text( errText( res ) ).show();
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false );
            $err.text( 'Request failed. Please try again.' ).show();
        } );
    } );

    // Allow Enter key in modal target input to submit.
    $( '#lgp-modal-target' ).on( 'keydown', function ( e ) {
        if ( e.key === 'Enter' ) {
            e.preventDefault();
            $( '#lgp-modal-save' ).trigger( 'click' );
        }
    } );

    // ── Redirects page: delete row ────────────────────────────────────────────

    $( document ).on( 'click', '.lgp-delete-redirect-btn', function () {
        var $btn = $( this ).prop( 'disabled', true );
        var id   = $btn.data( 'id' );

        if ( ! window.confirm( lgpData.i18n.confirm ) ) {
            $btn.prop( 'disabled', false );
            return;
        }

        $.post( lgpData.ajaxUrl, {
            action : 'lgp_delete_redirect',
            nonce  : lgpData.delRedNonce,
            id     : id,
        } ).done( function ( res ) {
            if ( res.success ) {
                $( '#lgp-redir-row-' + id ).fadeOut( 250, function () {
                    $( this ).remove();
                    if ( $( '#lgp-redirects-table tbody tr' ).length === 0 ) {
                        $( '#lgp-redirects-table' ).replaceWith(
                            '<p class="lgp-no-results">No redirects configured yet.</p>'
                        );
                    }
                } );
            } else {
                $btn.prop( 'disabled', false );
                alert( errText( res ) );
            }
        } ).fail( function () {
            $btn.prop( 'disabled', false );
        } );
    } );

    // ── Helpers ───────────────────────────────────────────────────────────────

    function showMsg( text, type ) {
        var $msg = $( '#lgp-scan-message' );
        $msg.text( text )
            .removeClass( 'lgp-scan-message--success lgp-scan-message--error' )
            .addClass( 'lgp-scan-message--' + type )
            .show();

        // Auto-hide success after 6 s.
        if ( type === 'success' ) {
            setTimeout( function () { $msg.fadeOut( 400 ); }, 6000 );
        }
    }

    function errText( res ) {
        return ( res.data && res.data.message ) ? res.data.message : 'An unexpected error occurred.';
    }

    function updateCountAfterDelete( removed ) {
        var $el      = $( '#lgp-broken-count' );
        var current  = parseInt( $el.text(), 10 ) || 0;
        var updated  = Math.max( 0, current - removed );
        $el.text( updated );

        if ( updated === 0 ) {
            $el.closest( '.lgp-stat-card' )
               .removeClass( 'lgp-stat-card--alert' )
               .addClass( 'lgp-stat-card--ok' );
        }
    }

    function checkEmptyTable() {
        if ( $( '.lgp-links-table tbody tr' ).length === 0 ) {
            $( '#lgp-links-container' ).html(
                '<p class="lgp-no-results">No broken links found. Your site looks healthy!</p>'
            );
        }
    }

} )( jQuery );

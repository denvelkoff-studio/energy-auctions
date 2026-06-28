/**
 * Energy Auctions — frontend.
 * Countdown таймер, AJAX polling за текуща оферта, потвърждение преди наддаване.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.EA_Data || {};

	function pad( n ) {
		return n < 10 ? '0' + n : '' + n;
	}

	/**
	 * Форматира оставащо време до целеви ISO момент.
	 */
	function formatRemaining( endIso ) {
		var end = new Date( endIso ).getTime();
		var now = Date.now();
		var diff = Math.max( 0, Math.floor( ( end - now ) / 1000 ) );

		if ( diff <= 0 ) {
			return null;
		}

		var d = Math.floor( diff / 86400 );
		var h = Math.floor( ( diff % 86400 ) / 3600 );
		var m = Math.floor( ( diff % 3600 ) / 60 );
		var s = diff % 60;

		var i18n = cfg.i18n || {};
		var out = '';
		if ( d > 0 ) {
			out += d + ' ' + ( i18n.days || 'd' ) + ' ';
		}
		out += pad( h ) + ' ' + ( i18n.hours || 'h' ) + ' ';
		out += pad( m ) + ' ' + ( i18n.minutes || 'm' ) + ' ';
		out += pad( s ) + ' ' + ( i18n.seconds || 's' );
		return out;
	}

	function tick( $auction ) {
		var endIso = $auction.attr( 'data-end' );
		var status = $auction.attr( 'data-status' );
		var $timer = $auction.find( '[data-ea-timer]' );

		if ( ! endIso || ( status !== 'active' && status !== 'scheduled' ) ) {
			return;
		}

		var text = formatRemaining( endIso );
		if ( text === null ) {
			$timer.text( ( cfg.i18n && cfg.i18n.ended ) || 'Ended' );
		} else {
			$timer.text( text );
		}
	}

	/**
	 * AJAX poll за текущото състояние.
	 */
	function poll( $auction ) {
		var id = $auction.attr( 'data-auction-id' );
		if ( ! id || ! cfg.ajaxUrl ) {
			return;
		}

		$.get( cfg.ajaxUrl, { action: cfg.pollAction, auction_id: id } )
			.done( function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					return;
				}
				var d = res.data;

				if ( typeof d.current_html !== 'undefined' ) {
					$auction.find( '[data-ea-current]' ).html( d.current_html );
				}
				if ( typeof d.count_text !== 'undefined' ) {
					$auction.find( '[data-ea-count]' ).text( d.count_text );
				}
				if ( d.end_iso ) {
					$auction.attr( 'data-end', d.end_iso );
				}
				if ( d.status ) {
					var prev = $auction.attr( 'data-status' );
					$auction.attr( 'data-status', d.status );
					// При смяна на статус (напр. приключи) — презареждаме за коректен изглед.
					if ( prev === 'active' && d.status !== 'active' ) {
						window.location.reload();
					}
				}
				if ( typeof d.min_next !== 'undefined' ) {
					var $input = $auction.find( '[data-ea-min]' );
					$input.attr( 'min', d.min_next ).attr( 'data-ea-min', d.min_next );
				}
			} );
	}

	$( function () {
		var $auctions = $( '.ea-auction' );
		if ( ! $auctions.length ) {
			return;
		}

		// Таймер на всяка секунда.
		setInterval( function () {
			$auctions.each( function () {
				tick( $( this ) );
			} );
		}, 1000 );
		$auctions.each( function () {
			tick( $( this ) );
		} );

		// AJAX polling.
		var every = ( cfg.pollEvery || 7 ) * 1000;
		setInterval( function () {
			$auctions.each( function () {
				poll( $( this ) );
			} );
		}, every );

		// Потвърждение преди подаване на оферта.
		$( document ).on( 'submit', '.ea-bid-form', function ( e ) {
			if ( cfg.confirmBid && ! window.confirm( cfg.confirmBid ) ) {
				e.preventDefault();
			}
		} );
	} );
} )( jQuery );

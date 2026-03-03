/* global lhwcData, jQuery */
( function ( $ ) {
	'use strict';

	var $list   = null;
	var $footer = null;

	$( function () {
		$list   = $( '.lhwc-log-list' );
		$footer = $( '.lhwc-footer' );

		if ( ! $list.length ) {
			return;
		}

		$( document ).on( 'click', '.lhwc-load-more', onLoadMore );
	} );

	/**
	 * Charge la prochaine tranche de logs via AJAX et l'ajoute à la liste.
	 */
	function onLoadMore() {
		var $btn   = $( this );
		var offset = parseInt( $btn.data( 'offset' ), 10 );
		var postId = $list.data( 'post-id' );

		$btn.text( lhwcData.i18n.loading ).prop( 'disabled', true );

		$.post(
			lhwcData.ajaxUrl,
			{
				action:  'lhwc_load_logs',
				nonce:   lhwcData.nonce,
				post_id: postId,
				offset:  offset,
			},
			function ( response ) {
				if ( ! response.success ) {
					$btn.text( lhwcData.i18n.error ).prop( 'disabled', false );
					return;
				}

				// Injecter le HTML des nouvelles lignes
				$list.append( response.data.html );

				if ( response.data.has_more ) {
					$btn
						.text( lhwcData.i18n.loadMore )
						.prop( 'disabled', false )
						.data( 'offset', response.data.new_offset );
				} else {
					// Remplacer le bouton par un message de fin
					$footer.html(
						'<span class="lhwc-no-more">' + lhwcData.i18n.noMore + '</span>'
					);
				}
			}
		).fail( function () {
			$btn.text( lhwcData.i18n.error ).prop( 'disabled', false );
		} );
	}

} )( jQuery );

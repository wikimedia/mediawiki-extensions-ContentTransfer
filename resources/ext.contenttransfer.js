( function( mw, $, d ) {
	$( function() {
		new contentTransfer.widget.PageSelectorWidget( {
			$element: $( '#content-transfer-main' ),
			filterData: mw.config.get( 'ctFilterData' )
		} );
	} );
} )( mediaWiki, jQuery, document );

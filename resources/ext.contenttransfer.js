( function( mw, $, d ) {
	$( function() {
		new contentTransfer.widget.PageSelectorWidget( {
			$element: $( '#content-transfer-main' ),
			filters: mw.config.get( 'ctFilters' )
		} );
	} );
} )( mediaWiki, jQuery, document );

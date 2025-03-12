( function ( mw, $ ) {
	$( () => {
		new contentTransfer.widget.PageSelectorWidget( { // eslint-disable-line no-new
			$element: $( '#content-transfer-main' ),
			filters: mw.config.get( 'ctFilters' )
		} );
	} );
}( mediaWiki, jQuery ) );

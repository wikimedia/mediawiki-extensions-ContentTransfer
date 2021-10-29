<?php

use ContentTransfer\TargetManager;
use MediaWiki\MediaWikiServices;
use ContentTransfer\PageProvider;

return [
	'ContentTransferPageProviderRegistry' => function ( MediaWikiServices $services ) {
		return new PageProvider(
			// If there gets to be more globals for this ext, make dedicated config
			$services->getMainConfig(),
			$services->getDBLoadBalancer()
		);
	},
	'ContentTransferTargetManager' => function ( MediaWikiServices $services ) {
		$config = $services->getMainConfig()->get( 'ContentTransferTargets' );
		return new TargetManager( $config );
	}
];

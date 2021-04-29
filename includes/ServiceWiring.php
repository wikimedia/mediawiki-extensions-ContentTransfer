<?php

use ContentTransfer\PageProvider;
use MediaWiki\MediaWikiServices;

return [
	'ContentTransferPageProvider' => function ( MediaWikiServices $services ) {
		return new PageProvider(
			// If there gets to be more globals for this ext, make dedicated config
			$services->getMainConfig(),
			$services->getDBLoadBalancer()
		);
	}
];

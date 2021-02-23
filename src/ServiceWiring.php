<?php

use \MediaWiki\MediaWikiServices;

return [
	'ContentTransferPageProviderRegistry' => function ( MediaWikiServices $services ) {
		return new \ContentTransfer\PageProviderRegistry(
			// If there gets to be more globals for this ext, make dedicated config
			$services->getMainConfig()
		);
	}
];

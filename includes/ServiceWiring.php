<?php

use ContentTransfer\AuthenticatedRequestHandlerFactory;
use ContentTransfer\PageContentProviderFactory;
use ContentTransfer\PageFilterFactory;
use ContentTransfer\PageProvider;
use ContentTransfer\TargetManager;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;

return [
	'ContentTransferPageProvider' => static function ( MediaWikiServices $services ) {
		// If there gets to be more globals for this ext, make dedicated config
		return new PageProvider(
			$services->getMainConfig(),
			$services->getDBLoadBalancer()
		);
	},
	'ContentTransferPageFilterFactory' => static function ( MediaWikiServices $services ) {
		return new PageFilterFactory(
			ExtensionRegistry::getInstance()->getAttribute( 'ContentTransferPageFilters' ),
			$services->getDBLoadBalancer(),
			RequestContext::getMain()
		);
	},
	'ContentTransferTargetManager' => static function ( MediaWikiServices $services ) {
		$config = $services->getMainConfig()->get( 'ContentTransferTargets' );

		return new TargetManager( $config );
	},
	'ContentTransferAuthenticatedRequestHandlerFactory' => static function ( MediaWikiServices $services ) {
		return new AuthenticatedRequestHandlerFactory(
			$services->getMainConfig()
		);
	},
	'ContentTransferPageContentProviderFactory' => static function () {
		return new PageContentProviderFactory();
	},
];

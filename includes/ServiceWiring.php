<?php

use ContentTransfer\AuthenticatedRequestHandlerFactory;
use ContentTransfer\PageContentProviderFactory;
use ContentTransfer\PageFilterFactory;
use ContentTransfer\PageProvider;
use ContentTransfer\PagePusherFactory;
use ContentTransfer\TargetManager;
use MediaWiki\Context\RequestContext;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

return [
	'ContentTransferPageProvider' => static function ( MediaWikiServices $services ) {
		// If there gets to be more globals for this ext, make dedicated config
		return new PageProvider(
			$services->getMainConfig(),
			$services->getDBLoadBalancer(),
			$services->getTitleFactory()
		);
	},
	'ContentTransferPageFilterFactory' => static function ( MediaWikiServices $services ) {
		return new PageFilterFactory(
			ExtensionRegistry::getInstance()->getAttribute( 'ContentTransferPageFilters' ),
			$services->getObjectFactory(),
			RequestContext::getMain()
		);
	},
	'ContentTransferTargetManager' => static function ( MediaWikiServices $services ) {
		$config = $services->getMainConfig()->get( 'ContentTransferTargets' );

		return new TargetManager( $config );
	},
	'ContentTransferAuthenticatedRequestHandlerFactory' => static function ( MediaWikiServices $services ) {
		return new AuthenticatedRequestHandlerFactory(
			$services->getMainConfig(),
			$services->getHttpRequestFactory(),
			$services->getUrlUtils(),
			LoggerFactory::getInstance( 'ContentTransfer' )
		);
	},
	'ContentTransferPageContentProviderFactory' => static function ( MediaWikiServices $services ) {
		return new PageContentProviderFactory(
			$services->getRevisionLookup(),
			$services->getRevisionRenderer(),
			$services->getTitleFactory(),
			$services->getRepoGroup(),
			$services->getDBLoadBalancer()
		);
	},
	'ContentTransfer.PagePusherFactory' => static function ( MediaWikiServices $services ) {
		return new PagePusherFactory(
			$services->getService( 'ContentTransferAuthenticatedRequestHandlerFactory' ),
			$services->getService( 'ContentTransferPageContentProviderFactory' ),
			$services->getMainConfig(),
			$services->getService( 'NamespaceInfo' ),
			$services->getContentLanguage(),
			$services->getHookContainer(),
			$services->getDBLoadBalancer(),
			$services->getRevisionLookup(),
			LoggerFactory::getInstance( 'ContentTransfer' )
		);
	}
];

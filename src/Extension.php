<?php

namespace ContentTransfer;

use Config;
use DatabaseUpdater;
use MediaWiki\MediaWikiServices;

class Extension {
	public static function onCallback() {
		// Service wiring wont get loaded automatically for some reason - TEMP
		$mwServices = MediaWikiServices::getInstance();
		$mwServices->loadWiringFiles( [ __DIR__ . '/ServiceWiring.php' ] );
	}

	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		if( !$updater->getDB()->fieldExists( 'push_history', 'ph_target', __METHOD__ ) ) {
			$updater->dropTable( 'push_history' );
		}

		$updater->addExtensionTable(
			'push_history',
			__DIR__ . "/../db/push_history.sql"
		);
	}

	public static function getTargetsForClient( Config $config ) {
		$pushTargets = $config->get( 'ContentTransferTargets' );

		$pushTargetsForClient = [];
		foreach( $pushTargets as $key => $target ) {
			$config = [
				'url' => $target[ 'url' ],
				'pushToDraft' => false
			];

			if( isset( $target[ 'pushToDraft' ] ) && $target[ 'pushToDraft' ] === true ) {
				$config['pushToDraft'] = true;
			}

			if( isset( $target['displayText'] ) ) {
				$config['displayText'] = $target['displayText'];
			}
			if ( isset( $target['draftNamespace'] ) ) {
				$config['draftNamespace'] = $target['draftNamespace'];
			}
			$pushTargetsForClient[$key] = $config;
		}

		return $pushTargetsForClient;
	}
}

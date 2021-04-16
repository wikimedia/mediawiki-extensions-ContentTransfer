<?php

namespace ContentTransfer;

use Config;
use DatabaseUpdater;

class Extension {

	/**
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		if ( $updater->getDB()->tableExists( 'push_history' )
			&& !$updater->getDB()->fieldExists( 'push_history', 'ph_target', __METHOD__ ) ) {
			$updater->dropExtensionTable( 'push_history' );
		}

		$updater->addExtensionTable(
			'push_history',
			__DIR__ . "/../maintenance/db/push_history.sql"
		);
	}

	/**
	 *
	 * @param Config $config
	 * @return array
	 */
	public static function getTargetsForClient( Config $config ) {
		$pushTargets = $config->get( 'ContentTransferTargets' );

		$pushTargetsForClient = [];
		foreach ( $pushTargets as $key => $target ) {
			$config = [
				'url' => $target[ 'url' ],
				'pushToDraft' => false
			];

			if ( isset( $target[ 'pushToDraft' ] ) && $target[ 'pushToDraft' ] === true ) {
				$config['pushToDraft'] = true;
			}

			if ( isset( $target['displayText'] ) ) {
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

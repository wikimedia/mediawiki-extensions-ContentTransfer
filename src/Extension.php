<?php

namespace ContentTransfer;

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
}

<?php

namespace ContentTransfer\Hook;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class RunDatabaseUpdates implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$dir = dirname( __DIR__, 2 );

		if ( $updater->getDB()->tableExists( 'push_history' )
			&& !$updater->getDB()->fieldExists( 'push_history', 'ph_target', __METHOD__ ) ) {
			$updater->dropExtensionTable( 'push_history' );
		}

		$updater->addExtensionTable(
			'push_history',
			"$dir/db/$dbType/push_history.sql"
		);
	}
}

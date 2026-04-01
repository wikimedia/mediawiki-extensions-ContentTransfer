<?php

namespace ContentTransfer;

use MediaWiki\WikiMap\WikiMap;

class Setup {

	/**
	 * Set ContentTransferCurrentWiki to WikiMap ID if not already configured.
	 */
	public static function init(): void {
		if ( !$GLOBALS['wgContentTransferCurrentWiki'] ) {
			$GLOBALS['wgContentTransferCurrentWiki'] = WikiMap::getCurrentWikiId();
		}
	}
}

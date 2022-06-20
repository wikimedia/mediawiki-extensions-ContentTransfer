<?php

namespace ContentTransfer\Hook;

interface ContentTransferApiAfterGetPagesHook {
	/**
	 * @param array &$pageCount
	 * @param array &$pages
	 * @return void
	 */
	public function onContentTransferApiAfterGetPagesHook( &$pageCount, &$pages );
}

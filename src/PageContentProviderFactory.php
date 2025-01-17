<?php

namespace ContentTransfer;

use MediaWiki\Title\Title;

class PageContentProviderFactory {
	/**
	 * @param Title $title
	 *
	 * @return PageContentProvider
	 */
	public function newFromTitle( Title $title ): PageContentProvider {
		return new PageContentProvider( $title );
	}
}

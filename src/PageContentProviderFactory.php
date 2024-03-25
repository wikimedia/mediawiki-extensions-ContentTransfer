<?php

namespace ContentTransfer;

use Title;

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

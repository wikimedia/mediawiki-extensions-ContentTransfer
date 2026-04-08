<?php

namespace ContentTransfer;

use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRenderer;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Psr\Log\LoggerInterface;
use RepoGroup;
use Wikimedia\Rdbms\ILoadBalancer;

class PageContentProviderFactory {

	public function __construct(
		private readonly RevisionLookup $revisionLookup,
		private readonly RevisionRenderer $revisionRenderer,
		private readonly TitleFactory $titleFactory,
		private readonly RepoGroup $repoGroup,
		private readonly ILoadBalancer $lb,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * @param Title $title
	 *
	 * @return PageContentProvider
	 */
	public function newFromTitle( Title $title ): PageContentProvider {
		return new PageContentProvider(
			$title, $this->revisionLookup, $this->revisionRenderer,
			$this->titleFactory, $this->repoGroup, $this->lb, $this->logger
		);
	}
}

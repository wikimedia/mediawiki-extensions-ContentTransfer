<?php

namespace ContentTransfer;

use MediaWiki\Config\Config;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Language\Language;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\ILoadBalancer;

class PagePusherFactory {

	/**
	 * @param AuthenticatedRequestHandlerFactory $requestHandlerFactory
	 * @param PageContentProviderFactory $contentProviderFactory
	 * @param Config $config
	 * @param NamespaceInfo $namespaceInfo
	 * @param Language $language
	 * @param HookContainer $hookContainer
	 * @param ILoadBalancer $lb
	 * @param RevisionLookup $revisionLookup
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		private readonly AuthenticatedRequestHandlerFactory $requestHandlerFactory,
		private readonly PageContentProviderFactory $contentProviderFactory,
		private readonly Config $config,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly Language $language,
		private readonly HookContainer $hookContainer,
		private readonly ILoadBalancer $lb,
		private readonly RevisionLookup $revisionLookup,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * @param Title $title
	 * @param Target $target
	 * @param PushHistory $pushHistory
	 * @param bool $force
	 * @return PagePusher
	 */
	public function newPusher(
		Title $title, Target $target, PushHistory $pushHistory, bool $force = false
	): PagePusher {
		return new PagePusher(
			$title,
			$target,
			$pushHistory,
			$force,
			$this->config->get( 'ContentTransferIgnoreInsecureSSL' ),
			$this->requestHandlerFactory,
			$this->contentProviderFactory,
			$this->namespaceInfo,
			$this->language,
			$this->hookContainer,
			$this->logger
		);
	}

	/**
	 * @param Title $title
	 * @param User $user
	 * @param string $target
	 * @return PushHistory
	 */
	public function newPushHistory( Title $title, User $user, string $target ): PushHistory {
		return new PushHistory(
			$title,
			$user,
			$target,
			$this->lb,
			$this->revisionLookup
		);
	}
}

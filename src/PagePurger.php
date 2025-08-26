<?php

namespace ContentTransfer;

use MediaWiki\Status\Status;

class PagePurger {

	/** @var AuthenticatedRequestHandler */
	protected AuthenticatedRequestHandler $requestHandler;

	/**
	 * @param AuthenticatedRequestHandlerFactory $requestHandlerFactory
	 * @param array $titles
	 * @param Target $target
	 * @param bool|null $ignoreInsecureSSL
	 */
	public function __construct(
		AuthenticatedRequestHandlerFactory $requestHandlerFactory,
		private readonly array $titles,
		Target $target,
		bool $ignoreInsecureSSL = true
	) {
		$this->requestHandler = $requestHandlerFactory->newFromTarget( $target, $ignoreInsecureSSL );
	}

	/**
	 * Purge the target pages
	 *
	 * @return Status
	 */
	public function purge() {
		return $this->requestHandler->runAuthenticatedRequest( [
			'action' => 'purge',
			'forcerecursivelinkupdate' => true,
			'titles' => implode( '|', $this->titles ),
			'format' => 'json'
		] );
	}
}

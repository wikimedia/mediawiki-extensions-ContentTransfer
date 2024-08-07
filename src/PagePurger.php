<?php

namespace ContentTransfer;

use StatusValue;

class PagePurger {

	/**
	 * @var array
	 */
	protected $titles;
	/** @var array */
	protected $target;
	/** @var AuthenticatedRequestHandler */
	protected $requestHandler;

	/**
	 * @param AuthenticatedRequestHandlerFactory $requestHandlerFactory
	 * @param array $titles
	 * @param Target $target
	 * @param bool|null $ignoreInsecureSSL
	 */
	public function __construct( $requestHandlerFactory, $titles, $target, $ignoreInsecureSSL = true ) {
		$this->titles = $titles;
		$this->target = $target;

		$this->requestHandler = $requestHandlerFactory->newFromTarget( $target, $ignoreInsecureSSL );
	}

	/**
	 * Purge the target pages
	 *
	 * @return StatusValue
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

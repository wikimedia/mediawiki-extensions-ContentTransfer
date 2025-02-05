<?php

namespace ContentTransfer;

use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Utils\UrlUtils;
use Psr\Log\LoggerInterface;

class AuthenticatedRequestHandlerFactory {

	/**
	 * @param Config $config
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param UrlUtils $urlUtils
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		private readonly Config $config,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly UrlUtils $urlUtils,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * @param Target $target
	 * @param bool|null $ignoreInsecureSSL
	 *
	 * @return AuthenticatedRequestHandler
	 */
	public function newFromTarget( Target $target, ?bool $ignoreInsecureSSL = null ): AuthenticatedRequestHandler {
		if ( $ignoreInsecureSSL === null ) {
			$ignoreInsecureSSL = $this->isInsecure();
		}

		return new AuthenticatedRequestHandler(
			$target, $ignoreInsecureSSL, $this->httpRequestFactory, $this->logger, $this->urlUtils
		);
	}

	/**
	 * @return bool
	 */
	private function isInsecure(): bool {
		return $this->config->get(
			'ContentTransferIgnoreInsecureSSL'
		);
	}
}

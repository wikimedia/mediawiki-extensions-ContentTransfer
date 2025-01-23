<?php

namespace ContentTransfer;

use MediaWiki\Config\Config;

class AuthenticatedRequestHandlerFactory {

	/** @var Config */
	private Config $config;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
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

		return new AuthenticatedRequestHandler( $target, $ignoreInsecureSSL );
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

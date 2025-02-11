<?php

namespace ContentTransfer\TargetAuthenticator;

use ContentTransfer\AuthenticatedRequestHandler;
use ContentTransfer\ITargetAuthentication;
use MediaWiki\Status\Status;
use MWHttpRequest;

class StaticAccessToken implements ITargetAuthentication {

	/**
	 * @param string $accessToken
	 */
	public function __construct(
		private readonly string $accessToken
	) {
	}

	public function authenticate( AuthenticatedRequestHandler $requestHandler ): bool {
		return true;
	}

	/**
	 * @param MWHttpRequest $httpRequest
	 * @return void
	 */
	public function decorateWithAuthentication( MWHttpRequest $httpRequest ) {
		$httpRequest->setHeader( 'Authorization', 'Bearer ' . $this->accessToken );
	}

	/**
	 * @return Status|null
	 */
	public function getStatus(): ?Status {
		return null;
	}

	/**
	 * @param array $parsedUrl
	 * @return array
	 */
	public function getAuthenticationHeader( array $parsedUrl ): array {
		return [ 'Authorization' => 'Bearer ' . $this->accessToken ];
	}

	/**
	 * @return bool
	 */
	public function isAuthenticated(): bool {
		return true;
	}

	/**
	 * @return mixed
	 */
	public function jsonSerialize(): mixed {
		return [];
	}
}

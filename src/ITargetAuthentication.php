<?php

namespace ContentTransfer;

use JsonSerializable;
use MediaWiki\Status\Status;
use MWHttpRequest;

interface ITargetAuthentication extends JsonSerializable {
	/**
	 * @param AuthenticatedRequestHandler $requestHandler
	 * @return bool
	 */
	public function authenticate( AuthenticatedRequestHandler $requestHandler ): bool;

	/**
	 * @param MWHttpRequest $httpRequest
	 * @return void
	 */
	public function decorateWithAuthentication( MWHttpRequest $httpRequest );

	/**
	 * @param array $parsedUrl
	 * @return array
	 */
	public function getAuthenticationHeader( array $parsedUrl ): array;

	/**
	 * @return bool
	 */
	public function isAuthenticated(): bool;

	/**
	 * @return Status|null
	 */
	public function getStatus(): ?Status;
}

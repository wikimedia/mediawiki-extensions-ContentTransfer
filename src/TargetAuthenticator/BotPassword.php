<?php

namespace ContentTransfer\TargetAuthenticator;

use ContentTransfer\AuthenticatedRequestHandler;
use ContentTransfer\ITargetAuthentication;
use CookieJar;
use MediaWiki\Json\FormatJson;
use MediaWiki\Message\Message;
use MediaWiki\Status\Status;
use MWHttpRequest;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class BotPassword implements ITargetAuthentication, LoggerAwareInterface {

	/** @var array|null */
	private ?array $selectedUser = null;

	/** @var bool */
	private bool $authenticated = false;

	/** @var CookieJar|null */
	protected ?CookieJar $cookieJar = null;

	/** @var string */
	private string $loginToken = '';

	/** @var LoggerInterface */
	private LoggerInterface $logger;

	/** @var Status|null */
	private ?Status $status = null;

	/**
	 * @param array $users
	 */
	public function __construct(
		private readonly array $users
	) {
		$this->logger = new NullLogger();
	}

	public function authenticate( AuthenticatedRequestHandler $requestHandler ): bool {
		if ( !$this->authenticated ) {
			$this->authenticated =
				$this->getLoginToken( $requestHandler ) &&
				$this->doLogin( $requestHandler );
		}

		return $this->authenticated;
	}

	/**
	 * @param MWHttpRequest $httpRequest
	 * @return void
	 */
	public function decorateWithAuthentication( MWHttpRequest $httpRequest ) {
		if ( $this->cookieJar ) {
			$httpRequest->setCookieJar( $this->cookieJar );
		}
	}

	public function getStatus(): ?Status {
		return $this->status;
	}

	/**
	 * @param array $parsedUrl
	 * @return array
	 */
	public function getAuthenticationHeader( array $parsedUrl ): array {
		if ( !$this->cookieJar ) {
			return [];
		}
		return [
			'Coookie' => $this->cookieJar->serializeToHttpRequest(
				$parsedUrl['path'] ?: '/', $parsedUrl['host']
			)
		];
	}

	/**
	 * @param string $user
	 */
	public function selectUser( $user ) {
		foreach ( $this->users as $userData ) {
			if ( $userData['user'] === $user ) {
				$this->selectedUser = $userData;
				return;
			}
		}
	}

	/**
	 * @return mixed
	 */
	public function jsonSerialize(): mixed {
		return array_map( static function ( $userData ) {
			return [
				'user' => $userData['user']
			];
		}, $this->users );
	}

	/**
	 * @param LoggerInterface $logger
	 */
	public function setLogger( LoggerInterface $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * @return bool
	 */
	public function isAuthenticated(): bool {
		return $this->authenticated;
	}

	/**
	 *
	 * @return bool
	 */
	private function getLoginToken( AuthenticatedRequestHandler $requestHandler ) {
		$requestData = [
			'action' => 'query',
			'meta' => 'tokens',
			'type' => 'login',
			'format' => 'json'
		];

		$request = $requestHandler->getRequest( $requestData );
		$status = $request->execute();

		if ( !$status->isOK() ) {
			$this->status = $status;
			$msg = $status->getMessages()[0];
			$msg = Message::newFromSpecifier( $msg )->text();
			$this->logger->error( 'Getting login token failed. $status message - "' . $msg . '"' );
			return false;
		}

		$this->logger->debug( 'Login token request executed. Request content - "' . $request->getContent() . '"' );

		$response = FormatJson::decode( $request->getContent() );

		if ( !property_exists( $response->query, 'tokens' ) ||
			!property_exists( $response->query->tokens, 'logintoken' ) ) {
			$this->status = Status::newFatal( 'contenttransfer-no-login-token' );
			return false;
		}

		$this->cookieJar = $request->getCookieJar();
		$this->loginToken = $response->query->tokens->logintoken;

		return true;
	}

	/**
	 * @return array|null
	 */
	private function getSelectedUser(): ?array {
		return $this->selectedUser ?? $this->users[0] ?? null;
	}

	/**
	 *
	 * @return bool
	 */
	protected function doLogin( AuthenticatedRequestHandler $requestHandler ) {
		$selectedUser = $this->getSelectedUser();
		if ( !$selectedUser ) {
			$this->status = Status::newFatal( 'contenttransfer-authentication-no-user' );
			return false;
		}
		$requestData = [
			'action' => 'login',
			'lgname' => $selectedUser['user'],
			'lgpassword' => $selectedUser['password'],
			'lgtoken' => $this->loginToken,
			'format' => 'json'
		];

		$request = $requestHandler->getRequest( $requestData );
		$status = $request->execute();

		if ( !$status->isOK() ) {
			$this->status = $status;
			$msg = $status->getMessages()[0];
			$msg = Message::newFromSpecifier( $msg )->text();
			$this->logger->error( 'Login failed. $status message - "' . $msg . '"' );
			return false;
		}

		$this->logger->debug( 'Login request executed. Request content - "' . $request->getContent() . '"' );

		$response = FormatJson::decode( $request->getContent() );

		if ( $response->login->result === 'Success' ) {
			$this->cookieJar = $request->getCookieJar();
		} else {
			$this->status = Status::newFatal( 'contenttransfer-authentication-bad-login' );
			return false;
		}

		return true;
	}
}

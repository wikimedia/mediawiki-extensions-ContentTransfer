<?php

namespace ContentTransfer;

use CookieJar;
use Exception;
use File;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttpRequest;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\Message\Message;
use MediaWiki\Status\Status;
use MediaWiki\Utils\UrlUtils;
use Psr\Log\LoggerInterface;
use StatusValue;
use Throwable;

class AuthenticatedRequestHandler {

	/**
	 * @var array|null
	 */
	protected $pageProps = null;

	/**
	 *
	 * @var CookieJar
	 */
	protected $cookieJar;

	/**
	 * @var array
	 */
	protected $tokens;

	/**
	 * @var Status
	 */
	protected $status;

	/** @var bool */
	protected $authenticated = false;

	/**
	 *
	 * @param Target $target
	 * @param bool $ignoreInsecureSSL
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param LoggerInterface $logger
	 * @param UrlUtils $urlUtils
	 */
	public function __construct(
		private readonly Target $target,
		private readonly bool $ignoreInsecureSSL,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly LoggerInterface $logger,
		private readonly UrlUtils $urlUtils
	) {
		$this->status = Status::newGood();
	}

	protected function authenticate() {
		if ( !$this->authenticated ) {
			$this->authenticated =
				$this->getLoginToken() &&
				$this->doLogin();
		}

		return $this->authenticated;
	}

	/**
	 * @param string $title Title to check
	 * @return array|null
	 */
	public function getPageProps( $title ) {
		if ( !$this->pageProps ) {
			if ( !$this->authenticate() ) {
				return null;
			}
			$requestData = [
				'action' => 'query',
				'prop' => 'pageprops',
				'format' => 'json',
				'titles' => $title
			];

			$request = $this->getRequest( $requestData );
			$request->setCookieJar( $this->cookieJar );

			$status = $request->execute();

			if ( !$status->isOK() ) {
				$this->status = Status::newFatal( 'contenttransfer-no-pageprops' );
				return null;
			}

			$response = FormatJson::decode( $request->getContent(), true );

			if ( count( $response[ 'query' ][ 'pages' ] ) === 0 ) {
				$this->status = Status::newFatal( 'contenttransfer-cannot-create' );
				return null;
			}

			$key = array_keys( $response[ 'query' ][ 'pages' ] )[0];
			$this->pageProps = $response[ 'query' ][ 'pages' ][ $key ];
		}

		return $this->pageProps;
	}

	/**
	 * @param array $props
	 */
	public function setPageProps( $props ) {
		$this->pageProps = $props;
	}

	/**
	 * @return string|null
	 */
	public function getCSRFToken() {
		if ( !isset( $this->tokens['csrf'] ) ) {
			if ( !$this->authenticate() ) {
				return null;
			}
			$requestData = [
				'action' => 'query',
				'meta' => 'tokens',
				'type' => 'csrf',
				'format' => 'json'
			];

			$request = $this->getRequest( $requestData );
			$request->setCookieJar( $this->cookieJar );

			$status = $request->execute();

			if ( !$status->isOK() ) {
				$this->status = Status::newFatal( 'contenttransfer-no-csrf-token' );
				return null;
			}

			$response = FormatJson::decode( $request->getContent() );

			if ( !property_exists( $response->query, 'tokens' ) ||
				!property_exists( $response->query->tokens, 'csrftoken' ) ) {
				$this->status = Status::newFatal( 'contenttransfer-no-csrf-token' );
				return null;
			}

			$this->tokens[ 'csrf' ] = $response->query->tokens->csrftoken;
		}

		return $this->tokens[ 'csrf' ];
	}

	/**
	 * Presumes all pre-flight requests are run
	 *
	 * @param array $requestData
	 * @return StatusValue
	 */
	public function runPushRequest( $requestData ) {
		if ( !$this->authenticated || !isset( $this->tokens['csrf'] ) ) {
			return StatusValue::newFatal( 'Preflight conditions not met' );
		}
		$request = $this->getRequest( $requestData );
		$request->setCookieJar( $this->cookieJar );

		return $this->statusFromRequest( $request, $request->execute() );
	}

	/**
	 *
	 * @param array $requestData
	 * @return Status
	 */
	public function runAuthenticatedRequest( $requestData ) {
		if ( !$this->authenticate() ) {
			return $this->status;
		}
		if ( !$this->getCSRFToken() ) {
			return Status::newFatal( 'contenttransfer-no-csrf-token' );
		}

		$request = $this->getRequest( $requestData );
		$request->setCookieJar( $this->cookieJar );

		return $this->statusFromRequest( $request, $request->execute() );
	}

	/**
	 *
	 * @param File $file
	 * @param string $content
	 * @param string $filename
	 * @return bool
	 */
	public function uploadFile( File $file, $content, $filename ) {
		$postData = [
			'verify' => !$this->ignoreInsecureSSL,
			'multipart' => [
				[
					'name' => 'token',
					'contents' => $this->tokens[ 'csrf' ]
				],
				[
					'name' => 'filename',
					'contents' => $filename
				],
				[
					'name' => 'text',
					'contents' => $content
				],
				[
					'name' => 'ignorewarnings',
					'contents' => 1
				],
				[
					'name' => 'filesize',
					'contents' => $file->getSize()
				],
				[
					'name' => 'format',
					'contents' => 'json'
				],
				[
					'name' => 'action',
					'contents' => 'upload'
				],
				[
					'name' => 'file',
					'contents' => file_get_contents( $file->getLocalRefPath() ),
					'filename' => 'file'
				]
			]
		];

		$parsedUrl = $this->urlUtils->parse( $this->target->getUrl() );
		$cookieHeader = $this->cookieJar->serializeToHttpRequest( $parsedUrl['path'] ?: '/', $parsedUrl['host'] );
		try {
			$response = $this->guzzleDirectRequest( $this->target->getUrl(), $postData, [ 'Cookie' => $cookieHeader ] );
			$this->logger->debug( 'File upload done. Response - ' . print_r( $response, true ) );
			$response = FormatJson::decode( $response );
		} catch ( Throwable $ex ) {
			$this->status = Status::newFatal( 'contenttransfer-upload-fail' );
			$this->logger->error( 'File upload failed. Exception message - "' . $ex->getMessage() . '"' );
			return false;
		}

		if ( $response && property_exists( $response, 'error' ) ) {
			if ( $response->error->code === 'fileexists-no-change' ) {
				// Do not consider pushing duplicate files as an error
				return true;
			}
			$this->status = Status::newFatal( 'contenttransfer-upload-fail-message', $response->error->info );
			return false;
		}

		return true;
	}

	/**
	 * Get target config
	 *
	 * @return Target
	 */
	public function getTarget() {
		return $this->target;
	}

	/**
	 * @return Status
	 */
	public function getStatus() {
		return $this->status;
	}

	/**
	 *
	 * @param GuzzleHttpRequest $request
	 * @param Status $status
	 * @return StatusValue
	 */
	protected function statusFromRequest( $request, $status ) {
		if ( !$status->isOK() ) {
			return $status;
		}
		return StatusValue::newGood( FormatJson::decode( $request->getContent(), 1 ) );
	}

	/**
	 *
	 * @return bool
	 */
	protected function doLogin() {
		$requestData = [
			'action' => 'login',
			'lgname' => $this->target->getSelectedUser()['user'],
			'lgpassword' => $this->target->getSelectedUser()['password'],
			'lgtoken' => $this->tokens['login'],
			'format' => 'json'
		];

		$request = $this->getRequest( $requestData );
		$request->setCookieJar( $this->cookieJar );

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

	/**
	 *
	 * @param array $requestData
	 * @param string $method
	 * @param string|int $timeout
	 * @return \MWHttpRequest
	 */
	protected function getRequest( $requestData, $method = 'POST', $timeout = 'default' ) {
		$params = [
			'postData' => $requestData,
			'method' => $method,
			'timeout' => $timeout
		];
		if ( $this->ignoreInsecureSSL ) {
			$this->deSecuritize( $params );
		}

		return $this->httpRequestFactory->create(
			$this->target->getUrl(),
			$params
		);
	}

	/**
	 *
	 * @return bool
	 */
	protected function getLoginToken() {
		$requestData = [
			'action' => 'query',
			'meta' => 'tokens',
			'type' => 'login',
			'format' => 'json'
		];

		$request = $this->getRequest( $requestData );
		if ( $this->cookieJar ) {
			$request->setCookieJar( $this->cookieJar );
		}

		$status = $request->execute();

		if ( !$status->isOK() ) {
			$this->status = $status;
			$this->logger->error( 'Getting login token failed. $status message - "' . $status->getMessage() . '"' );
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

		$this->tokens[ 'login' ] = $response->query->tokens->logintoken;

		return true;
	}

	/**
	 *
	 * @param array &$params
	 */
	private function deSecuritize( array &$params ) {
		$params[ 'sslVerifyCert'] = false;
		$params[ 'sslVerifyHost'] = false;
		$params[ 'sslVerifyPeer'] = false;
	}

	/**
	 * @param string $url
	 * @param array $options
	 * @param array|null $headers
	 *
	 * @return string
	 * @throws GuzzleException
	 */
	private function guzzleDirectRequest( string $url, array $options, ?array $headers = [] ): string {
		$config = [
			'headers' => $headers,
			'timeout' => 120
		];
		$config['headers']['User-Agent'] = $this->httpRequestFactory->getUserAgent();
		// Create client manually, since calling `createGuzzleClient` on httpFactory will throw a fatal
		// complaining `$this->options` is NULL. Which should not happen, but I cannot find why it happens
		$client = new Client( $config );
		$response = $client->request( 'POST', $url, $options );
		if ( $response->getStatusCode() !== 200 ) {
			throw new Exception( 'HTTP error: ' . $response->getStatusCode() );
		}
		return $response->getBody()->getContents();
	}
}

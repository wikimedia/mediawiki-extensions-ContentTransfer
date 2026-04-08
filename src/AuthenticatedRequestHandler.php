<?php

namespace ContentTransfer;

use Exception;
use File;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttpRequest;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Json\FormatJson;
use MediaWiki\Status\Status;
use MediaWiki\Utils\UrlUtils;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class AuthenticatedRequestHandler {

	/**
	 * @var array|null
	 */
	protected $pageProps = null;

	/**
	 * @var array
	 */
	protected $tokens;

	/**
	 * @var Status
	 */
	protected $status;

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
		if ( $this->target->getAuthentication() instanceof LoggerAwareInterface ) {
			$this->target->getAuthentication()->setLogger( $this->logger );
		}
	}

	/**
	 * @param string $title Title to check
	 * @return array|null
	 */
	public function getPageProps( $title ) {
		if ( !$this->pageProps ) {
			if ( !$this->getTarget()->getAuthentication()->authenticate( $this ) ) {
				$this->logger->error( 'Authentication failed when fetching page props for "{title}"',
					[ 'title' => $title ]
				);
				$this->status = $this->getTarget()->getAuthentication()->getStatus()
					?? Status::newFatal( 'contenttransfer-authentication-failed' );
				return null;
			}
			$requestData = [
				'action' => 'query',
				'prop' => 'pageprops',
				'format' => 'json',
				'titles' => $title
			];

			$request = $this->getRequest( $requestData );
			$status = $request->execute();

			if ( !$status->isOK() ) {
				$this->logger->error( 'Failed to fetch page props for "{title}" from "{url}".' .
					'Status message: {status}.',
					[ 'title' => $title, 'url' => $this->target->getUrl(), 'status' => $status->getMessage()->text() ]
				);
				$this->status = Status::newFatal( 'contenttransfer-no-pageprops' );
				return null;
			}

			$response = FormatJson::decode( $request->getContent(), true );

			$pages = $response[ 'query' ][ 'pages' ] ?? null;
			if ( !$pages || ( count( $pages ) === 0 ) ) {
				$error = $response[ 'error' ][ 'info' ] ?? '';
				$this->logger->error( 'Page props response contained no pages for "{title}". Error: "{error}".' .
					'Response: {response}.',
					[ 'title' => $title, 'error' => $error, 'response' => $request->getContent() ]
				);

				if ( $error !== '' ) {
					$this->status = Status::newFatal( 'contenttransfer-cannot-create-message', $error );
				} else {
					$this->status = Status::newFatal( 'contenttransfer-cannot-create' );
				}
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
			if ( !$this->getTarget()->getAuthentication()->authenticate( $this ) ) {
				$this->logger->error( 'Authentication failed when fetching CSRF token from "{url}"',
					[ 'url' => $this->target->getUrl() ]
				);
				$this->status = $this->getTarget()->getAuthentication()->getStatus()
					?? Status::newFatal( 'contenttransfer-authentication-failed' );
				return null;
			}
			$requestData = [
				'action' => 'query',
				'meta' => 'tokens',
				'type' => 'csrf',
				'format' => 'json'
			];

			$request = $this->getRequest( $requestData );

			$status = $request->execute();

			if ( !$status->isOK() ) {
				$this->logger->error( 'Failed to fetch CSRF token from "{url}"',
					[ 'url' => $this->target->getUrl() ]
				);
				$this->status = Status::newFatal( 'contenttransfer-no-csrf-token' );
				return null;
			}

			$response = FormatJson::decode( $request->getContent() );

			if ( !property_exists( $response->query, 'tokens' ) ||
				!property_exists( $response->query->tokens, 'csrftoken' ) ) {
				$this->logger->error( 'CSRF token missing from API response from "{url}". Response: "{response}"',
					[ 'url' => $this->target->getUrl(), 'response' => $request->getContent() ]
				);
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
	 * @return Status
	 */
	public function runPushRequest( $requestData ) {
		if ( !$this->getTarget()->getAuthentication()->isAuthenticated() || !isset( $this->tokens['csrf'] ) ) {
			$this->logger->error(
				'Push request attempted before authentication or CSRF token was obtained from "{url}"',
				[ 'url' => $this->target->getUrl() ]
			);
			return Status::newFatal( 'contenttransfer-preflight-failed' );
		}
		$request = $this->getRequest( $requestData );

		return $this->statusFromRequest( $request, $request->execute() );
	}

	/**
	 *
	 * @param array $requestData
	 * @return Status
	 */
	public function runAuthenticatedRequest( array $requestData ) {
		if ( !$this->getTarget()->getAuthentication()->authenticate( $this ) ) {
			$this->status = $this->getTarget()->getAuthentication()->getStatus()
				?? Status::newFatal( 'contenttransfer-authentication-failed' );
			return $this->status;
		}
		if ( !$this->getCSRFToken() ) {
			return Status::newFatal( 'contenttransfer-no-csrf-token' );
		}

		$request = $this->getRequest( $requestData );
		$this->target->getAuthentication()->decorateWithAuthentication( $request );

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
		try {
			$response = $this->guzzleDirectRequest(
				$this->target->getUrl(), $postData,
				$this->target->getAuthentication()->getAuthenticationHeader( $parsedUrl )
			);
			$this->logger->info(
				'File "{filename}" uploaded to "{url}"',
				[ 'filename' => $filename, 'url' => $this->target->getUrl() ]
			);
			$this->logger->debug( 'File upload response', [ 'response' => $response ] );
			$response = FormatJson::decode( $response );
		} catch ( Throwable $ex ) {
			$errorDetail = $ex->getMessage();
			if ( $errorDetail ) {
				$this->status = Status::newFatal( 'contenttransfer-upload-fail-message', $errorDetail );
			} else {
				$this->status = Status::newFatal( 'contenttransfer-upload-fail' );
			}
			$this->logger->error(
				'File upload failed ({exceptionClass}): {message}',
				[ 'exceptionClass' => get_class( $ex ), 'message' => $ex->getMessage() ]
			);
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
	 * @return Status
	 */
	protected function statusFromRequest( $request, $status ) {
		if ( !$status->isOK() ) {
			return $status;
		}
		return Status::newGood( FormatJson::decode( $request->getContent(), 1 ) );
	}

	/**
	 *
	 * @param array $requestData
	 * @param string $method
	 * @param string|int $timeout
	 * @return \MWHttpRequest
	 */
	public function getRequest( $requestData, $method = 'POST', $timeout = 'default' ) {
		$params = [
			'postData' => $requestData,
			'method' => $method,
			'timeout' => $timeout
		];
		if ( $this->ignoreInsecureSSL ) {
			$this->deSecuritize( $params );
		}

		$request = $this->httpRequestFactory->create(
			$this->target->getUrl(),
			$params
		);
		$this->target->getAuthentication()->decorateWithAuthentication( $request );
		return $request;
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

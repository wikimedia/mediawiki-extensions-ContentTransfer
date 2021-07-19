<?php

namespace ContentTransfer;

use CookieJar;
use CURLFile;
use File;
use FormatJson;
use Status;
use StatusValue;

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

	/** @var bool */
	protected $ignoreInsecureSSL = false;

	/**
	 * @var Status
	 */
	protected $status;

	/** @var array */
	protected $target;

	/** @var bool */
	protected $authenticated = false;

	/**
	 *
	 * @param array $target
	 * @param bool $ignoreInsecureSSL
	 */
	public function __construct( $target, $ignoreInsecureSSL ) {
		$this->target = $target;
		$this->ignoreInsecureSSL = $ignoreInsecureSSL;
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
			return StatusValue::newFatal( 'contenttransfer-authentication-failed' );
		}
		if ( !$this->getCSRFToken() ) {
			return StatusValue::newFatal( 'contenttransfer-no-csrf-token' );
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
		$curlFile = new CURLFile(
			$file->getLocalRefPath(),
			$file->getMimeType(),
			'file'
		);

		$postData = [
			'action' => 'upload',
			'token' => $this->tokens[ 'csrf' ],
			'filename' => $filename,
			'text' => $content,
			'ignorewarnings' => 1,
			'file' => $curlFile,
			'filesize' => $file->getSize(),
			'format' => 'json'
		];

		$requestData = [
			'postData' => $postData,
			'method' => 'POST',
			'timeout' => 'default'
		];
		if ( $this->ignoreInsecureSSL ) {
			$this->deSecuritize( $requestData );
		}

		if ( !function_exists( 'curl_init' ) ) {
			$this->status = Status::newFatal( 'contenttransfer-no-curl' );
			return false;
		} elseif (
			!defined( 'CurlHttpRequest::SUPPORTS_FILE_POSTS' )
			|| !\CurlHttpRequest::SUPPORTS_FILE_POSTS
		) {
			$this->status = Status::newFatal( 'contenttransfer-curl-file-posts-not-supported' );
			return false;
		}

		$httpEngine = \Http::$httpEngine;
		\Http::$httpEngine = 'curl';
		$request = \MWHttpRequest::factory( $this->target['url'], $requestData, __METHOD__ );
		\Http::$httpEngine = $httpEngine;

		$request->setHeader(
			'Content-Disposition', "form-data; name=\"file\"; filename=\"{$file->getName()}\""
		);
		$request->setHeader( 'Content-Type', 'multipart/form-data' );
		$request->setCookieJar( $this->cookieJar );

		$status = $request->execute();
		if ( !$status->isOK() ) {
			$this->status = Status::newFatal( 'contenttransfer-upload-fail' );
			return false;
		}

		$response = FormatJson::decode( $request->getContent() );

		if ( property_exists( $response, 'error' ) ) {
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
	 * @return array
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
	 * @param MWHttpRequest $request
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
			'lgname' => $this->target['user'],
			'lgpassword' => $this->target['password'],
			'lgtoken' => $this->tokens['login'],
			'format' => 'json'
		];

		$request = $this->getRequest( $requestData );
		$request->setCookieJar( $this->cookieJar );

		$status = $request->execute();

		if ( !$status->isOK() ) {
			$this->status = Status::newFatal( 'contenttransfer-authentication-failed' );
			return false;
		}

		$response = FormatJson::decode( $request->getContent() );

		if ( $response->login->result === 'Success' ) {
			$this->cookieJar = $request->getCookieJar();
		} else {
			$this->status = Status::newFatal( 'contenttransfer-authentication-failed' );
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
		return \MWHttpRequest::factory(
			$this->target['url'],
			$params,
			__METHOD__
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
			$this->status = Status::newFatal( 'contenttransfer-no-login-token' );
			return false;
		}

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
}

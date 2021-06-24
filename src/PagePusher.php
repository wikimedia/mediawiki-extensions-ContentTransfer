<?php

namespace ContentTransfer;

use Hooks;
use Status;
use Title;

class PagePusher {
	const USER_ACTION_ACKNOWLEDGE = 'ack';
	const USER_ACTION_FORCE = 'force';

	/**
	 * Title object being pushed
	 *
	 * @var Title
	 */
	protected $title;

	/** @var AuthenticatedRequestHandler */
	protected $requestHandler;

	/**
	 * @var PageContentProvider
	 */
	protected $contentProvider;

	/**
	 * Ignore warnings
	 *
	 * @var boolean
	 */
	protected $force;

	/**
	 * @var string
	 */
	protected $userAction = false;

	/**
	 * @var PushHistory
	 */
	protected $pushHistory;

	/**
	 * @var Status
	 */
	protected $status;

	/**
	 * @var boolean
	 */
	protected $pushToDraft = false;

	/**
	 * @var string
	 */
	protected $targetPushNamespaceName = '';

	/**
	 *
	 * @param Title $title
	 * @param string $target
	 * @param PushHistory $pushHistory
	 * @param bool|false $force
	 * @param bool|false $ignoreInsecureSSL
	 */
	public function __construct( Title $title, $target, $pushHistory, $force = false,
		$ignoreInsecureSSL = false ) {
		$this->title = $title;
		$this->requestHandler = new AuthenticatedRequestHandler( $target, $ignoreInsecureSSL );
		$this->contentProvider = new PageContentProvider( $title );
		$this->force = $force;

		if ( isset( $target[ 'pushToDraft' ] ) && $target[ 'pushToDraft' ] === true ) {
			if ( isset( $target[ 'draftNamespace' ] ) ) {
				$this->pushToDraft = true;
				$this->targetPushNamespaceName = $target[ 'draftNamespace' ];
			}
		}

		$this->pushHistory = $pushHistory;
		$this->status = Status::newGood();
	}

	/**
	 * Do the push process
	 */
	public function push() {
		if ( $this->requestHandler->getPageProps( $this->getTargetTitleText() ) === null ) {
			return;
		}
		if ( $this->ensurePushPossible() === false ) {
			return;
		}
		if ( $this->requestHandler->getCSRFToken() === null ) {
			return;
		}
		if ( $this->doPush() === false ) {
			return;
		}
		$this->runAdditionalRequests();
	}

	/**
	 * Get the status of the push
	 *
	 * @return Status
	 */
	public function getStatus() {
		return $this->requestHandler->getStatus();
	}

	/**
	 * Get user action, if any
	 *
	 * @return string|false
	 */
	public function getUserAction() {
		return $this->userAction;
	}

	protected function ensurePushPossible() {
		if ( $this->title->getNamespace() != NS_MAIN &&
				$this->requestHandler->getPageProps( $this->getTargetTitleText() )[ 'ns' ] == NS_MAIN ) {
			// Namespace does not exist on received and
			// cannot be retrieved from page title
			$namespaceText = $this->title->getNsText();
			$this->status = Status::newFatal(
				wfMessage( 'contenttransfer-namespace-not-found', $namespaceText )
			);
			$this->userAction = static::USER_ACTION_ACKNOWLEDGE;
			return false;
		}
		if ( $this->isPageProtected() && $this->force == false ) {
			$this->status = Status::newFatal(
				wfMessage( 'contenttransfer-page-protected' )
			);
			$this->userAction = static::USER_ACTION_FORCE;
			return false;
		}
		return true;
	}

	protected function doPush() {
		$content = $this->contentProvider->getContent();
		if ( $this->contentProvider->isFile() ) {
			$file = $this->contentProvider->getFile();
			$filename = $file->getName();
			if ( $this->pushToDraft ) {
				$filename = $this->requestHandler->getTarget()[ 'draftNamespace' ] . '_' . $filename;
			}
			if ( $this->requestHandler->uploadFile( $file, $content, $filename ) === false ) {
				return false;
			};
		}

		$requestData = [
			'action' => 'edit',
			'token' => $this->requestHandler->getCSRFToken(),
			'summary' => 'Pushed content',
			'text' => $content,
			'title' => $this->getTargetTitleText(),
			'format' => 'json'
		];
		$status = $this->requestHandler->runPushRequest( $requestData );

		if ( !$status->isOK() ) {
			$this->status = Status::newFatal( 'contenttransfer-edit-fail' );
			return false;
		}

		$response = (object)$status->getValue();
		if ( property_exists( $response, 'error' ) ) {
			$this->status = Status::newFatal( 'contenttransfer-edit-fail-message', $response->error->info );
			return false;
		}

		// Maybe will be useful in the future
		$editInfo = $response->edit;
		$pageId = $editInfo['pageid'];

		$this->pushHistory->insert();

		return true;
	}

	protected function runAdditionalRequests() {
		$requests = [];
		Hooks::run( 'ContentTransferAdditionalRequests', [
			$this->title,
			$this->requestHandler->getTarget(),
			&$requests
		] );
		if ( !is_array( $requests ) || empty( $requests ) ) {
			return;
		}
		foreach ( $requests as $requestData ) {
			$requestData['token'] = $this->requestHandler->getCSRFToken();
			$requestData['format'] = 'json';

			$this->requestHandler->runAuthenticatedRequest( $requestData );
		}
	}

	/**
	 * Gets if this page protected on receiving wiki
	 * If data cannot be retrieved it will return true
	 *
	 * @return bool
	 */
	protected function isPageProtected() {
		$requestData = [
			'action' => 'query',
			'prop' => 'info',
			'inprop' => 'protection',
			'titles' => $this->getTargetTitleText(),
			'format' => 'json'
		];

		$status = $this->requestHandler->runAuthenticatedRequest( $requestData );
		if ( !$status->isOK() ) {
			return true;
		}
		$response = $status->getValue();

		if ( count( $response[ 'query' ][ 'pages' ] ) === 0 ) {
			return true;
		}

		$key = array_keys( $response[ 'query' ][ 'pages' ] )[0];
		$protectionData = $response[ 'query' ][ 'pages' ][ $key ];
		$this->requestHandler->setPageProps( array_merge(
			$protectionData,
			$this->requestHandler->getPageProps( $this->getTargetTitleText() )
		) );

		foreach ( $protectionData[ 'protection' ] as $protectionInfo ) {
			if ( $protectionInfo[ 'type' ] === 'edit' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 *
	 * @return string
	 */
	protected function getTargetTitleText() {
		if ( $this->pushToDraft ) {
			if ( $this->contentProvider->isFile() ) {
				return $this->title->getNsText() . ':' .
					$this->requestHandler->getTarget()[ 'draftNamespace' ]
						. '_' . $this->title->getDBkey();
			} else {
				return $this->requestHandler->getTarget()[ 'draftNamespace' ]
					. ':' . $this->title->getPrefixedDBkey();
			}
		} else {
			return $this->title->getPrefixedDBkey();
		}
	}
}

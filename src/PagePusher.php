<?php

namespace ContentTransfer;

use ContentTransfer\Utility\ApiErrorHelper;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Language\Language;
use MediaWiki\Status\Status;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PagePusher {
	protected const USER_ACTION_ACKNOWLEDGE = 'ack';
	protected const USER_ACTION_FORCE = 'force';

	/** @var AuthenticatedRequestHandler */
	protected AuthenticatedRequestHandler $requestHandler;

	/**
	 * @var PageContentProvider
	 */
	protected PageContentProvider $contentProvider;

	/**
	 * @var string|bool
	 */
	protected string|bool $userAction = false;

	/**
	 * @var Status
	 */
	protected Status $status;

	/**
	 * @var bool
	 */
	protected bool $pushToDraft = false;

	/**
	 * @var string
	 */
	protected string $targetPushNamespaceName = '';

	/**
	 *
	 * @param Title $title
	 * @param Target $target
	 * @param PushHistory $pushHistory
	 * @param bool|false $force
	 * @param bool|false $ignoreInsecureSSL
	 * @param AuthenticatedRequestHandlerFactory $requestHandlerFactory
	 * @param PageContentProviderFactory $contentProviderFactory
	 * @param NamespaceInfo $namespaceInfo
	 * @param Language $language
	 * @param HookContainer $hookContainer
	 * @param LoggerInterface $logger
	 */
	public function __construct(
		private readonly Title $title,
		Target $target,
		private readonly PushHistory $pushHistory,
		private readonly bool $force,
		bool $ignoreInsecureSSL,
		AuthenticatedRequestHandlerFactory $requestHandlerFactory,
		PageContentProviderFactory $contentProviderFactory,
		private readonly NamespaceInfo $namespaceInfo,
		private readonly Language $language,
		private readonly HookContainer $hookContainer,
		private readonly LoggerInterface $logger
	) {
		$this->requestHandler = $requestHandlerFactory->newFromTarget( $target, $ignoreInsecureSSL );
		$this->contentProvider = $contentProviderFactory->newFromTitle( $title );

		if ( $target->shouldPushToDraft() ) {
			if ( $target->getDraftNamespace() ) {
				$this->pushToDraft = true;
				$this->targetPushNamespaceName = $target->getDraftNamespace();
			}
		}
		$this->status = Status::newGood();
	}

	/**
	 * Do the push process
	 */
	public function push() {
		$targetTitle = $this->getTargetTitleText();
		$this->logger->info(
			'Starting push of "{title}" to "{target}"',
			[ 'title' => $this->title->getPrefixedText(), 'target' => $targetTitle ]
		);
		if ( $this->requestHandler->getPageProps( $targetTitle ) === null ) {
			$this->status = $this->requestHandler->getStatus();
			throw new RuntimeException(
				'Could not get page props for "' . $targetTitle . '" on target "' .
				$this->requestHandler->getTarget()->getUrl() . '"'
			);
		}
		if ( $this->ensurePushPossible() === false ) {
			throw new RuntimeException( 'Push not possible for "' . $targetTitle . '"' );
		}
		if ( $this->requestHandler->getCSRFToken() === null ) {
			$this->status = $this->requestHandler->getStatus();
			throw new RuntimeException(
				'Could not get CSRF token from "' . $this->requestHandler->getTarget()->getUrl() . '"'
			);
		}
		$pageId = $this->doPush();
		if ( $pageId === false ) {
			throw new RuntimeException( 'Edit request failed for "' . $targetTitle . '"' );
		}
		$this->logger->info(
			'Successfully pushed "{title}" to "{target}"',
			[ 'title' => $this->title->getPrefixedText(), 'target' => $targetTitle ]
		);
		$this->runAdditionalRequests( $pageId );
	}

	/**
	 * Get the status of the push
	 *
	 * @return Status
	 */
	public function getStatus() {
		return $this->status;
	}

	/**
	 * Override the current status (used by callers to surface caught exceptions)
	 *
	 * @param Status $status
	 */
	public function setStatus( Status $status ) {
		$this->status = $status;
	}

	/**
	 * Get user action, if any
	 *
	 * @return string|false
	 */
	public function getUserAction() {
		return $this->userAction;
	}

	/**
	 * @return bool
	 */
	protected function ensurePushPossible() {
		if ( $this->title->getNamespace() != NS_MAIN &&
				$this->requestHandler->getPageProps( $this->getTargetTitleText() )[ 'ns' ] == NS_MAIN ) {
			// Namespace does not exist on received and
			//cannot be retrieved from page title
			$namespaceText = $this->title->getNsText();
			$this->logger->warning(
				'Push blocked: namespace "{ns}" not found on target for page "{title}"',
				[ 'ns' => $namespaceText, 'title' => $this->title->getPrefixedText() ]
			);
			$this->status = Status::newFatal(
				wfMessage( 'contenttransfer-namespace-not-found', $namespaceText )
			);
			$this->userAction = static::USER_ACTION_ACKNOWLEDGE;
			return false;
		}
		if ( $this->isPageProtected() && $this->force == false ) {
			$this->logger->warning(
				'Push blocked: page "{title}" is protected on target',
				[ 'title' => $this->getTargetTitleText() ]
			);
			$this->status = Status::newFatal(
				wfMessage( 'contenttransfer-page-protected' )
			);
			$this->userAction = static::USER_ACTION_FORCE;
			return false;
		}
		return true;
	}

	/**
	 * @return int|false
	 */
	protected function doPush() {
		$content = $this->contentProvider->getContent();

		$wikitextProcessor = new WikitextProcessor( $this->namespaceInfo, $this->language );

		$content = $wikitextProcessor->canonizeNamespacesInLinks( $content );

		if ( $this->contentProvider->isFile() ) {
			$file = $this->contentProvider->getFile();
			$filename = $file->getName();
			if ( $this->pushToDraft ) {
				$filename = $this->requestHandler->getTarget()->getDraftNamespace() .
					'_' . $filename;
			}
			if ( $this->requestHandler->uploadFile( $file, $content, $filename ) === false ) {
				$this->status = $this->requestHandler->getStatus();
				return false;
			}
		}

		$requestData = [
			'action' => 'edit',
			'token' => $this->requestHandler->getCSRFToken(),
			'summary' => 'Pushed content',
			'text' => $content,
			'title' => $this->getTargetTitleText(),
			'format' => 'json',
			'errorformat' => 'raw'
		];
		$status = $this->requestHandler->runPushRequest( $requestData );

		if ( !$status->isOK() ) {
			$errorDetail = $status->getMessage()->text();
			$this->logger->error(
				'Edit request failed for "{title}": {error}',
				[ 'title' => $this->getTargetTitleText(), 'error' => $errorDetail ]
			);
			if ( $errorDetail ) {
				$this->status = Status::newFatal( 'contenttransfer-edit-fail-message', $errorDetail );
			} else {
				$this->status = Status::newFatal( 'contenttransfer-edit-fail' );
			}
			return false;
		}

		$response = (object)$status->getValue();
		if ( property_exists( $response, 'errors' ) && $response->errors ) {
			$error = ApiErrorHelper::extractLocalizedError( $response->errors );
			$this->logger->error(
				'Edit request for "{title}" returned API error: {error}',
				[ 'title' => $this->getTargetTitleText(), 'error' => $error,
					'response' => json_encode( $response ) ]
			);
			$this->status = Status::newFatal(
				'contenttransfer-edit-fail-message',
				$error
			);
			return false;
		}

		// Maybe will be useful in the future
		$editInfo = $response->edit;
		$pageId = $editInfo['pageid'];

		$this->pushHistory->insert();

		return $pageId;
	}

	/**
	 * @param int $pageId
	 */
	protected function runAdditionalRequests( $pageId ) {
		$requests = [];
		$this->hookContainer->run(
			'ContentTransferAdditionalRequests',
			[
				$this->title,
				$this->requestHandler->getTarget(),
				&$requests,
				$pageId
			]
		);

		if ( !is_array( $requests ) || empty( $requests ) ) {
			return;
		}
		foreach ( $requests as $requestData ) {
			$requestData['token'] = $this->requestHandler->getCSRFToken();
			$requestData['format'] = 'json';

			$this->logger->debug(
				'Running additional request for page "{title}": action={action}',
				[ 'title' => $this->title->getPrefixedText(), 'action' => $requestData['action'] ?? 'unknown' ]
			);
			$additionalReqStatus = $this->requestHandler->runAuthenticatedRequest( $requestData );
			if ( !$additionalReqStatus->isOK() ) {
				$this->logger->warning(
					'Additional request failed for page "{title}": action={action}',
					[ 'title' => $this->title->getPrefixedText(), 'action' => $requestData['action'] ?? 'unknown' ]
				);
			}
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
		$nsText = $this->title->getNsText();

		$nsId = $this->title->getNamespace();
		if ( $nsId !== NS_MAIN ) {
			$nsCanonicalText = $this->namespaceInfo->getCanonicalName( $nsId );
			if ( $nsCanonicalText ) {
				// If that's local variant of namespace like "Datei" - then translate it to canonical
				$nsText = $nsCanonicalText;
			}
		}

		if ( $this->pushToDraft ) {
			if ( $this->contentProvider->isFile() ) {
				$canonicalFileNs = $this->namespaceInfo->getCanonicalName( NS_FILE );
				return $canonicalFileNs . ':' .
					$this->requestHandler->getTarget()->getDraftNamespace()
						. '_' . $this->title->getDBkey();
			} else {
				return $this->requestHandler->getTarget()->getDraftNamespace() .
					':' . ( $nsText ? $nsText . ':' : '' ) . $this->title->getDBkey();
			}
		} else {
			return ( $nsText ? $nsText . ':' : '' ) . $this->title->getDBkey();
		}
	}
}

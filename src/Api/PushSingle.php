<?php

namespace ContentTransfer\Api;

use ApiBase;
use ApiMain;
use Content;
use ContentTransfer\AuthenticatedRequestHandlerFactory;
use ContentTransfer\PageContentProviderFactory;
use ContentTransfer\PagePusher;
use ContentTransfer\PushHistory;
use ContentTransfer\Target;
use ContentTransfer\TargetManager;
use FormatJson;
use MediaWiki\MediaWikiServices;
use Message;
use Title;

class PushSingle extends ApiBase {
	/** @var Target */
	protected $target;

	/** @var Title */
	protected $title;
	/** @var Content */
	protected $content;
	/** @var PagePusher */
	protected $pusher;
	/** @var bool */
	protected $force = false;
	/** @var TargetManager */
	private $targetManager;

	/**
	 * @var PageContentProviderFactory
	 */
	protected $contentProviderFactory;

	/**
	 * @var AuthenticatedRequestHandlerFactory
	 */
	protected $requestHandlerFactory;

	/** @var string */
	private $targetId;

	/**
	 * @param ApiMain $mainModule
	 * @param string $moduleName
	 * @param PageContentProviderFactory $contentProviderFactory
	 * @param AuthenticatedRequestHandlerFactory $requestHandlerFactory
	 */
	public function __construct(
		ApiMain $mainModule, $moduleName,
		PageContentProviderFactory $contentProviderFactory,
		AuthenticatedRequestHandlerFactory $requestHandlerFactory
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->targetManager = MediaWikiServices::getInstance()->getService(
			'ContentTransferTargetManager'
		);
		$this->contentProviderFactory = $contentProviderFactory;
		$this->requestHandlerFactory = $requestHandlerFactory;
	}

	public function execute() {
		$this->isAuthorized();
		$this->readInParameters();
		$this->doPush();
		$this->returnData();
	}

	protected function isAuthorized() {
		if ( !$this->getUser()->isAllowed( 'content-transfer' ) ) {
			$this->dieWithError( 'You don\'t have permission to push pages', 'permissiondenied' );
		}
		if ( !$this->getUser()->matchEditToken( $this->getParameter( 'token' ) ) ) {
			$this->dieWithError( 'Edit token invalid', 'invalidtoken' );
		}
	}

	/**
	 *
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'articleId' => [
				static::PARAM_TYPE => 'integer',
				static::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-articleid',
			],
			'pushTarget' => [
				static::PARAM_TYPE => 'string',
				static::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-pushtarget',
			],
			'force' => [
				static::PARAM_TYPE => 'boolean',
				static::PARAM_REQUIRED => false,
				static::PARAM_DFLT => false,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-force',
			],
			'token' => [
				static::PARAM_TYPE => 'string',
				static::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-token',
			]
		];
	}

	/**
	 * Using the settings determine the value for the given parameter
	 *
	 * @param string $paramName Parameter name
	 * @param array|mixed $paramSettings Default value or an array of settings
	 *  using PARAM_* constants.
	 * @param bool $parseLimit Whether to parse and validate 'limit' parameters
	 * @return mixed Parameter value
	 */
	protected function getParameterFromSettings( $paramName, $paramSettings, $parseLimit ) {
		$value = parent::getParameterFromSettings( $paramName, $paramSettings, $parseLimit );
		if ( $paramName === 'pushTarget' ) {
			return FormatJson::decode( $value, true );

		}
		return $value;
	}

	protected function readInParameters() {
		$articleId = $this->getParameter( 'articleId' );
		$pushTarget = $this->getParameter( 'pushTarget' );
		$this->setPushTarget( $pushTarget );
		$this->title = Title::newFromID( $articleId );
		$this->force = $this->getParameter( 'force' ) ? true : false;
	}

	protected function prepareContent() {
		$contentProvider = $this->contentProviderFactory->newFromTitle( $this->title );
		$this->content = $contentProvider->getContent();
	}

	/**
	 *
	 * @param array $pushTarget
	 * @return void
	 */
	protected function setPushTarget( $pushTarget ) {
		$target = $this->targetManager->getTarget( $pushTarget['id'] );
		if ( !$target ) {
			$this->dieWithError(
				Message::newFromKey( 'contenttransfer-invalid-target' )->plain(),
				'push-invalid-target'
			);
		}
		if ( isset( $pushTarget['selectedUser'] ) ) {
			$target->selectUser( $pushTarget['selectedUser' ] );
		}
		$this->target = $target;
		$this->targetId = $pushTarget['id'];
	}

	protected function doPush() {
		$ignoreInsecureSSL = $this->getConfig()->get( 'ContentTransferIgnoreInsecureSSL' );

		$pushHistory = new PushHistory( $this->title, $this->getUser(), $this->targetId );
		$this->pusher = new PagePusher(
			$this->title,
			$this->target,
			$pushHistory,
			$this->requestHandlerFactory,
			$this->contentProviderFactory,
			$this->force,
			$ignoreInsecureSSL
		);
		$this->pusher->push();
	}

	protected function returnData() {
		$result = $this->getResult();

		$status = $this->pusher->getStatus();

		if ( $status->isGood() ) {
			$result->addValue( null, 'success', 1 );
		} else {
			$result->addValue( null, 'message', $status->getMessage() );
			$result->addValue( null, 'success', 0 );
			if ( $this->pusher->getUserAction() !== false ) {
				$result->addValue( null, 'userAction', $this->pusher->getUserAction() );
			}
			return;
		}
	}

}

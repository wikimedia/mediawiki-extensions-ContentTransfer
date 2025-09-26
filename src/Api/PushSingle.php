<?php

namespace ContentTransfer\Api;

use ContentTransfer\AuthenticatedRequestHandlerFactory;
use ContentTransfer\PagePusher;
use ContentTransfer\PagePusherFactory;
use ContentTransfer\Target;
use ContentTransfer\TargetAuthenticator\BotPassword;
use ContentTransfer\TargetManager;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Content\Content;
use MediaWiki\Json\FormatJson;
use MediaWiki\Message\Message;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;

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

	/** @var string */
	private $targetId;

	/**
	 * @param ApiMain $mainModule
	 * @param string $moduleName
	 * @param AuthenticatedRequestHandlerFactory $requestHandlerFactory
	 * @param TargetManager $targetManager
	 * @param TitleFactory $titleFactory
	 * @param PagePusherFactory $pagePusherFactory
	 */
	public function __construct(
		ApiMain $mainModule, string $moduleName,
		protected readonly AuthenticatedRequestHandlerFactory $requestHandlerFactory,
		private readonly TargetManager $targetManager,
		private readonly TitleFactory $titleFactory,
		private readonly PagePusherFactory $pagePusherFactory
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	/**
	 * @return void
	 * @throws ApiUsageException
	 */
	public function execute() {
		$this->isAuthorized();
		$this->readInParameters();
		$this->doPush();
		$this->returnData();
	}

	/**
	 * @return void
	 * @throws ApiUsageException
	 */
	protected function isAuthorized() {
		if ( !$this->getUser()->isAllowed( 'content-transfer' ) ) {
			$this->dieWithError( 'You don\'t have permission to push pages', 'permissiondenied' );
		}
		if ( !$this->getCsrfTokenSet()->matchToken( $this->getParameter( 'token' ) ) ) {
			$this->dieWithError( 'Edit token invalid', 'invalidtoken' );
		}
	}

	/**
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'articleId' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-articleid',
			],
			'pushTarget' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-pushtarget',
			],
			'force' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => false,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-force',
			],
			'token' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-token',
			]
		];
	}

	/**
	 * Using the settings determine the value for the given parameter
	 *
	 * @param string $name Parameter name
	 * @param array|mixed $settings Default value or an array of settings
	 *  using PARAM_* constants.
	 * @param bool $parseLimit Whether to parse and validate 'limit' parameters
	 * @return mixed Parameter value
	 */
	protected function getParameterFromSettings( $name, $settings, $parseLimit ) {
		$value = parent::getParameterFromSettings( $name, $settings, $parseLimit );
		if ( $name === 'pushTarget' ) {
			return FormatJson::decode( $value, true );

		}
		return $value;
	}

	/**
	 * @throws ApiUsageException
	 */
	protected function readInParameters() {
		$articleId = $this->getParameter( 'articleId' );
		$pushTarget = $this->getParameter( 'pushTarget' );
		$this->setPushTarget( $pushTarget );
		$this->title = $this->titleFactory->newFromID( $articleId );
		$this->force = (bool)$this->getParameter( 'force' );
	}

	/**
	 * @param array $pushTarget
	 * @return void
	 * @throws ApiUsageException
	 */
	protected function setPushTarget( array $pushTarget ) {
		$target = $this->targetManager->getTarget( $pushTarget['id'] );
		if ( !$target ) {
			$this->dieWithError(
				Message::newFromKey( 'contenttransfer-invalid-target' )->plain(),
				'push-invalid-target'
			);
		}
		if ( isset( $pushTarget['selectedUser'] ) && $target instanceof BotPassword ) {
			$target->selectUser( $pushTarget['selectedUser' ] );
		}
		$this->target = $target;
		$this->targetId = $pushTarget['id'];
	}

	/**
	 * @return void
	 */
	protected function doPush() {
		$pushHistory = $this->pagePusherFactory->newPushHistory( $this->title, $this->getUser(), $this->targetId );
		$this->pusher = $this->pagePusherFactory->newPusher(
			$this->title,
			$this->target,
			$pushHistory,
			$this->force
		);
		$this->pusher->push();
	}

	/**
	 * @return void
	 */
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
		}
	}

}

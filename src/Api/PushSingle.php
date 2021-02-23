<?php

namespace ContentTransfer\Api;

use ApiBase;
use Content;
use ContentTransfer\PageContentProvider;
use ContentTransfer\PagePusher;
use ContentTransfer\PushHistory;
use FormatJson;
use Message;
use Title;

class PushSingle extends ApiBase {
	/** @var array */
	protected $target;

	/** @var Title */
	protected $title;
	/** @var Content */
	protected $content;
	/** @var PagePusher */
	protected $pusher;
	/** @var bool  */
	protected $force = false;

	public function execute() {
		$this->isAuthorized();
		$this->readInParameters();
		$this->doPush();
		$this->returnData();
	}

	protected function isAuthorized() {
		if( !$this->getUser()->isAllowed( 'content-transfer' ) ) {
			$this->dieWithError( 'You don\'t have permission to push pages', 'permissiondenied' );
		}
		if ( !$this->getUser()->matchEditToken( $this->getParameter( 'token' ) ) ) {
			$this->dieWithError( 'Edit token invalid', 'invalidtoken' );
		};
	}

	protected function getAllowedParams() {
		return [
			'articleId' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => true
			],
			'pushTarget' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			],
			'force' => [
				ApiBase::PARAM_TYPE => 'integer',
				ApiBase::PARAM_REQUIRED => false
			],
			'token' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			]
		];
	}

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
		$contentProvider = new PageContentProvider( $this->title );
		$this->content = $contentProvider->getContent();
	}

	protected function setPushTarget( $pushTarget ) {
		$pushTargets = $this->getConfig()->get( 'ContentTransferTargets' );

		foreach( $pushTargets as $id => $target ) {
			if( $target['url'] === $pushTarget['url'] ) {
				$this->target = $target;
				$this->target['id'] = $id;
				return;
			}
		}

		$this->dieWithError(
			Message::newFromKey( 'push-invalid-target' )->plain(),
			'push-invalid-target'
		);
	}

	protected function doPush() {
		$ignoreInsecureSSL = $this->getConfig()->get( 'ContentTransferIgnoreInsecureSSL' );
		$pushHistory = new PushHistory( $this->title, $this->getUser(), $this->target['id'] );
		$this->pusher = new PagePusher( $this->title, $this->target, $pushHistory, $this->force, $ignoreInsecureSSL );
		$this->pusher->push();
	}

	protected function returnData() {
		$result = $this->getResult();

		$status = $this->pusher->getStatus();

		if( $status->isGood() ) {
			$result->addValue( null , 'success', 1 );
		} else {
			$result->addValue( null, 'message', $status->getMessage() );
			$result->addValue( null , 'success', 0 );
			if( $this->pusher->getUserAction() !== false ) {
				$result->addValue( null, 'userAction', $this->pusher->getUserAction() );
			}
			return;
		}
	}

}

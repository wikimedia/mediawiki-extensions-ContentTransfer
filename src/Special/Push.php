<?php

namespace ContentTransfer\Special;

use ContentTransfer\TargetManager;
use Html;
use MediaWiki\MediaWikiServices;
use Message;
use MWNamespace;
use SpecialPage;

class Push extends SpecialPage {
	/** @var TargetManager */
	private $targetManager;

	public function __construct() {
		parent::__construct( "ContentTransfer", "content-transfer" );
		$this->targetManager = MediaWikiServices::getInstance()->getService(
			'ContentTransferTargetManager'
		);
	}

	/**
	 *
	 * @param string $subPage
	 */
	public function execute( $subPage ) {
		parent::execute( $subPage );

		$out = $this->getOutput();
		$out->addModules( 'ext.contenttransfer' );

		$out->addJsConfigVars( 'ctPushTargets', $this->targetManager->getTargetsForClient() );
		// Would be better over API, but yeah...
		$out->addJsConfigVars( 'ctFilterData', $this->getFilterData() );
		$out->addJsConfigVars(
			'ctEnableBeta',
			$this->getConfig()->get( 'ContentTransferEnableBetaFeatures' )
		);

		$out->enableOOUI();
		$out->addHTML( Html::element( 'div', [ 'id' => 'content-transfer-main' ] ) );
	}

	private function getFilterData() {
		return [
			'categories' => $this->getAvailableCategories(),
			'namespaces' => $this->getAvailableNamespaces()
		];
	}

	private function getAvailableCategories() {
		$db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$res = $db->select(
			'category',
			[ 'cat_id', 'cat_title' ]
		);

		$categories = [];
		foreach ( $res as $row ) {
			$categories[] = [
				'id' => $row->cat_id,
				'text' => $row->cat_title
			];
		}
		return $categories;
	}

	private function getAvailableNamespaces() {
		// Get only content namespaces. Other NSs can be transfered,
		// but cannot be base for transfering
		$lang = $this->getContext()->getLanguage();
		$onlyContent = $this->getConfig()->get( 'ContentTransferOnlyContentNamespaces' );
		$allowTalk = $this->getConfig()->get( 'ContentTransferAllowTalkNamespaces' );

		$namespaceIds = array_unique( MWNamespace::getValidNamespaces() );
		if ( $onlyContent ) {
			$namespaceIds = array_unique( MWNamespace::getContentNamespaces() );
		} elseif ( !$allowTalk ) {
			$notTalk = [];
			foreach ( $namespaceIds as $id ) {
				if ( MWNamespace::isTalk( $id ) ) {
					continue;
				}
				$notTalk[] = $id;
			}
			$namespaceIds = $notTalk;
		}

		$namespaces = [];
		foreach ( $namespaceIds as $namespaceId ) {
			$namespaceText = $lang->getNsText( $namespaceId );
			if ( $namespaceId === NS_MAIN ) {
				$namespaceText = Message::newFromKey( 'contenttransfer-ns-main' )->text();
			}
			$namespaces[] = [
				'id' => $namespaceId,
				'text' => $namespaceText
			];
		}

		return $namespaces;
	}
}

<?php

namespace ContentTransfer\Special;

use ContentTransfer\PageFilterFactory;
use ContentTransfer\TargetManager;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

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
		$out->addModules( [ 'ext.contenttransfer' ] );

		$out->addJsConfigVars( 'ctPushTargets', $this->targetManager->getTargetsForClient() );
		$out->addJsConfigVars( 'ctFilters', $this->loadFilters() );

		$out->addJsConfigVars(
			'ctEnableBeta',
			$this->getConfig()->get( 'ContentTransferEnableBetaFeatures' )
		);

		$out->enableOOUI();
		$out->addHTML( Html::element( 'div', [ 'id' => 'content-transfer-main' ] ) );
	}

	/**
	 *
	 * @return array
	 */
	private function loadFilters() {
		/** @var PageFilterFactory $filterFactory */
		$filterFactory = MediaWikiServices::getInstance()->getService(
			'ContentTransferPageFilterFactory'
		);

		$this->getOutput()->addModules( $filterFactory->getRLModules() );

		return $filterFactory->getFiltersForClient();
	}
}

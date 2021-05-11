<?php

namespace ContentTransfer\Special;

use ContentTransfer\Extension;
use ContentTransfer\PageFilterFactory;
use Html;
use MediaWiki\MediaWikiServices;
use SpecialPage;

class Push extends SpecialPage {
	public function __construct() {
		parent::__construct( "ContentTransfer", "content-transfer" );
	}

	/**
	 *
	 * @param string $subPage
	 */
	public function execute( $subPage ) {
		parent::execute( $subPage );

		$out = $this->getOutput();
		$out->addModules( 'ext.contenttransfer' );

		$pushTargetsForClient = Extension::getTargetsForClient( $this->getConfig() );

		$out->addJsConfigVars( 'ctPushTargets', $pushTargetsForClient );
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

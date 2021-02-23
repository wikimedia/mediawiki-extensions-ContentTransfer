<?php

namespace ContentTransfer\Api;

use ApiBase;
use ContentTransfer\PageProvider;
use FormatJson;
use MediaWiki\MediaWikiServices;

class GetPages extends ApiBase {
	/** @var array  */
	protected $filterData = [];
	/** @var array  */
	protected $pages = [];
	/** @var int  */
	protected $pageCount = 0;

	public function execute() {
		$this->readInParameters();
		$this->getPages();
		$this->returnPages();
	}

	protected function getAllowedParams() {
		return [
			'filterData' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true
			],
		];
	}

	protected function getParameterFromSettings( $paramName, $paramSettings, $parseLimit ) {
		$value = parent::getParameterFromSettings( $paramName, $paramSettings, $parseLimit );
		if ( $paramName === 'filterData' ) {
			return FormatJson::decode( $value, true );
		}
		return $value;
	}

	protected function readInParameters() {
		$this->filterData = $this->getParameter( 'filterData' );
	}

	protected function getPages() {
		$provider = PageProvider::factory(
			$this->getConfig(),
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);
		$provider->setFilterData( $this->filterData );
		$provider->execute();
		$this->pageCount = $provider->getPageCount();
		$pageTitles = $provider->getPages();
		foreach( $pageTitles as $title ) {
			$this->pages[] = [
				'id' => $title->getArticleId(),
				'prefixed_text' => $title->getPrefixedText()
			];
		}

		usort( $this->pages, function( $a, $b ) {
			return $a['prefixed_text'] < $b['prefixed_text'] ? -1 : 1;
		} );
	}

	protected function returnPages() {
		$result = $this->getResult();

		$result->addValue( null , 'pages', $this->pages );
		$result->addValue( null , 'page_count', count( $this->pages ) );
		$result->addValue( null , 'total', $this->pageCount );
	}

}

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

	/**
	 *
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'filterData' => [
				static::PARAM_TYPE => 'string',
				static::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-filterdata',
			],
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
		if ( $paramName === 'filterData' ) {
			return FormatJson::decode( $value, true );
		}
		return $value;
	}

	protected function readInParameters() {
		$this->filterData = $this->getParameter( 'filterData' );
	}

	// phpcs:ignore Generic.NamingConventions.ConstructorName.OldStyle, MediaWiki.Commenting.FunctionComment.MissingDocumentationProtected
	protected function getPages() {
		$provider = PageProvider::factory(
			$this->getConfig(),
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);
		$provider->setFilterData( $this->filterData );
		$provider->execute();
		$this->pageCount = $provider->getPageCount();
		$pageTitles = $provider->getPages();
		foreach ( $pageTitles as $title ) {
			$this->pages[] = [
				'id' => $title->getArticleId(),
				'prefixed_text' => $title->getPrefixedText()
			];
		}

		usort( $this->pages, function ( $a, $b ) {
			return $a['prefixed_text'] < $b['prefixed_text'] ? -1 : 1;
		} );
	}

	protected function returnPages() {
		$result = $this->getResult();

		$result->addValue( null, 'pages', $this->pages );
		$result->addValue( null, 'page_count', count( $this->pages ) );
		$result->addValue( null, 'total', $this->pageCount );
	}

}

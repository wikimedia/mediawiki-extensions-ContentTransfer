<?php

namespace ContentTransfer\Api;

use ContentTransfer\PageFilterFactory;
use ContentTransfer\PageProvider;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Json\FormatJson;
use Wikimedia\ParamValidator\ParamValidator;

class GetPages extends ApiBase {
	/** @var array */
	protected $filterData = [];
	/** @var array */
	protected $pages = [];
	/** @var int */
	protected $pageCount = 0;

	public function __construct(
		ApiMain $mainModule, string $moduleName,
		private readonly PageProvider $pageProvider,
		private readonly PageFilterFactory $pageFilterFactory,
		private readonly HookContainer $hookContainer
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	/**
	 * @return void
	 * @throws \MediaWiki\Api\ApiUsageException
	 */
	public function execute() {
		$this->readInParameters();
		$this->makePages();
		$this->returnPages();
	}

	/**
	 *
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'filterData' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				ParamValidator::PARAM_DEFAULT => '[]',
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-filterdata',
			],
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
		if ( $name === 'filterData' ) {
			return FormatJson::decode( $value, true );
		}
		return $value;
	}

	/**
	 * @return void
	 * @throws \MediaWiki\Api\ApiUsageException
	 */
	protected function readInParameters() {
		$this->filterData = $this->getParameter( 'filterData' );
	}

	/**
	 * @return void
	 */
	protected function makePages() {
		$this->pageProvider->setFilters( $this->pageFilterFactory->getFilters(), $this->filterData );
		$this->pageProvider->execute();
		$this->pageCount = $this->pageProvider->getPageCount();
		$pageTitles = $this->pageProvider->getPages();
		foreach ( $pageTitles as $title ) {
			$this->pages[] = [
				'id' => $title->getArticleId(),
				'prefixed_text' => $title->getPrefixedText()
			];
		}

		usort( $this->pages, static function ( $a, $b ) {
			return $a['prefixed_text'] < $b['prefixed_text'] ? -1 : 1;
		} );

		$this->hookContainer->run(
			'ContentTransferApiAfterGetPages',
			[ &$this->pageCount, &$this->pages ]
		);
	}

	/**
	 * @return void
	 */
	protected function returnPages() {
		$result = $this->getResult();

		$result->addValue( null, 'pages', $this->pages );
		$result->addValue( null, 'page_count', count( $this->pages ) );
		$result->addValue( null, 'total', $this->pageCount );
	}

}

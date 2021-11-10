<?php

namespace ContentTransfer;

use Config;
use Title;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\LoadBalancer;

class PageProvider {
	/** @var Config */
	protected $config;
	/** @var LoadBalancer */
	protected $lb;
	/** @var array */
	private $pageFilters;
	/** @var array */
	protected $filterData = [];
	/** @var int */
	protected $pageCount = 0;
	/** @var array */
	protected $pages = [];
	/** @var bool */
	protected $executed = false;

	/**
	 * @param Config $config
	 * @param LoadBalancer $lb
	 * @return static
	 */
	public static function factory( $config, $lb ) {
		return new static( $config, $lb );
	}

	/**
	 * @param Config $config
	 * @param LoadBalancer $lb
	 */
	public function __construct( $config, $lb ) {
		$this->config = $config;
		$this->lb = $lb;
	}

	/**
	 * @param array $pageFilters
	 * @param array $filterData
	 */
	public function setFilters( array $pageFilters, array $filterData ) {
		$this->pageFilters = $pageFilters;
		$this->filterData = $filterData;
	}

	/**
	 * Retrieve the pages
	 */
	public function execute() {
		$db = $this->lb->getConnection( DB_REPLICA );
		$res = $db->select(
			$this->getTables(),
			[ 'DISTINCT( page.page_id )', 'page.page_title', 'page.page_namespace' ],
			$this->makeConds( $db ),
			__METHOD__,
			[
				'LIMIT' => $this->getLimit()
			],
			$this->makeJoins( $db )
		);

		$this->pages = [];
		foreach ( $res as $row ) {
			$title = Title::newFromRow( $row );
			if ( $title instanceof Title && $title->exists() ) {
				$this->pages[] = $title;
			}
		}
	}

	/**
	 * Get total number of pages fitting the filter
	 * Might be more that actual number of returned pages
	 * due to hard limits
	 *
	 * @return int
	 */
	public function getPageCount() {
		$db = $this->lb->getConnection( DB_REPLICA );
		$res = $db->selectRow(
			$this->getTables(),
			[ 'COUNT( DISTINCT( page.page_id ) ) as count' ],
			$this->makeConds( $db ),
			__METHOD__,
			[
				'LIMIT' => $this->getLimit()
			],
			$this->makeJoins( $db )
		);

		return (int)$res->count;
	}

	/**
	 * @return Title[]
	 */
	public function getPages() {
		return $this->pages;
	}

	/**
	 * @return string[]
	 */
	protected function getTables() {
		$tables = [ 'page', 'push_history', 'revision' ];
		/**
		 * @var string $name
		 * @var IPageFilter $filter
		 */
		foreach ( $this->pageFilters as $name => $filter ) {
			$filter->modifyTables( $tables );
		}

		return $tables;
	}

	/**
	 * @param Database $db
	 * @return array
	 */
	protected function makeJoins( Database $db ) {
		$options = [
			'revision' => [ 'INNER JOIN', [ 'page_latest = rev_id' ] ],
			'push_history' => [ 'LEFT OUTER JOIN', [ 'page_id = ph_page' ] ],
		];

		if ( isset( $this->filterData['target'] ) ) {
			$target = $db->addQuotes( $this->filterData['target'] );
			$options['push_history'] = [ 'LEFT OUTER JOIN', [ "page_id = ph_page", "ph_target = $target" ] ];
		}

		/**
		 * @var string $name
		 * @var IPageFilter $filter
		 */
		foreach ( $this->pageFilters as $name => $filter ) {
			$filter->modifyJoins( $options );
		}

		return $options;
	}

	/**
	 *
	 * @param Database $db
	 * @return array
	 */
	protected function makeConds( Database $db ) {
		$conds = [];
		if ( $this->config->get( 'ContentTransferOnlyContentNamespaces' ) ) {
			$conds[] = 'page.page_namespace IN (' . $db->makeList(
				array_unique( \MWNamespace::getContentNamespaces() )
			) . ')';
		}
		if ( isset( $this->filterData['modifiedSince'] ) ) {
			$sinceDate = static::getModifiedSinceDate( $this->filterData['modifiedSince' ] );
			if ( $sinceDate !== null ) {
				$conds[] = 'revision.rev_timestamp >= ' . $db->timestamp( $sinceDate );
			}
		}
		if (
			isset( $this->filterData['onlyModified'] ) &&
			$this->filterData['onlyModified'] === true
		) {

			$exitsConds = [];
			$exitsConds[] = 'push_history.ph_target = ' . $db->addQuotes( $this->filterData['target'] );
			$exitsConds[] = 'push_history.ph_timestamp <= revision.rev_timestamp';
			$conds[] = 'push_history.ph_page IS NULL OR (' . implode( ' AND ', $exitsConds ) . ')';
		}

		/**
		 * @var string $name
		 * @var IPageFilter $filter
		 */
		foreach ( $this->pageFilters as $name => $filter ) {
			$filter->modifyConds( $this->filterData, $conds );
		}
		return $conds;
	}

	/**
	 *
	 * @return int
	 */
	protected function getLimit() {
		if ( !$this->config->has( 'ContentTransferPageLimit' ) ) {
			return 999999;
		}

		return (int)$this->config->get( 'ContentTransferPageLimit' );
	}

	/**
	 *
	 * @param string $modifiedSince
	 * @return string|null
	 */
	public static function getModifiedSinceDate( $modifiedSince ) {
		if ( empty( $modifiedSince ) ) {
			return null;
		}
		$matches = [];
		preg_match(
			'/^\s*(3[01]|[12][0-9]|0?[1-9])\.(1[012]|0?[1-9])\.((?:19|20)\d{2})\s*$/',
			$modifiedSince,
			$matches
		);
		if ( empty( $matches ) ) {
			return null;
		}
		$date = strtotime( "{$matches[2]}/{$matches[1]}/{$matches[3]}" );
		return wfTimestamp( TS_MW, $date );
	}
}

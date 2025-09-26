<?php

namespace ContentTransfer;

use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\LoadBalancer;

class PageProvider {
	/** @var array */
	private array $pageFilters;
	/** @var array */
	protected array $filterData = [];
	/** @var int */
	protected int $pageCount = 0;
	/** @var array */
	protected array $pages = [];
	/** @var bool */
	protected bool $executed = false;

	/**
	 * @param Config $config
	 * @param LoadBalancer $lb
	 * @param TitleFactory $titleFactory
	 */
	public function __construct(
		private readonly Config $config,
		private readonly ILoadBalancer $lb,
		private readonly TitleFactory $titleFactory
	) {
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
		$res = $db->newSelectQueryBuilder()
			->tables( $this->getTables() )
			->select( [
				"DISTINCT( page_id )",
				"page_title",
				"page_namespace"
			] )
			->where( $this->makeConds( $db ) )
			->limit( $this->getLimit() )
			->joinConds( $this->makeJoins( $db ) )
			->caller( __METHOD__ )
			->fetchResultSet();

		$this->pages = [];
		foreach ( $res as $row ) {
			$title = $this->titleFactory->newFromRow( $row );
			if ( $title->exists() ) {
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
		$res = $db->newSelectQueryBuilder()
			->tables( $this->getTables() )
			->select( [
				"COUNT( DISTINCT( page_id ) ) as count"
			] )
			->where( $this->makeConds( $db ) )
			->limit( $this->getLimit() )
			->joinConds( $this->makeJoins( $db ) )
			->caller( __METHOD__ )
			->fetchRow();

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
		foreach ( $this->pageFilters as $filter ) {
			$filter->modifyTables( $tables );
		}

		return $tables;
	}

	/**
	 * @param DBConnRef $db
	 * @return array
	 */
	protected function makeJoins( $db ) {
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
	 * @param DBConnRef $db
	 * @return array
	 */
	protected function makeConds( $db ) {
		$conds = [];
		if ( $this->config->get( 'ContentTransferOnlyContentNamespaces' ) ) {
			$namespaceInfo = MediaWikiServices::getInstance()->getNamespaceInfo();
			$conds[] = "page_namespace IN (" . $db->makeList(
				array_unique( $namespaceInfo->getContentNamespaces() )
			) . ')';
		}
		if ( isset( $this->filterData['modifiedSince'] ) ) {
			$sinceDate = static::getModifiedSinceDate( $this->filterData['modifiedSince' ] );
			if ( $sinceDate !== null ) {
				$conds[] = "rev_timestamp >= " . $db->timestamp( $sinceDate );
			}
		}
		if (
			isset( $this->filterData['onlyModified'] ) &&
			$this->filterData['onlyModified'] === true
		) {
			$exitsConds = [];
			$exitsConds[] = "ph_target = " . $db->addQuotes( $this->filterData['target'] );
			$exitsConds[] = "ph_timestamp <= rev_timestamp";
			$conds[] = "ph_page IS NULL OR (" . implode( ' AND ', $exitsConds ) . ')';
		}

		/**
		 * @var IPageFilter $filter
		 */
		foreach ( $this->pageFilters as $filter ) {
			$filter->modifyConds( $this->filterData, $conds );
		}
		return $conds;
	}

	/**
	 * @return int
	 */
	protected function getLimit() {
		if ( !$this->config->has( 'ContentTransferPageLimit' ) ) {
			return 999999;
		}

		return (int)$this->config->get( 'ContentTransferPageLimit' );
	}

	/**
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

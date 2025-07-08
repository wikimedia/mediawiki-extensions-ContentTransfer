<?php

namespace ContentTransfer\PageFilter;

use ContentTransfer\IPageFilter;
use MediaWiki\Context\IContextSource;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\LoadBalancer;

class PageName implements IPageFilter {
	/** @var IContextSource */
	protected $context = null;

	/** @var string */
	private string $searchIndexTableName;

	/**
	 * @param LoadBalancer $lb
	 */
	public function __construct(
		private readonly ILoadBalancer $lb
	) {
		$this->searchIndexTableName = 'searchindex';
	}

	/**
	 * @param IContextSource $context
	 * @return void
	 */
	public function setContextSource( IContextSource $context ): void {
		$this->context = $context;
	}

	/**
	 * @inheritDoc
	 */
	public function getId() {
		return 'term';
	}

	/**
	 * @inheritDoc
	 */
	public function getDisplayName() {
		return $this->context->msg( 'contenttransfer-text-filter-input-label' )->text();
	}

	/**
	 * @inheritDoc
	 */
	public function getRLModule() {
		return "ext.contenttransfer.filters";
	}

	/**
	 * @inheritDoc
	 */
	public function getWidgetClass() {
		return 'contentTransfer.widget.PageNameFilter';
	}

	/**
	 * @inheritDoc
	 */
	public function getWidgetData() {
		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function modifyTables( &$tables ) {
		$tables[] = $this->searchIndexTableName;
	}

	/**
	 * @inheritDoc
	 */
	public function modifyJoins( &$joins ) {
		$joins[$this->searchIndexTableName] = [ 'INNER JOIN', [ 'page_id = si_page' ] ];
	}

	/**
	 * @inheritDoc
	 */
	public function modifyConds( $filterData, &$conds ) {
		if ( isset( $filterData['term'] ) && $filterData['term'] !== '' ) {
			$db = $this->lb->getConnection( DB_REPLICA );
			$bits = explode( ':', $filterData['term'] );
			$term = trim( strtolower( array_pop( $bits ) ) );
			$term = implode( '%', explode( ' ', $term ) );
			$tableName = $this->searchIndexTableName;
			$termConds[] = "$tableName.si_title = " . $db->addQuotes( $term );
			$termConds[] = "$tableName.si_title LIKE " . $db->addQuotes( "%$term%" );
			$termConds[] = "$tableName.si_title LIKE " . $db->addQuotes( "$term%" );
			$termConds[] = "$tableName.si_title LIKE " . $db->addQuotes( "%$term" );
			$conds[] = '(' . implode( '  OR ', $termConds ) . ')';
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getPriority() {
		return 10;
	}
}

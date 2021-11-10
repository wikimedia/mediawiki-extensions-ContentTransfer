<?php

namespace ContentTransfer\PageFilter;

use ContentTransfer\IPageFilter;
use IContextSource;
use Wikimedia\Rdbms\LoadBalancer;

class PageName implements IPageFilter {
	/** @var LoadBalancer */
	protected $lb;
	/** @var IContextSource */
	protected $context;

	/**
	 * @param LoadBalancer $lb
	 * @param IContextSource $context
	 * @return static
	 */
	public function factory( LoadBalancer $lb, IContextSource $context ) {
		return new static( $lb, $context );
	}

	/**
	 * @param LoadBalancer $lb
	 * @param IContextSource $context
	 */
	public function __construct( LoadBalancer $lb, IContextSource $context ) {
		$this->lb = $lb;
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
		 $tables[] = 'searchindex';
	}

	/**
	 * @inheritDoc
	 */
	public function modifyJoins( &$joins ) {
		$joins['searchindex'] = [ 'INNER JOIN', [ 'page_id = si_page' ] ];
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
			$termConds[] = 'searchindex.si_title = ' . $db->addQuotes( $term );
			$termConds[] = 'searchindex.si_title LIKE ' . $db->addQuotes( "%$term%" );
			$termConds[] = 'searchindex.si_title LIKE ' . $db->addQuotes( "$term%" );
			$termConds[] = 'searchindex.si_title LIKE ' . $db->addQuotes( "%$term" );
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

<?php

namespace ContentTransfer\PageFilter;

use ContentTransfer\IPageFilter;
use MediaWiki\Context\IContextSource;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\LoadBalancer;

class Category implements IPageFilter {
	/** @var IContextSource */
	protected $context = null;

	/**
	 * @param LoadBalancer $lb
	 */
	public function __construct(
		private readonly ILoadBalancer $lb
	) {
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
		return 'category';
	}

	/**
	 * @inheritDoc
	 */
	public function getDisplayName() {
		return $this->context->msg( 'contenttransfer-category-filter-input-label' )->text();
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
		return 'contentTransfer.widget.CategoryFilter';
	}

	/**
	 * @inheritDoc
	 */
	public function getWidgetData() {
		$db = $this->lb->getConnection( DB_REPLICA );
		$res = $db->select(
			'category',
			[ 'cat_id', 'cat_title' ],
			'',
			__METHOD__
		);

		$categories = [];
		foreach ( $res as $row ) {
			$categories[] = [
				'id' => $row->cat_id,
				'text' => $row->cat_title
			];
		}

		return [
			'optionData' => $categories
		];
	}

	/**
	 * @inheritDoc
	 */
	public function modifyTables( &$tables ) {
		$tables[] = 'categorylinks';
	}

	/**
	 * @inheritDoc
	 */
	public function modifyJoins( &$joins ) {
		$joins['categorylinks'] = [ 'LEFT OUTER JOIN', [ 'page_id = cl_from' ] ];
	}

	/**
	 * @inheritDoc
	 */
	public function modifyConds( $filterData, &$conds ) {
		$db = $this->lb->getConnection( DB_REPLICA );
		if ( isset( $filterData['category'] ) && $filterData['category'] !== false ) {
			$categoryLinksTableName = $db->tableName( 'categorylinks' );
			$conds[] = "$categoryLinksTableName.cl_to = " . $db->addQuotes( $filterData['category'] );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getPriority() {
		return 30;
	}
}

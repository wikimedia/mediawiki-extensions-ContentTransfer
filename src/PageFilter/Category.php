<?php

namespace ContentTransfer\PageFilter;

use ContentTransfer\IPageFilter;
use IContextSource;
use Wikimedia\Rdbms\LoadBalancer;

class Category implements IPageFilter {
	/** @var LoadBalancer */
	protected $lb;
	/** @var IContextSource */
	protected $context;

	/**
	 * @param LoadBalancer $lb
	 * @param IContextSource $context
	 * @return static
	 */
	public static function factory( LoadBalancer $lb, IContextSource $context ) {
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
			[ 'cat_id', 'cat_title' ]
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
			$conds[] = 'categorylinks.cl_to = ' . $db->addQuotes( $filterData['category'] );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getPriority() {
		return 30;
	}
}

<?php

namespace ContentTransfer;

use MediaWiki\Context\IContextSource;
use Wikimedia\Rdbms\LoadBalancer;

class PageFilterFactory {
	/** @var array */
	private $attribute;
	/** @var LoadBalancer */
	private $lb;
	/** @var array */
	private $filters = [];
	/** @var bool */
	private $isLoaded = false;
	/** @var IContextSource */
	private $context;

	/**
	 * @param array $attribute
	 * @param LoadBalancer $lb
	 * @param IContextSource $context
	 */
	public function __construct( $attribute, LoadBalancer $lb, IContextSource $context ) {
		$this->attribute = $attribute;
		$this->lb = $lb;
		$this->context = $context;
	}

	/**
	 * @return array
	 */
	public function getFilters() {
		if ( !$this->isLoaded ) {
			$this->load();
		}

		return $this->filters;
	}

	/**
	 * @return array
	 */
	public function getFiltersForClient() {
		if ( !$this->isLoaded ) {
			$this->load();
		}

		$forClient = [];
		foreach ( $this->filters as $name => $instance ) {
			$forClient[$name] = $this->prepareForClient( $instance );
		}

		return $forClient;
	}

	/**
	 * Get all RL modules required by all filters
	 * @return array
	 */
	public function getRLModules() {
		if ( !$this->isLoaded ) {
			$this->load();
		}

		$modules = [];
		foreach ( $this->filters as $name => $instance ) {
			$modules[] = $instance->getRLModule();
		}

		return array_unique( $modules );
	}

	/**
	 * Prepare filter for passing to client-side
	 * @param IPageFilter $filter
	 * @return array
	 */
	private function prepareForClient( IPageFilter $filter ) {
		return [
			'id' => $filter->getId(),
			'displayName' => $filter->getDisplayName(),
			'widgetClass' => $filter->getWidgetClass(),
			'widgetData' => $filter->getWidgetData()
		];
	}

	/**
	 * Load Filters
	 */
	private function load() {
		foreach ( $this->attribute as $name => $callable ) {
			if ( !is_callable( $callable ) ) {
				continue;
			}
			$instance = call_user_func_array( $callable, [ $this->lb, $this->context ] );
			if ( !$instance instanceof IPageFilter ) {
				continue;
			}
			$this->filters[$name] = $instance;
		}

		uasort( $this->filters, static function ( IPageFilter $a, IPageFilter $b ) {
			if ( $a->getPriority() === $b->getPriority() ) {
				return 0;
			}

			return ( $a->getPriority() < $b->getPriority() ) ? -1 : 1;
		} );

		$this->isLoaded = true;
	}
}

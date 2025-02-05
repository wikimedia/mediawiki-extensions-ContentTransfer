<?php

namespace ContentTransfer;

use MediaWiki\Context\IContextSource;
use Wikimedia\ObjectFactory\ObjectFactory;

class PageFilterFactory {
	/** @var array */
	private $attribute;
	/** @var array */
	private $filters = [];
	/** @var bool */
	private $isLoaded = false;

	/**
	 * @param array $attribute
	 * @param ObjectFactory $objectFactory
	 * @param IContextSource $contextSource
	 */
	public function __construct(
		array $attribute,
		private readonly ObjectFactory $objectFactory,
		private readonly IContextSource $contextSource
	) {
		$this->attribute = $attribute;
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
		foreach ( $this->filters as $instance ) {
			$modules[] = $instance->getRLModule();
		}

		return array_unique( $modules );
	}

	/**
	 * Prepare filter for passing to client-side
	 * @param IPageFilter $filter
	 * @return array
	 */
	private function prepareForClient( IPageFilter $filter ): array {
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
		foreach ( $this->attribute as $name => $spec ) {
			$instance = $this->objectFactory->createObject( $spec );
			if ( !$instance instanceof IPageFilter ) {
				continue;
			}
			$instance->setContextSource( $this->contextSource );
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

<?php

namespace ContentTransfer;

interface IPageFilter {
	/**
	 * @return string
	 */
	public function getId();

	/**
	 * @return string
	 */
	public function getDisplayName();

	/**
	 * @return string
	 */
	public function getRLModule();

	/**
	 * @return array
	 */
	public function getWidgetData();

	/**
	 * @return string
	 */
	public function getWidgetClass();

	/**
	 * @param array &$tables
	 */
	public function modifyTables( &$tables );

	/**
	 * @param array &$joins
	 */
	public function modifyJoins( &$joins );

	/**
	 * @param array $filterData
	 * @param array &$conds
	 */
	public function modifyConds( $filterData, &$conds );

	/**
	 * Get filter priority for layouting
	 *
	 * @return int
	 */
	public function getPriority();
}

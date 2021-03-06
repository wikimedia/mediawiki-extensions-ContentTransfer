<?php

namespace ContentTransfer\Api;

use ApiBase;
use ContentTransfer\PageContentProvider;
use ContentTransfer\PageProvider;
use Title;

class PushInfo extends ApiBase {
	protected const TYPE_WIKIPAGE = 'wikipage';
	protected const TYPE_TEMPLATE = 'template';
	protected const TYPE_CATEGORY = 'category';
	protected const TYPE_FILE = 'file';

	protected $titles = [];
	protected $onlyModified;
	protected $modifiedSince = '';
	protected $target = '';
	protected $groupedInfo = [];
	protected $joinedInfo = [];
	protected $includeRelated = false;

	public function execute() {
		$this->readInParameters();
		$this->getInfo();
		$this->returnData();
	}

	/**
	 *
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'titles' => [
				static::PARAM_TYPE => 'string',
				static::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-titles',
			],
			'onlyModified' => [
				static::PARAM_TYPE => 'string',
				static::PARAM_REQUIRED => false,
				static::PARAM_DFLT => '0',
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-onlymodified',
			],
			'modifiedSince' => [
				static::PARAM_TYPE => 'string',
				static::PARAM_REQUIRED => false,
				static::PARAM_DFLT => '',
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-modifiedsince',
			],
			'target' => [
				static::PARAM_TYPE => 'string',
				static::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-target',
			],
			'includeRelated' => [
				static::PARAM_TYPE => 'string',
				static::PARAM_REQUIRED => false,
				static::PARAM_DFLT => '0',
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-includerelated',
			]
		];
	}

	/**
	 * Using the settings determine the value for the given parameter
	 *
	 * @param string $paramName Parameter name
	 * @param array|mixed $paramSettings Default value or an array of settings
	 *  using PARAM_* constants.
	 * @param bool $parseLimit Whether to parse and validate 'limit' parameters
	 * @return mixed Parameter value
	 */
	protected function getParameterFromSettings( $paramName, $paramSettings, $parseLimit ) {
		$value = parent::getParameterFromSettings( $paramName, $paramSettings, $parseLimit );
		if ( $paramName === 'titles' ) {
			$titleIds = \FormatJson::decode( $value, true );
			$res = [];
			foreach ( $titleIds as $titleId ) {
				$title = Title::newFromId( $titleId );
				if ( $title instanceof Title && $title->exists() ) {
					$res[ $title->getPrefixedDBkey() ] = $title;
				}
			}
			return $res;
		}
		return $value;
	}

	protected function readInParameters() {
		$this->titles = $this->getParameter( 'titles' );
		$this->onlyModified = (bool)$this->getParameter( 'onlyModified' );
		$this->modifiedSince = $this->getParameter( 'modifiedSince' );
		$this->target = $this->getParameter( 'target' );
		$this->includeRelated = (bool)$this->getParameter( 'includeRelated' );
	}

	/**
	 *
	 */
	protected function getInfo() {
		foreach ( $this->titles as $dbKey => $title ) {
			$contentProvider = new PageContentProvider( $title );

			if ( $this->includeRelated ) {
				$this->titles = array_merge(
					$this->titles,
					$contentProvider->getRelatedTitles( $this->getModificationData(), $this->target )
				);
			}
		}

		$this->generatePushInfo();
	}

	protected function generatePushInfo() {
		foreach ( $this->titles as $title ) {
			$type = static::TYPE_WIKIPAGE;
			switch ( $title->getNamespace() ) {
				case NS_TEMPLATE:
					$type = static::TYPE_TEMPLATE;
					break;
				case NS_FILE:
					$type = static::TYPE_FILE;
					break;
				case NS_CATEGORY:
					$type = static::TYPE_CATEGORY;
					break;
			}

			$this->joinedInfo[ $title->getPrefixedDBKey() ] = [
				'id' => $title->getArticleId(),
				'title' => $title->getFullText(),
				'type' => $type
			];

			$this->groupedInfo[ $type ][ $title->getPrefixedDBKey() ] = [
				'id' => $title->getArticleId(),
				'title' => $title->getFullText(),
				'uri' => $title->getLocalURL()
			];
		}

		ksort( $this->groupedInfo );
		foreach ( $this->groupedInfo as $type => &$values ) {
			usort( $values, static function ( $a, $b ) {
				return $a['title'] < $b['title'] ? -1 : 1;
			} );
		}

		ksort( $this->joinedInfo );
	}

	protected function returnData() {
		$result = $this->getResult();

		$result->addValue( null, 'joined', $this->joinedInfo );
		$result->addValue( null, 'grouped', $this->groupedInfo );
	}

	/**
	 *
	 * @return array
	 */
	private function getModificationData() {
		$data = [];
		if ( $this->modifiedSince !== '' ) {
			$data['date'] = PageProvider::getModifiedSinceDate( $this->modifiedSince );
		}
		$data['onlyModified'] = $this->onlyModified;

		return $data;
	}

}

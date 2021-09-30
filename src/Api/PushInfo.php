<?php

namespace ContentTransfer\Api;

use ApiBase;
use ContentTransfer\PageContentProvider;
use ContentTransfer\PageProvider;
use Title;

class PushInfo extends ApiBase {
	const TYPE_WIKIPAGE = 'wikipage';
	const TYPE_TEMPLATE = 'template';
	const TYPE_CATEGORY = 'category';
	const TYPE_FILE = 'file';

	protected $titles = [];
	protected $related = [];
	protected $onlyModified;
	protected $modifiedSince = '';
	protected $target = '';
	protected $groupedInfo = [];
	protected $joinedInfo = [];
	protected $includeRelated = false;
	/** @var array */
	protected $transcluded = [];

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
				$this->related = array_merge(
					$this->related,
					$contentProvider->getRelatedTitles(
						$this->getModificationData(),
						$this->target
					)
				);
				$this->transcluded = array_merge(
					$this->transcluded, $contentProvider->getTranscluded()
				);
			}
		}

		$this->generatePushInfo();
	}

	/**
	 * @param Title $title
	 * @return array
	 */
	protected function getInfoForTitle( Title $title ) {
		return [
			'id' => $title->getArticleId(),
			'title' => $title->getFullText(),
			'uri' => $title->getLocalURL()
		];
	}

	protected function generatePushInfo() {
		$this->groupedInfo['original'] = [];
		foreach ( $this->titles as $title ) {
			$this->groupedInfo['original'][$title->getPrefixedDBKey()] =
				$this->getInfoForTitle( $title );
			$this->joinedInfo[$title->getPrefixedDBKey()] = [
				'id' => $title->getArticleId(),
				'title' => $title->getFullText(),
				'type' => 'original',
				'groupKey' => 'original#linked'
			];
		}

		foreach ( $this->related as $title ) {
			if ( isset( $this->groupedInfo['original'][$title->getPrefixedDBKey()] ) ) {
				continue;
			}
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

			$isTranscluded = in_array( $title->getPrefixedDbKey(), $this->transcluded );
			$this->joinedInfo[ $title->getPrefixedDBKey() ] = [
				'id' => $title->getArticleId(),
				'title' => $title->getFullText(),
				'type' => $type,
				'groupKey' => $type . '#' . ( $isTranscluded ? 'transcluded' : 'linked' ),
			];

			$this->groupedInfo[$type][$title->getPrefixedDBKey()] =
				$this->getInfoForTitle( $title );
		}

		ksort( $this->groupedInfo );
		foreach ( $this->groupedInfo as $type => &$values ) {
			usort( $values, function ( $a, $b ) {
				return $a['title'] < $b['title'] ? -1 : 1;
			} );
		}

		foreach ( $this->groupedInfo as $type => &$values ) {
			if ( !in_array( $type, [ static::TYPE_TEMPLATE, static::TYPE_WIKIPAGE ] ) ) {
				continue;
			}
			$values = $this->separateTranscluded( $values );
		}

		ksort( $this->joinedInfo );
	}

	/**
	 * @param array $titles
	 * @return array
	 */
	private function separateTranscluded( $titles ) {
		$separated = [
			'linked' => [],
			'transcluded' => []
		];
		/** @var Title $title */
		foreach ( $titles as $data ) {
			if ( in_array( $data['title'], $this->transcluded ) ) {
				$separated['transcluded'][] = $data;
			} else {
				$separated['linked'][] = $data;
			}
		}

		return $separated;
	}

	protected function returnData() {
		$result = $this->getResult();

		$result->addValue( null, 'joined', $this->joinedInfo );
		$result->addValue( null, 'grouped', $this->groupedInfo );
	}

	private function getModificationData() {
		$data = [];
		if ( $this->modifiedSince !== '' ) {
			$data['date'] = PageProvider::getModifiedSinceDate( $this->modifiedSince );
		}
		$data['onlyModified'] = $this->onlyModified;

		return $data;
	}

}

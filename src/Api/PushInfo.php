<?php

namespace ContentTransfer\Api;

use ApiBase;
use ApiMain;
use ContentTransfer\PageContentProviderFactory;
use ContentTransfer\PageProvider;
use Title;
use Wikimedia\ParamValidator\ParamValidator;

class PushInfo extends ApiBase {
	protected const TYPE_WIKIPAGE = 'wikipage';
	protected const TYPE_TEMPLATE = 'template';
	protected const TYPE_CATEGORY = 'category';
	protected const TYPE_FILE = 'file';

	/** @var array */
	protected $titles = [];
	/** @var array */
	protected $related = [];
	/** @var bool */
	protected $onlyModified;
	/** @var string */
	protected $modifiedSince = '';
	/** @var string */
	protected $target = '';
	/** @var array */
	protected $groupedInfo = [];
	/** @var array */
	protected $joinedInfo = [];
	/** @var bool */
	protected $includeRelated = false;
	/** @var array */
	protected $transcluded = [];

	/**
	 * @var PageContentProviderFactory
	 */
	private $contentProviderFactory;

	/**
	 * @param ApiMain $mainModule
	 * @param string $moduleName
	 * @param PageContentProviderFactory $contentProviderFactory
	 */
	public function __construct(
		ApiMain $mainModule, $moduleName,
		PageContentProviderFactory $contentProviderFactory
	) {
		parent::__construct( $mainModule, $moduleName );
		$this->contentProviderFactory = $contentProviderFactory;
	}

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
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-titles',
			],
			'onlyModified' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => '0',
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-onlymodified',
			],
			'modifiedSince' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => '',
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-modifiedsince',
			],
			'target' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-target',
			],
			'includeRelated' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => '0',
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
			$contentProvider = $this->contentProviderFactory->newFromTitle( $title );

			if ( $this->includeRelated ) {
				$this->related = array_merge(
					$this->related,
					$contentProvider->getRelatedTitles( $this->getModificationData(), $this->target )
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
			usort( $values, static function ( $a, $b ) {
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

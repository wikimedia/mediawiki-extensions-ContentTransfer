<?php

namespace ContentTransfer;

use File;
use MediaWiki\MediaWikiServices;
use ParserOutput;
use RequestContext;
use Title;
use WikiPage;

class PageContentProvider {
	/** @var Title */
	protected $title;
	/** @var WikiPage */
	protected $wikipage;

	/**
	 *
	 * @var ParserOutput
	 */
	protected $parserOutput;

	/**
	 *
	 * @var array
	 */
	protected $relatedTitles = [];
	/** @var array */
	protected $transcluded = [];
	/** @var bool */
	private $relatedLoaded = false;

	/**
	 *
	 * @param Title $title
	 */
	public function __construct( Title $title ) {
		$this->title = $title;
		$this->wikipage = WikiPage::factory( $this->title );
		$this->parserOutput = $this->wikipage->getContent()->getParserOutput( $this->title );
	}

	/**
	 * Gets all titles related to this page,
	 * all titles necessary for this page to fully function
	 *
	 * @param array $modificationData
	 * @param string|null $target
	 * @return Title[]
	 */
	public function getRelatedTitles( array $modificationData, $target = '' ) {
		$this->extractTemplates();
		$this->extractFiles();
		$this->extractCategories();
		$this->extractLinks();

		if ( !empty( $modificationData ) && $target !== '' ) {
			foreach ( $this->relatedTitles as $dbKey => $title ) {
				if ( !$this->shouldPush( $modificationData, $title, $target ) ) {
					unset( $this->relatedTitles[ $dbKey ] );
				}
			}
		}

		$this->relatedLoaded = true;

		return $this->relatedTitles;
	}

	/**
	 * @return array of dbkeys of transcluded titles
	 * @throws \MWException
	 */
	public function getTranscluded() {
		$this->assertLoaded();
		return $this->transcluded;
	}

	private function assertLoaded() {
		if ( !$this->relatedLoaded ) {
			throw new \MWException( 'Related titles not loaded. Call getRelatedTitles()' );
		}
	}

	/**
	 * Gets raw content of a page
	 * @return string
	 */
	public function getContent() {
		return $this->wikipage->getContent()->getNativeData();
	}

	/**
	 * Get if give title is a file
	 *
	 * @return bool
	 */
	public function isFile() {
		if ( $this->title->getNamespace() === NS_FILE ) {
			return true;
		}
		return false;
	}

	/**
	 * If title is file, retrieve the file instance
	 *
	 * @return File
	 */
	public function getFile() {
		if ( !$this->isFile() ) {
			return null;
		}

		$file = wfFindFile( $this->title );
		return $file;
	}

	protected function extractTemplates() {
		$rawTemplates = $this->parserOutput->getTemplates();
		$this->relatedTitlesFromNestedArray( $rawTemplates, true );
	}

	protected function extractFiles() {
		$rawFiles = $this->parserOutput->getImages();
		foreach ( $rawFiles as $dbKey => $value ) {
			$fileTitle = Title::makeTitle( NS_FILE, $dbKey );
			if ( $fileTitle instanceof Title && $fileTitle->exists() ) {
				$this->relatedTitles[ $fileTitle->getPrefixedDBkey() ] = $fileTitle;
			}
		}
	}

	protected function extractCategories() {
		$rawCategories = $this->parserOutput->getCategories();
		foreach ( $rawCategories as $catName => $displayText ) {
			$category = \Category::newFromName( $catName );
			if ( $category->getTitle()->exists() ) {
				$categoryTitle = $category->getTitle();
				$this->relatedTitles[ $categoryTitle->getPrefixedDBkey() ] = $categoryTitle;
			}
		}
	}

	protected function extractLinks() {
		$rawLinks = $this->parserOutput->getLinks();
		$this->relatedTitlesFromNestedArray( $rawLinks );
	}

	/**
	 *
	 * @param array $nested
	 * @param bool|null $transcluded
	 */
	protected function relatedTitlesFromNestedArray( $nested, $transcluded = false ) {
		foreach ( $nested as $ns => $pages ) {
			foreach ( $pages as $dbKey => $pageId ) {
				$title = Title::newFromId( $pageId );
				if ( $title instanceof Title && $title->exists() ) {
					$this->relatedTitles[ $title->getPrefixedDBkey() ] = $title;
					if ( $transcluded ) {
						$this->transcluded[] = $title->getPrefixedDBkey();
					}
				}
			}
		}
	}

	/**
	 * @param array $modificationData
	 * @param Title $title
	 * @param string $target
	 * @return bool
	 */
	private function shouldPush( $modificationData, $title, $target ) {
		if ( isset( $modificationData['date'] ) ) {
			$revision = MediaWikiServices::getInstance()->getRevisionStore()->getRevisionByTitle( $title );
			if (
				$revision &&
				$revision->getTimestamp() &&
				$revision->getTimestamp() < $modificationData['date']
			) {
				return false;
			}
		}

		if ( isset( $modificationData['onlyModified'] ) && $modificationData['onlyModified'] ) {
			$pushHistory = new PushHistory( $title, RequestContext::getMain()->getUser(), $target );
			return $pushHistory->isChangedSinceLastPush();
		}

		return true;
	}
}

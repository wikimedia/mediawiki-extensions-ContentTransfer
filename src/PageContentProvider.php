<?php

namespace ContentTransfer;

use BadMethodCallException;
use File;
use MediaWiki\Category\Category;
use MediaWiki\Content\TextContent;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRenderer;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use RepoGroup;
use RuntimeException;
use Wikimedia\Rdbms\ILoadBalancer;

class PageContentProvider {
	/** @var ParserOutput|null */
	protected ?ParserOutput $parserOutput = null;
	/** @var array */
	protected array $relatedTitles = [];
	/** @var array */
	protected array $transcluded = [];
	/** @var bool */
	private bool $relatedLoaded = false;

	/**
	 * @param Title $title
	 * @param RevisionLookup $revisionLookup
	 * @param RevisionRenderer $revisionRenderer
	 * @param TitleFactory $titleFactory
	 * @param RepoGroup $repoGroup
	 * @param ILoadBalancer $lb
	 */
	public function __construct(
		private readonly Title $title,
		private readonly RevisionLookup $revisionLookup,
		private readonly RevisionRenderer $revisionRenderer,
		private readonly TitleFactory $titleFactory,
		private readonly RepoGroup $repoGroup,
		private readonly ILoadBalancer $lb
	) {
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
		$revision = $this->revisionLookup->getRevisionByTitle( $this->title );
		if ( !$revision ) {
			throw new RuntimeException( 'Revision not found' );
		}
		$options = ParserOptions::newFromAnon();
		$renderedRevision = $this->revisionRenderer->getRenderedRevision( $revision, $options );
		if ( !$renderedRevision ) {
			throw new RuntimeException( 'Failed to render revision' );
		}
		$this->parserOutput = $renderedRevision->getRevisionParserOutput();
		if ( !$this->parserOutput ) {
			throw new RuntimeException( 'Failed to get parser output' );
		}
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
	 */
	public function getTranscluded() {
		$this->assertLoaded();
		return $this->transcluded;
	}

	/**
	 * @return void
	 */
	private function assertLoaded() {
		if ( !$this->relatedLoaded ) {
			throw new BadMethodCallException( 'Related titles not loaded. Call getRelatedTitles()' );
		}
	}

	/**
	 * Gets raw content of a page
	 * @return string
	 */
	public function getContent() {
		$revision = $this->revisionLookup->getRevisionByTitle( $this->title );
		if ( !$revision ) {
			throw new RuntimeException( 'Revision not found' );
		}
		$content = $revision->getContent( SlotRecord::MAIN );
		return ( $content instanceof TextContent ) ? $content->getText() : '';
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

		return $this->repoGroup->findFile( $this->title );
	}

	/**
	 * @return void
	 */
	protected function extractTemplates() {
		$rawTemplates = $this->parserOutput->getLinkList( ParserOutputLinkTypes::TEMPLATE );
		$this->relatedTitlesFromNestedArray( $rawTemplates, true );
	}

	/**
	 * @return void
	 */
	protected function extractFiles() {
		$rawFiles = $this->parserOutput->getLinkList( ParserOutputLinkTypes::MEDIA );
		foreach ( $rawFiles as $dbKey => $value ) {
			if ( !isset( $value['link'] ) ) {
				continue;
			}
			$linkTitle = $value['link'];
			$fileTitle = $this->titleFactory->makeTitle( NS_FILE, $linkTitle->getDBkey() );
			if ( $fileTitle->exists() ) {
				$this->relatedTitles[ $fileTitle->getPrefixedDBkey() ] = $fileTitle;
			}
		}
	}

	/**
	 * @return void
	 */
	protected function extractCategories() {
		$rawCategories = $this->parserOutput->getLinkList( ParserOutputLinkTypes::CATEGORY );
		foreach ( $rawCategories as $catName => $displayText ) {
			if ( !isset( $displayText['link'] ) ) {
				continue;
			}
			$categoryText = $displayText['link']->getDBkey();
			$category = Category::newFromName( $categoryText );
			if ( $category->getPage()->exists() ) {
				$categoryTitle = $this->titleFactory->castFromPageIdentity( $category->getPage() );
				$this->relatedTitles[ $categoryTitle->getPrefixedDBkey() ] = $categoryTitle;
			}
		}
	}

	/**
	 * @return void
	 */
	protected function extractLinks() {
		$rawLinks = $this->parserOutput->getLinkList( ParserOutputLinkTypes::LOCAL );
		$this->relatedTitlesFromNestedArray( $rawLinks );
	}

	/**
	 * @param array $nested
	 * @param bool|null $transcluded
	 */
	protected function relatedTitlesFromNestedArray( $nested, $transcluded = false ) {
		foreach ( $nested as $page ) {
			$title = $this->titleFactory->castFromLinkTarget( $page['link'] ?? null );
			if ( !$title || !$title->exists() ) {
				continue;
			}
			$this->relatedTitles[ $title->getPrefixedDBkey() ] = $title;
			if ( $transcluded ) {
				$this->transcluded[] = $title->getPrefixedDBkey();
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
			$pushHistory = new PushHistory(
				$title, RequestContext::getMain()->getUser(), $target,
				$this->lb, $this->revisionLookup
			);
			return $pushHistory->isChangedSinceLastPush();
		}

		return true;
	}
}

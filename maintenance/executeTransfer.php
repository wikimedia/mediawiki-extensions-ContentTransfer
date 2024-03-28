<?php

use ContentTransfer\PageContentProvider;
use ContentTransfer\PageFilterFactory;
use ContentTransfer\PageProvider;
use ContentTransfer\PagePusher;
use ContentTransfer\PushHistory;
use ContentTransfer\Target;
use ContentTransfer\TargetManager;
use MediaWiki\MediaWikiServices;

require_once dirname( dirname( dirname( __DIR__ ) ) ) . '/maintenance/Maintenance.php';

class ExecuteTransfer extends Maintenance {

	/**
	 * List of target wiki instances.
	 * Key is target name, value is target object.
	 *
	 * @var Target[]
	 */
	private $targets = [];

	/**
	 * Wiki titles which are explicitly passed for transfer.
	 * If they are passed in that way, there will be no filtering by category or namespace.
	 *
	 * But there still will be filtering by "only modified" and "modified since" filters.
	 *
	 * @var array
	 */
	private $titles = [];

	/**
	 * Context user who will be used to transfer wiki pages
	 *
	 * @var User
	 */
	private $user = null;

	/**
	 * Transfer page if it is protected on receiving wiki
	 *
	 * @var bool
	 */
	private $force = false;

	/**
	 * Data used by {@link PageProvider} to filter out necessary pages.
	 * Has such structure:
	 * [
	 * 		'category' => <category>,
	 * 		'namespace' => <namespace>
	 * ]
	 * This array may not be filled if pages are explicitly specified, see {@link ExecuteTransfer::$titles}.
	 *
	 * @var array
	 */
	private $filterData = [];

	/**
	 * Data used by {@link ExecuteTransfer::shouldTransfer()} to filter out titles by their modification date.
	 * Has such structure:
	 * [
	 * 		'modifiedSince => <modifiedSince>,
	 * 		'onlyModified' => <onlyModified>
	 * ]
	 * This filtering will be done even in case with explicitly defined pages.
	 *
	 * @var array
	 */
	private $modificationData = [];

	/**
	 * If all related to wiki page titles also should be transferred.
	 * Related titles are: used on page templates, embedded files, wiki pages links to which are used
	 *
	 * @var bool
	 */
	private $includeRelated = false;

	/**
	 * Target manager, used to get objects of target wikis.
	 * See {@link ExecuteTransfer::$targets}
	 *
	 * @var TargetManager
	 */
	private $targetManager;

	/**
	 * See config "ContentTransferIgnoreInsecureSSL"
	 *
	 * @var bool
	 */
	private $ignoreInsecureSsl;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'json-config', 'JSON file which contains necessary transfer configuration. ' .
		 'Sample config file is located here: ' .
			'"extensions/ContentTransfer/docs/transfer-config.json.example"', false, true );
		$this->addOption( 'targets', 'List of target wikis, separated by comma. ' .
			'Names of target wikis should be targets keys from "$wgContentTransferTargets" global. ' .
			'If target wiki has multiple users who can be used to create page on "receiving" wiki, ' .
			'then first one will be used.' .
			'If other user should be used, target should be specified like that: ' .
			'"Target1=User1,Target2,Target3"', false, true );
		$this->addOption( 'category', 'Category which can be used to select certain wiki pages to transfer',
			false, true );
		$this->addOption( 'namespace', 'Namespace ID which can be used to select certain wiki pages to transfer',
			false, true );
		$this->addOption( 'pages', 'Explicit list of wiki pages to transfer, separated by comma. ' .
			'If wiki pages to transfer are passed in that way, there will be no filtering by category or namespace',
			false, true );
		$this->addOption( 'user', 'Context user of "sending" wiki who will be used to transfer wiki pages. ' .
			'Actually it\'s just about recording transfer in DB',
			false, true );
		$this->addOption( 'only-modified', 'If page should be transferred only if it was modified ' .
			'since last transfer' );
		$this->addOption( 'modified-since', 'Transfer page only if was modified since specified date. ' .
			'Date must be specified in format "DD.MM.YYYY"',
			false, true );
		$this->addOption( 'include-related', 'If all related wiki pages should also be transferred. ' .
			'Like templates, files, links which are used on transferring page' );
		$this->addOption( 'force', 'Transfer page if it is protected on receiving wiki. Default: false' );
		$this->addOption( 'dry', 'If we don\'t need any changes on "receiving" wikis, ' .
		'just to check configuration and pages to transfer.' );

		$this->addDescription( 'Transfers specific (found by selectors - "category", "namespace" or "page name") ' .
			'wiki pages to specified target wikis' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->targetManager = MediaWikiServices::getInstance()->getService(
			'ContentTransferTargetManager'
		);

		if ( $this->hasOption( 'json-config' ) ) {
			$this->readJsonConfig();
		}
		$this->readCliParams();

		if ( !$this->canTransfer() ) {
			$this->output( "Transfer start checks failed. Please check if all necessary configuration is set\n" );
			return;
		}

		$this->output( "User: {$this->user->getName()}\n\n" );

		$this->ignoreInsecureSsl = $this->getConfig()->get( 'ContentTransferIgnoreInsecureSSL' );

		$this->output( "Content transfer started.\n" );

		$this->output( "\nTarget wikis:\n" );
		foreach ( $this->targets as $targetKey => $target ) {
			$this->output( "- '$targetKey'\n" );
		}

		$this->output( "\nCollecting titles to transfer...\n" );

		// Contains information about:
		// * which is this title related to
		// * which target is this title should be transferred to
		$relatedTitlesMap = [];

		// Contains information about:
		// * which target is this title should be transferred to
		$targetTitlesMap = [];

		// Contains actual titles objects to transfer. Key is title DB key, value is object
		$titlesToTransfer = [];

		// Contains actual related titles objects to transfer. Key is title DB key, value is object
		$relatedTitlesToTransfer = [];
		$titlesObjects = $this->getTitles();
		foreach ( $titlesObjects as $titleObj ) {
			$titleKey = $titleObj->getDBkey();

			// Exclude non-existing pages or pages with incorrect names
			if ( !$titleObj->exists() ) {
				$this->output( "Page '$titleKey' does not exist in the source wiki! Skipping...\n" );
				continue;
			}

			// Save title objects in one place to not pollute memory
			$titlesToTransfer[$titleKey] = $titleObj;

			// Check to which targets we should transfer this title
			// It depends on title "modification date" on each target wiki (if such filters were set)
			foreach ( $this->targets as $targetKey => $target ) {
				if ( !$this->shouldTransfer( $this->modificationData, $titleObj, $targetKey ) ) {
					$this->output( "Title '$titleKey' was not modified since last transfer or date provided." .
						"Skipping...\n" );
					continue;
				}

				// Title "$titleKey" should be transferred to "$targetKey"
				$targetTitlesMap[$targetKey][] = $titleKey;
			}

			// Collect related titles
			if ( $this->includeRelated ) {
				$pageContentProvider = new PageContentProvider( $titleObj );
				$relatedTitles = $pageContentProvider->getRelatedTitles( [] );

				foreach ( $this->targets as $targetKey => $target ) {
					foreach ( $relatedTitles as $relatedTitle ) {
						if ( !$this->shouldTransfer( $this->modificationData, $relatedTitle, $targetKey ) ) {
							$this->output( "Related title was not modified since last transfer or date provided." .
								"Skipping...\n" );
							continue;
						}

						$relatedTitleKey = $relatedTitle->getDBkey();

						// Title "$relatedTitleKey" is related to "$titleKey" and should be transferred to "$targetKey"
						$relatedTitlesMap[$titleKey][$targetKey][] = $relatedTitleKey;
						// Save title objects in one place to not pollute memory
						$relatedTitlesToTransfer[$relatedTitleKey] = $relatedTitle;
					}
				}
			}
		}

		// Free memory, this array is not needed anymore
		unset( $titlesObjects );

		$this->output( "\nTitles to transfer per target:\n" );
		foreach ( $this->targets as $targetKey => $target ) {
			$this->output( "- Target: '$targetKey'\n" );

			// If there are titles to transfer to that target
			if ( !empty( $targetTitlesMap[$targetKey] ) ) {

				$titleKeys = $targetTitlesMap[$targetKey];
				foreach ( $titleKeys as $titleKey ) {
					$this->output( "-- Title '$titleKey'...\n" );

					// If there are related titles to transfer to that target
					if ( !empty( $relatedTitlesMap[$titleKey][$targetKey] ) ) {

						$relatedTitles = $relatedTitlesMap[$titleKey][$targetKey];
						foreach ( $relatedTitles as $relatedTitleKey ) {
							$this->output( "--- Related title '$relatedTitleKey'...\n" );
						}
					}
				}
			}
		}

		if ( $this->getOption( 'dry' ) ) {
			$this->output( "\nThis was a dry run, no pages were transferred\n" );
			return;
		}

		// Actual transferring
		foreach ( $this->targets as $targetKey => $target ) {
			// If there are titles to transfer to that target
			if ( !empty( $targetTitlesMap[$targetKey] ) ) {
				$this->output( "\nTransferring to target '$targetKey'...\n" );

				$titleKeys = $targetTitlesMap[$targetKey];
				foreach ( $titleKeys as $titleKey ) {
					$this->output( "Transferring title '$titleKey'... " );
					$this->transferTitle( $titlesToTransfer[$titleKey], $target );

					// If there are related titles to transfer to that target
					if ( !empty( $relatedTitlesMap[$titleKey][$targetKey] ) ) {

						$relatedTitles = $relatedTitlesMap[$titleKey][$targetKey];
						foreach ( $relatedTitles as $relatedTitleKey ) {
							$this->output( "Transferring related title '$relatedTitleKey'... " );
							$this->transferTitle( $relatedTitlesToTransfer[$relatedTitleKey], $target );
						}
					}
				}
			}
		}

		$this->output( "Done\n" );
	}

	/**
	 * @return void
	 */
	private function readJsonConfig(): void {
		$jsonPath = realpath( $this->getOption( 'json-config' ) );

		$this->output( "Consuming JSON config file: $jsonPath\n" );

		// JSON file does not exist
		if ( !file_exists( $jsonPath ) ) {
			$this->output( "JSON config file does not exist!\n" );
			$this->output( "Params from CLI will be read only...\n" );

			return;
		}

		$content = file_get_contents( $jsonPath );
		if ( !$content ) {
			$this->output( "Failed to read JSON config file!\n" );
			$this->output( "Params from CLI will be read only...\n" );

			return;
		}

		$config = json_decode( $content, true );
		if ( $config === null ) {
			$this->output( "JSON config file is not valid!\n" );
			$this->output( "Params from CLI will be read only...\n" );
		}

		$this->processConfig( $config );
	}

	/**
	 * @return void
	 */
	private function readCliParams(): void {
		$config = [
			'targets' => $this->getOption( 'targets' ),
			'category' => $this->getOption( 'category' ),
			'namespace' => $this->getOption( 'namespace' ),
			'pages' => $this->getOption( 'pages' ),
			'user' => $this->getOption( 'user' ),
			'onlyModified' => $this->getOption( 'only-modified' ),
			'modifiedSince' => $this->getOption( 'modified-since' ),
			'includeRelated' => $this->getOption( 'include-related' ),
			'force' => $this->getOption( 'force' )
		];

		$this->processConfig( $config );
	}

	/**
	 * @param array $config
	 *
	 * @return void
	 */
	private function processConfig( array $config ): void {
		// Get targets list
		if ( $config['targets'] ) {
			$targets = $config['targets'];
			if ( !is_array( $targets ) ) {
				$targets = explode( ',', $targets );
			}

			$this->targets = [];
			foreach ( $targets as $targetSpec ) {
				// Target specification may also contain user, who should be used to create pages on target wiki
				if ( strpos( $targetSpec, '=' ) ) {
					list( $targetName, $selectedUser ) = explode( '=', $targetSpec );
				} else {
					$targetName = $targetSpec;
					$selectedUser = null;
				}

				$target = $this->targetManager->getTarget( $targetName );
				if ( !$target ) {
					$this->output( "Target '$targetName' was not found!\n" );
					continue;
				}

				if ( $selectedUser !== null ) {
					$target->selectUser( $selectedUser );
				}

				$this->targets[$targetName] = $target;
			}
		}

		// Set category filter data
		if ( $config['category'] ) {
			$this->filterData['category'] = $config['category'];
		}

		// Set namespace filter data
		if ( $config['namespace'] ) {
			$this->filterData['namespace'] = $config['namespace'];
		}

		// Set specific pages to transfer
		if ( $config['pages'] ) {
			$pages = $config['pages'];
			if ( !is_array( $pages ) ) {
				$pages = explode( ',', $pages );
			}

			$titles = [];
			foreach ( $pages as $pageName ) {
				$titles[] = Title::newFromText( $pageName );
			}

			$this->titles = $titles;
		}

		// Set context user
		if ( $config['user'] ) {
			$userFactory = MediaWikiServices::getInstance()->getUserFactory();
			$this->user = $userFactory->newFromName( $config['user'] );
		} elseif ( !$this->user ) {
			// If user was set in JSON and was not passed as CLI param - we will leave keep it
			// But if user was not passed at all - use maintenance one
			$this->user = User::newSystemUser( 'MediaWiki default' );
		}

		if ( $config['onlyModified'] ) {
			$this->modificationData['onlyModified'] = true;
		}

		if ( $config['modifiedSince'] ) {
			$this->modificationData['modifiedSince'] = PageProvider::getModifiedSinceDate(
				$config['modifiedSince']
			);
		}

		if ( $config['includeRelated'] ) {
			$this->includeRelated = true;
		}

		if ( $config['force'] ) {
			$this->force = true;
		}
	}

	/**
	 * Do specific checks and decide if transfer can be done.
	 *
	 * @return bool <tt>true</tt> if transfer can be done, <tt>false</tt> otherwise
	 */
	private function canTransfer(): bool {
		// We cannot transfer content to targets, if there are no targets specified
		if ( !$this->targets ) {
			$this->output( "No targets specified, cannot start transfer.\n" );
			return false;
		}

		// We cannot transfer pages, if we cannot filter out necessary pages.
		// Either pages should be specified or filter parameters should be set
		if ( !isset( $this->filterData['namespace'] ) && !isset( $this->filterData['category'] ) && !$this->titles ) {
			$this->output( "No pages to transfer can be found. " .
				"There should be either explicit pages list ('pages' option)" .
				"or filtering conditions set ('namespace' and/or 'category' options)\n" );
			return false;
		}

		return true;
	}

	/**
	 * Filter out necessary titles to transfer.
	 * If wiki pages are explicitly specified by 'pages' option - no filtering will be done.
	 *
	 * @return Title[] List of title objects
	 */
	private function getTitles(): array {
		// If specific pages were passed explicitly - then we don't need filtering by category or namespace
		// But there still should be filtering by "modified since" and/or "only modified", if they were passed
		// Such filtering will happen before actual transferring
		if ( $this->titles ) {
			return $this->titles;
		}

		/** @var PageProvider $pageProvider */
		$pageProvider = MediaWikiServices::getInstance()->getService(
			'ContentTransferPageProvider'
		);

		/** @var PageFilterFactory $filterFactory */
		$filterFactory = MediaWikiServices::getInstance()->getService( 'ContentTransferPageFilterFactory' );
		$pageProvider->setFilters( $filterFactory->getFilters(), $this->filterData );

		$pageProvider->execute();

		return $pageProvider->getPages();
	}

	/**
	 * Transfer specified title to specified target wiki
	 *
	 * @param Title $title
	 * @param Target $target
	 *
	 * @return void
	 */
	private function transferTitle( Title $title, Target $target ): void {
		$pushHistory = new PushHistory( $title, $this->user, $target->getDisplayText() );
		$pagePusher = new PagePusher(
			$title,
			$target,
			$pushHistory,
			$this->force,
			$this->ignoreInsecureSsl
		);

		$pagePusher->push();

		$status = $pagePusher->getStatus();
		if ( $status->getErrors() ) {
			$this->output( "Transfer failed. Errors:\n" );
			$this->output( "{$status->getMessage()}\n\n" );
			return;
		}

		$this->output( "Transfer done.\n" );
	}

	/**
	 * Decides if specified title should be transferred, depending on "modified since" and "only modified" parameters.
	 *
	 * Copy of {@link PageContentProvider::shouldPush()}.
	 * It is needed here for convenience.
	 *
	 * We get all related titles and
	 * then in cycle for each target wiki decide if we need to transfer that related title.
	 * So, as soon as we anyway iterate through related titles and
	 * decide if we need to transfer them - it is easier with this method.
	 *
	 * @param array $modificationData May have two parameters:
	 * 		"modified since" - title will be transferred only if it was modified since specific date.
	 * 				Date should be formatted by {@link PageProvider::getModifiedSinceDate()} before passing here.
	 * 		"only modified" - title will be transferred only if it was modified since last transfer.
	 * @param Title $title Specified title
	 * @param string $target Target wiki key
	 * @return bool <tt>true</tt> if title should be transferred, <tt>false</tt> otherwise
	 */
	private function shouldTransfer( array $modificationData, Title $title, string $target ): bool {
		if ( isset( $modificationData['modifiedSince'] ) ) {
			$revision = MediaWikiServices::getInstance()->getRevisionStore()->getRevisionByTitle( $title );
			if (
				$revision &&
				$revision->getTimestamp() &&
				$revision->getTimestamp() >= $modificationData['modifiedSince']
			) {
				return false;
			}
		}

		if ( isset( $modificationData['onlyModified'] ) && $modificationData['onlyModified'] ) {
			$pushHistory = new PushHistory( $title, $this->user, $target );
			return $pushHistory->isChangedSinceLastPush();
		}

		return true;
	}
}

$maintClass = ExecuteTransfer::class;
require_once RUN_MAINTENANCE_IF_MAIN;

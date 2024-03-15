<?php

namespace ContentTransfer\PageFilter;

use Config;
use ContentTransfer\IPageFilter;
use IContextSource;
use Language;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\LoadBalancer;

class NamespaceFilter implements IPageFilter {
	/** @var Language */
	protected $lang;
	/** @var Config */
	protected $config;
	/** @var IContextSource */
	protected $context;

	/**
	 * @param LoadBalancer $lb
	 * @param IContextSource $context
	 * @return static
	 */
	public static function factory( LoadBalancer $lb, IContextSource $context ) {
		return new static(
			$context,
			$context->getLanguage(),
			MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'bsg' )
		);
	}

	/**
	 * @param IContextSource $context
	 * @param Language $language
	 * @param Config $config
	 */
	public function __construct( IContextSource $context, Language $language, Config $config ) {
		$this->lang = $language;
		$this->config = $config;
		$this->context = $context;
	}

	/**
	 * @inheritDoc
	 */
	public function getId() {
		return 'namespace';
	}

	/**
	 * @inheritDoc
	 */
	public function getDisplayName() {
		return $this->context->msg( 'contenttransfer-namespace-filter-input-label' )->text();
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
		return 'contentTransfer.widget.NamespaceFilter';
	}

	/**
	 * @inheritDoc
	 */
	public function getWidgetData() {
		// Get only content namespaces. Other NSs can be transferred,
		// but cannot be base for transferring
		$onlyContent = $this->config->get( 'ContentTransferOnlyContentNamespaces' );
		$allowTalk = $this->config->get( 'ContentTransferAllowTalkNamespaces' );

		$namespaceInfo = MediaWikiServices::getInstance()->getNamespaceInfo();
		$namespaceIds = array_unique( $namespaceInfo->getValidNamespaces() );
		if ( $onlyContent ) {
			$namespaceIds = array_unique( $namespaceInfo->getContentNamespaces() );
		} elseif ( !$allowTalk ) {
			$notTalk = [];
			foreach ( $namespaceIds as $id ) {
				if ( $namespaceInfo->isTalk( $id ) ) {
					continue;
				}
				$notTalk[] = $id;
			}
			$namespaceIds = $notTalk;
		}

		$namespaces = [];
		foreach ( $namespaceIds as $namespaceId ) {
			$namespaceText = $this->lang->getNsText( $namespaceId );
			if ( $namespaceId === NS_MAIN ) {
				$namespaceText = $this->context->msg( 'contenttransfer-ns-main' )->text();
			}
			$namespaces[] = [
				'id' => $namespaceId,
				'text' => $namespaceText
			];
		}

		return [
			'optionData' => $namespaces
		];
	}

	/**
	 * @inheritDoc
	 */
	public function modifyTables( &$tables ) {
		// NOOP
	}

	/**
	 * @inheritDoc
	 */
	public function modifyJoins( &$joins ) {
		// NOOP
	}

	/**
	 * @inheritDoc
	 */
	public function modifyConds( $filterData, &$conds ) {
		if ( isset( $filterData['namespace'] ) && $filterData['namespace'] !== false ) {
			$dbr = MediaWikiServices::getInstance()
				->getDBLoadBalancer()
				->getConnection( DB_REPLICA );
			$pageTableName = $dbr->tableName( 'page' );
			$conds[] = "$pageTableName.page_namespace = " . (int)$filterData['namespace'];
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getPriority() {
		return 20;
	}
}

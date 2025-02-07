<?php

namespace ContentTransfer\PageFilter;

use ContentTransfer\IPageFilter;
use Language;
use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Title\NamespaceInfo;
use Wikimedia\Rdbms\ILoadBalancer;

class NamespaceFilter implements IPageFilter {

	/** @var IContextSource */
	protected $context = null;

	/**
	 * @param ILoadBalancer $lb
	 * @param Language $lang
	 * @param Config $config
	 * @param NamespaceInfo $nsInfo
	 */
	public function __construct(
		private readonly ILoadBalancer $lb,
		private readonly Language $lang,
		private readonly Config $config,
		private readonly NamespaceInfo $nsInfo
	) {
	}

	/**
	 * @param IContextSource $context
	 * @return void
	 */
	public function setContextSource( IContextSource $context ): void {
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

		$namespaceIds = array_unique( $this->nsInfo->getValidNamespaces() );
		if ( $onlyContent ) {
			$namespaceIds = array_unique( $this->nsInfo->getContentNamespaces() );
		} elseif ( !$allowTalk ) {
			$notTalk = [];
			foreach ( $namespaceIds as $id ) {
				if ( $this->nsInfo->isTalk( $id ) ) {
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
			$dbr = $this->lb->getConnection( DB_REPLICA );
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

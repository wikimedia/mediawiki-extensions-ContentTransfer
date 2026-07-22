<?php

namespace ContentTransfer;

use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikimedia\Rdbms\ILoadBalancer;

class PushHistory {

	/**
	 * @param Title $title
	 * @param User $user
	 * @param string $target
	 * @param ILoadBalancer $lb
	 * @param RevisionLookup $revisionLookup
	 */
	public function __construct(
		private readonly Title $title,
		private readonly User $user,
		private readonly string $target,
		private readonly ILoadBalancer $lb,
		private readonly RevisionLookup $revisionLookup
	) {
	}

	/**
	 * Insert tracking
	 */
	public function insert() {
		if ( $this->getLastPush() ) {
			$this->doUpdate();
		} else {
			$this->doInsert();
		}
	}

	/**
	 * Is page changed since the last time it was pushed
	 *
	 * @return bool
	 */
	public function isChangedSinceLastPush() {
		$lastPush = $this->getLastPush();
		if ( !$lastPush ) {
			// Never been pushed before
			return true;
		}

		$revision = $this->revisionLookup->getRevisionByTitle( $this->title );
		if (
			$revision &&
			$revision->getTimestamp() &&
			$revision->getTimestamp() > $lastPush->ph_timestamp
		) {
			return true;
		}

		return false;
	}

	/**
	 * @return \stdClass|false
	 */
	protected function getLastPush() {
		return $this->lb->getConnection( DB_REPLICA )->newSelectQueryBuilder()
			->from( 'push_history' )
			->select( [ '*' ] )
			->where( [
				'ph_page' => $this->title->getArticleID(),
				'ph_target' => $this->target
			] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	/**
	 * @return bool
	 */
	protected function doUpdate(): bool {
		$this->lb->getConnection( DB_PRIMARY )->newUpdateQueryBuilder()
			->update( 'push_history' )
			->set( [
				'ph_user' => $this->user->getId(),
				'ph_timestamp' => wfTimestamp( TS_MW )
			] )
			->where( [
				'ph_page' => $this->title->getArticleID(),
				'ph_target' => $this->target
			] )
			->caller( __METHOD__ )
			->execute();
		return true;
	}

	/**
	 * @return true
	 */
	protected function doInsert() {
		$this->lb->getConnection( DB_PRIMARY )->newInsertQueryBuilder()
			->insertInto( 'push_history' )
			->row( [
				'ph_page' => $this->title->getArticleID(),
				'ph_user' => $this->user->getId(),
				'ph_target' => $this->target,
				'ph_timestamp' => wfTimestamp( TS_MW )

			] )
			->caller( __METHOD__ )
			->execute();
		return true;
	}
}

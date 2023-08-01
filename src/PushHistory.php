<?php

namespace ContentTransfer;

use MediaWiki\MediaWikiServices;
use Title;
use User;
use Wikimedia\Rdbms\IDatabase;

class PushHistory {

	/**
	 * @var IDatabase
	 */
	protected $db;

	/**
	 * @var Title
	 */
	protected $title;

	/**
	 * @var User
	 */
	protected $user;

	/** @var string */
	protected $target;

	/**
	 *
	 * @param Title $title
	 * @param User $user
	 * @param string $target
	 */
	public function __construct( Title $title, User $user, $target ) {
		$this->title = $title;
		$this->user = $user;
		$this->target = $target;

		$this->db = wfGetDB( DB_MASTER );
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

		$revision = MediaWikiServices::getInstance()->getRevisionStore()
			->getRevisionByTitle( $this->title );
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
	 *
	 * @return \stdClass|false
	 */
	protected function getLastPush() {
		return $this->db->selectRow(
			'push_history',
			'*',
			[
				'ph_page' => $this->title->getArticleID(),
				'ph_target' => $this->target
			]
		);
	}

	protected function doUpdate() {
		$res = $this->db->update(
			'push_history',
			[
				'ph_user' => $this->user->getId(),
				'ph_timestamp' => wfTimestamp( TS_MW )
			],
			[
				'ph_page' => $this->title->getArticleID(),
				'ph_target' => $this->target
			]
		);

		if ( $res ) {
			return true;
		}
		return false;
	}

	protected function doInsert() {
		$res = $this->db->insert(
			'push_history',
			[
				'ph_page' => $this->title->getArticleID(),
				'ph_user' => $this->user->getId(),
				'ph_target' => $this->target,
				'ph_timestamp' => $this->db->timestamp( wfTimestamp() )
			]
		);

		if ( $res ) {
			return true;
		}
		return false;
	}
}

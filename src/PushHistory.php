<?php

namespace ContentTransfer;

use Database;
use Title;
use User;

class PushHistory {

	/**
	 * @var Database
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
		if( $this->getLastPush() ) {
			$this->doUpdate();
		} else {
			$this->doInsert();
		}
	}

	/**
	 * Is page changed since the last time it was pushed
	 *
	 * @return boolean
	 */
	public function isChangedSinceLastPush() {
		$lastPush = $this->getLastPush();
		if( !$lastPush ) {
			// Never been pushed before
			return true;
		}

		$touched = $this->title->getTouched();

		if( $touched > $lastPush->ph_timestamp ) {
			return true;
		}
		return false;
	}

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

		if( $res ) {
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

		if( $res ) {
			return true;
		}
		return false;
	}
}

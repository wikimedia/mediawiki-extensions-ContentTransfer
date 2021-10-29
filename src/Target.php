<?php

namespace ContentTransfer;

class Target {
	/** @var string */
	private $url;
	/** @var array */
	private $users;
	/** @var string */
	private $draftNamespace;
	/** @var bool */
	private $pushToDraft;
	/** @var string */
	private $displayText;
	/** @var array */
	private $selectedUser;

	/**
	 * @param array $data
	 * @return static
	 */
	public static function newFromData( $data ) {
		$users = [];
		if ( isset( $data['users'] ) ) {
			$users = $data['users'];
		} elseif ( isset( $data['user'] ) && isset( $data['password' ] ) ) {
			$users[] = [
				'user' => $data['user'],
				'password' => $data['password']
			];
		}
		$draftNs = isset( $data['draftNamespace'] ) ? $data['draftNamespace'] : '';
		$pushToDraft = isset( $data['pushToDraft'] ) ? (bool)$data['pushToDraft'] : false;
		$displayText = isset( $data['displayText'] ) ? $data['displayText'] : '';

		return new static( $data['url'], $users, $draftNs, $pushToDraft, $displayText );
	}

	/**
	 * @param string $url
	 * @param string $users
	 * @param string|null $draftNamespace
	 * @param bool|null $pushToDraft
	 * @param string|null $displayText
	 */
	public function __construct(
		$url, $users, $draftNamespace = '', $pushToDraft = false, $displayText = ''
	) {
		$this->url = $url;
		$this->users = $users;
		$this->draftNamespace = $draftNamespace;
		$this->pushToDraft = $pushToDraft;
		$this->displayText = $displayText;
	}

	/**
	 * @return string
	 */
	public function getUrl() {
		return $this->url;
	}

	/**
	 * @return array
	 */
	public function getUsers() {
		return $this->users;
	}

	/**
	 * @return string
	 */
	public function getDraftNamespace() {
		return $this->draftNamespace;
	}

	/**
	 * @return bool
	 */
	public function shouldPushToDraft() {
		return $this->pushToDraft;
	}

	/**
	 * @return string
	 */
	public function getDisplayText() {
		return $this->displayText;
	}

	/**
	 * @param string $user
	 */
	public function selectUser( $user ) {
		foreach ( $this->users as $userData ) {
			if ( $userData['user'] === $user ) {
				$this->selectedUser = $userData;
				return;
			}
		}
	}

	/**
	 * @return array
	 */
	public function getSelectedUser() {
		if ( $this->selectedUser ) {
			return $this->selectedUser;
		}

		return $this->users[0];
	}
}

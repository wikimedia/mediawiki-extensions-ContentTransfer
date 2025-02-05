<?php

namespace ContentTransfer;

class Target {
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
		$draftNs = $data['draftNamespace'] ?? '';
		$pushToDraft = isset( $data['pushToDraft'] ) && $data['pushToDraft'];
		$displayText = $data['displayText'] ?? '';

		return new static( $data['url'], $users, $draftNs, $pushToDraft, $displayText );
	}

	/**
	 * @param string $url
	 * @param array $users
	 * @param string $draftNamespace
	 * @param bool|null $pushToDraft
	 * @param string $displayText
	 */
	public function __construct(
		private readonly string $url,
		private readonly array $users,
		private readonly string $draftNamespace = '',
		private readonly bool $pushToDraft = false,
		private readonly string $displayText = ''
	) {
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

<?php

namespace ContentTransfer;

use ContentTransfer\TargetAuthenticator\BotPassword;
use ContentTransfer\TargetAuthenticator\StaticAccessToken;

class Target implements \JsonSerializable {

	/**
	 * @param string $key
	 * @param array $data
	 * @return static
	 */
	public static function newFromData( string $key, array $data ) {
		if ( isset( $data['access_token'] ) ) {
			$authenticator = new StaticAccessToken( $data['access_token'] );
		} elseif ( isset( $data['users'] ) ) {
			$users = $data['users'];
			$authenticator = new BotPassword( $users );
		} elseif ( isset( $data['user'] ) && isset( $data['password' ] ) ) {
			$authenticator = new BotPassword( [ [
				'user' => $data['user'],
				'password' => $data['password']
			] ] );
		} else {
			throw new \InvalidArgumentException( 'No target authentication defined for ' . $key );
		}
		$draftNs = $data['draftNamespace'] ?? '';
		$pushToDraft = isset( $data['pushToDraft'] ) && $data['pushToDraft'];
		$displayText = $data['displayText'] ?? '';

		return new static( $key, $data['url'], $authenticator, $draftNs, $pushToDraft, $displayText );
	}

	/**
	 * @param string $key
	 * @param string $url
	 * @param ITargetAuthentication $authentication
	 * @param string $draftNamespace
	 * @param bool|null $pushToDraft
	 * @param string $displayText
	 */
	public function __construct(
		private readonly string $key,
		private readonly string $url,
		private readonly ITargetAuthentication $authentication,
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
	 * @return ITargetAuthentication
	 */
	public function getAuthentication(): ITargetAuthentication {
		return $this->authentication;
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
	 * @return mixed
	 */
	public function jsonSerialize(): mixed {
		return [
			'url' => $this->getUrl(),
			'pushToDraft' => $this->shouldPushToDraft(),
			'draftNamespace' => $this->getDraftNamespace(),
			'displayText' => $this->getDisplayText() ?: $this->key,
			'authentication' => $this->authentication,
		];
	}
}

<?php

namespace ContentTransfer;

class TargetManager {
	/** @var Target[] */
	private $targets = [];

	/**
	 * @param array $data
	 */
	public function __construct( $data ) {
		foreach ( $data as $key => $targetData ) {
			$this->targets[$key] = Target::newFromData( $targetData );
		}
	}

	/**
	 * @param string $key
	 * @return Target|null
	 */
	public function getTarget( $key ) {
		if ( !isset( $this->targets[$key] ) ) {
			return null;
		}
		return $this->targets[$key];
	}

	/**
	 * @return array
	 */
	public function getTargetsForClient() {
		$pushTargetsForClient = [];
		foreach ( $this->targets as $key => $target ) {
			$config = [
				'url' => $target->getUrl(),
				'pushToDraft' => $target->shouldPushToDraft(),
				'draftNamespace' => $target->getDraftNamespace(),
				'displayText' => $target->getDisplayText() ?: $key,
				'users' => [],
			];

			foreach ( $target->getUsers() as $userConfig ) {
				$config['users'][] = $userConfig['user'];
			}

			$pushTargetsForClient[$key] = $config;
		}

		return $pushTargetsForClient;
	}
}

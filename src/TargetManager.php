<?php

namespace ContentTransfer;

use MediaWiki\Config\Config;

class TargetManager {
	/** @var Target[] */
	private $targets = null;

	/**
	 * @param Config $config
	 */
	public function __construct(
		private readonly Config $config
	) {
	}

	/**
	 * @param string $key
	 * @return Target|null
	 */
	public function getTarget( $key ) {
		$this->assertLoaded();
		if ( !isset( $this->targets[$key] ) ) {
			return null;
		}
		return $this->targets[$key];
	}

	/**
	 * @return array
	 */
	public function getTargets() {
		$this->assertLoaded();
		return $this->targets;
	}

	/**
	 * @return void
	 */
	private function assertLoaded() {
		if ( $this->targets === null ) {
			$this->targets = [];
			$data = $this->config->get( 'ContentTransferTargets' );
			foreach ( $data as $key => $targetDefinition ) {
				$this->targets[$key] = Target::newFromData( $key, $targetDefinition );
			}
		}
	}
}

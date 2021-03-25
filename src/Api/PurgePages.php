<?php

namespace ContentTransfer\Api;

use ContentTransfer\PagePurger;
use Title;

class PurgePages extends PushSingle {
	/** @var Title[] */
	protected $titles;

	public function execute() {
		$this->isAuthorized();
		$this->readInParameters();
		$this->doPurge();
	}

	protected function getAllowedParams() {
		return [
			'titles' => [
				static::PARAM_TYPE => 'string',
				static::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-titles',
			],
			'pushTarget' => [
				static::PARAM_TYPE => 'string',
				static::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-pushtarget',
			]
		];
	}

	protected function getParameterFromSettings( $paramName, $paramSettings, $parseLimit ) {
		$value = parent::getParameterFromSettings( $paramName, $paramSettings, $parseLimit );
		if ( $paramName === 'titles' ) {
			return explode( '|', $value );
		}
		return $value;
	}

	protected function readInParameters() {
		$target = $this->getParameter( 'pushTarget' );
		$this->setPushTarget( $target );
		$this->titles = $this->getParameter( 'titles' );
	}

	protected function doPurge() {
		$purger = new PagePurger(
			$this->titles,
			$this->target,
			$this->getConfig()->get( 'ContentTransferIgnoreInsecureSSL' )
		);
		$purger->purge();
	}

}

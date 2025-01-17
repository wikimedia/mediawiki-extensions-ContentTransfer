<?php

namespace ContentTransfer\Api;

use ContentTransfer\PagePurger;
use MediaWiki\Title\Title;
use Wikimedia\ParamValidator\ParamValidator;

class PurgePages extends PushSingle {
	/** @var Title[] */
	protected $titles;

	public function execute() {
		$this->isAuthorized();
		$this->readInParameters();
		$this->doPurge();
	}

	/**
	 *
	 * @return array
	 */
	protected function getAllowedParams() {
		return [
			'titles' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-titles',
			],
			'pushTarget' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
				static::PARAM_HELP_MSG => 'contenttransfer-apihelp-param-pushtarget',
			]
		];
	}

	/**
	 * Using the settings determine the value for the given parameter
	 *
	 * @param string $paramName Parameter name
	 * @param array|mixed $paramSettings Default value or an array of settings
	 *  using PARAM_* constants.
	 * @param bool $parseLimit Whether to parse and validate 'limit' parameters
	 * @return mixed Parameter value
	 */
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
			$this->requestHandlerFactory,
			$this->titles,
			$this->target,
			$this->getConfig()->get( 'ContentTransferIgnoreInsecureSSL' )
		);
		$purger->purge();
	}

}

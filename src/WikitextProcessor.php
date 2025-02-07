<?php

namespace ContentTransfer;

use MediaWiki\Language\Language;
use NamespaceInfo;

class WikitextProcessor {

	/**
	 * @var NamespaceInfo
	 */
	private $namespaceInfo;

	/**
	 * @var Language
	 */
	private $wikiLanguage;

	/**
	 * @param NamespaceInfo $namespaceInfo
	 * @param Language $wikiLanguage
	 */
	public function __construct( NamespaceInfo $namespaceInfo, Language $wikiLanguage ) {
		$this->namespaceInfo = $namespaceInfo;
		$this->wikiLanguage = $wikiLanguage;
	}

	/**
	 * Looks for all internal links in the wikitext like "[[File:Somefile.pdf]]" or "[[Datei:Somefile.pdf]]"
	 * and replaces local namespaces names with their canonical names.
	 *
	 * So "[[Datei:Somefile.pdf]]" will be replaced with "[[File:Somefile.pdf]]" and so on.
	 *
	 * It is done for cases when, for example, page from German wiki is pushed to another language wiki.
	 * Without canonizing of internal links like "[[Datei:Somefile.pdf]]" - they will be broken on target wiki
	 * after transferring.
	 *
	 * @param string $content Page content
	 * @return string Page content after processing
	 */
	public function canonizeNamespacesInLinks( string $content ): string {
		$localNamespaces = $this->wikiLanguage->getNamespaces();

		$nsMap = [];
		foreach ( $localNamespaces as $nsId => $nsName ) {
			$nsCanonicalName = $this->namespaceInfo->getCanonicalName( $nsId );

			$nsMap[$nsName] = $nsCanonicalName;
		}

		return preg_replace_callback( "#\[\[(.*?)]]#", static function ( $matches ) use ( $nsMap ) {
			$internalWikiLink = $matches[1];

			$linkArr = explode( ':', $internalWikiLink );
			if ( count( $linkArr ) > 1 ) {
				// There is probably namespace prefix in the link
				if ( $linkArr[0] === '' ) {
					// It could be a link like "[[:File:SomeFile.pdf]]"
					$nsPos = 1;
				} else {
					// Link like "[[File:SomeFile.pdf]]"
					$nsPos = 0;
				}

				$nsLocalText = $linkArr[$nsPos];
				if ( isset( $nsMap[$nsLocalText] ) ) {
					// If that's local variant of namespace like "Datei" - then translate it to canonical
					$linkArr[$nsPos] = $nsMap[$nsLocalText];
				}

				$link = implode( ':', $linkArr );

				return '[[' . $link . ']]';
			}

			return $matches[0];
		}, $content );
	}
}

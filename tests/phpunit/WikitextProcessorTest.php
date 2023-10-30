<?php

namespace ContentTransfer\Tests;

use ContentTransfer\WikitextProcessor;
use MediaWiki\Languages\LanguageFactory;
use MediaWiki\MediaWikiServices;
use NamespaceInfo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ContentTransfer\WikitextProcessor
 */
class WikitextProcessorTest extends TestCase {

	/**
	 * @var NamespaceInfo
	 */
	private $namespaceInfo;

	/**
	 * @var LanguageFactory
	 */
	private $languageFactory;

	/**
	 * @inheritDoc
	 */
	protected function setUp(): void {
		$services = MediaWikiServices::getInstance();

		$this->namespaceInfo = $services->getNamespaceInfo();
		$this->languageFactory = $services->getLanguageFactory();
	}

	/**
	 * @return array
	 */
	public function provideData(): array {
		return [
			'Regular case with multiple different internal links' => [
				// Initial content
				'
[[Datei:SomeFile.png]]

[[Medium:Huh.pdf]]


[[:Datei:SomeFile2.png]]

[[:Medium:Huh2.pdf]]


[[File:SomeFile3.png]]

[[Media:Huh3.pdf]]


[[:File:SomeFile4.png]]

[[:Media:Huh4.pdf]]

[[Regular_Link]]',
				// Wiki language
				'de',
				// Content after replacements
				'
[[File:SomeFile.png]]

[[Media:Huh.pdf]]


[[:File:SomeFile2.png]]

[[:Media:Huh2.pdf]]


[[File:SomeFile3.png]]

[[Media:Huh3.pdf]]


[[:File:SomeFile4.png]]

[[:Media:Huh4.pdf]]

[[Regular_Link]]'
			]
		];
	}

	/**
	 * @dataProvider provideData
	 * @covers \ContentTransfer\WikitextProcessor::canonizeNamespacesInLinks
	 */
	public function testRegular(
		string $initialContent,
		string $wikiLanguageCode,
		string $expectedResult
	): void {
		$wikiLanguage = $this->languageFactory->getLanguage( $wikiLanguageCode );

		$wikitextProcessor = new WikitextProcessor( $this->namespaceInfo, $wikiLanguage );

		$actualResult = $wikitextProcessor->canonizeNamespacesInLinks( $initialContent );

		$this->assertEquals( $expectedResult, $actualResult );
	}
}

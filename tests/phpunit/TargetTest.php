<?php

namespace ContentTransfer\Tests;

use ContentTransfer\Target;
use ContentTransfer\TargetAuthenticator\BotPassword;
use ContentTransfer\TargetAuthenticator\StaticAccessToken;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ContentTransfer\Target
 */
class TargetTest extends TestCase {
	/**
	 * @param array $data
	 * @param array $expected
	 * @param string $expectedAuthClass
	 * @dataProvider provideData
	 */
	public function testTarget( $data, $expected, string $expectedAuthClass ) {
		$target = Target::newFromData( 'dummy', $data );

		$this->assertSame( $expected, [
			'url' => $target->getUrl(),
			'authentication' => $target->getAuthentication()->jsonSerialize(),
			'pushToDraft' => $target->shouldPushToDraft(),
			'draftNamespace' => $target->getDraftNamespace(),
			'displayText' => $target->getDisplayText(),
		] );
		$this->assertInstanceOf( $expectedAuthClass, $target->getAuthentication() );
	}

	/**
	 * @return array
	 */
	public static function provideData() {
		return [
			'single-user' => [
				'input' => [
					'url' => 'http://dummy',
					'user' => 'name@user',
					'password' => '3893883',
				],
				'expectedData' => [
					'url' => 'http://dummy',
					'authentication' => [ [
						'user' => 'name@user',
					] ],
					'pushToDraft' => false,
					'draftNamespace' => '',
					'displayText' => '',
				],
				BotPassword::class
			],
			'multi-user' => [
				'input' => [
					'url' => 'http://dummy',
					'users' => [ [
						'user' => 'name@user',
						'password' => '3893883',
					], [
						'user' => 'user2@user',
						'password' => '3893883asd',
					] ],
					'pushToDraft' => true,
					'draftNamespace' => 'Draft',
					'displayText' => 'Dummy'
				],
				'expectedData' => [
					'url' => 'http://dummy',
					'authentication' => [ [
						'user' => 'name@user',
					], [
						'user' => 'user2@user',
					] ],
					'pushToDraft' => true,
					'draftNamespace' => 'Draft',
					'displayText' => 'Dummy'
				],
				'expectedAuthClass' => BotPassword::class
			],
			'access-token' => [
				'input' => [
					'url' => 'http://dummy',
					'access_token' => 'abc123',
				],
				'expectedData' => [
					'url' => 'http://dummy',
					'authentication' => [],
					'pushToDraft' => false,
					'draftNamespace' => '',
					'displayText' => '',
				],
				'expectedAuthClass' => StaticAccessToken::class
			]
		];
	}
}

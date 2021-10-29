<?php

namespace ContentTransfer\Tests;

use ContentTransfer\Target;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ContentTransfer\Target
 */
class TargetTest extends TestCase {
	/**
	 * @param array $data
	 * @param array $expected
	 * @dataProvider provideData
	 */
	public function testTarget( $data, $expected ) {
		$target = Target::newFromData( $data );

		$this->assertSame( $expected, [
			'url' => $target->getUrl(),
			'users' => $target->getUsers(),
			'pushToDraft' => $target->shouldPushToDraft(),
			'draftNamespace' => $target->getDraftNamespace(),
			'displayText' => $target->getDisplayText()
		] );
	}

	public function provideData() {
		return [
			'single-user' => [
				[
					'url' => 'http://dummy',
					'user' => 'name@user',
					'password' => '3893883',
				],
				[
					'url' => 'http://dummy',
					'users' => [ [
						'user' => 'name@user',
						'password' => '3893883',
					] ],
					'pushToDraft' => false,
					'draftNamespace' => '',
					'displayText' => '',
				]
			],
			'multi-user' => [
				[
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
				[
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
				]
			]
		];
	}
}

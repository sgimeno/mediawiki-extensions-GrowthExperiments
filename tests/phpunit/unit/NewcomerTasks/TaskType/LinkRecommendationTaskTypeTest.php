<?php

namespace GrowthExperiments\Tests;

use GrowthExperiments\NewcomerTasks\TaskType\LinkRecommendationTaskType;
use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use MediaWiki\Json\JsonCodec;
use MediaWikiUnitTestCase;
use TitleValue;

/**
 * @covers \GrowthExperiments\NewcomerTasks\TaskType\LinkRecommendationTaskType
 */
class LinkRecommendationTaskTypeTest extends MediaWikiUnitTestCase {

	public function testJsonSerialization() {
		$codec = new JsonCodec();
		$taskType = new LinkRecommendationTaskType(
			'foo',
			TaskType::DIFFICULTY_MEDIUM,
			[ 'setting' => 'value' ],
			[ 'extra' => 'data' ],
			[
				new TitleValue( NS_TEMPLATE, 'Foo' ),
				new TitleValue( NS_TEMPLATE, 'Bar' ),
			],
			[
				new TitleValue( NS_CATEGORY, 'Foo' ),
				new TitleValue( NS_CATEGORY, 'Bar' ),
			]
		);
		$taskType2 = $codec->unserialize( $codec->serialize( $taskType ) );
		$this->assertEquals( $taskType, $taskType2 );
	}

}

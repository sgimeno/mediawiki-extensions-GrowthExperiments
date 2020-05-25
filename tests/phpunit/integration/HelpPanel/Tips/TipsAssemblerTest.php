<?php

namespace GrowthExperiments\Tests\Integration\HelpPanel\Tips;

use DerivativeContext;
use GrowthExperiments\HelpPanel\Tips\TipNodeRenderer;
use GrowthExperiments\HelpPanel\Tips\TipsAssembler;
use GrowthExperiments\NewcomerTasks\ConfigurationLoader\StaticConfigurationLoader;
use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use MediaWikiIntegrationTestCase;
use RequestContext;

/**
 * @coversDefaultClass \GrowthExperiments\HelpPanel\Tips\TipsAssembler
 */
class TipsAssemblerTest extends MediaWikiIntegrationTestCase {

	/**
	 * @dataProvider getTipsProvider
	 * @covers ::getTips
	 * @covers ::setMessageLocalizer
	 * @param array $config
	 * @param array $expected
	 */
	public function testGetTips( array $config, array $expected ) {
		if ( isset( $expected['expectedException'] ) ) {
			$this->expectException( $expected['expectedException'] );
		}
		$configurationLoader = new StaticConfigurationLoader( [ $config['tasktype'] ] );
		$tipsAssembler = new TipsAssembler(
			$configurationLoader,
			new TipNodeRenderer( '' )
		);
		$context = new DerivativeContext( RequestContext::getMain() );
		$tipsAssembler->setMessageLocalizer( $context );
		$tips = $tipsAssembler->getTips(
			$config['skin'],
			$config['editor'],
			$config['tasktype'],
			$config['dir']
		);
		$this->assertSame( $expected['tipCount'], count( $tips ) );
	}

	/**
	 * @return array
	 */
	public function getTipsProvider(): array {
		return [
			[
				[
					'tasktype' => new TaskType( 'copyedit', TaskType::DIFFICULTY_EASY ),
					'editor' => 'visualeditor',
					'skin' => 'vector',
					'dir' => 'ltr'
				],
				[
					'tipCount' => 6
				]
			],
			[
				[
					'tasktype' => new TaskType( 'foo', TaskType::DIFFICULTY_HARD ),
					'editor' => 'visualeditor',
					'skin' => 'vector',
					'dir' => 'ltr'
				],
				[
					'tipCount' => 0,
					'expectedException' => \LogicException::class
				]
			],
			[
				[
					'tasktype' => new TaskType( 'links', TaskType::DIFFICULTY_MEDIUM ),
					'editor' => 'foo',
					'skin' => 'vector',
					'dir' => 'ltr'
				],
				[
					'tipCount' => 6
				]
			],
			[
				[
					'tasktype' => new TaskType( 'references', TaskType::DIFFICULTY_HARD ),
					'editor' => 'foo',
					'skin' => 'bar',
					'dir' => 'ltr'
				],
				[
					'tipCount' => 7
				]
			],
			[
				[
					'tasktype' => new TaskType( 'expand', TaskType::DIFFICULTY_HARD ),
					'editor' => 'foo',
					'skin' => 'bar',
					'dir' => 'zxvzxc'
				],
				[
					'tipCount' => 6
				]
			],
			[
				[
					'tasktype' => new TaskType( 'update', TaskType::DIFFICULTY_EASY ),
					'editor' => 1,
					'skin' => 2,
					'dir' => 3
				],
				[
					'tipCount' => 6
				]
			]
		];
	}

}

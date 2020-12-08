<?php

namespace GrowthExperiments\Tests;

use GrowthExperiments\NewcomerTasks\TaskSuggester\ErrorForwardingTaskSuggester;
use GrowthExperiments\NewcomerTasks\TaskSuggester\LocalSearchTaskSuggester;
use GrowthExperiments\NewcomerTasks\TaskSuggester\LocalSearchTaskSuggesterFactory;
use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use GrowthExperiments\NewcomerTasks\TaskType\TaskTypeHandlerRegistry;
use GrowthExperiments\NewcomerTasks\Topic\Topic;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\User\UserIdentityValue;
use PHPUnit\Framework\MockObject\MockObject;
use SearchEngineFactory;
use StatusValue;

/**
 * @covers \GrowthExperiments\NewcomerTasks\TaskSuggester\LocalSearchTaskSuggesterFactory
 * @covers \GrowthExperiments\NewcomerTasks\TaskSuggester\SearchTaskSuggesterFactory
 * @covers \GrowthExperiments\NewcomerTasks\TaskSuggester\TaskSuggesterFactory
 * @covers \GrowthExperiments\NewcomerTasks\TaskSuggester\ErrorForwardingTaskSuggester
 */
class LocalSearchTaskSuggesterFactoryTest extends SearchTaskSuggesterFactoryTest {

	/**
	 * @dataProvider provideCreate
	 * @param TaskType[]|StatusValue $taskTypes
	 * @param Topic[]|StatusValue $topics
	 * @param LinkTarget[]|StatusValue $templateBlacklist
	 * @param StatusValue|null $expectedError
	 */
	public function testCreate( $taskTypes, $topics, $templateBlacklist, $expectedError ) {
		$taskTypeHandlerRegistry = $this->getTaskTypeHandlerRegistry();
		$configurationLoader = $this->getConfigurationLoader( $taskTypes, $topics, $templateBlacklist );
		$searchStrategy = $this->getSearchStrategy();
		$searchEngineFactory = $this->getSearchEngineFactory();
		$linkBatchFactory = $this->getLinkBatchFactory();
		$taskSuggesterFactory = new LocalSearchTaskSuggesterFactory( $taskTypeHandlerRegistry,
			$configurationLoader, $searchStrategy, $searchEngineFactory, $linkBatchFactory );
		$taskSuggester = $taskSuggesterFactory->create();
		if ( $expectedError ) {
			$this->assertInstanceOf( ErrorForwardingTaskSuggester::class, $taskSuggester );
			$error = $taskSuggester->suggest( new UserIdentityValue( 1, 'Foo', 1 ) );
			$this->assertInstanceOf( StatusValue::class, $error );
			$this->assertSame( $expectedError, $error );
		} else {
			$this->assertInstanceOf( LocalSearchTaskSuggester::class, $taskSuggester );
		}
	}

	/**
	 * @return TaskTypeHandlerRegistry|MockObject
	 */
	private function getTaskTypeHandlerRegistry() {
		return $this->createMock( TaskTypeHandlerRegistry::class );
	}

	/**
	 * @return SearchEngineFactory|MockObject
	 */
	private function getSearchEngineFactory() {
		return $this->createNoOpMock( SearchEngineFactory::class );
	}

	/**
	 * @return LinkBatchFactory|MockObject
	 */
	private function getLinkBatchFactory() {
		return $this->createNoOpMock( LinkBatchFactory::class );
	}

}

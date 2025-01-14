<?php

namespace GrowthExperiments\NewcomerTasks\TaskSuggester;

use ApiRawMessage;
use GrowthExperiments\NewcomerTasks\NewcomerTasksUserOptionsLookup;
use GrowthExperiments\NewcomerTasks\TaskSuggester\SearchStrategy\SearchQuery;
use GrowthExperiments\NewcomerTasks\TaskSuggester\SearchStrategy\SearchStrategy;
use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use GrowthExperiments\NewcomerTasks\TaskType\TaskTypeHandlerRegistry;
use GrowthExperiments\NewcomerTasks\Topic\Topic;
use IBufferingStatsdDataFactory;
use ISearchResultSet;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use SearchEngine;
use SearchEngineFactory;
use Status;
use StatusValue;
use Wikimedia\TestingAccessWrapper;

/**
 * Most of the search functionality is shared with RemoteSearchTaskSuggester via the parent class
 * and covered in RemoteSearchTaskSuggesterTest. This class only tests the non-shared bits.
 * @coversDefaultClass \GrowthExperiments\NewcomerTasks\TaskSuggester\LocalSearchTaskSuggester
 */
class LocalSearchTaskSuggesterTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideSearch
	 * @covers ::__construct
	 * @covers ::search
	 * @param string $searchTerm
	 * @param string|null $topic
	 * @param int $limit
	 * @param int $offset
	 * @param ISearchResultSet|null|StatusValue $searchResults
	 * @param ISearchResultSet|StatusValue $expectedResult
	 */
	public function testSearch(
		$searchTerm, $topic, $limit, $offset, $searchResults, $expectedResult
	) {
		$taskTypeHandlerRegistry = $this->getMockTaskTypeHandlerRegistry();
		$searchEngineFactory = $this->getMockSearchEngineFactory( $searchResults, $searchTerm,
			$limit, $offset );
		$searchStrategy = $this->getMockSearchStrategy();
		$newcomerTasksUserOptionsLookup = $this->getNewcomerTasksUserOptionsLookup();
		$linkBatchFactory = $this->getLinkBatchFactory();
		$suggester = new LocalSearchTaskSuggester( $taskTypeHandlerRegistry, $searchEngineFactory,
			$searchStrategy, $newcomerTasksUserOptionsLookup, $linkBatchFactory, [], [], $this->getStatsdFactory() );
		$wrappedSuggester = TestingAccessWrapper::newFromObject( $suggester );

		$taskType = new TaskType( 'fake; wont be used', TaskType::DIFFICULTY_EASY );
		$topic = $topic ? new Topic( $topic ) : null;
		$query = new SearchQuery( $this->getName(), $searchTerm, $taskType, $topic );

		$result = $wrappedSuggester->search( $query, $limit, $offset, false );
		if ( $expectedResult instanceof ApiRawMessage ) {
			$this->assertInstanceOf( StatusValue::class, $result );
			/** @var StatusValue $result */
			$this->assertNotEmpty( $result->getErrors() );
			$message = $result->getErrors()[0]['message'];
			$this->assertInstanceOf( ApiRawMessage::class, $message );
			/** @var ApiRawMessage $message */
			$this->assertSame( $expectedResult->getApiCode(), $message->getApiCode() );
		} else {
			$this->assertSame( $expectedResult, $result );
		}
	}

	public function provideSearch() {
		$searchResult = $this->getMockSearchResultSet();
		$error = Status::newFatal( 'foo' );
		return [
			'success' => [
				'search term' => 'hastemplate:foo morelikethis:bar',
				'topic' => 'bar',
				'limit' => 10,
				'offset' => 0,
				'search results' => $searchResult,
				'expected result' => $searchResult,
			],
			'success, no topics' => [
				'search term' => 'hastemplate:foo',
				'topic' => null,
				'limit' => 10,
				'offset' => 0,
				'search results' => $searchResult,
				'expected result' => $searchResult,
			],
			'success, wrapped' => [
				'search term' => 'hastemplate:foo morelikethis:bar',
				'topic' => 'bar',
				'limit' => 10,
				'offset' => 0,
				'search results' => Status::newGood( $searchResult ),
				'expected result' => $searchResult,
			],
			'error' => [
				'search term' => 'hastemplate:foo morelikethis:bar',
				'topic' => 'bar',
				'limit' => 10,
				'offset' => 0,
				'search results' => $error,
				'expected result' => $error,
			],
			'disabled' => [
				'search term' => 'hastemplate:foo morelikethis:bar',
				'topic' => 'bar',
				'limit' => 10,
				'offset' => 0,
				'search results' => null,
				'expected result' => new ApiRawMessage( '', 'grothexperiments-no-fulltext-search' ),
			],
		];
	}

	/**
	 * @return TaskTypeHandlerRegistry|MockObject
	 */
	private function getMockTaskTypeHandlerRegistry() {
		return $this->createMock( TaskTypeHandlerRegistry::class );
	}

	/**
	 * @return ISearchResultSet|MockObject
	 */
	private function getMockSearchResultSet() {
		return $this->getMockBuilder( ISearchResultSet::class )
			->onlyMethods( [] )
			->getMockForAbstractClass();
	}

	/**
	 * @param ISearchResultSet|null|StatusValue $searchResults
	 * @param string $expectedSearchText
	 * @param int $expectedLimit
	 * @param int $expectedOffset
	 * @return SearchEngineFactory|MockObject
	 */
	private function getMockSearchEngineFactory(
		$searchResults, $expectedSearchText, $expectedLimit, $expectedOffset
	) {
		$factory = $this->getMockBuilder( SearchEngineFactory::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'create' ] )
			->getMockForAbstractClass();

		$searchEngine = $this->getMockBuilder( SearchEngine::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'setLimitOffset', 'setNamespaces', 'setShowSuggestion', 'getValidSorts',
				'setSort', 'searchText' ] )
			->getMockForAbstractClass();
		$factory->expects( $this->once() )
			->method( 'create' )
			->willReturn( $searchEngine );

		$searchEngine->method( 'getValidSorts' )
			->willReturn( [ 'random' ] );
		$searchEngine->expects( $this->once() )
			->method( 'setLimitOffset' )
			->with( $expectedLimit, $expectedOffset );
		$searchEngine->expects( $this->once() )
			->method( 'searchText' )
			->with( $expectedSearchText )
			->willReturn( $searchResults );
		return $factory;
	}

	/**
	 * @return SearchStrategy|MockObject
	 */
	private function getMockSearchStrategy() {
		return $this->createNoOpMock( SearchStrategy::class );
	}

	/**
	 * @return NewcomerTasksUserOptionsLookup|MockObject
	 */
	private function getNewcomerTasksUserOptionsLookup() {
		$lookup = $this->createNoOpMock( NewcomerTasksUserOptionsLookup::class, [ 'filterTaskTypes' ] );
		$lookup->method( 'filterTaskTypes' )->willReturnArgument( 0 );
		return $lookup;
	}

	/**
	 * @return LinkBatchFactory|MockObject
	 */
	private function getLinkBatchFactory() {
		return $this->createNoOpMock( LinkBatchFactory::class );
	}

	/**
	 * @return IBufferingStatsdDataFactory|MockObject
	 */
	private function getStatsdFactory() {
		return $this->createMock( IBufferingStatsdDataFactory::class );
	}

}

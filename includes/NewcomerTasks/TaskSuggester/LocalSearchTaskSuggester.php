<?php

namespace GrowthExperiments\NewcomerTasks\TaskSuggester;

use ApiRawMessage;
use GrowthExperiments\NewcomerTasks\TaskSuggester\SearchStrategy\SearchQuery;
use GrowthExperiments\NewcomerTasks\TaskSuggester\SearchStrategy\SearchStrategy;
use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use GrowthExperiments\NewcomerTasks\TaskType\TaskTypeHandlerRegistry;
use GrowthExperiments\NewcomerTasks\Topic\Topic;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Linker\LinkTarget;
use SearchEngine;
use SearchEngineFactory;
use SpecialPage;
use StatusValue;

/**
 * Suggest edits based on searching the wiki via SearchEngine.
 */
class LocalSearchTaskSuggester extends SearchTaskSuggester {

	/** @var SearchEngineFactory */
	private $searchEngineFactory;

	/**
	 * @param TaskTypeHandlerRegistry $taskTypeHandlerRegistry
	 * @param SearchEngineFactory $searchEngineFactory
	 * @param SearchStrategy $searchStrategy
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param TaskType[] $taskTypes
	 * @param Topic[] $topics
	 * @param LinkTarget[] $templateBlacklist
	 */
	public function __construct(
		TaskTypeHandlerRegistry $taskTypeHandlerRegistry,
		SearchEngineFactory $searchEngineFactory,
		SearchStrategy $searchStrategy,
		LinkBatchFactory $linkBatchFactory,
		array $taskTypes,
		array $topics,
		array $templateBlacklist
	) {
		parent::__construct( $taskTypeHandlerRegistry, $searchStrategy, $linkBatchFactory,
			$taskTypes, $topics, $templateBlacklist );
		$this->searchEngineFactory = $searchEngineFactory;
	}

	/**
	 * @inheritDoc
	 */
	protected function search(
		SearchQuery $query,
		int $limit,
		int $offset,
		bool $debug
	) {
		$searchEngine = $this->searchEngineFactory->create();
		$searchEngine->setLimitOffset( $limit, $offset );
		$searchEngine->setNamespaces( [ NS_MAIN ] );
		$searchEngine->setShowSuggestion( false );
		$searchEngine->setFeatureData(
			SearchEngine::FT_QUERY_INDEP_PROFILE_TYPE,
			'classic_noboostlinks'
		);
		$sort = $query->getSort();
		if ( $sort ) {
			$searchEngine->setSort( $sort );
		}
		$matches = $searchEngine->searchText( $query->getQueryString() );
		if ( $matches instanceof StatusValue ) {
			if ( !$matches->isOK() ) {
				return $matches;
			} else {
				$matches = $matches->getValue();
			}
		}
		if ( !$matches ) {
			return StatusValue::newFatal( new ApiRawMessage(
				'Full text searches are unsupported or disabled',
				'grothexperiments-no-fulltext-search'
			) );
		}
		if ( $debug ) {
			$params = [
				'search' => $query->getQueryString(),
				'fulltext' => 1,
				'ns0' => 1,
				'limit' => $limit,
				'offset' => $offset,
				'cirrusRescoreProfile' => 'classic_noboostlinks',
				'cirrusDumpResult' => 1,
				'cirrusExplain' => 'pretty',
			];
			if ( $query->getSort() ) {
				$params['sort'] = $query->getSort();
			}
			$query->setDebugUrl( SpecialPage::getTitleFor( 'Search' )
				->getFullURL( $params, false, PROTO_CANONICAL ) );
		}
		return $matches;
	}

}

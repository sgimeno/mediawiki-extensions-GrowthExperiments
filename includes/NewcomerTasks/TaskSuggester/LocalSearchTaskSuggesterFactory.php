<?php

namespace GrowthExperiments\NewcomerTasks\TaskSuggester;

use GrowthExperiments\NewcomerTasks\ConfigurationLoader\ConfigurationLoader;
use GrowthExperiments\NewcomerTasks\TaskSuggester\SearchStrategy\SearchStrategy;
use GrowthExperiments\NewcomerTasks\TaskType\TaskTypeHandlerRegistry;
use MediaWiki\Cache\LinkBatchFactory;
use SearchEngineFactory;
use StatusValue;

/**
 * Factory for LocalSearchTaskSuggester.
 */
class LocalSearchTaskSuggesterFactory extends SearchTaskSuggesterFactory {

	/**
	 * @var SearchEngineFactory
	 */
	private $searchEngineFactory;

	/**
	 * @param TaskTypeHandlerRegistry $taskTypeHandlerRegistry
	 * @param ConfigurationLoader $configurationLoader
	 * @param SearchStrategy $searchStrategy
	 * @param SearchEngineFactory $searchEngineFactory
	 * @param LinkBatchFactory $linkBatchFactory
	 */
	public function __construct(
		TaskTypeHandlerRegistry $taskTypeHandlerRegistry,
		ConfigurationLoader $configurationLoader,
		SearchStrategy $searchStrategy,
		SearchEngineFactory $searchEngineFactory,
		LinkBatchFactory $linkBatchFactory
	) {
		parent::__construct(
			$taskTypeHandlerRegistry,
			$configurationLoader,
			$searchStrategy,
			$linkBatchFactory
		);
		$this->searchEngineFactory = $searchEngineFactory;
	}

	/**
	 * @return LocalSearchTaskSuggester|ErrorForwardingTaskSuggester
	 */
	public function create() {
		$taskTypes = $this->configurationLoader->loadTaskTypes();
		if ( $taskTypes instanceof StatusValue ) {
			return $this->createError( $taskTypes );
		}
		$topics = $this->configurationLoader->loadTopics();
		if ( $topics instanceof StatusValue ) {
			return $this->createError( $topics );
		}
		$templateBlacklist = $this->configurationLoader->loadTemplateBlacklist();
		if ( $templateBlacklist instanceof StatusValue ) {
			return $this->createError( $templateBlacklist );
		}
		$suggester = new LocalSearchTaskSuggester(
			$this->taskTypeHandlerRegistry,
			$this->searchEngineFactory,
			$this->searchStrategy,
			$this->linkBatchFactory,
			$taskTypes,
			$topics,
			$templateBlacklist
		);
		$suggester->setLogger( $this->logger );
		return $suggester;
	}

}

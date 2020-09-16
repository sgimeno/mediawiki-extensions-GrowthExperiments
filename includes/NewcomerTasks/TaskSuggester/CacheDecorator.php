<?php

namespace GrowthExperiments\NewcomerTasks\TaskSuggester;

use GrowthExperiments\NewcomerTasks\Task\TaskSet;
use GrowthExperiments\NewcomerTasks\Task\TaskSetFilters;
use MediaWiki\User\UserIdentity;
use WANObjectCache;

/**
 * A TaskSuggester decorator which uses WANObjectCache to get/set TaskSets.
 */
class CacheDecorator implements TaskSuggester {

	/** @var TaskSuggester */
	private $taskSuggester;

	/** @var WANObjectCache */
	private $cache;

	/**
	 * @param TaskSuggester $taskSuggester
	 * @param WANObjectCache $cache
	 */
	public function __construct(
		TaskSuggester $taskSuggester,
		WANObjectCache $cache
	) {
		$this->taskSuggester = $taskSuggester;
		$this->cache = $cache;
	}

	/** @inheritDoc */
	public function suggest(
		UserIdentity $user,
		array $taskTypeFilter = [],
		array $topicFilter = [],
		$limit = null,
		$offset = null,
		$debug = false
	) {
		$taskSetFilters = new TaskSetFilters( $taskTypeFilter, $topicFilter );

		if ( $debug || $limit > SearchTaskSuggester::DEFAULT_LIMIT ) {
			return $this->taskSuggester->suggest( $user, $taskTypeFilter, $topicFilter, $limit, $offset, $debug );
		}

		return $this->cache->getWithSetCallback(
			$this->cache->makeKey(
				'GrowthExperiments-NewcomerTasks-TaskSet',
				$user->getId()
			),
			$this->cache::TTL_WEEK,
			function ( $oldValue, &$ttl ) use ( $user, $taskSetFilters, $limit ) {
				// This callback is always invoked each time getWithSetCallback is called,
				// because we need to examine the contents of the cache (if any) before
				// deciding whether to return those contents or if they need to be regenerated.
				if ( $oldValue instanceof TaskSet && $oldValue->filtersEqual( $taskSetFilters ) ) {
					// &$ttl needs to be set to UNCACHEABLE so that WANObjectCache
					// doesn't attempt a set() after returning the existing value.
					$ttl = $this->cache::TTL_UNCACHEABLE;
					// Shuffle the contents again (they were shuffled when first placed into the
					// cache) and return only the subset of tasks that the requester asked for.
					$oldValue->randomSort();
					$oldValue->truncate( $limit );
					return $oldValue;
				}
				// We don't have a task set, or the taskset filters in the request don't match
				// what is stored in the cache. Call the search backend and return the results.
				// N.B. we cache whatever the taskSuggester returns, which could be a StatusValue,
				// so when retrieving items from the cache we need to check the type before assuming
				// we are working with a TaskSet.
				$result = $this->taskSuggester->suggest(
					$user,
					$taskSetFilters->getTaskTypeFilters(),
					$taskSetFilters->getTopicFilters(),
					SearchTaskSuggester::DEFAULT_LIMIT
				);
				if ( $result instanceof TaskSet && $result->count() ) {
					$result->randomSort();
				}
				return $result;
			},
			// minAsOf is used to reject values below the defined timestamp. By
			// settings minAsOf = INF (PHP's constant for the infinite), we are
			// telling WANObjectCache to always invoke the callback. See
			// callback comment for more on why.
			[ 'minAsOf' => INF ]
		);
	}
}
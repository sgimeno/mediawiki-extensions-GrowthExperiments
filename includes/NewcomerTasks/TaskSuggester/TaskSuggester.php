<?php

namespace GrowthExperiments\NewcomerTasks\TaskSuggester;

use GrowthExperiments\NewcomerTasks\Task\TaskSet;
use MediaWiki\User\UserIdentity;
use StatusValue;

/**
 * Service for getting task recommendations for inexperienced users.
 * @see https://www.mediawiki.org/wiki/Growth/Personalized_first_day/Newcomer_tasks
 */
interface TaskSuggester {

	/**
	 * @param UserIdentity $user
	 * @param string[] $taskTypeFilter List of task type IDs to limit the suggestions to.
	 *   An empty array means no filtering.
	 * @param string[] $topicFilter List of topic IDs to limit the suggestions to.
	 *   An empty array means no filtering.
	 * @param int|null $limit Number of suggestions to return.
	 * @param int|null $offset Offset within full result set, for continuation.
	 * @param bool $debug Debug mode; will return information about how the tasks were selected
	 * @param bool $useCache If the cache should be used when obtaining suggestions.
	 * @return TaskSet|StatusValue A set of suggestions or an error in the form of a
	 *   StatusValue.
	 */
	public function suggest(
		UserIdentity $user,
		array $taskTypeFilter = [],
		array $topicFilter = [],
		$limit = null,
		$offset = null,
		$debug = false,
		$useCache = true
	);

}

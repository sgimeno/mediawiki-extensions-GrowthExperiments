<?php

namespace GrowthExperiments\NewcomerTasks\TaskSuggester;

use GrowthExperiments\NewcomerTasks\Task\Task;
use GrowthExperiments\NewcomerTasks\Task\TaskSet;
use GrowthExperiments\NewcomerTasks\Task\TaskSetFilters;
use GrowthExperiments\NewcomerTasks\Topic\Topic;
use MediaWiki\User\UserIdentity;
use Wikimedia\Assert\Assert;

/**
 * A TaskSuggester which always starts with the same preconfigured set of tasks, and applies
 * filter/limit/offset to them. Intended for testing and local frontend development.
 */
class StaticTaskSuggester implements TaskSuggester {

	/** @var Task[] */
	private $tasks;

	/**
	 * @param Task[] $tasks
	 */
	public function __construct( array $tasks ) {
		Assert::parameterElementType( Task::class, $tasks, '$suggestions' );
		$this->tasks = $tasks;
	}

	/** @inheritDoc */
	public function suggest(
		UserIdentity $user,
		array $taskTypeFilter = [],
		array $topicFilter = [],
		$limit = null,
		$offset = null,
		$debug = false,
		$useCache = true
	) {
		$filteredTasks = array_filter( $this->tasks,
			function ( Task $task ) use ( $taskTypeFilter, $topicFilter ) {
				if ( $taskTypeFilter && !in_array( $task->getTaskType()->getId(), $taskTypeFilter, true ) ) {
					return false;
				} elseif ( $topicFilter && !array_intersect( $this->getTopicIds( $task ), $topicFilter ) ) {
					return false;
				}
				return true;
			}
		);
		return new TaskSet( array_slice( $filteredTasks, $offset, $limit ),
			count( $filteredTasks ), $offset ?: 0, new TaskSetFilters() );
	}

	/**
	 * @param Task $task
	 * @return string[]
	 */
	private function getTopicIds( Task $task ) {
		return array_map( function ( Topic $topic ) {
			return $topic->getId();
		}, $task->getTopics() );
	}

}

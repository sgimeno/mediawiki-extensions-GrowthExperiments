<?php

namespace GrowthExperiments\NewcomerTasks\ConfigurationLoader;

use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use GrowthExperiments\NewcomerTasks\Topic\Topic;
use StatusValue;

/**
 * Helper for retrieving task recommendation configuration.
 */
interface ConfigurationLoader {

	/**
	 * Load configured task types.
	 * @return TaskType[]|StatusValue Set of configured task types, or an error status.
	 */
	public function loadTaskTypes();

	/**
	 * Load configured topics.
	 * @return Topic[]|StatusValue
	 */
	public function loadTopics();

	/**
	 * Convenience method to get task types as an array of task type id => task type.
	 *
	 * If an error is generated while loading task types, an empty array is
	 * returned.
	 *
	 * @return TaskType[]
	 */
	public function getTaskTypes(): array;

	/**
	 * Convenience method to get topics as an array of topic id => topic.
	 *
	 * If an error is generated while loading, an empty array is returned.
	 *
	 * @return Topic[]
	 */
	public function getTopics(): array;

}

<?php

namespace GrowthExperiments\NewcomerTasks\ConfigurationLoader;

use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use GrowthExperiments\NewcomerTasks\Topic\Topic;
use MediaWiki\Linker\LinkTarget;
use MessageLocalizer;
use StatusValue;

/**
 * A minimal ConfigurationLoader for testing and development which returns preconfigured values.
 */
class StaticConfigurationLoader implements ConfigurationLoader {

	use ConfigurationLoaderTrait;

	/** @var TaskType[]|StatusValue */
	private $taskTypes;

	/** @var Topic[]|StatusValue */
	private $topics;

	/** @var LinkTarget[]|StatusValue */
	private $templateBlacklist;

	/**
	 * @param TaskType[]|StatusValue $taskTypes
	 * @param Topic[]|StatusValue $topics
	 * @param LinkTarget[]|StatusValue $templateBlacklist
	 */
	public function __construct( $taskTypes, $topics = [], $templateBlacklist = [] ) {
		$this->taskTypes = $taskTypes;
		$this->topics = $topics;
		$this->templateBlacklist = $templateBlacklist;
	}

	/** @inheritDoc */
	public function loadTaskTypes() {
		return $this->taskTypes;
	}

	/** @inheritDoc */
	public function loadTopics() {
		return $this->topics;
	}

	/** @inheritDoc */
	public function loadTemplateBlacklist() {
		return $this->templateBlacklist;
	}

	/** @inheritDoc */
	public function setMessageLocalizer( MessageLocalizer $messageLocalizer ): void {
	}

}

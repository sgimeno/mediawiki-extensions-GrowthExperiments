<?php

namespace GrowthExperiments\NewcomerTasks\TaskType;

use MediaWiki\Json\JsonUnserializer;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;

class LinkRecommendationTaskType extends TaskType {

	/** @see ::getMinimumTasksPerTopic */
	public const FIELD_MIN_TASKS_PER_TOPIC = 'minimumTasksPerTopic';
	/** @see :getMinimumLinksPerTask */
	public const FIELD_MIN_LINKS_PER_TASK = 'minimumLinksPerTask';
	/** @see :getMinimumLinkScore */
	public const FIELD_MIN_LINK_SCORE = 'minimumLinkScore';
	/** @see :getMaximumLinksPerTask */
	public const FIELD_MAX_LINKS_PER_TASK = 'maximumLinksPerTask';
	/** @see :getMinimumTimeSinceLastEdit */
	public const FIELD_MIN_TIME_SINCE_LAST_EDIT = 'minimumTimeSinceLastEdit';
	/** @see :getMinimumWordCount */
	public const FIELD_MIN_WORD_COUNT = 'minimumWordCount';
	/** @see :getMaximumWordCount */
	public const FIELD_MAX_WORD_COUNT = 'maximumWordCount';
	/** @see :getMaxTasksPerDay */
	public const FIELD_MAX_TASKS_PER_DAY = 'maxTasksPerDay';

	/** Exclude a (task page, target page) pair from future tasks after this many rejections. */
	public const REJECTION_EXCLUSION_LIMIT = 2;

	public const DEFAULT_SETTINGS = [
		self::FIELD_MIN_TASKS_PER_TOPIC => 500,
		self::FIELD_MIN_LINKS_PER_TASK => 2,
		self::FIELD_MIN_LINK_SCORE => 0.5,
		self::FIELD_MAX_LINKS_PER_TASK => 10,
		self::FIELD_MIN_TIME_SINCE_LAST_EDIT => ExpirationAwareness::TTL_DAY,
		self::FIELD_MIN_WORD_COUNT => 0,
		self::FIELD_MAX_WORD_COUNT => PHP_INT_MAX,
		self::FIELD_MAX_TASKS_PER_DAY => 25,
	];

	/** @inheritDoc */
	protected const IS_MACHINE_SUGGESTION = true;

	/** @var int */
	protected $minimumTasksPerTopic;
	/** @var int */
	protected $minimumLinksPerTask;
	/** @var float */
	protected $minimumLinkScore;
	/** @var int */
	protected $maximumLinksPerTask;
	/** @var int */
	protected $minimumTimeSinceLastEdit;
	/** @var int */
	protected $minimumWordCount;
	/** @var int */
	protected $maximumWordCount;
	/** @var int */
	protected $maxTasksPerDay;

	/**
	 * @inheritDoc
	 * @param array $settings A settings array matching LinkRecommendationTaskType::DEFAULT_SETTINGS.
	 */
	public function __construct(
		$id,
		$difficulty,
		array $settings = [],
		array $extraData = [],
		array $excludedTemplates = [],
		array $excludedCategories = []
	) {
		parent::__construct( $id, $difficulty, $extraData, $excludedTemplates, $excludedCategories );
		$settings += self::DEFAULT_SETTINGS;
		$this->minimumTasksPerTopic = $settings[self::FIELD_MIN_TASKS_PER_TOPIC];
		$this->minimumLinksPerTask = $settings[self::FIELD_MIN_LINKS_PER_TASK];
		$this->minimumLinkScore = $settings[self::FIELD_MIN_LINK_SCORE];
		$this->maximumLinksPerTask = $settings[self::FIELD_MAX_LINKS_PER_TASK];
		$this->minimumTimeSinceLastEdit = $settings[self::FIELD_MIN_TIME_SINCE_LAST_EDIT];
		$this->minimumWordCount = $settings[self::FIELD_MIN_WORD_COUNT];
		$this->maximumWordCount = $settings[self::FIELD_MAX_WORD_COUNT];
		$this->maxTasksPerDay = $settings[self::FIELD_MAX_TASKS_PER_DAY];
	}

	/**
	 * Try to have at least this many link recommendations prepared for each ORES topic.
	 * Recommendations are filled up every hour to this level.
	 * Note: these are individual ORES topics, not the combined Growth topics defined via
	 * $wgGENewcomerTasksOresTopicConfigTitle.
	 * @return int
	 */
	public function getMinimumTasksPerTopic(): int {
		return $this->minimumTasksPerTopic;
	}

	/**
	 * Each recommendation must contain at least this many suggested links.
	 * @return int
	 */
	public function getMinimumLinksPerTask(): int {
		return $this->minimumLinksPerTask;
	}

	/**
	 * Required confidence score for each link.
	 * @return float
	 */
	public function getMinimumLinkScore(): float {
		return $this->minimumLinkScore;
	}

	/**
	 * Don't show more than this many link recommendations at the same time.
	 * @return int
	 */
	public function getMaximumLinksPerTask(): int {
		return $this->maximumLinksPerTask;
	}

	/**
	 * At least this much time (in seconds) needs to have passed since the article was last edited.
	 * @return int
	 */
	public function getMinimumTimeSinceLastEdit(): int {
		return $this->minimumTimeSinceLastEdit;
	}

	/**
	 * Only use articles with this at least many words for link recommendations.
	 * (The word count will be some naive wikitext-based estimation.)
	 * @return int
	 */
	public function getMinimumWordCount(): int {
		return $this->minimumWordCount;
	}

	/**
	 * Only use articles with this at most many words for link recommendations.
	 * (The word count will be some naive wikitext-based estimation.)
	 * @return int
	 */
	public function getMaximumWordCount(): int {
		return $this->maximumWordCount;
	}

	/**
	 * The maximum number of image recommendation tasks that a user can perform each calendar day.
	 *
	 * @return int
	 */
	public function getMaxTasksPerDay(): int {
		return $this->maxTasksPerDay;
	}

	/** @inheritDoc */
	public function shouldOpenInEditMode(): bool {
		return true;
	}

	/** @inheritDoc */
	public function getDefaultEditSection(): string {
		return 'all';
	}

	/** @inheritDoc */
	public function getQualityGateIds(): array {
		return [ 'dailyLimit' ];
	}

	/** @inheritDoc */
	protected function toJsonArray(): array {
		return parent::toJsonArray() + [
				'settings' => [
					'minimumTasksPerTopic' => $this->minimumTasksPerTopic,
					'minimumLinksPerTask' => $this->minimumLinksPerTask,
					'minimumLinkScore' => $this->minimumLinkScore,
					'maximumLinksPerTask' => $this->maximumLinksPerTask,
					'minimumTimeSinceLastEdit' => $this->minimumTimeSinceLastEdit,
					'minimumWordCount' => $this->minimumWordCount,
					'maximumWordCount' => $this->maximumWordCount,
					'maxTasksPerDay' => $this->maxTasksPerDay,
				],
			];
	}

	/** @inheritDoc */
	public static function newFromJsonArray( JsonUnserializer $unserializer, array $json ) {
		$taskType = new LinkRecommendationTaskType(
			$json['id'],
			$json['difficulty'],
			$json['settings'],
			$json['extraData'],
			self::getExcludedTemplatesTitleValues( $json ),
			self::getExcludedCategoriesTitleValues( $json )
		);
		$taskType->setHandlerId( $json['handlerId'] );
		return $taskType;
	}

}

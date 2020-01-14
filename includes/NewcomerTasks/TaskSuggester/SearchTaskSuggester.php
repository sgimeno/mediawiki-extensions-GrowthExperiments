<?php

namespace GrowthExperiments\NewcomerTasks\TaskSuggester;

use GrowthExperiments\NewcomerTasks\Task\TaskSet;
use GrowthExperiments\NewcomerTasks\Task\TemplateBasedTask;
use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use GrowthExperiments\NewcomerTasks\TaskType\TemplateBasedTaskType;
use GrowthExperiments\NewcomerTasks\TemplateProvider;
use GrowthExperiments\NewcomerTasks\Topic\MorelikeBasedTopic;
use GrowthExperiments\NewcomerTasks\Topic\Topic;
use GrowthExperiments\Util;
use ISearchResultSet;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\User\UserIdentity;
use MultipleIterator;
// phpcs:ignore MediaWiki.Classes.UnusedUseStatement.UnusedUse
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
// phpcs:ignore MediaWiki.Classes.UnusedUseStatement.UnusedUse
use SearchResult;
use Status;
use StatusValue;

/**
 * Shared functionality for local and remote search.
 */
abstract class SearchTaskSuggester implements TaskSuggester, LoggerAwareInterface {

	use LoggerAwareTrait;

	const DEFAULT_LIMIT = 200;

	/** @var TemplateProvider */
	private $templateProvider;

	/** @var TaskType[] id => TaskType */
	protected $taskTypes = [];

	/** @var LinkTarget[] List of templates which disqualify a page from being recommendable. */
	protected $templateBlacklist;

	/** @var Topic[] id => Topic */
	protected $topics = [];

	/**
	 * @param TemplateProvider $templateProvider
	 * @param TaskType[] $taskTypes
	 * @param Topic[] $topics
	 * @param LinkTarget[] $templateBlacklist
	 */
	public function __construct(
		TemplateProvider $templateProvider,
		array $taskTypes,
		array $topics,
		array $templateBlacklist
	) {
		$this->templateProvider = $templateProvider;
		foreach ( $taskTypes as $taskType ) {
			$this->taskTypes[$taskType->getId()] = $taskType;
		}
		foreach ( $topics as $topic ) {
			$this->topics[$topic->getId()] = $topic;
		}
		$this->templateBlacklist = $templateBlacklist;
		$this->logger = new NullLogger();
	}

	/** @inheritDoc */
	public function suggest(
		UserIdentity $user,
		array $taskTypeFilter = null,
		array $topicFilter = null,
		$limit = null,
		$offset = null
	) {
		// FIXME these should apply user settings.
		$taskTypeFilter = $taskTypeFilter ?? array_keys( $this->taskTypes );
		$topicFilter = $topicFilter ?? [];

		// FIXME these and task types should have similar validation rules
		$topics = array_values( array_intersect_key( $this->topics, array_flip( $topicFilter ) ) );

		$limit = $limit ?? self::DEFAULT_LIMIT;
		// FIXME we are completely ignoring offset for now because 1) doing offsets when we are
		//   interleaving search results from multiple sources is hard, and 2) we are randomizing
		//   search results so offsets would not really be meaningful anyway.
		$offset = 0;

		$totalCount = 0;
		$matchIterator = new MultipleIterator( MultipleIterator::MIT_NEED_ANY |
			MultipleIterator::MIT_KEYS_ASSOC );
		foreach ( $taskTypeFilter as $taskTypeId ) {
			$taskType = $this->taskTypes[$taskTypeId] ?? null;
			if ( !$taskType ) {
				return StatusValue::newFatal( wfMessage( 'growthexperiments-newcomertasks-invalid-tasktype',
					$taskTypeId ) );
			} elseif ( !( $taskType instanceof TemplateBasedTaskType ) ) {
				$this->logger->notice( 'Invalid task type: {taskType}', [
					'taskType' => get_class( $taskType ),
				] );
				continue;
			}

			$searchTerm = $this->getSearchTerm( $taskType, $topics );
			$matches = $this->search( $searchTerm, $limit, $offset );
			if ( $matches instanceof StatusValue ) {
				// Only log when there's a logger; Status::getWikiText would break unit tests.
				if ( !$this->logger instanceof NullLogger ) {
					$this->logger->warning( 'Search error: {message}', [
						'message' => Status::wrap( $matches )->getWikiText( false, false, 'en' ),
						'searchTerm' => $searchTerm,
						'limit' => $limit,
						'offset' => $offset,
					] );
				}
				return $matches;
			}

			$totalCount += $matches->getTotalHits();
			$matchIterator->attachIterator( Util::getIteratorFromTraversable( $matches ), $taskTypeId );
		}

		$taskCount = 0;
		$suggestions = [];
		foreach ( $matchIterator as $matchSlice ) {
			foreach ( array_filter( $matchSlice ) as $type => $match ) {
				// TODO: Filter out pages that are protected.
				/** @var $match SearchResult */
				$taskType = $this->taskTypes[$type];
				$suggestions[] = new TemplateBasedTask( $taskType, $match->getTitle() );
				$taskCount++;
				if ( $taskCount >= $limit ) {
					break 2;
				}
			}
		}
		$this->templateProvider->fill( $suggestions );

		// search() implementations try to request random sorting; that breaks when a topic filter
		// is used (the mechanism used for topic filtering is itself a kind of sorting, and it
		// overrides random sorting). As a poor way of correcting for that, sort the result set.
		// This means we'll return a deterministic subset of the full result set, the same for all
		// requests which use identical task and topic filter settings, but at least the ordering
		// of that subset will be random. In the future, we might look for a better solution.
		if ( $topicFilter ) {
			shuffle( $suggestions );
		}

		return new TaskSet( $suggestions, $totalCount, $offset );
	}

	/**
	 * @param TemplateBasedTaskType $taskType
	 * @param Topic[] $topics
	 * @return string
	 */
	protected function getSearchTerm(
		TemplateBasedTaskType $taskType,
		array $topics
	) {
		$typeTerm = $this->getHasTemplateTerm( $taskType->getTemplates() );
		$topicTerm = $this->getTopicTerm( $topics );
		$deletionTerm = $this->templateBlacklist ?
			'-' . $this->getHasTemplateTerm( $this->templateBlacklist ) :
			'';

		return implode( ' ', array_filter( [ $typeTerm, $topicTerm, $deletionTerm ] ) );
	}

	/**
	 * @param string $searchTerm
	 * @param int $limit
	 * @param int $offset
	 * @return ISearchResultSet|StatusValue Search results, or StatusValue on error.
	 */
	abstract protected function search( $searchTerm, $limit, $offset );

	/**
	 * @param LinkTarget[] $templates
	 * @return string
	 */
	private function getHasTemplateTerm( array $templates ) {
		return 'hastemplate:' . $this->escapeSearchTitleList( $templates );
	}

	/**
	 * @param Topic[] $topics
	 * @return string
	 */
	private function getTopicTerm( array $topics ) {
		if ( !$topics ) {
			return '';
		}
		return 'morelikethis:' . $this->escapeSearchTitleList(
			array_reduce( $topics, function ( array $carry, Topic $topic ) {
				if ( $topic instanceof MorelikeBasedTopic ) {
					$carry = array_merge( $carry, $topic->getReferencePages() );
				}
				return $carry;
			}, [] ) );
	}

	/**
	 * @param LinkTarget[] $titles
	 * @return string
	 */
	private function escapeSearchTitleList( array $titles ) {
		return '"' . implode( '|', array_map( function ( LinkTarget $title ) {
			return str_replace( [ '"', '?' ], [ '\"', '\?' ], $title->getDBkey() );
		}, $titles ) ) . '"';
	}

}

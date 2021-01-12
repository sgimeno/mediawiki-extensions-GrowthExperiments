<?php

namespace GrowthExperiments\Maintenance;

use ChangeTags;
use CirrusSearch\Query\ArticleTopicFeature;
use Generator;
use GrowthExperiments\GrowthExperimentsServices;
use GrowthExperiments\NewcomerTasks\AddLink\LinkRecommendation;
use GrowthExperiments\NewcomerTasks\AddLink\LinkRecommendationProvider;
use GrowthExperiments\NewcomerTasks\AddLink\LinkRecommendationStore;
use GrowthExperiments\NewcomerTasks\ConfigurationLoader\ConfigurationLoader;
use GrowthExperiments\NewcomerTasks\ConfigurationLoader\ConfigurationLoaderTrait;
use GrowthExperiments\NewcomerTasks\TaskSuggester\TaskSuggester;
use GrowthExperiments\NewcomerTasks\TaskType\LinkRecommendationTaskType;
use GrowthExperiments\NewcomerTasks\TaskType\LinkRecommendationTaskTypeHandler;
use GrowthExperiments\NewcomerTasks\TaskType\NullTaskTypeHandler;
use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use GrowthExperiments\NewcomerTasks\Topic\RawOresTopic;
use IDBAccessObject;
use Maintenance;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\RevisionStore;
use MediaWiki\Revision\SlotRecord;
use MWTimestamp;
use RuntimeException;
use SearchEngineFactory;
use Status;
use StatusValue;
use Title;
use TitleFactory;
use User;
use WikitextContent;

$path = dirname( dirname( dirname( __DIR__ ) ) );

if ( getenv( 'MW_INSTALL_PATH' ) !== false ) {
	$path = getenv( 'MW_INSTALL_PATH' );
}

require_once $path . '/maintenance/Maintenance.php';

/**
 * Update the growthexperiments_link_recommendations table to ensure there are enough
 * recommendations for all topics
 */
class RefreshLinkRecommendations extends Maintenance {

	/** @var SearchEngineFactory */
	protected $searchEngineFactory;

	/** @var TitleFactory */
	private $titleFactory;

	/** @var LinkBatchFactory */
	private $linkBatchFactory;

	/** @var RevisionStore */
	private $revisionStore;

	/** @var ConfigurationLoader */
	private $configurationLoader;

	/** @var TaskSuggester */
	private $taskSuggester;

	/** @var LinkRecommendationProvider */
	protected $linkRecommendationProviderUncached;

	/** @var LinkRecommendationStore */
	private $linkRecommendationStore;

	/** @var EventBusFactory */
	private $eventBusFactory;

	/** @var string */
	private $recommendationTaskTypeId;

	/** @var LinkRecommendationTaskType */
	private $recommendationTaskType;

	/** @var User */
	private $searchUser;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'GrowthExperiments' );
		$this->requireExtension( 'CirrusSearch' );
		$this->requireExtension( 'EventBus' );

		$this->addDescription( 'Update the growthexperiments_link_recommendations table to ensure '
			. 'there are enough recommendations for all topics.' );
		$this->setBatchSize( 500 );

		$this->recommendationTaskTypeId = 'links';
	}

	public function execute() {
		$this->initServices();
		$this->initConfig();

		$this->output( "Refreshing link recommendations...\n" );
		$oresTopics = array_keys( ArticleTopicFeature::TERMS_TO_LABELS );
		foreach ( $oresTopics as $oresTopic ) {
			$this->output( "  processing topic $oresTopic...\n" );
			$suggestions = $this->taskSuggester->suggest(
				$this->searchUser,
				[ $this->recommendationTaskTypeId ],
				[ $oresTopic ],
				1,
				0,
				// Enabling the debug flag is relatively harmless, and disables all caching,
				// which we need here. useCache would prevent reading the cache, but would
				// still write it, which would be just a waste of space.
				[ 'debug' => true ]
			);
			$recommendationsNeeded = $this->recommendationTaskType->getMinimumTasksPerTopic()
				- $suggestions->getTotalCount();
			// TODO can we reuse actual Suggester / SearchStrategy / etc code here?
			if ( $recommendationsNeeded === 0 ) {
				$this->output( "    no new tasks needed\n" );
				continue;
			}
			$this->output( "    $recommendationsNeeded new tasks needed\n" );
			foreach ( $this->findArticlesInTopic( $oresTopic ) as $titleBatch ) {
				$recommendationsFound = 0;
				$this->linkBatchFactory->newLinkBatch( $titleBatch )->execute();
				foreach ( $titleBatch as $title ) {
					// TODO filter out protected pages. Needs to be batched. Or wait for T259346.
					/** @var Title $title */
					$lastRevision = $this->revisionStore->getRevisionByTitle( $title );
					if ( !$this->evaluateTitle( $title, $lastRevision ) ) {
						continue;
					}
					// Prevent infinite loop. Cirrus updates are not realtime so pages we have
					// just created recommendations for will be included again in the next batch.
					// Skip them to ensure $recommendationsFound is only nonzero then we have
					// actually added a new recommendation.
					// FIXME there is probably a better way to do this via search offsets.
					if ( $this->linkRecommendationStore->getByPageId( $lastRevision->getPageId(),
						IDBAccessObject::READ_LATEST )
					) {
						continue;
					}
					$recommendation = $this->linkRecommendationProviderUncached->get( $title );
					if ( !$this->evaluateRecommendation( $recommendation, $lastRevision ) ) {
						continue;
					}
					$this->linkRecommendationStore->insert( $recommendation );
					$this->updateCirrusSearchIndex( $lastRevision );
					// Caching is up to the provider, this script is just warming the cache.
					$recommendationsFound++;
					$recommendationsNeeded--;
					if ( $recommendationsNeeded <= 0 ) {
						break 2;
					}
				}
				if ( $recommendationsFound === 0 ) {
					break;
				}
			}
			$this->output( ( $recommendationsNeeded === 0 ) ? "    task pool filled\n"
				: "    topic exhausted, $recommendationsNeeded tasks still needed\n" );
		}
	}

	protected function initServices(): void {
		$this->replaceConfigurationLoader();

		$services = MediaWikiServices::getInstance();
		$growthServices = GrowthExperimentsServices::wrap( $services );
		$this->searchEngineFactory = $services->getSearchEngineFactory();
		$this->titleFactory = $services->getTitleFactory();
		$this->linkBatchFactory = $services->getLinkBatchFactory();
		$this->revisionStore = $services->getRevisionStore();
		$this->configurationLoader = $growthServices->getConfigurationLoader();
		$this->taskSuggester = $growthServices->getTaskSuggesterFactory()->create();
		$this->linkRecommendationProviderUncached =
			$services->get( 'GrowthExperimentsLinkRecommendationProviderUncached' );
		$this->linkRecommendationStore = $growthServices->getLinkRecommendationStore();
		$this->eventBusFactory = $services->get( 'EventBus.EventBusFactory' );
	}

	/**
	 * Use fake topic configuration consisting of raw ORES topics, so we can use TaskSuggester
	 * to check the number of suggestions per ORES topic. Extend the task type configuration
	 * with a null task type, which generates the same generic search filters as all other
	 * task types, to handle non-task-type-specific filtering.
	 */
	protected function replaceConfigurationLoader(): void {
		$services = MediaWikiServices::getInstance();
		$services->addServiceManipulator( 'GrowthExperimentsConfigurationLoader',
			function ( ConfigurationLoader $configurationLoader, MediaWikiServices $services ) {
				return new class ( $configurationLoader ) implements ConfigurationLoader {
					use ConfigurationLoaderTrait;

					/** @var ConfigurationLoader */
					private $realConfigurationLoader;

					/** @var TaskType[] */
					private $extraTaskTypes;

					/** @var RawOresTopic[] */
					private $topics;

					/** @param ConfigurationLoader $realConfigurationLoader */
					public function __construct( ConfigurationLoader $realConfigurationLoader ) {
						$this->realConfigurationLoader = $realConfigurationLoader;
						$this->extraTaskTypes = [
							NullTaskTypeHandler::getNullTaskType( 'nolinkrecommendations', '-hasrecommendation:link' ),
						];
						$this->topics = array_map( function ( string $oresId ) {
							return new RawOresTopic( $oresId, $oresId );
						}, array_keys( ArticleTopicFeature::TERMS_TO_LABELS ) );
					}

					/** @inheritDoc */
					public function loadTaskTypes() {
						return array_merge( $this->realConfigurationLoader->loadTaskTypes(), $this->extraTaskTypes );
					}

					/** @inheritDoc */
					public function loadTopics() {
						return $this->topics;
					}

					/** @inheritDoc */
					public function loadTemplateBlacklist() {
						return $this->realConfigurationLoader->loadTemplateBlacklist();
					}
				};
			} );
	}

	protected function initConfig(): void {
		$taskTypes = $this->configurationLoader->getTaskTypes();
		$taskType = $taskTypes[$this->recommendationTaskTypeId] ?? null;
		if ( !$taskType || !$taskType instanceof LinkRecommendationTaskType ) {
			$this->fatalError( "'$this->recommendationTaskTypeId' is not a link recommendation task type" );
		} else {
			$this->recommendationTaskType = $taskType;
		}
		$this->searchUser = User::newSystemUser( 'Maintenance script' );
	}

	/**
	 * @param string $oresTopic
	 * @return Generator<Title[]>
	 */
	private function findArticlesInTopic( $oresTopic ) {
		$batchSize = $this->getBatchSize();
		do {
			$this->output( "    fetching $batchSize tasks...\n" );
			$candidates = $this->taskSuggester->suggest(
				$this->searchUser,
				[ 'nolinkrecommendations' ],
				[ $oresTopic ],
				$batchSize,
				null,
				[ 'debug' => true ]
			);
			if ( $candidates instanceof StatusValue ) {
				// FIXME exiting will make the cronjob unreliable. Not exiting might result
				//  in an infinite error loop. Neither looks like a great option.
				throw new RuntimeException( 'Search error: '
					. Status::wrap( $candidates )->getWikiText( null, null, 'en' ) );
			}

			$titles = [];
			foreach ( $candidates as $candidate ) {
				$titles[] = $this->titleFactory->newFromLinkTarget( $candidate->getTitle() );
			}
			yield $titles;
		} while ( $candidates->count() );
	}

	private function evaluateTitle( Title $title, ?RevisionRecord $revision ): bool {
		// FIXME ideally most of this should be moved inside the search query

		if ( $revision === null ) {
			// Maybe the article has just been deleted and the search index is behind?
			return false;
		}
		$content = $revision->getContent( SlotRecord::MAIN );
		if ( !$content || !$content instanceof WikitextContent ) {
			return false;
		}
		$revisionTime = MWTimestamp::convert( TS_UNIX, $revision->getTimestamp() );
		if ( time() - $revisionTime < $this->recommendationTaskType->getMinimumTimeSinceLastEdit() ) {
			return false;
		}

		$wordCount = preg_match_all( '/\w+/', $content->getText() );
		if ( $wordCount < $this->recommendationTaskType->getMinimumWordCount()
			|| $wordCount > $this->recommendationTaskType->getMaximumWordCount()
		) {
			return false;
		}

		$db = $this->getDB( DB_REPLICA );
		$tags = ChangeTags::getTagsWithData( $db, null, $revision->getId() );
		if ( array_key_exists( LinkRecommendationTaskTypeHandler::CHANGE_TAG, $tags ) ) {
			return false;
		}
		if ( array_intersect( ChangeTags::REVERT_TAGS, array_keys( $tags ) ) ) {
			$tagData = json_decode( $tags[ChangeTags::TAG_REVERTED], true );
			/** @var array $tagData */'@phan-var array $tagData';
			$revertedAddLinkEditCount = $db->selectRowCount(
				[ 'revision', 'change_tag' ],
				'1',
				[
					'rev_id = ct_rev_id',
					'rev_page' => $title->getArticleID(),
					'rev_id <=' . (int)$tagData['newestRevertedRevId'],
					'rev_id >=' . (int)$tagData['oldestRevertedRevId'],
					'ct_tag_id' => LinkRecommendationTaskTypeHandler::CHANGE_TAG,
				],
				__METHOD__
			);
			if ( $revertedAddLinkEditCount > 0 ) {
				return false;
			}
		}
		return true;
	}

	private function evaluateRecommendation( $recommendation, RevisionRecord $revision ): bool {
		if ( !( $recommendation instanceof LinkRecommendation ) ) {
			return false;
		}
		if ( $recommendation->getRevisionId() !== $revision->getId() ) {
			// Some kind of race condition? Generating another task is easy so just discard this.
			return false;
		}
		// We could check here for more race conditions, ie. whether the revision in the
		// recommendation matches the live revision. But there are plenty of other ways for race
		// conditions to happen, so we'll have to deal with them on the client side anyway. No
		// point in getting a master connection just for that.

		$goodLinks = array_filter( $recommendation->getLinks(), function ( $link ) {
			return $link[LinkRecommendation::FIELD_SCORE]
				>= $this->recommendationTaskType->getMinimumLinkScore();
		} );
		if ( count( $goodLinks ) < $this->recommendationTaskType->getMinimumLinksPerTask() ) {
			return false;
		}

		return true;
	}

	private function updateCirrusSearchIndex( RevisionRecord $revision ): void {
		$stream = 'mediawiki.revision-recommendation-create';
		$eventBus = $this->eventBusFactory->getInstanceForStream( $stream );
		$eventFactory = $eventBus->getFactory();
		$event = $eventFactory->createRecommendationCreateEvent( $stream, 'link', $revision );
		$result = $eventBus->send( [ $event ] );
		if ( $result !== true ) {
			$this->error( "  Could not send search index update:\n    "
				. implode( "    \n", (array)$result ) );
		}
	}

}

$maintClass = RefreshLinkRecommendations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
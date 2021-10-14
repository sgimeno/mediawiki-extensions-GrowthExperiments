<?php

namespace GrowthExperiments\Maintenance;

use FormatJson;
use GrowthExperiments\GrowthExperimentsServices;
use GrowthExperiments\MentorDashboard\MenteeOverview\DatabaseMenteeOverviewDataProvider;
use GrowthExperiments\MentorDashboard\MenteeOverview\MenteeOverviewDataProvider;
use GrowthExperiments\MentorDashboard\MenteeOverview\UncachedMenteeOverviewDataProvider;
use GrowthExperiments\Mentorship\MentorManager;
use GrowthExperiments\Mentorship\Store\MentorStore;
use GrowthExperiments\WikiConfigException;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\ILoadBalancer;

$path = dirname( dirname( dirname( __DIR__ ) ) );

if ( getenv( 'MW_INSTALL_PATH' ) !== false ) {
	$path = getenv( 'MW_INSTALL_PATH' );
}

require_once $path . '/maintenance/Maintenance.php';

class UpdateMenteeData extends Maintenance {
	/** @var UncachedMenteeOverviewDataProvider */
	private $uncachedMenteeOverviewDataProvider;

	/** @var MenteeOverviewDataProvider */
	private $databaseMenteeOverviewDataProvider;

	/** @var MentorManager */
	private $mentorManager;

	/** @var MentorStore */
	private $mentorStore;

	/** @var UserFactory */
	private $userFactory;

	/** @var ILoadBalancer */
	private $growthLoadBalancer;

	/** @var StatsdDataFactoryInterface */
	private $dataFactory;

	/** @var array */
	private $detailedProfilingInfo = [];

	public function __construct() {
		parent::__construct();
		$this->setBatchSize( 200 );
		$this->requireExtension( 'GrowthExperiments' );

		$this->addDescription( 'Update growthexperiments_mentee_data database table' );
		$this->addOption( 'force', 'Do the update even if GEMentorDashboardBackendEnabled is false' );
		$this->addOption( 'mentor', 'Username of the mentor to update the data for', false, true );
		$this->addOption( 'statsd', 'Send timing information to statsd' );
		$this->addOption( 'verbose', 'Output detailed profiling information' );
		$this->addOption(
			'dbshard',
			'ID of the DB shard this script runs at',
			false,
			true
		);
	}

	private function initServices() {
		$services = MediaWikiServices::getInstance();
		$geServices = GrowthExperimentsServices::wrap( $services );

		$this->uncachedMenteeOverviewDataProvider = $geServices->getUncachedMenteeOverviewDataProvider();
		$this->databaseMenteeOverviewDataProvider = $geServices->getMenteeOverviewDataProvider();
		$this->mentorManager = $geServices->getMentorManager();
		$this->mentorStore = $geServices->getMentorStore();
		$this->userFactory = $services->getUserFactory();
		$this->growthLoadBalancer = $geServices->getLoadBalancer();
		$this->dataFactory = $services->getStatsdDataFactory();
	}

	private function addProfilingInfoForMentor( array $mentorProfilingInfo ): void {
		foreach ( $mentorProfilingInfo as $section => $seconds ) {
			if ( !array_key_exists( $section, $this->detailedProfilingInfo ) ) {
				$this->detailedProfilingInfo[$section] = 0;
			}

			$this->detailedProfilingInfo[$section] += $seconds;
		}
	}

	public function execute() {
		if (
			!$this->getConfig()->get( 'GEMentorDashboardBackendEnabled' ) &&
			!$this->hasOption( 'force' )
		) {
			$this->output( "Mentor dashboard backend is disabled.\n" );
			return;
		}

		$this->initServices();

		$startTime = time();

		if ( $this->hasOption( 'mentor' ) ) {
			$mentors = [ $this->getOption( 'mentor' ) ];
		} else {
			try {
				$mentors = $this->mentorManager->getMentors();
			} catch ( WikiConfigException $e ) {
				$this->fatalError( 'List of mentors cannot be fetched.' );
			}
		}

		$thisBatch = 0;
		$batchSize = $this->getBatchSize();
		$allUpdatedMenteeIds = [];
		$dbw = $this->growthLoadBalancer->getConnection( DB_PRIMARY );
		$dbr = $this->growthLoadBalancer->getConnection( DB_REPLICA );
		foreach ( $mentors as $mentorRaw ) {
			$mentor = $this->userFactory->newFromName( $mentorRaw );
			if ( $mentor === null ) {
				$this->output( 'Skipping ' . $mentorRaw . ", invalid user\n" );
				continue;
			}

			$data = $this->uncachedMenteeOverviewDataProvider->getFormattedDataForMentor( $mentor );
			$mentees = $this->mentorStore->getMenteesByMentor( $mentor, MentorStore::ROLE_PRIMARY );
			$updatedMenteeIds = [];
			foreach ( $data as $menteeId => $menteeData ) {
				$encodedData = FormatJson::encode( $menteeData );
				$storedEncodedData = $dbr->selectField(
					'growthexperiments_mentee_data',
					'mentee_data',
					[ 'mentee_id' => $menteeId ]
				);
				if ( $storedEncodedData === false ) {
					// Row doesn't exist yet, insert it
					$dbw->insert(
						'growthexperiments_mentee_data',
						[
							'mentee_id' => $menteeId,
							'mentee_data' => $encodedData
						],
						__METHOD__
					);
				} else {
					// Row exists, if anything changed, update
					if ( FormatJson::decode( $storedEncodedData, true ) !== $menteeData ) {
						$dbw->update(
							'growthexperiments_mentee_data',
							[ 'mentee_data' => $encodedData ],
							[ 'mentee_id' => $menteeId ],
							__METHOD__
						);
					}
				}

				$thisBatch++;
				$updatedMenteeIds[] = $menteeId;
				$allUpdatedMenteeIds[] = $menteeId;

				if ( $thisBatch >= $batchSize ) {
					$thisBatch = 0;
					$this->waitForReplication();
				}
			}

			// Delete all mentees of $mentor we did not update
			$menteeIdsToDelete = array_diff(
				array_map(
					static function ( $mentee ) {
						return $mentee->getId();
					},
					$mentees
				),
				$updatedMenteeIds
			);
			if ( $menteeIdsToDelete !== [] ) {
				$dbw->begin( __METHOD__ );
				$dbw->delete(
					'growthexperiments_mentee_data',
					[
						'mentee_id' => $menteeIdsToDelete
					],
					__METHOD__
				);
				$dbw->commit( __METHOD__ );
				$this->waitForReplication();
			}

			$this->addProfilingInfoForMentor(
				$this->uncachedMenteeOverviewDataProvider->getProfilingInfo()
			);

			// if applicable, clear cache for the mentor we just updated
			if ( $this->databaseMenteeOverviewDataProvider instanceof DatabaseMenteeOverviewDataProvider ) {
				$this->databaseMenteeOverviewDataProvider->invalidateCacheForMentor( $mentor );
			}
		}

		// Delete all mentees recorded in the table which were not updated
		// This cannot happen when --mentor was passed, as that would delete
		// most of the data.
		if ( !$this->hasOption( 'mentor' ) ) {
			$menteeIdsToDelete = array_diff(
				array_map(
					'intval',
					$dbw->selectFieldValues(
						'growthexperiments_mentee_data',
						'mentee_id',
						'',
						__METHOD__
					)
				),
				$allUpdatedMenteeIds
			);
			if ( $menteeIdsToDelete !== [] ) {
				$dbw->delete(
					'growthexperiments_mentee_data',
					[
						'mentee_id' => $menteeIdsToDelete
					]
				);
			}
		}

		$totalTime = time() - $startTime;

		if ( $this->hasOption( 'verbose' ) ) {
			$this->output( "Profiling data:\n" );
			foreach ( $this->detailedProfilingInfo as $section => $seconds ) {
				$this->output( "  * {$section}: {$seconds} seconds\n" );
			}
			$this->output( "===============\n" );
		}

		if ( $this->hasOption( 'statsd' ) && $this->hasOption( 'dbshard' ) ) {
			$this->dataFactory->timing(
				'timing.growthExperiments.updateMenteeData.' . $this->getOption( 'dbshard' ) . '.total',
				$totalTime
			);

			foreach ( $this->detailedProfilingInfo as $section => $seconds ) {
				$this->dataFactory->timing(
					'timing.growthExperiments.updateMenteeData.' .
					$this->getOption( 'dbshard' ) .
					'.' .
					$section,
					$seconds
				);
			}
		}

		$this->output( "Done. Took {$totalTime} seconds.\n" );
	}
}

$maintClass = UpdateMenteeData::class;
require_once RUN_MAINTENANCE_IF_MAIN;

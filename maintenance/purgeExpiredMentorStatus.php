<?php

namespace GrowthExperiments\Maintenance;

use GrowthExperiments\MentorDashboard\MentorTools\MentorStatusManager;
use Maintenance;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Purge expired rows related to mentor status from user_properties
 */
class PurgeExpiredMentorStatus extends Maintenance {

	/** @var IDatabase */
	private $dbr;

	/** @var IDatabase */
	private $dbw;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'GrowthExperiments' );
		$this->addDescription(
			'Remove expired values of MentorStatusManager::MENTOR_AWAY_TIMESTAMP_PREF from user_properties'
		);
		$this->addOption( 'dry-run', 'Do not actually change anything.' );
		$this->setBatchSize( 100 );
	}

	private function initServices(): void {
		$this->dbr = $this->getDB( DB_REPLICA );
		$this->dbw = $this->getDB( DB_PRIMARY );
	}

	private function getRows() {
		yield from $this->dbr->select(
			'user_properties',
			[ 'up_user', 'up_value' ],
			[
				'up_property' => MentorStatusManager::MENTOR_AWAY_TIMESTAMP_PREF
			],
			__METHOD__
		);
	}

	private function filterAndBatch() {
		$batch = [];
		foreach ( $this->getRows() as $row ) {
			if (
				$row->up_value === null ||
				ConvertibleTimestamp::convert( TS_UNIX, $row->up_value ) < wfTimestamp( TS_UNIX )
			) {
				$batch[] = $row->up_user;

				if ( count( $batch ) >= $this->getBatchSize() ) {
					yield $batch;
					$batch = [];
				}
			}
		}

		if ( $batch !== [] ) {
			yield $batch;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$this->initServices();

		$deletedCount = 0;
		foreach ( $this->filterAndBatch() as $batch ) {
			$this->deleteTimestamps( $batch );
			$deletedCount += count( $batch );
		}

		if ( $this->getOption( 'dry-run' ) ) {
			$this->output( "Would delete $deletedCount rows from user_properties.\n" );
		} else {
			$this->output( "Deleted $deletedCount rows from user_properties.\n" );
		}
	}

	private function deleteTimestamps( array $toDelete ): void {
		if ( $this->getOption( 'dry-run' ) ) {
			return;
		}
		$this->dbw->begin( __METHOD__ );
		$this->dbw->delete(
			'user_properties',
			[
				'up_property' => MentorStatusManager::MENTOR_AWAY_TIMESTAMP_PREF,
				'up_user' => $toDelete
			],
			__METHOD__
		);
		$this->dbw->commit( __METHOD__ );
		$this->waitForReplication();
	}
}

$maintClass = PurgeExpiredMentorStatus::class;
require_once RUN_MAINTENANCE_IF_MAIN;

<?php

namespace GrowthExperiments\Mentorship\Store;

use DBAccessObjectUtils;
use JobQueueGroup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use Wikimedia\Rdbms\IDatabase;

class DatabaseMentorStore extends MentorStore {
	/** @var UserFactory */
	private $userFactory;

	/** @var IDatabase */
	private $dbr;

	/** @var IDatabase */
	private $dbw;

	/**
	 * @param UserFactory $userFactory
	 * @param IDatabase $dbr
	 * @param IDatabase $dbw
	 * @param bool $wasPosted
	 */
	public function __construct(
		UserFactory $userFactory,
		IDatabase $dbr,
		IDatabase $dbw,
		bool $wasPosted
	) {
		parent::__construct( $wasPosted );

		$this->userFactory = $userFactory;
		$this->dbr = $dbr;
		$this->dbw = $dbw;
	}

	/**
	 * @inheritDoc
	 */
	public function loadMentorUserUncached(
		UserIdentity $mentee,
		string $mentorRole,
		$flags
	): ?UserIdentity {
		list( $index, $options ) = DBAccessObjectUtils::getDBOptions( $flags );
		$db = ( $index === DB_MASTER ) ? $this->dbw : $this->dbr;
		$id = $db->selectField(
			'growthexperiments_mentor_mentee',
			'gemm_mentor_id',
			[
				'gemm_mentee_id' => $mentee->getId(),

				// As of now, there is no but primary mentor, but the field is in the database
				// The role field will be useful as part of T227876.
				'gemm_mentor_role' => $mentorRole,
			],
			__METHOD__,
			$options
		);
		if ( $id === false ) {
			// No mentor in the database, return null
			return null;
		}

		// Construct & return the user
		$user = $this->userFactory->newFromId( $id );
		// Return null if user does not exist
		$user->load();
		if ( !$user->isRegistered() ) {
			return null;
		}
		return new UserIdentityValue( $user->getId(), $user->getName() );
	}

	/**
	 * Really set a mentor for a given user
	 *
	 * @param UserIdentity $mentee
	 * @param UserIdentity $mentor
	 * @param string $mentorRole
	 */
	private function setMentorForUserReal(
		UserIdentity $mentee,
		UserIdentity $mentor,
		string $mentorRole
	): void {
		$this->dbw->upsert(
			'growthexperiments_mentor_mentee',
			[
				'gemm_mentee_id' => $mentee->getId(),
				'gemm_mentor_id' => $mentor->getId(),
				'gemm_mentor_role' => $mentorRole,
			],
			[ 'gemm_mentee_id', 'gemm_mentor_role' ],
			[
				'gemm_mentor_id' => $mentor->getId()
			],
			__METHOD__
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function setMentorForUserInternal(
		UserIdentity $mentee,
		UserIdentity $mentor,
		string $mentorRole = self::ROLE_PRIMARY
	): void {
		if ( $this->wasPosted ) {
			$this->setMentorForUserReal(
				$mentee,
				$mentor,
				$mentorRole
			);
		} else {
			JobQueueGroup::singleton()->lazyPush( new SetUserMentorDatabaseJob( [
				'menteeId' => $mentee->getId(),
				'mentorId' => $mentor->getId(),
				'roleId' => $mentorRole,
			] ) );
		}
	}
}

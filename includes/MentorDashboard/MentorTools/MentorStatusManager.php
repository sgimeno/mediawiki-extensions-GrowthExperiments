<?php

namespace GrowthExperiments\MentorDashboard\MentorTools;

use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityLookup;
use MediaWiki\User\UserOptionsManager;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class MentorStatusManager {

	/** @var string Mentor status */
	public const STATUS_ACTIVE = 'active';
	/** @var string Mentor status */
	public const STATUS_AWAY = 'away';

	/** @var string[] List of MentorStatusManager::STATUS_* constants */
	public const STATUSES = [
		self::STATUS_ACTIVE,
		self::STATUS_AWAY
	];

	/** @var string Preference key to store mentor's away timestamp */
	public const MENTOR_AWAY_TIMESTAMP_PREF = 'growthexperiments-mentor-away-timestamp';

	/** @var int Number of seconds in a day */
	private const SECONDS_DAY = 86400;

	/** @var UserOptionsManager */
	private $userOptionsManager;

	/** @var UserIdentityLookup */
	private $userIdentityLookup;

	/** @var UserFactory */
	private $userFactory;

	/** @var IDatabase */
	private $dbr;

	/**
	 * @param UserOptionsManager $userOptionsManager
	 * @param UserIdentityLookup $userIdentityLookup
	 * @param UserFactory $userFactory
	 * @param IDatabase $dbr
	 */
	public function __construct(
		UserOptionsManager $userOptionsManager,
		UserIdentityLookup $userIdentityLookup,
		UserFactory $userFactory,
		IDatabase $dbr
	) {
		$this->userOptionsManager = $userOptionsManager;
		$this->userIdentityLookup = $userIdentityLookup;
		$this->userFactory = $userFactory;
		$this->dbr = $dbr;
	}

	/**
	 * Get mentor's current status
	 *
	 * @param UserIdentity $mentor
	 * @return string one of MentorStatusManager::STATUS_* constants
	 */
	public function getMentorStatus( UserIdentity $mentor ): string {
		if ( $this->getMentorBackTimestamp( $mentor ) === null ) {
			return self::STATUS_ACTIVE;
		} else {
			return self::STATUS_AWAY;
		}
	}

	/**
	 * @param UserIdentity $mentor
	 * @return string|null Null if mentor is currently active
	 */
	public function getMentorBackTimestamp( UserIdentity $mentor ): ?string {
		return $this->parseBackTimestamp( $this->userOptionsManager->getOption(
			$mentor,
			self::MENTOR_AWAY_TIMESTAMP_PREF
		) );
	}

	/**
	 * @param string|null $rawTs
	 * @return string|null
	 */
	private function parseBackTimestamp( ?string $rawTs ): ?string {
		if (
			$rawTs === null ||
			(int)ConvertibleTimestamp::convert( TS_UNIX, $rawTs ) < (int)wfTimestamp( TS_UNIX )
		) {
			return null;
		}

		return $rawTs;
	}

	/**
	 * Get mentors marked as away
	 *
	 * @return UserIdentity[]
	 */
	public function getAwayMentors(): array {
		// This should be okay, as up_property is an index, and we won't
		// get a lot of rows to process.
		$awayMentorIds = $this->dbr->selectFieldValues(
			'user_properties',
			'up_user',
			[
				'up_property' => self::MENTOR_AWAY_TIMESTAMP_PREF,
				'up_value IS NOT NULL',
				'up_value > ' . $this->dbr->addQuotes(
					$this->dbr->timestamp()
				)
			],
			__METHOD__
		);

		if ( $awayMentorIds === [] ) {
			return [];
		}

		return iterator_to_array(
			$this->userIdentityLookup
				->newSelectQueryBuilder()
				->whereUserIds( $awayMentorIds )
				->fetchUserIdentities()
		);
	}

	/**
	 * Mark a mentor as away
	 *
	 * @param UserIdentity $mentor
	 * @param int $backInDays Length of mentor's wiki-vacation in days
	 */
	public function markMentorAsAway( UserIdentity $mentor, int $backInDays ): void {
		$this->userOptionsManager->setOption(
			$mentor,
			self::MENTOR_AWAY_TIMESTAMP_PREF,
			ConvertibleTimestamp::convert(
				TS_MW,
				(int)wfTimestamp( TS_UNIX ) + self::SECONDS_DAY * $backInDays
			)
		);
		$this->userFactory->newFromUserIdentity( $mentor )->saveSettings();
	}

	/**
	 * Mark a mentor as active
	 *
	 * @param UserIdentity $mentor
	 */
	public function markMentorAsActive( UserIdentity $mentor ): void {
		$this->userOptionsManager->setOption(
			$mentor,
			self::MENTOR_AWAY_TIMESTAMP_PREF,
			null
		);
		$this->userFactory->newFromUserIdentity( $mentor )->saveSettings();
	}
}

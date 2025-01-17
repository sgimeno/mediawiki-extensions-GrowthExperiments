<?php

namespace GrowthExperiments\Mentorship;

use GenericParameterJob;
use GrowthExperiments\GrowthExperimentsServices;
use Job;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentityLookup;
use RequestContext;

/**
 * Job to reassign all mentees operated by a given mentor
 *
 * The following job parameters are required:
 *  - mentorId: user ID of the mentor to process
 *  - reassignMessageKey: Message to store in logs as well as in notifications to mentees
 */
class ReassignMenteesJob extends Job implements GenericParameterJob {

	/** @var UserIdentityLookup */
	private $userIdentityLookup;

	/** @var QuitMentorshipFactory */
	private $quitMentorshipFactory;

	/**
	 * @inheritDoc
	 */
	public function __construct( $params = null ) {
		parent::__construct( 'reassignMenteesJob', $params );

		// init services
		$services = MediaWikiServices::getInstance();
		$this->userIdentityLookup = $services->getUserIdentityLookup();
		$this->quitMentorshipFactory = GrowthExperimentsServices::wrap( $services )
			->getQuitMentorshipFactory();
	}

	/**
	 * @inheritDoc
	 */
	public function run() {
		$mentor = $this->userIdentityLookup->getUserIdentityByUserId( $this->params['mentorId'] );
		if ( !$mentor ) {
			return false;
		}

		$quitMentorship = $this->quitMentorshipFactory->newQuitMentorship(
			$mentor,
			RequestContext::getMain()
		);
		$quitMentorship->doReassignMentees( $this->params['reassignMessageKey'] );

		return true;
	}
}

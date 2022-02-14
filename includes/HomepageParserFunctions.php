<?php

namespace GrowthExperiments;

use GrowthExperiments\Mentorship\MentorManager;
use MediaWiki\User\UserFactory;
use Parser;

/**
 * Class for handling parser functions introduced by GrowthExperiments
 */
class HomepageParserFunctions {
	/**
	 * Handler for {{#mentor:Username}}
	 *
	 * @param UserFactory $userFactory
	 * @param MentorManager $mentorManager
	 * @param Parser $parser
	 * @param string $username Mentee's username
	 *
	 * @return string
	 */
	public static function mentorRender(
		UserFactory $userFactory,
		MentorManager $mentorManager,
		Parser $parser,
		$username
	): string {
		$menteeUser = $userFactory->newFromName( $username );
		if ( $menteeUser === null ) {
			return '';
		}

		$mentor = $mentorManager->getMentorForUserIfExists(
			$menteeUser
		);
		if ( $mentor === null ) {
			return '';
		}

		return $mentor->getUserIdentity()->getName();
	}
}

<?php

namespace GrowthExperiments\MentorDashboard;

use Config;
use GrowthExperiments\Mentorship\MentorManager;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\PersonalUrlsHook;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsLookup;
use SpecialPage;

class MentorDashboardDiscoveryHooks implements PersonalUrlsHook, BeforePageDisplayHook {

	public const MENTOR_DASHBOARD_SEEN_PREF = 'growthexperiments-mentor-dashboard-seen';

	/** @var Config */
	private $config;

	/** @var MentorManager */
	private $mentorManager;

	/** @var UserOptionsLookup */
	private $userOptionsLookup;

	/**
	 * @param Config $config
	 * @param MentorManager $mentorManager
	 * @param UserOptionsLookup $userOptionsLookup
	 */
	public function __construct(
		Config $config,
		MentorManager $mentorManager,
		UserOptionsLookup $userOptionsLookup
	) {
		$this->config = $config;
		$this->mentorManager = $mentorManager;
		$this->userOptionsLookup = $userOptionsLookup;
	}

	/**
	 * Are mentor dashboard discovery features enabled?
	 *
	 * @param UserIdentity $user
	 * @return bool
	 */
	private function isDiscoveryEnabled( UserIdentity $user ): bool {
		return $this->config->get( 'GEMentorDashboardEnabled' ) &&
			$this->config->get( 'GEMentorDashboardDiscoveryEnabled' ) &&
			$user->isRegistered() &&
			$this->mentorManager->isMentor( $user );
	}

	/**
	 * @inheritDoc
	 */
	public function onPersonalUrls( &$personalUrls, &$title, $skin ): void {
		if ( !$this->isDiscoveryEnabled( $skin->getUser() ) ) {
			return;
		}

		$newPersonalUrls = [];
		foreach ( $personalUrls as $key => $link ) {
			if ( $key == 'logout' ) {
				$newPersonalUrls['mentordashboard'] = [
					'id' => 'pt-mentordashboard',
					'text' => $skin->msg( 'growthexperiments-mentor-dashboard-pt-link' )->text(),
					'href' => SpecialPage::getTitleFor( 'MentorDashboard' )->getLocalURL(),
					'icon' => 'userGroup',
				];
			}
			$newPersonalUrls[$key] = $link;
		}

		$personalUrls = $newPersonalUrls;
	}

	/**
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$user = $skin->getUser();

		if (
			!$this->isDiscoveryEnabled( $user ) ||
			// do not show the blue dot if the user ever visited their mentor dashboard
			$this->userOptionsLookup->getBoolOption( $user, self::MENTOR_DASHBOARD_SEEN_PREF ) ||
			// do not show the blue dot if the user is currently at their dashboard
			$skin->getTitle()->equals( SpecialPage::getTitleFor( 'MentorDashboard' ) )
		) {
			return;
		}

		$out->addModules( 'ext.growthExperiments.MentorDashboard.Discovery' );
	}
}

<?php

namespace GrowthExperiments\Specials;

use DeferredUpdates;
use ErrorPageError;
use EventLogging;
use ExtensionRegistry;
use GrowthExperiments\DashboardModule\IDashboardModule;
use GrowthExperiments\MentorDashboard\MentorDashboardDiscoveryHooks;
use GrowthExperiments\MentorDashboard\MentorDashboardModuleRegistry;
use GrowthExperiments\Mentorship\MentorManager;
use GrowthExperiments\Util;
use Html;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\User\UserOptionsLookup;
use PermissionsError;
use SpecialPage;
use User;
use UserOptionsUpdateJob;

class SpecialMentorDashboard extends SpecialPage {

	/** @var string Versioned schema URL for $schema field */
	private const SCHEMA_VERSIONED = '/analytics/mediawiki/mentor_dashboard/visit/1.0.0';

	/** @var string Stream name for EventLogging::submit */
	private const STREAM = 'mediawiki.mentor_dashboard.visit';

	/** @var MentorDashboardModuleRegistry */
	private $mentorDashboardModuleRegistry;

	/** @var MentorManager */
	private $mentorManager;

	/** @var UserOptionsLookup */
	private $userOptionsLookup;

	/** @var JobQueueGroupFactory */
	private $jobQueueGroupFactory;

	/**
	 * @param MentorDashboardModuleRegistry $mentorDashboardModuleRegistry
	 * @param MentorManager $mentorManager
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param JobQueueGroupFactory $jobQueueGroupFactory
	 */
	public function __construct(
		MentorDashboardModuleRegistry $mentorDashboardModuleRegistry,
		MentorManager $mentorManager,
		UserOptionsLookup $userOptionsLookup,
		JobQueueGroupFactory $jobQueueGroupFactory
	) {
		parent::__construct( 'MentorDashboard' );

		$this->mentorDashboardModuleRegistry = $mentorDashboardModuleRegistry;
		$this->mentorManager = $mentorManager;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'growthexperiments-mentor-dashboard-title' )->text();
	}

	/**
	 * @param bool $isMobile
	 * @return IDashboardModule[]
	 */
	private function getModules( bool $isMobile = false ): array {
		$moduleConfig = array_filter( [
			'mentee-overview' => true,
			'mentor-tools' => $this->getConfig()->get( 'GEMentorDashboardBetaMode' ),
			'resources' => true,
		] );
		$modules = [];
		foreach ( $moduleConfig as $moduleId => $_ ) {
			$modules[$moduleId] = $this->mentorDashboardModuleRegistry->get(
				$moduleId,
				$this->getContext()
			);
		}
		return $modules;
	}

	/**
	 * @return string[][]
	 */
	private function getModuleGroups(): array {
		return [
			'main' => [
				'mentee-overview'
			],
			'sidebar' => [
				'mentor-tools',
				'resources'
			]
		];
	}

	/**
	 * Ensure mentor dashboard is enabled
	 *
	 * @throws ErrorPageError
	 */
	private function requireMentorDashboardEnabled() {
		if ( !$this->isEnabled() ) {
			// Mentor dashboard is disabled, display a meaningful restriction error
			throw new ErrorPageError(
				'growthexperiments-mentor-dashboard-title',
				'growthexperiments-mentor-dashboard-disabled'
			);
		}
	}

	/**
	 * Ensure the automatic mentor list is configured
	 *
	 * @throws ErrorPageError if mentor list is missing
	 */
	private function requireMentorList() {
		if ( !$this->mentorManager->getAutoMentorsListTitle() ) {
			throw new ErrorPageError(
				'growthexperiments-mentor-dashboard-title',
				'growthexperiments-mentor-dashboard-misconfigured-missing-list'
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->requireLogin();
		$this->requireMentorDashboardEnabled();
		$this->requireMentorList();
		parent::execute( $par );

		$out = $this->getContext()->getOutput();
		$out->enableOOUI();
		$out->addModules( 'ext.growthExperiments.MentorDashboard' );
		$out->addModuleStyles( 'ext.growthExperiments.MentorDashboard.styles' );

		$out->addHTML( Html::openElement( 'div', [
			'class' => 'growthexperiments-mentor-dashboard-container'
		] ) );

		$modules = $this->getModules( false );
		foreach ( $this->getModuleGroups() as $group => $moduleNames ) {
			$out->addHTML( Html::openElement(
				'div',
				[
					'class' => "growthexperiments-mentor-dashboard-group-$group"
				]
			) );

			foreach ( $moduleNames as $moduleName ) {
				$module = $modules[$moduleName] ?? null;
				if ( !$module ) {
					continue;
				}
				$out->addHTML( $module->render( IDashboardModule::RENDER_DESKTOP ) );
			}

			$out->addHTML( Html::closeElement( 'div' ) );
		}

		$out->addHTML( Html::closeElement( 'div' ) );

		$this->maybeLogVisit();
		$this->maybeSetSeenPreference();
	}

	/**
	 * Log visit to the mentor dashboard, if EventLogging is installed
	 */
	private function maybeLogVisit(): void {
		if ( ExtensionRegistry::getInstance()->isLoaded( 'EventLogging' ) ) {
			DeferredUpdates::addCallableUpdate( function () {
				EventLogging::submit(
					self::STREAM,
					[
						'$schema' => self::SCHEMA_VERSIONED,
						'user_id' => $this->getUser()->getId(),
						'is_mobile' => Util::isMobile( $this->getSkin() )
					]
				);
			} );
		}
	}

	/**
	 * If applicable, record that the user seen the dashboard
	 *
	 * This is used by MentorDashboardDiscoveryHooks to decide whether or not
	 * to add a blue dot informing the mentors about their dashboard.
	 *
	 * Happens via a DeferredUpdate, because it doesn't affect what the user
	 * sees in their dashboard (and is not time-sensitive as it depends on a job).
	 */
	private function maybeSetSeenPreference(): void {
		DeferredUpdates::addCallableUpdate( function () {
			$user = $this->getUser();
			if ( $this->userOptionsLookup->getBoolOption(
				$user,
				MentorDashboardDiscoveryHooks::MENTOR_DASHBOARD_SEEN_PREF
			) ) {
				// no need to set the option again
				return;
			}

			// we're in a GET context, set the seen pref via a job rather than directly
			$this->jobQueueGroupFactory->makeJobQueueGroup()->lazyPush( new UserOptionsUpdateJob( [
				'userId' => $user->getId(),
				'options' => [
					MentorDashboardDiscoveryHooks::MENTOR_DASHBOARD_SEEN_PREF => 1
				]
			] ) );
		} );
	}

	/**
	 * Check if mentor dashboard is enabled via GEMentorDashboardEnabled
	 *
	 * @return bool
	 */
	private function isEnabled(): bool {
		return $this->getConfig()->get( 'GEMentorDashboardEnabled' );
	}

	/**
	 * @inheritDoc
	 */
	public function userCanExecute( User $user ) {
		// Require both enabled wiki config and user-specific access level to
		// be able to use the special page.
		return $this->mentorManager->isMentor( $this->getUser() ) &&
			parent::userCanExecute( $user );
	}

	/**
	 * @inheritDoc
	 */
	public function displayRestrictionError() {
		throw new PermissionsError(
			null,
			[ [ 'growthexperiments-mentor-dashboard-must-be-mentor',
				$this->mentorManager->getAutoMentorsListTitle()->getPrefixedText() ] ]
		);
	}
}

<?php

namespace GrowthExperiments;

use Config;
use GrowthExperiments\Config\GrowthConfigLoaderStaticTrait;
use GrowthExperiments\HelpPanel\QuestionPoster\HelpdeskQuestionPoster;
use GrowthExperiments\HomepageModules\Mentorship;
use GrowthExperiments\HomepageModules\SuggestedEdits;
use GrowthExperiments\Mentorship\MentorManager;
use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\ChangeTags\Hook\ChangeTagsListActiveHook;
use MediaWiki\ChangeTags\Hook\ListDefinedTagsHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderExcludeUserOptionsHook;
use MediaWiki\User\Hook\UserGetDefaultOptionsHook;
use MessageLocalizer;
use RequestContext;
use ResourceLoaderContext;
use User;

class HelpPanelHooks implements
	GetPreferencesHook,
	UserGetDefaultOptionsHook,
	ResourceLoaderExcludeUserOptionsHook,
	LocalUserCreatedHook,
	BeforePageDisplayHook,
	ListDefinedTagsHook,
	ChangeTagsListActiveHook
{
	use GrowthConfigLoaderStaticTrait;

	public const HELP_PANEL_PREFERENCES_TOGGLE = 'growthexperiments-help-panel-tog-help-panel';

	/**
	 * Register preference to toggle help panel.
	 *
	 * @param User $user
	 * @param array &$preferences
	 */
	public function onGetPreferences( $user, &$preferences ) {
		if ( HelpPanel::isHelpPanelEnabled() ) {
			$preferences[self::HELP_PANEL_PREFERENCES_TOGGLE] = [
				'type' => 'toggle',
				'section' => 'editing/editor',
				'label-message' => self::HELP_PANEL_PREFERENCES_TOGGLE
			];
			$preferences[HelpdeskQuestionPoster::QUESTION_PREF] = [
				'type' => 'api',
			];
		}

		$context = RequestContext::getMain();
		// FIXME: Guidance doesn't need an opt-in anymore, let's remove this.
		if ( SuggestedEdits::isGuidanceEnabledForAnyone( $context )
			&& $context->getConfig()->get( 'GENewcomerTasksGuidanceRequiresOptIn' )
		) {
			$preferences[SuggestedEdits::GUIDANCE_ENABLED_PREF] = [
				'type' => 'api',
			];
		}
	}

	/**
	 * Register default preferences for Help Panel.
	 *
	 * @param array &$defaultOptions
	 */
	public function onUserGetDefaultOptions( &$defaultOptions ) {
		if ( HelpPanel::isHelpPanelEnabled() ) {
			$defaultOptions += [
				self::HELP_PANEL_PREFERENCES_TOGGLE => false
			];
		}
	}

	/** @inheritDoc */
	public function onResourceLoaderExcludeUserOptions(
		array &$keysToExclude,
		ResourceLoaderContext $context
	): void {
		$keysToExclude = array_merge( $keysToExclude, [
			HelpdeskQuestionPoster::QUESTION_PREF,
			SuggestedEdits::GUIDANCE_ENABLED_PREF,
		] );
	}

	/** @inheritDoc */
	public function onLocalUserCreated( $user, $autocreated ) {
		if ( !HelpPanel::isHelpPanelEnabled() ) {
			return;
		}

		$growthOptInOptOutOverride = HomepageHooks::getGrowthFeaturesOptInOptOutOverride();
		if ( $growthOptInOptOutOverride === HomepageHooks::GROWTH_FORCE_OPTOUT ) {
			// User opted-out from Growth features, short-circuit
			return;
		}

		// Enable the help panel for a percentage of non-autocreated users.
		$config = RequestContext::getMain()->getConfig();
		if (
			$config->get( 'GEHelpPanelNewAccountEnableWithHomepage' ) &&
			HomepageHooks::isHomepageEnabled()
		) {
			// HomepageHooks::onLocalUserCreated() will enable the help panel if needed
			return;
		}

		$enablePercentage = $config->get( 'GEHelpPanelNewAccountEnablePercentage' );
		if (
			$growthOptInOptOutOverride === HomepageHooks::GROWTH_FORCE_OPTIN ||
			( $user->isRegistered() && !$autocreated && rand( 0, 99 ) < $enablePercentage )
		) {
			MediaWikiServices::getInstance()
				->getUserOptionsManager()
				->setOption( $user, self::HELP_PANEL_PREFERENCES_TOGGLE, 1 );
		}
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$geServices = GrowthExperimentsServices::wrap( MediaWikiServices::getInstance() );
		$wikiConfig = $geServices->getGrowthWikiConfig();

		$definitelyShow = HelpPanel::shouldShowHelpPanel( $out );
		$maybeShow = HelpPanel::shouldShowHelpPanel( $out, false );

		if ( $definitelyShow ) {
			$out->enableOOUI();
			$out->addModuleStyles( 'ext.growthExperiments.HelpPanelCta.styles' );
			$out->addModuleStyles( 'ext.growthExperiments.icons' );
			$out->addModules( 'ext.growthExperiments.HelpPanel' );

			$out->addHTML( HelpPanel::getHelpPanelCtaButton() );
		}

		if ( SuggestedEdits::isGuidanceEnabled( $out->getContext() ) ) {
			// Note: wgGELinkRecommendationsFrontendEnabled reflects the configuration flag.
			// Checking whether Add Link has been disabled in community configuration is the
			// frontend code's responsibility.
			$out->addJsConfigVars( [
				'wgGENewcomerTasksGuidanceEnabled' => true,
				'wgGEAskQuestionEnabled' => HelpPanel::getHelpDeskTitle( $wikiConfig ) !== null,
				'wgGELinkRecommendationsFrontendEnabled' =>
					$out->getConfig()->get( 'GELinkRecommendationsFrontendEnabled' )
			] );
		}

		// If the help panel would be shown but for the value of the 'action' parameter,
		// add the email config var anyway. We'll need it if the user loads an editor via JS.
		// Also set wgGEHelpPanelEnabled to let our JS modules know it's safe to display the help panel.
		if ( $maybeShow ) {
			$mentorManager = $geServices->getMentorManager();
			$out->addJsConfigVars( [
				// We know the help panel is enabled, otherwise we wouldn't get here
				'wgGEHelpPanelEnabled' => true,
				'wgGEHelpPanelMentorData'
					=> $this->getMentorData( $wikiConfig, $out->getUser(), $out->getContext() ),
				// wgGEHelpPanelAskMentor needs to be here and not in getModuleData,
				// because getting current user is not possible within ResourceLoader context
				'wgGEHelpPanelAskMentor' =>
					$wikiConfig->get( 'GEMentorshipEnabled' ) &&
					$wikiConfig->get( 'GEHelpPanelAskMentor' ) &&
					$mentorManager->getMentorshipStateForUser(
						$out->getUser()
					) === MentorManager::MENTORSHIP_ENABLED &&
					$mentorManager->getEffectiveMentorForUserSafe( $out->getUser() ) !== null,
			] + HelpPanel::getUserEmailConfigVars( $out->getUser() ) );

			if ( !$definitelyShow ) {
				// Add the init module to make sure that the main HelpPanel module is loaded
				// if and when VisualEditor is loaded
				$out->addModules( 'ext.growthExperiments.HelpPanel.init' );
			}
		}
	}

	/**
	 * Build the contents of the data.json file in the ext.growthExperiments.Help module.
	 * @param ResourceLoaderContext $context
	 * @param Config $config
	 * @return array
	 */
	public static function getModuleData( ResourceLoaderContext $context, Config $config ) {
		$helpdeskTitle = HelpPanel::getHelpDeskTitle( self::getGrowthWikiConfig() );
		// The copyright warning can contain markup and has to be parsed via PHP messages API.
		$copyrightWarningMessage = $context->msg( 'wikimedia-copyrightwarning' );
		return [
			'GEHelpPanelLoggingEnabled' => $config->get( 'GEHelpPanelLoggingEnabled' ),
			'GEHelpPanelSearchNamespaces' => self::getGrowthWikiConfig()
				->get( 'GEHelpPanelSearchNamespaces' ),
			'GEHelpPanelReadingModeNamespaces' => self::getGrowthWikiConfig()
				->get( 'GEHelpPanelReadingModeNamespaces' ),
			'GEHelpPanelSearchForeignAPI' => $config->get( 'GEHelpPanelSearchForeignAPI' ),
			'GEHelpPanelLinks' => HelpPanel::getHelpPanelLinks(
				$context, self::getGrowthWikiConfig()
			),
			'GEHelpPanelSuggestedEditsPreferredEditor' => self::getPreferredEditor( $context, $config ),
			'GEHelpPanelHelpDeskTitle' => $helpdeskTitle ? $helpdeskTitle->getPrefixedText() : null,
			'GEAskHelpCopyrightWarning' => $copyrightWarningMessage->exists() ?
				$copyrightWarningMessage->parse() : ''
		];
	}

	/** @inheritDoc */
	public function onListDefinedTags( &$tags ) {
		if ( HelpPanel::isHelpPanelEnabled() ) {
			$tags[] = HelpPanel::HELPDESK_QUESTION_TAG;
		}
	}

	/** @inheritDoc */
	public function onChangeTagsListActive( &$tags ) {
		if ( HelpPanel::isHelpPanelEnabled() ) {
			$tags[] = HelpPanel::HELPDESK_QUESTION_TAG;
		}
	}

	/**
	 * @param Config $wikiConfig
	 * @param User $user
	 * @param MessageLocalizer $localizer
	 * @return array
	 */
	private function getMentorData(
		Config $wikiConfig,
		User $user,
		MessageLocalizer $localizer
	): array {
		if ( !$wikiConfig->get( 'GEHelpPanelAskMentor' ) || !$wikiConfig->get( 'GEMentorshipEnabled' ) ) {
			return [];
		}
		$genderCache = MediaWikiServices::getInstance()->getGenderCache();
		$mentorManager = GrowthExperimentsServices::wrap( MediaWikiServices::getInstance() )
			->getMentorManager();
		$mentor = $mentorManager->getMentorForUserSafe( $user );
		$effectiveMentor = $mentorManager->getEffectiveMentorForUserSafe( $user );
		if ( !$mentor || !$effectiveMentor ) {
			return [];
		}
		return [
			'name' => $mentor->getUserIdentity()->getName(),
			'gender' => $genderCache->getGenderOf(
				$mentor->getUserIdentity(),
				__METHOD__
			),
			'effectiveName' => $effectiveMentor->getUserIdentity()->getName(),
			'effectiveGender' => $genderCache->getGenderOf(
				$effectiveMentor->getUserIdentity(),
				__METHOD__
			),
			'editCount' => MediaWikiServices::getInstance()->getUserEditTracker()
				->getUserEditCount( $mentor->getUserIdentity() ),
			'lastActive' => Mentorship::getMentorLastActive( $mentor->getUserIdentity(), $user, $localizer ),
		];
	}

	/**
	 * Return preferred editor for each task type based on task type handler
	 *
	 * @param ResourceLoaderContext $context
	 * @param Config $config
	 * @return array
	 */
	private static function getPreferredEditor( ResourceLoaderContext $context, Config $config ): array {
		$geServices = GrowthExperimentsServices::wrap( MediaWikiServices::getInstance() );

		// Hack - ResourceLoaderContext is not exposed to services initialization
		$validator = $geServices->getNewcomerTasksConfigurationValidator();
		$validator->setMessageLocalizer( $context );

		$taskTypes = $geServices->getNewcomerTasksConfigurationLoader()->getTaskTypes();
		$preferredEditorPerHandlerId = $config->get( 'GEHelpPanelSuggestedEditsPreferredEditor' );
		$preferredEditorPerTaskType = [];
		foreach ( $taskTypes as $taskTypeId => $taskType ) {
			$preferredEditorPerTaskType[ $taskTypeId ] = $preferredEditorPerHandlerId[ $taskType->getHandlerId() ];
		}
		return $preferredEditorPerTaskType;
	}

}

<?php

namespace GrowthExperiments\Specials;

use Config;
use DerivativeContext;
use GrowthExperiments\DashboardModule\IDashboardModule;
use GrowthExperiments\ExperimentUserManager;
use GrowthExperiments\HomepageModules\Impact;
use GrowthExperiments\HomepageModules\SuggestedEdits;
use Html;
use MediaWiki\Extensions\PageViewInfo\PageViewService;
use MediaWiki\User\UserOptionsLookup;
use SpecialPage;
use TitleFactory;
use User;
use Wikimedia\Rdbms\IDatabase;

class SpecialImpact extends SpecialPage {

	/**
	 * @var IDatabase
	 */
	private $dbr;

	/**
	 * @var PageViewService|null
	 */
	private $pageViewService;
	/**
	 * @var ExperimentUserManager
	 */
	private $experimentUserManager;

	/**
	 * @var TitleFactory
	 */
	private $titleFactory;

	/** @var Config */
	private $wikiConfig;

	/** @var UserOptionsLookup */
	private $userOptionsLookup;

	/**
	 * @param IDatabase $dbr
	 * @param ExperimentUserManager $experimentUserManager
	 * @param TitleFactory $titleFactory
	 * @param Config $wikiConfig
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param PageViewService|null $pageViewService
	 */
	public function __construct(
		IDatabase $dbr,
		ExperimentUserManager $experimentUserManager,
		TitleFactory $titleFactory,
		Config $wikiConfig,
		UserOptionsLookup $userOptionsLookup,
		PageViewService $pageViewService = null
	) {
		parent::__construct( 'Impact' );
		$this->dbr = $dbr;
		$this->pageViewService = $pageViewService;
		$this->experimentUserManager = $experimentUserManager;
		$this->titleFactory = $titleFactory;
		$this->wikiConfig = $wikiConfig;
		$this->userOptionsLookup = $userOptionsLookup;
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'growthexperiments-specialimpact-title' )->text();
	}

	/**
	 * Render the impact module in following conditions:
	 *
	 * - user is logged out, $par must be a valid username
	 * - user is logged-in, $par is not set
	 * - user is logged-in, $par is set to a valid username
	 *
	 * Error if:
	 *
	 * - user is logged-in, $par is set to an invalid username
	 * - user is logged-out and $par is not supplied
	 *
	 * @param string|null $par
	 * @return void
	 */
	public function execute( $par ) {
		parent::execute( $par );
		$impactUser = $this->getUser();
		// If an argument was supplied, attempt to load a user.
		if ( $par ) {
			$impactUser = User::newFromName( $par );
		}
		$out = $this->getContext()->getOutput();
		// If we don't have a user (logged-in or from argument) then error out.
		if ( !$impactUser || !$impactUser->getId() ) {
			$out->addHTML( Html::element( 'p', [ 'class' => 'error' ], $this->msg(
				'growthexperiments-specialimpact-invalid-username'
			)->text() ) );
			return;
		}
		$out->enableOOUI();
		// Use a derivative context as we might be modifying the user.
		$context = new DerivativeContext( $this->getContext() );
		if ( !$impactUser->equals( $this->getUser() ) ) {
			// Add warning if viewing someone else's impact data.
			$out->addHTML(
				Html::element( 'p', [ 'class' => 'warning' ],
					$this->msg(
					'growthexperiments-specialimpact-showing-for-other-user'
					)->plaintextParams( $impactUser->getName() )
				->text() ) );
		}
		$context->setUser( $impactUser );
		$impact = new Impact(
			$context,
			$this->wikiConfig,
			$context->getConfig()->get( 'GEHomepageImpactModuleEnabled' ),
			$this->dbr,
			$this->experimentUserManager,
			[
				'isSuggestedEditsEnabled' => SuggestedEdits::isEnabled( $context ),
				'isSuggestedEditsActivated' => SuggestedEdits::isActivated( $context, $this->userOptionsLookup ),
			],
			$this->titleFactory,
			$this->pageViewService
		);
		$out->addHTML( $impact->render( IDashboardModule::RENDER_DESKTOP ) );
	}
}

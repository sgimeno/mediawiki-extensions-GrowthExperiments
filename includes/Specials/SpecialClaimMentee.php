<?php

namespace GrowthExperiments\Specials;

use FormSpecialPage;
use GrowthExperiments\Mentorship\ChangeMentor;
use GrowthExperiments\Mentorship\MentorManager;
use GrowthExperiments\Mentorship\MentorPageMentorManager;
use GrowthExperiments\WikiConfigException;
use Linker;
use LogEventsList;
use LogPager;
use MediaWiki\Logger\LoggerFactory;
use Message;
use PermissionsError;
use Status;
use User;

class SpecialClaimMentee extends FormSpecialPage {
	/**
	 * @var array
	 */
	private $mentees;

	/**
	 * @var User|null
	 */
	private $newMentor;

	/**
	 * @var string[]|null List of mentors that can be assigned.
	 */
	private $mentorsList;
	/**
	 * @var MentorManager
	 */
	private $mentorManager;

	/**
	 * @param MentorManager $mentorManager
	 */
	public function __construct( MentorManager $mentorManager ) {
		parent::__construct( 'ClaimMentee' );
		$this->mentorManager = $mentorManager;
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription() {
		return $this->msg( 'growthexperiments-homepage-claimmentee-title' )->text();
	}

	protected function preText() {
		return $this->msg( 'growthexperiments-homepage-claimmentee-pretext' )->params(
			$this->getUser()->getName()
		)->escaped();
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->requireLogin();
		$this->addHelpLink( 'Help:Growth/Tools/How to claim a mentee' );
		parent::execute( $par );
	}

	/**
	 * @inheritDoc
	 */
	public function isListed() {
		return $this->userCanExecute( $this->getUser() );
	}

	/**
	 * @inheritDoc
	 */
	public function userCanExecute( User $user ) {
		try {
			$this->mentorsList = $this->mentorManager->getMentors();
		} catch ( WikiConfigException $wikiConfigException ) {
			return false;
		}
		return in_array( $user->getName(), $this->mentorsList );
	}

	/**
	 * @inheritDoc
	 */
	public function displayRestrictionError() {
		if ( $this->mentorsList === null ) {
			throw new PermissionsError(
				null,
				[ 'growthexperiments-homepage-mentors-list-missing-or-misconfigured-generic' ]
			);
		}

		if ( $this->mentorManager instanceof MentorPageMentorManager ) {
			// User is not signed up at a page
			if ( $this->mentorManager->getManuallyAssignedMentorsPage() !== null ) {
				// User is not signed up at either auto-assignment page, or the manual page
				$error = [ 'growthexperiments-homepage-claimmentee-must-be-mentor-two-lists',
					$this->getUser(),
					str_replace(
						'_',
						' ',
						$this->getConfig()->get( 'GEHomepageMentorsList' )
					),
					str_replace(
						'_',
						' ',
						$this->getConfig()->get( 'GEHomepageManualAssignmentMentorsList' )
					)
				];
			} else {
				// User is not signed up at the auto assignment page
				$error = [ 'growthexperiments-homepage-claimmentee-must-be-mentor',
					$this->getUser(),
					str_replace(
						'_',
						' ',
						$this->getConfig()->get( 'GEHomepageMentorsList' )
					)
				];
			}
		} else {
			// User is just not a mentor, display a generic access denied message - no details available
			$error = [ 'growthexperiments-homepage-claimmentee-must-be-mentor-generic', $this->getUser() ];
		}

		throw new PermissionsError( null, [ $error ] );
	}

	/**
	 * Get an HTMLForm descriptor array
	 * @return array
	 */
	protected function getFormFields() {
		$req = $this->getRequest();
		$fields = [
			'mentees' => [
				'label-message' => 'growthexperiments-homepage-claimmentee-mentee',
				'type'          => 'usersmultiselect',
				'exists'        => true,
				'required'      => true
			],
			'reason' => [
				'label-message' => 'growthexperiments-homepage-claimmentee-reason',
				'type'          => 'text',
			],
			'stage' => [ 'type' => 'hidden', 'default' => 2 ]
		];
		$stage = $req->getInt( 'wpstage', 1 );
		$this->setMentees( $req->getVal( 'wpmentees' ) );
		if ( $stage >= 2 && $this->validateMentees() ) {
			$fields['stage']['default'] = 3;
			$fields['confirm'] = [
				'label-message' => 'growthexperiments-claimmentee-confirm',
				'type' => 'check',
				'default' => false,
			];
		}
		return $fields;
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/**
	 * @inheritDoc
	 */
	public function onSubmit( array $data ) {
		$this->setMentees( $data['mentees'] );

		// Should be caught by exits => true, but just to be sure
		if ( !$this->validateMentees() ) {
			return Status::newFatal( 'growthexperiments-homepage-claimmentee-invalid-username' );
		}

		$this->newMentor = $this->getUser();

		$status = Status::newGood();
		$logger = LoggerFactory::getInstance( 'GrowthExperiments' );
		$context = $this->getContext();
		foreach ( $this->mentees as $mentee ) {
			$changementor = new ChangeMentor(
				$mentee,
				$this->newMentor,
				$context,
				$logger,
				$this->mentorManager->getMentorForUser( $mentee ),
				new LogPager(
					new LogEventsList( $context ),
					[ 'growthexperiments' ],
					'',
					$mentee->getUserPage()
				),
				$this->mentorManager
			);

			if (
				$data['confirm'] !== true
				&& $data['stage'] !== 3
				&& $changementor->wasMentorChanged()
			) {
				return Status::newFatal(
					'growthexperiments-homepage-claimmentee-alreadychanged',
					$mentee,
					$this->newMentor
				);
			}

			$status->merge( $changementor->execute( $this->newMentor, $data['reason'] ) );
			if ( !$status->isOK() ) {
				// Do not process next users if at least one failed
				return $status;
			}
		}

		return $status;
	}

	public function onSuccess() {
		$mentees = array_map( function ( $user ) {
			return Linker::userLink( $user->getId(), $user->getName() );
		}, $this->mentees );

		$language = $this->getLanguage();

		$this->getOutput()->addWikiMsg(
			'growthexperiments-homepage-claimmentee-success',
			Message::rawParam( $language->listToText( $mentees ) ),
			$language->formatNum( count( $mentees ) ),
			$this->getUser()->getName(),
			$this->newMentor->getName(),
			Message::rawParam( $this->getLinkRenderer()->makeLink(
				$this->newMentor->getUserPage(), $this->newMentor->getName() ) )
		);
	}

	private function setMentees( $namesRaw = '' ) {
		$names = explode( "\n", $namesRaw );
		$this->mentees = [];

		foreach ( $names as $name ) {
			$user = User::newFromName( $name );
			if ( $user !== false ) {
				$this->mentees[] = $user;
			}
		}
	}

	private function validateMentees() {
		foreach ( $this->mentees as $mentee ) {
			if ( ( $mentee instanceof User && $mentee->getId() !== 0 ) !== true ) {
				return false;
			}
		}
		return true;
	}
}

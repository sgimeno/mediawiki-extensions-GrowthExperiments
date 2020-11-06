<?php

namespace GrowthExperiments\HelpPanel\QuestionPoster;

use CommentStoreComment;
use Config;
use Content;
use DerivativeContext;
use ExtensionRegistry;
use FatalError;
use Flow\Container;
use GrowthExperiments\HelpPanel\QuestionRecord;
use GrowthExperiments\HelpPanel\QuestionStoreFactory;
use Hooks;
use IContextSource;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\PageUpdater;
use MWException;
use Status;
use Title;
use UserNotLoggedIn;
use WikiPage;
use WikitextContent;

/**
 * Base class for sending messages containing user questions to some target page.
 */
abstract class QuestionPoster {

	/**
	 * @var WikiPageFactory
	 */
	private $wikiPageFactory;

	/**
	 * @var PermissionManager
	 */
	private $permissionManager;

	/**
	 * @var bool
	 */
	private $postOnTop = false;

	/**
	 * @var IContextSource
	 */
	private $context;

	/**
	 * @var bool
	 */
	private $isFirstEdit;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var Title
	 */
	private $targetTitle;

	/**
	 * @var string
	 */
	private $resultUrl;

	/**
	 * @var PageUpdater
	 */
	protected $pageUpdater;

	/**
	 * @var mixed
	 */
	private $revisionId;

	/**
	 * @var string
	 */
	protected $relevantTitle;

	/**
	 * @var string
	 */
	private $postedOnTimestamp;

	/**
	 * @var QuestionRecord[]
	 */
	private $existingQuestionsByUser;

	/**
	 * @var string
	 */
	private $body;

	/**
	 * @var string
	 */
	private $sectionHeader;

	/**
	 * @param WikiPageFactory $wikiPageFactory
	 * @param PermissionManager $permissionManager
	 * @param IContextSource $context
	 * @param string $body
	 * @param string $relevantTitle
	 * @throws UserNotLoggedIn
	 */
	public function __construct(
		WikiPageFactory $wikiPageFactory,
		PermissionManager $permissionManager,
		IContextSource $context,
		$body,
		$relevantTitle = ''
	) {
		$this->wikiPageFactory = $wikiPageFactory;
		$this->permissionManager = $permissionManager;
		$this->context = $context;
		$this->relevantTitle = $relevantTitle;
		if ( $this->getContext()->getUser()->isAnon() ) {
			throw new UserNotLoggedIn();
		}
		$this->config = $this->getContext()->getConfig();
		$this->isFirstEdit = ( $this->getContext()->getUser()->getEditCount() === 0 );
		$this->targetTitle = $this->getTargetTitle();
		$page = new WikiPage( $this->targetTitle );
		$this->pageUpdater = $page->newPageUpdater( $this->getContext()->getUser() );
		$this->body = trim( $body );
	}

	/**
	 * Whether to post on top of the help desk (as opposed to the bottom). Defaults to false.
	 * Only affects wikitext pages.
	 * @param bool $postOnTop
	 */
	public function setPostOnTop( bool $postOnTop ): void {
		$this->postOnTop = $postOnTop;
	}

	/**
	 * Load the current user's existing questions.
	 */
	protected function loadExistingQuestions() {
		$questionStore = QuestionStoreFactory::newFromContextAndStorage(
			$this->getContext(),
			$this->getQuestionStoragePref()
		);
		$this->existingQuestionsByUser = $questionStore->loadQuestions();
	}

	/**
	 * @return Status
	 * @throws MWException
	 * @throws \Exception
	 */
	public function submit() {
		$this->loadExistingQuestions();

		// Do not let captcha to stop us
		if ( ExtensionRegistry::getInstance()->isLoaded( 'ConfirmEdit' ) ) {
			$scope = $this->permissionManager->addTemporaryUserRights(
				$this->getContext()->getUser(),
				'skipcaptcha'
			);
		}

		$this->postedOnTimestamp = wfTimestamp();
		$this->setSectionHeader();

		$contentModel = $this->getTargetContentModel();
		if ( $contentModel === CONTENT_MODEL_WIKITEXT ) {
			$status = $this->submitWikitext();
		} elseif (
			ExtensionRegistry::getInstance()->isLoaded( 'Flow' ) &&
			$contentModel === CONTENT_MODEL_FLOW_BOARD
		) {
			$status = $this->submitStructuredDiscussions();
		} else {
			throw new \Exception( "Content model $contentModel is not supported." );
		}

		if ( $status->isGood() ) {
			$this->saveNewQuestion();
		}

		return $status;
	}

	/**
	 * @return string Content model of the target page. One of the CONTENT_MODEL_* constants.
	 */
	protected function getTargetContentModel() {
		return $this->targetTitle->getContentModel();
	}

	/**
	 * @return Status
	 * @throws MWException
	 * @throws \Exception
	 */
	private function submitWikitext() {
		$content = $this->makeWikitextContent();

		$contentStatus = $this->checkContent( $content );
		if ( !$contentStatus->isGood() ) {
			return $contentStatus;
		}
		$permissionStatus = $this->checkPermissions( $content );
		if ( !$permissionStatus->isGood() ) {
			return $permissionStatus;
		}

		$this->getPageUpdater()->addTag( $this->getTag() );
		$this->getPageUpdater()->setContent( SlotRecord::MAIN, $content );
		$newRev = $this->getPageUpdater()->saveRevision(
			CommentStoreComment::newUnsavedComment(
				$this->getContext()
				->msg( 'newsectionsummary' )
				->params(
					MediaWikiServices::getInstance()
					->getParserFactory()
					->create()
					->stripSectionName( $this->getSectionHeader() )
				)
				->text()
			)
		);
		if ( !$this->getPageUpdater()->getStatus()->isGood() ) {
			return $this->getPageUpdater()->getStatus();
		}

		$this->revisionId = $newRev->getId();
		$this->targetTitle->setFragment(
			MediaWikiServices::getInstance()
				->getParser()
				->guessSectionNameFromWikiText( $this->getSectionHeader() )
		);
		$this->setResultUrl( $this->targetTitle->getLinkURL() );

		return Status::newGood();
	}

	/**
	 * @return Status
	 * @throws MWException
	 */
	private function submitStructuredDiscussions() {
		$workflowLoaderFactory = Container::get( 'factory.loader.workflow' );
		$loader = $workflowLoaderFactory->createWorkflowLoader( $this->targetTitle );
		$blocks = $loader->handleSubmit(
			$this->getContext(),
			'new-topic',
			[
				'topiclist' => [
					'topic' => $this->getSectionHeader(),
					'content' => $this->getBody(),
					'format' => 'wikitext',
				],
			]
		);

		$status = Status::newGood();
		foreach ( $blocks as $block ) {
			if ( $block->hasErrors() ) {
				$errors = $block->getErrors();
				foreach ( $errors as $errorKey ) {
					$status->fatal( $block->getErrorMessage( $errorKey ) );
				}
			}
		}
		if ( !$status->isOK() ) {
			return $status;
		}

		$commitMetadata = $loader->commit( $blocks );

		$topicTitle = Title::newFromText( $commitMetadata['topiclist']['topic-page'] );
		$this->setResultUrl( $topicTitle->getLinkURL() );
		$this->revisionId = $commitMetadata['topiclist']['topic-id']->getAlphadecimal();

		return Status::newGood();
	}

	private function getNumberedSectionHeaderIfDuplicatesExist( $sectionHeader ) {
		$sectionHeaders = array_map(
			function ( QuestionRecord $questionRecord ) {
				return $questionRecord->getSectionHeader();
			},
			$this->existingQuestionsByUser
		);
		$counter = 1;
		while ( in_array( $counter === 1 ? $sectionHeader : "$sectionHeader ($counter)",
			$sectionHeaders ) ) {
			$counter++;
		}
		return $counter === 1 ? $sectionHeader : $sectionHeader . ' (' . $counter . ')';
	}

	/**
	 * @param Content $content
	 * @return Status
	 * @throws FatalError
	 * @throws MWException
	 */
	protected function checkPermissions( $content ) {
		$userPermissionStatus = $this->checkUserPermissions();
		if ( !$userPermissionStatus->isGood() ) {
			return $userPermissionStatus;
		}
		$editFilterMergedContentHookStatus = $this->runEditFilterMergedContentHook(
			$content,
			$this->getSectionHeaderTemplate()
		);
		if ( !$editFilterMergedContentHookStatus->isGood() ) {
			return $editFilterMergedContentHookStatus;
		}
		return Status::newGood();
	}

	/**
	 * The tag to add to the edit.
	 */
	abstract protected function getTag();

	/**
	 * Create a Content object with the header and question text provided by the user.
	 *
	 * @return Content|null
	 * @throws MWException
	 */
	protected function makeWikitextContent() {
		$wikitextContent = new WikitextContent(
			$this->addSignature( $this->getBody() )
		);
		$header = $this->getSectionHeader();
		$parent = $this->getPageUpdater()->grabParentRevision();
		if ( !$parent ) {
			return $wikitextContent->addSectionHeader( $header );
		}
		$existingContent = $parent->getContent( SlotRecord::MAIN );
		if ( !$existingContent ) {
			return null;
		}

		if ( $this->postOnTop ) {
			$section1 = $existingContent->getSection( 1 );
			if ( $section1 ) {
				// Prepend to section 1 to post on top without disturbing top-of-the-page templates
				return $existingContent->replaceSection( 1,
					$wikitextContent->replaceSection( 'new', $section1 )->addSectionHeader( $header ) );
			}
			// No sections on the page - just post on bottom.
		}
		return $existingContent->replaceSection(
			'new',
			$wikitextContent,
			$header
		);
	}

	/**
	 * @return PageUpdater
	 */
	protected function getPageUpdater() {
		return $this->pageUpdater;
	}

	/**
	 * Add signature unless already set.
	 *
	 * @param string $body
	 * @return string
	 */
	private function addSignature( $body ) {
		if ( strpos( $body, '~~~~' ) === false ) {
			$body .= " --~~~~";
		}
		return $body;
	}

	/**
	 * @return Status
	 */
	public function validateRelevantTitle() {
		$title = Title::newFromText( $this->relevantTitle );
		return $title && $title->isValid() ?
			Status::newGood() :
			Status::newFatal( 'growthexperiments-help-panel-questionposter-invalid-title' );
	}

	/**
	 * @return string
	 */
	public function getResultUrl() {
		return $this->resultUrl;
	}

	/**
	 * @return int
	 */
	public function getRevisionId() {
		return $this->revisionId;
	}

	/**
	 * @return bool
	 */
	public function isFirstEdit() {
		return $this->isFirstEdit;
	}

	/**
	 * Get the section header template for the question posted by the user.
	 *
	 * This method is used for generating the comment summary as well as the
	 * section header in the edit.
	 *
	 * @return string
	 */
	abstract protected function getSectionHeaderTemplate();

	/**
	 * Set the result URL to go directly to the newly created question.
	 *
	 * @param string $resultUrl
	 */
	private function setResultUrl( $resultUrl ) {
		$this->resultUrl = $resultUrl;
	}

	/**
	 * Set the section header with a timestamp (wikitext only) and number.
	 *
	 * THe number is appended for flow posts. For wikitext posts, a number is appended
	 * only if duplicate headers exist, which can happen when questions
	 * are posted within the same minute.
	 */
	protected function setSectionHeader() {
		$this->sectionHeader = $this->getSectionHeaderTemplate();
		// If wikitext, override the section header to include the timestamp.
		if ( $this->getTargetContentModel() === CONTENT_MODEL_WIKITEXT ) {
			$this->sectionHeader .= ' ' . $this->getContext()
					->msg( 'parentheses' )
					->plaintextParams( $this->getFormattedPostedOnTimestamp() )
					->inContentLanguage()
					->escaped();
		}
		$this->sectionHeader = $this->getNumberedSectionHeaderIfDuplicatesExist(
			$this->sectionHeader
		);
	}

	/**
	 * @return string
	 */
	private function getSectionHeader() {
		return $this->sectionHeader;
	}

	/**
	 * @return string
	 */
	private function getPostedOnTimestamp() {
		return $this->postedOnTimestamp;
	}

	/**
	 * Timezone adjustment, site default format, and site default time zone are used for formatting.
	 * @return string
	 */
	private function getFormattedPostedOnTimestamp() {
		return MediaWikiServices::getInstance()->getContentLanguage()
			->timeanddate( $this->getPostedOnTimestamp(), true, false, '' );
	}

	/**
	 * @return Title The page where the question should be posted.
	 */
	protected function getTargetTitle() : Title {
		$title = $this->getDirectTargetTitle();
		if ( $title->isRedirect() ) {
			$page = $this->wikiPageFactory->newFromTitle( $title );
			return $page->getRedirectTarget();
		}
		return $title;
	}

	/**
	 * @return Title The page where the question should be posted (barring redirects).
	 */
	abstract protected function getDirectTargetTitle();

	/**
	 * @return IContextSource
	 */
	final protected function getContext() {
		return $this->context;
	}

	/**
	 * The preference name where the posted question will be stored.
	 *
	 * @return string
	 */
	abstract protected function getQuestionStoragePref();

	/**
	 * @return Status
	 * @throws \Exception
	 */
	protected function checkUserPermissions() {
		$errors = $this->permissionManager->getPermissionErrors(
			'edit',
			$this->getContext()->getUser(),
			$this->targetTitle
		);

		if ( count( $errors ) ) {
			$key = array_shift( $errors[0] );
			$message = $this->getContext()->msg( $key )
				->params( $errors[0] )
				->parse();
			return Status::newFatal( $message );
		}
		return Status::newGood();
	}

	/**
	 * @param Content $content
	 * @param string $summary
	 * @return Status
	 * @throws MWException
	 * @throws FatalError
	 */
	protected function runEditFilterMergedContentHook( Content $content, $summary ) {
		$derivativeContext = new DerivativeContext( $this->getContext() );
		$derivativeContext->setConfig( MediaWikiServices::getInstance()->getMainConfig() );
		$derivativeContext->setTitle( $this->targetTitle );
		$derivativeContext->setWikiPage( WikiPage::factory( $this->targetTitle ) );
		$status = new Status();
		if ( !Hooks::run( 'EditFilterMergedContent', [
			$derivativeContext,
			$content,
			$status,
			$summary,
			$derivativeContext->getUser(),
			false
		] ) ) {
			if ( $status->isGood() ) {
				$status->fatal( 'hookaborted' );
			}
			return $status;
		}
		return $status;
	}

	private function getBody() {
		return $this->body;
	}

	/**
	 * Validate that $content is an instance of Content
	 *
	 * @param Content|null $content
	 * @return Status
	 */
	protected function checkContent( $content ) {
		return $content instanceof Content ?
			Status::newGood() :
			Status::newFatal(
				'apierror-missingcontent-revid',
				$this->getPageUpdater()->grabParentRevision()->getId()
			);
	}

	private function saveNewQuestion() {
		$question = new QuestionRecord(
			$this->getBody(),
			$this->getSectionHeader(),
			$this->revisionId,
			$this->getPostedOnTimestamp(),
			$this->getResultUrl(),
			$this->getTargetContentModel()
		);
		QuestionStoreFactory::newFromContextAndStorage(
			$this->getContext(),
			$this->getQuestionStoragePref()
		)->add( $question );
	}

}

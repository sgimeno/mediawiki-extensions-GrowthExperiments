<?php

namespace GrowthExperiments\Rest\Handler;

use CirrusSearch\CirrusSearch;
use GrowthExperiments\ErrorException;
use GrowthExperiments\NewcomerTasks\AddLink\LinkRecommendationHelper;
use GrowthExperiments\NewcomerTasks\AddLink\LinkRecommendationLink;
use GrowthExperiments\NewcomerTasks\AddLink\LinkSubmissionRecorder;
use GrowthExperiments\NewcomerTasks\TaskType\LinkRecommendationTaskTypeHandler;
use GrowthExperiments\Util;
use MalformedTitleException;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\ParamValidator\TypeDef\TitleDef;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use RequestContext;
use Status;
use TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Record the user's decision on the recommendations for a given page.
 */
class AddLinkSubmissionHandler extends SimpleHandler {

	/** @var LinkRecommendationHelper */
	private $linkRecommendationHelper;

	/** @var LinkSubmissionRecorder */
	private $addLinkSubmissionRecorder;

	/** @var TitleFactory */
	private $titleFactory;

	/** @var callable */
	private $cirrusSearchFactory;

	/**
	 * @param LinkRecommendationHelper $linkRecommendationHelper
	 * @param LinkSubmissionRecorder $addLinkSubmissionRecorder
	 * @param TitleFactory $titleFactory
	 * @param callable $cirrusSearchFactory A factory method returning a CirrusSearch instance.
	 */
	public function __construct(
		LinkRecommendationHelper $linkRecommendationHelper,
		LinkSubmissionRecorder $addLinkSubmissionRecorder,
		TitleFactory $titleFactory,
		callable $cirrusSearchFactory
	) {
		$this->linkRecommendationHelper = $linkRecommendationHelper;
		$this->addLinkSubmissionRecorder = $addLinkSubmissionRecorder;
		$this->titleFactory = $titleFactory;
		$this->cirrusSearchFactory = $cirrusSearchFactory;
	}

	/**
	 * Entry point.
	 * @param LinkTarget $title
	 * @return Response|mixed A Response or a scalar passed to ResponseFactory::createFromReturnValue
	 * @throws HttpException
	 */
	public function run( LinkTarget $title ) {
		if ( !Util::areLinkRecommendationsEnabled( RequestContext::getMain() ) ) {
			throw new HttpException( 'Disabled', 404 );
		}
		$user = RequestContext::getMain()->getUser();
		if ( $user->isAnon() ) {
			throw new HttpException( 'Must be logged in', 403 );
		}
		// should we also check the user's Tracker?

		try {
			$linkRecommendation = $this->linkRecommendationHelper->getLinkRecommendation( $title );
		} catch ( ErrorException $e ) {
			throw new HttpException( $e->getErrorMessageInEnglish() );
		}
		if ( !$linkRecommendation ) {
			throw new HttpException( 'None of the links in the recommendation are valid', 409 );
		}
		$expectedRevId = $linkRecommendation->getRevisionId();
		$links = $this->normalizeTargets( $linkRecommendation->getLinks() );

		// FIXME fix JsonBodyValidator so it actually validates
		$data = $this->getValidatedBody();
		$baseRevId = (int)$data['baseRevId'];
		$editRevId = (int)$data['editRevId'] ?: null;
		$acceptedTargets = $this->normalizeTargets( $data['acceptedTargets'] ?: [] );
		$rejectedTargets = $this->normalizeTargets( $data['rejectedTargets'] ?: [] );
		$skippedTargets = $this->normalizeTargets( $data['skippedTargets'] ?: [] );

		$allTargets = array_merge( $acceptedTargets, $rejectedTargets, $skippedTargets );
		$unexpectedTargets = array_diff( $allTargets, $links );
		$missingTargets = array_diff( $links, $allTargets );
		if ( $baseRevId !== $expectedRevId ) {
			throw new HttpException( "Invalid revision ID: expected $expectedRevId, got $baseRevId" );
		} elseif ( $unexpectedTargets ) {
			throw new HttpException( 'Unexpected link targets: ' . implode( ', ', $unexpectedTargets ) );
		} elseif ( $missingTargets ) {
			throw new HttpException( 'Missing link targets: ' . implode( ', ', $missingTargets ) );
		}

		if ( !$editRevId ) {
			// The search index is updated after an edit; with no edit, we have to do it manually.
			$cirrusSearch = ( $this->cirrusSearchFactory )();
			$pageIdentity = $this->titleFactory->newFromLinkTarget( $title )->toPageIdentity();
			$cirrusSearch->resetWeightedTags( $pageIdentity,
				LinkRecommendationTaskTypeHandler::WEIGHTED_TAG_PREFIX );
		}

		$status = $this->addLinkSubmissionRecorder->record( $user, $linkRecommendation, $acceptedTargets,
			$rejectedTargets, $skippedTargets, $editRevId );
		if ( !$status->isOK() ) {
			throw new HttpException( Status::wrap( $status )->getWikiText( null, null, 'en' ) );
		}
		$result = $status->getValue();
		return [ 'success' => true, 'logId' => $result['logId'] ];
	}

	/** @inheritDoc */
	public function getParamSettings() {
		return [
			'title' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'title',
				ParamValidator::PARAM_REQUIRED => true,
				TitleDef::PARAM_RETURN_OBJECT => true,
			],
		];
	}

	/** @inheritDoc */
	public function getBodyValidator( $contentType ) {
		return new JsonBodyValidator( [
			'baseRevId' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'editRevId' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'acceptedTargets' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'title',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ALLOW_DUPLICATES => true,
			],
			'rejectedTargets' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'title',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ALLOW_DUPLICATES => true,
			],
			'skippedTargets' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'title',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_ISMULTI => true,
				ParamValidator::PARAM_ALLOW_DUPLICATES => true,
			],
		] );
	}

	/**
	 * Normalize link targets into prefixed dbkey format
	 * @param array<int,string|LinkTarget|LinkRecommendationLink> $targets
	 * @return string[]
	 * @throws HttpException
	 */
	private function normalizeTargets( array $targets ): array {
		$normalized = [];
		foreach ( $targets as $target ) {
			if ( $target instanceof LinkRecommendationLink ) {
				$target = $target->getLinkTarget();
			}
			if ( !$target instanceof LinkTarget ) {
				try {
					$target = $this->titleFactory->newFromTextThrow( $target );
				} catch ( MalformedTitleException $e ) {
					$error = $e->getMessageObject()->inLanguage( 'en' )
						->useDatabase( false )->text();
					throw new HttpException( "Could not parse title: $target ($error)" );
				}
			}
			$normalized[] = $this->titleFactory->newFromLinkTarget( $target )->getPrefixedDBkey();
		}
		return $normalized;
	}

	/**
	 * Factory method to allow mocking.
	 * @return CirrusSearch
	 */
	private static function cirrusSearchFactory(): CirrusSearch {
		return new CirrusSearch();
	}

}

<?php

namespace GrowthExperiments\NewcomerTasks\AddLink;

use DBAccessObjectUtils;
use DomainException;
use GrowthExperiments\Util;
use IDBAccessObject;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\PageRecord;
use MediaWiki\Page\PageStore;
use MediaWiki\User\UserIdentity;
use RuntimeException;
use stdClass;
use TitleFactory;
use TitleValue;
use Wikimedia\Rdbms\IDatabase;

/**
 * Service that handles access to the link recommendation related database tables.
 */
class LinkRecommendationStore {

	/** @var IDatabase Read handle */
	private $dbr;

	/** @var IDatabase Write handle */
	private $dbw;

	/** @var TitleFactory */
	private $titleFactory;

	/** @var LinkBatchFactory */
	private $linkBatchFactory;

	/** @var PageStore */
	private $pageStore;

	/**
	 * @param IDatabase $dbr
	 * @param IDatabase $dbw
	 * @param TitleFactory $titleFactory
	 * @param LinkBatchFactory $linkBatchFactory
	 * @param PageStore $pageStore
	 */
	public function __construct(
		IDatabase $dbr,
		IDatabase $dbw,
		TitleFactory $titleFactory,
		LinkBatchFactory $linkBatchFactory,
		PageStore $pageStore
	) {
		$this->dbr = $dbr;
		$this->dbw = $dbw;
		$this->titleFactory = $titleFactory;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->pageStore = $pageStore;
	}

	// growthexperiments_link_recommendations

	/**
	 * Get a link recommendation by some condition.
	 * @param array $condition A Database::select() condition array.
	 * @param int $flags IDBAccessObject flags
	 * @return LinkRecommendation|null
	 */
	protected function getByCondition( array $condition, int $flags = 0 ): ?LinkRecommendation {
		[ $index, $options ] = DBAccessObjectUtils::getDBOptions( $flags );
		$row = $this->getDB( $index )->selectRow(
			'growthexperiments_link_recommendations',
			[ 'gelr_page', 'gelr_revision', 'gelr_data' ],
			$condition,
			__METHOD__,
			$options + [
				// $condition is supposed to be unique, but if somehow that isn't the case,
				// use the most up-to-date recommendation.
				'ORDER BY' => 'gelr_revision DESC'
			]
		);
		if ( $row === false ) {
			return null;
		}
		return $this->getLinkRecommendationsFromRows( [ $row ], $flags )[0] ?? null;
	}

	/**
	 * Get a link recommendation by revision ID.
	 * @param int $revId
	 * @param int $flags IDBAccessObject flags
	 * @return LinkRecommendation|null
	 */
	public function getByRevId( int $revId, int $flags = 0 ): ?LinkRecommendation {
		return $this->getByCondition( [ 'gelr_revision' => $revId ], $flags );
	}

	/**
	 * Get a link recommendation by page ID.
	 * @param int $pageId
	 * @param int $flags IDBAccessObject flags
	 * @return LinkRecommendation|null
	 */
	public function getByPageId( int $pageId, int $flags = 0 ): ?LinkRecommendation {
		return $this->getByCondition( [ 'gelr_page' => $pageId ], $flags );
	}

	/**
	 * Get a link recommendation by link target.
	 * @param LinkTarget $linkTarget
	 * @param int $flags IDBAccessObject flags
	 * @param bool $allowOldRevision When true, return any recommendation for the given page;
	 *   otherwise, only use a recommendation if it's for the current revision.
	 * @return LinkRecommendation|null
	 */
	public function getByLinkTarget(
		LinkTarget $linkTarget,
		int $flags = 0,
		bool $allowOldRevision = false
	): ?LinkRecommendation {
		$title = $this->titleFactory->newFromLinkTarget( $linkTarget );
		if ( $allowOldRevision ) {
			$pageId = $title->getArticleID( $flags );
			if ( $pageId === 0 ) {
				return null;
			}
			return $this->getByPageId( $pageId, $flags );
		} else {
			$revId = $title->getLatestRevID( $flags );
			if ( $revId === 0 ) {
				return null;
			}
			return $this->getByRevId( $revId, $flags );
		}
	}

	/**
	 * Iterate through all link recommendations, in ascending page ID order.
	 * @param int $limit
	 * @param int &$fromPageId Starting page ID. Will be set to the last fetched page ID plus one.
	 *   (This cannot be done on the caller side because records with non-existing page IDs are
	 *   omitted from the result.) Will be set to false when there are no more rows.
	 * @return LinkRecommendation[]
	 */
	public function getAllRecommendations( int $limit, int &$fromPageId ): array {
		$res = $this->getDB( DB_REPLICA )->select(
			'growthexperiments_link_recommendations',
			[ 'gelr_revision', 'gelr_page', 'gelr_data' ],
			[ 'gelr_page >= ' . $fromPageId ],
			__METHOD__,
			[
				'ORDER BY' => 'gelr_page ASC',
				'LIMIT' => $limit,
			]
		);
		$rows = iterator_to_array( $res );
		$fromPageId = ( $res->numRows() === $limit ) ? end( $rows )->gelr_page + 1 : false;
		reset( $rows );
		return $this->getLinkRecommendationsFromRows( $rows );
	}

	/**
	 * Given a set of page IDs, return the ones which have a valid link recommendation
	 * (valid as in it's for the latest revision).
	 * @param int[] $pageIds
	 * @return int[]
	 */
	public function filterPageIds( array $pageIds ): array {
		$pageRecords = $this->pageStore
			->newSelectQueryBuilder()
			->wherePageIds( $pageIds )
			->caller( __METHOD__ )
			->fetchPageRecords();

		$conds = [];
		/** @var PageRecord $pageRecord */
		foreach ( $pageRecords as $pageRecord ) {
			// Making it obvious there's no SQL injection risk is nice, but Phan disagrees.
			// @phan-suppress-next-line PhanRedundantConditionInLoop
			$pageId = (int)$pageRecord->getId();
			$revId = (int)$pageRecord->getLatest();
			if ( !$pageId || !$revId ) {
				continue;
			}
			// $revId can be outdated due to replag; we don't want to delete the record then.
			$conds[] = "gelr_page = $pageId AND gelr_revision >= $revId";
		}
		return array_map( 'intval', $this->dbr->selectFieldValues(
			'growthexperiments_link_recommendations',
			'gelr_page',
			$this->dbr->makeList( $conds, IDatabase::LIST_OR ),
			__METHOD__
		) );
	}

	/**
	 * List all pages with link recommendations, by page ID.
	 * @param int $limit
	 * @param int|null $from ID to list from, exclusive
	 * @return int[]
	 */
	public function listPageIds( int $limit, int $from = null ): array {
		return array_map( 'intval', $this->dbr->selectFieldValues(
			'growthexperiments_link_recommendations',
			'gelr_page',
			$from ? [ "gelr_page > $from" ] : [],
			__METHOD__,
			[
				'LIMIT' => $limit,
				'GROUP BY' => 'gelr_page',
				'ORDER BY' => 'gelr_page ASC',
			]
		) );
	}

	/**
	 * Insert a new link recommendation.
	 * @param LinkRecommendation $linkRecommendation
	 */
	public function insert( LinkRecommendation $linkRecommendation ): void {
		$pageId = $linkRecommendation->getPageId();
		$revisionId = $linkRecommendation->getRevisionId();
		$row = [
			'gelr_revision' => $revisionId,
			'gelr_page' => $pageId,
			'gelr_data' => json_encode( $linkRecommendation->toArray() ),
		];
		$this->dbw->replace(
			'growthexperiments_link_recommendations',
			'gelr_revision',
			$row,
			__METHOD__
		);
	}

	/**
	 * Delete all link recommendations for the given pages.
	 * @param int[] $pageIds
	 * @return int The number of deleted rows.
	 */
	public function deleteByPageIds( array $pageIds ): int {
		$this->dbw->delete(
			'growthexperiments_link_recommendations',
			[ 'gelr_page' => $pageIds ],
			__METHOD__
		);
		return $this->dbw->affectedRows();
	}

	/**
	 * Delete all link recommendations for the given page.
	 * @param LinkTarget $linkTarget
	 * @return bool
	 */
	public function deleteByLinkTarget( LinkTarget $linkTarget ): bool {
		$pageId = $this->titleFactory->newFromLinkTarget( $linkTarget )
			->getArticleID( IDBAccessObject::READ_LATEST );
		if ( $pageId === 0 ) {
			return false;
		}
		return (bool)$this->deleteByPageIds( [ $pageId ] );
	}

	// growthexperiments_link_submissions

	/**
	 * Get the list of link targets for a given page which should not be recommended anymore,
	 * as they have been rejected by users too many times.
	 * @param int $pageId
	 * @param int $limit Link targets rejected at least this many times are included.
	 * @return int[]
	 */
	public function getExcludedLinkIds( int $pageId, int $limit ): array {
		$pageIdsToExclude = $this->dbr->selectFieldValues(
			'growthexperiments_link_submissions',
			'gels_target',
			[
				'gels_page' => $pageId,
				'gels_feedback' => 'r',
			],
			__METHOD__,
			[
				'GROUP BY' => 'gels_target',
				'HAVING' => "COUNT(*) >= $limit",
			]
		);
		return array_map( 'intval', $pageIdsToExclude );
	}

	/**
	 * Record user feedback about a set for recommended links.
	 * Caller should make sure there is no feedback recorded for this revision yet.
	 * @param UserIdentity $user
	 * @param LinkRecommendation $linkRecommendation
	 * @param int[] $acceptedTargetIds Page IDs of accepted link targets.
	 * @param int[] $rejectedTargetIds Page IDs of rejected link targets.
	 * @param int[] $skippedTargetIds Page IDs of skipped link targets.
	 * @param int|null $editRevId Revision ID of the edit adding the links (might be null since
	 *   it's not necessary that any links have been added).
	 */
	public function recordSubmission(
		UserIdentity $user,
		LinkRecommendation $linkRecommendation,
		array $acceptedTargetIds,
		array $rejectedTargetIds,
		array $skippedTargetIds,
		?int $editRevId
	): void {
		$pageId = $linkRecommendation->getPageId();
		$revId = $linkRecommendation->getRevisionId();
		$links = $linkRecommendation->getLinks();
		$allTargetIds = [ 'a' => $acceptedTargetIds, 'r' => $rejectedTargetIds, 's' => $skippedTargetIds ];

		// correlate LinkRecommendation link data with the target IDs
		$linkBatch = $this->linkBatchFactory->newLinkBatch();
		$linkIndexToTitleText = [];
		foreach ( $links as $i => $link ) {
			$title = $this->titleFactory->newFromTextThrow( $link->getLinkTarget() );
			$linkIndexToTitleText[$i] = $title->getPrefixedDBkey();
			$linkBatch->addObj( $title );
		}
		$titleTextToLinkIndex = array_flip( $linkIndexToTitleText );
		$titleTextToPageId = $linkBatch->execute();
		$pageIdToTitleText = array_flip( $titleTextToPageId );
		$pageIdToLink = [];
		foreach ( array_merge( ...array_values( $allTargetIds ) ) as $targetId ) {
			$titleText = $pageIdToTitleText[$targetId] ?? null;
			if ( $titleText === null ) {
				// User-submitted page ID does not exist. Could be some kind of race condition.
				Util::logException( new RuntimeException( 'Page ID does not exist ' ), [
					'pageID' => $targetId,
				] );
				continue;
			}
			$pageIdToLink[$targetId] = $links[$titleTextToLinkIndex[$titleText]];
		}

		$rowData = [
			'gels_page' => $pageId,
			'gels_revision' => $revId,
			'gels_edit_revision' => $editRevId,
			'gels_user' => $user->getId(),
		];
		$rows = [];
		foreach ( $allTargetIds as $feedback => $targetIds ) {
			foreach ( $targetIds as $targetId ) {
				$link = $pageIdToLink[$targetId] ?? null;
				if ( !$link ) {
					continue;
				}
				$rows[] = $rowData + [
					'gels_target' => $targetId,
					'gels_feedback' => $feedback,
					'gels_anchor_offset' => $link->getWikitextOffset(),
					'gels_anchor_length' => mb_strlen( $link->getText(), 'UTF-8' ),
				];
			}
		}
		// No need to check if $rows is empty, Database::insert() does that.
		$this->dbw->insert(
			'growthexperiments_link_submissions',
			$rows,
			__METHOD__
		);
	}

	/**
	 * Check if there is already a submission for a given recommendation.
	 * @param LinkRecommendation $linkRecommendation
	 * @param int $flags IDBAccessObject flags
	 * @return bool
	 */
	public function hasSubmission( LinkRecommendation $linkRecommendation, int $flags ): bool {
		[ $index, $options ] = DBAccessObjectUtils::getDBOptions( $flags );
		return (bool)$this->getDB( $index )->selectRowCount(
			'growthexperiments_link_submissions',
			'*',
			[ 'gels_revision' => $linkRecommendation->getRevisionId() ],
			__METHOD__,
			$options
		);
	}

	// common

	/**
	 * @param int $index DB_PRIMARY or DB_REPLICA
	 * @return IDatabase
	 */
	public function getDB( int $index ): IDatabase {
		return ( $index === DB_PRIMARY ) ? $this->dbw : $this->dbr;
	}

	/**
	 * Convert growthexperiments_link_recommendations rows into objects.
	 * Rows with no matching page are skipped.
	 * @param stdClass[] $rows
	 * @param int $flags IDBAccessObject flags
	 * @return LinkRecommendation[]
	 */
	private function getLinkRecommendationsFromRows( array $rows, int $flags = 0 ): array {
		if ( !$rows ) {
			return [];
		}

		$pageIds = $linkTargets = [];
		foreach ( $rows as $row ) {
			$pageIds[] = $row->gelr_page;
		}

		$pageRecords = $this->pageStore
			->newSelectQueryBuilder( $flags )
			->wherePageIds( $pageIds )
			->caller( __METHOD__ )
			->fetchPageRecords();

		/** @var PageRecord $pageRecord */
		foreach ( $pageRecords as $pageRecord ) {
			$linkTarget = TitleValue::castPageToLinkTarget( $pageRecord );
			$linkTargets[$pageRecord->getId()] = $linkTarget;
		}

		$linkRecommendations = [];
		foreach ( $rows as $row ) {
			// TODO use JSON_THROW_ON_ERROR once we require PHP 7.3
			$data = json_decode( $row->gelr_data, true );
			if ( $data === null ) {
				throw new DomainException( 'Invalid JSON: ' . json_last_error_msg() );
			}
			$linkTarget = $linkTargets[$row->gelr_page] ?? null;
			if ( !$linkTarget ) {
				continue;
			}

			$linkRecommendations[] = new LinkRecommendation(
				$linkTarget,
				$row->gelr_page,
				$row->gelr_revision,
				LinkRecommendation::getLinksFromArray( $data['links'] ),
				// Backwards compatibility for recommendations added before metadata was included in output and stored.
				LinkRecommendation::getMetadataFromArray( $data['meta'] ?? [] )
			);
		}
		return $linkRecommendations;
	}

}

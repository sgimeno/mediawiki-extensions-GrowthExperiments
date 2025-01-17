<?php

namespace GrowthExperiments\Specials;

use GrowthExperiments\NewcomerTasks\SuggestionsInfo;
use OOUI\Tag;
use SpecialPage;
use WANObjectCache;

class SpecialNewcomerTasksInfo extends SpecialPage {

	/** @var SuggestionsInfo */
	private $suggestionsInfo;
	/** @var WANObjectCache */
	private $cache;

	/**
	 * @param SuggestionsInfo $suggestionsInfo
	 * @param WANObjectCache $cache
	 */
	public function __construct( SuggestionsInfo $suggestionsInfo, WANObjectCache $cache ) {
		parent::__construct( 'NewcomerTasksInfo' );
		$this->suggestionsInfo = $suggestionsInfo;
		$this->cache = $cache;
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		parent::execute( $subPage );
		$this->addHelpLink( 'mw:Growth/Personalized_first_day/Newcomer_tasks' );

		$info = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'GrowthExperiments', 'SuggestionsInfo' ),
			$this->cache::TTL_HOUR,
			function ( $oldValue, &$ttl, &$setOpts ) {
				$data = $this->suggestionsInfo->getInfo();
				if ( !$data || isset( $data['error'] ) ) {
					$ttl = $this->cache::TTL_UNCACHEABLE;
				} else {
					// WANObjectCache::fetchOrRegenerate would set this to the start of callback
					// execution if unset. If at the end of the callback more than a few seconds
					// have passed since the given time, it will refuse to cache.
					$setOpts['since'] = INF;
				}
				return $data;
			}
		);
		$out = $this->getOutput();
		$out->addWikiMsg( 'newcomertasksinfo-config-form-info' );
		if ( !isset( $info['tasks'] ) || !isset( $info[ 'topics' ] ) ) {
			$out->addHTML(
				( new Tag( 'p' ) )
					->addClasses( [ 'error' ] )
					->appendContent( $out->msg( 'newcomertasksinfo-no-data' )->text() )
			);
			return;
		}
		$taskTypeTableRows = [];
		foreach ( $info['tasks'] as $taskTypeId => $taskTypeData ) {
			$taskTypeTableRows[] = ( new Tag( 'tr' ) )->appendContent(
				( new Tag( 'td' ) )->appendContent(
					$out->msg( 'growthexperiments-homepage-suggestededits-tasktype-name-' . $taskTypeId )->text()
				),
				( new Tag( 'td' ) )->appendContent( ( new Tag( 'code' ) )->appendContent( $taskTypeId ) ),
				( new Tag( 'td' ) )->appendContent(
					$out->msg( 'newcomertasksinfo-task-count' )
						->numParams( $taskTypeData['totalCount'] )
						->text() )
			);
		}
		$taskTypeTableRows[] = ( new Tag( 'tr' ) )->appendContent(
			( new Tag( 'td' ) ),
			( new Tag( 'td' ) ),
			( new Tag( 'td' ) )->appendContent(
				$out->msg( 'newcomertasksinfo-task-count' )
					->numParams( $info['totalCount'] )
					->text()
			)
		);
		$taskTypeTable = ( new Tag( 'table' ) )->addClasses( [ 'wikitable' ] )
			->appendContent( ( new Tag( 'thead' ) )->appendContent(
				( new Tag( 'th' ) )->appendContent( $out->msg( 'newcomertasksinfo-table-header-task-type' )->text() ),
				( new Tag( 'th' ) )->appendContent(
					$out->msg( 'newcomertasksinfo-table-header-task-type-id' )->text()
				),
				( new Tag( 'th' ) )->appendContent(
					$out->msg( 'newcomertasksinfo-table-header-task-count' )->text()
				)
			) )
			->appendContent( ( new Tag( 'tbody' ) )->appendContent( $taskTypeTableRows ) );

		$out->addHTML( $taskTypeTable );

		$topicsTableHeaders = [
			( new Tag( 'th' ) )->appendContent( $out->msg( 'newcomertasksinfo-table-header-topic-type' )->text() )
		];
		foreach ( array_keys( $info['tasks'] ) as $taskTypeId ) {
			$topicsTableHeaders[] = ( new Tag( 'th' ) )->appendContent( $taskTypeId );
		}
		$topicsTableHeaders[] = ( new Tag( 'th' ) )->appendContent(
			$out->msg( 'newcomertasksinfo-table-header-task-count' )->text()
		);

		$topicsTableRows = [];
		foreach ( $info['topics'] as $topicId => $topicData ) {
			$topicsTableRowData = [
				( new Tag( 'td' ) )->appendContent( ( new Tag( 'code' ) )->appendContent( $topicId ) )
			];
			foreach ( array_keys( $info['tasks'] ) as $taskTypeId ) {
				$topicsTableRowData[] = ( new Tag( 'td' ) )->appendContent(
					$out->msg( 'newcomertasksinfo-task-count' )
					->numParams( $topicData['tasks'][$taskTypeId] )
					->text()
				);
			}
			$topicsTableRowData[] = ( new Tag( 'td' ) )->appendContent(
				$out->msg( 'newcomertasksinfo-task-count' )
				->numParams( $topicData['totalCount'] )
				->text()
			);
			$topicsTableRows[] = ( new Tag( 'tr' ) )->appendContent(
				$topicsTableRowData
			);
		}
		$topicsTable = ( new Tag( 'table' ) )->addClasses( [ 'wikitable' ] )
			->appendContent( ( new Tag( 'thead' ) )->appendContent(
				$topicsTableHeaders
			) )
			->appendContent( ( new Tag( 'tbody' ) )->appendContent( $topicsTableRows ) );

		$out->addHTML( $topicsTable );
	}
}

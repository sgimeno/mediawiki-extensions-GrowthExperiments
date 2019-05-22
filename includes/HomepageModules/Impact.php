<?php

namespace GrowthExperiments\HomepageModules;

use ActorMigration;
use DateTime;
use Exception;
use ExtensionRegistry;
use Html;
use IContextSource;
use MediaWiki\Extensions\PageViewInfo\PageViewService;
use MediaWiki\MediaWikiServices;
use MWException;
use OOUI\IconWidget;
use PageImages;
use SpecialPage;
use Title;

/**
 * This is the "Impact" module. It shows the page views information
 * of recently edited pages.
 *
 * @package GrowthExperiments\HomepageModules
 */
class Impact extends BaseModule {

	const THUMBNAIL_SIZE = 40;

	/**
	 * @var array
	 */
	private $contribs = null;

	/**
	 * @inheritDoc
	 */
	public function __construct( IContextSource $context ) {
		parent::__construct( 'impact', $context );
	}

	/**
	 * @inheritDoc
	 */
	protected function getModuleStyles() {
		return 'oojs-ui.styles.icons-media';
	}

	/**
	 * @inheritDoc
	 */
	protected function getHeader() {
		return $this->getContext()
			->msg( 'growthexperiments-homepage-impact-header' )
			->params( $this->getContext()->getUser()->getName() )
			->escaped();
	}

	/**
	 * @inheritDoc
	 */
	protected function getBody() {
		if ( $this->isActivated() ) {
			$articleLinkTooltip = $this->getContext()
				->msg( 'growthexperiments-homepage-impact-article-link-tooltip' )
				->text();
			$pageviewsTooltip = $this->getContext()
				->msg( 'growthexperiments-homepage-impact-pageviews-link-tooltip' )
				->text();
			$emptyImage = new IconWidget( [
				'icon' => 'image',
				'classes' => [ 'placeholder-image' ],
			] );
			$emptyViewsElement = Html::element(
				'span',
				[ 'class' => 'pageviews' ],
				'--'
			);
			return implode( "\n", array_map(
				function ( $contrib ) use (
					$articleLinkTooltip, $pageviewsTooltip, $emptyImage, $emptyViewsElement
				) {
					$titleText = $contrib['title']->getText();
					$titlePrefixedText = $contrib['title']->getPrefixedText();
					$titleUrl = $contrib['title']->getLinkUrl();

					$image = isset( $contrib['image_url'] ) ?
						Html::element(
							'div',
							[
								'alt' => $titleText,
								'title' => $titlePrefixedText,
								'class' => [ 'real-image' ],
								'style' => 'background-image: url(' . $contrib['image_url'] . ');',
							]
						) : $emptyImage;
					$imageElement = Html::rawElement(
						'a',
						[
							'class' => 'article-image',
							'href' => $titleUrl,
							'title' => $articleLinkTooltip,
							'data-link-id' => 'impact-article-image',
						],
						$image
					);

					$titleElement = Html::rawElement(
						'span',
						[ 'class' => 'article-title' ],
						Html::element(
							'a',
							[
								'href' => $titleUrl,
								'title' => $articleLinkTooltip,
								'data-link-id' => 'impact-article-title',
							],
							$titlePrefixedText
						)
					);

					$viewsElement = isset( $contrib['views'] ) ?
						Html::element(
							'a',
							[
								'class' => 'pageviews',
								'href' => $this->getPageViewToolsUrl(
									$contrib['title'], $contrib['ts']
								),
								'title' => $pageviewsTooltip,
								'data-link-id' => 'impact-pageviews',
							],
							$contrib['views']
						) : $emptyViewsElement;

					return Html::rawElement(
						'div',
						[ 'class' => 'impact-row' ],
						$imageElement . $titleElement . $viewsElement
					);
				},
				$this->getArticleContributions()
			) );
		} else {
			return $this->getContext()
				->msg( 'growthexperiments-homepage-impact-body-no-edit' )
				->params( $this->getContext()->getUser()->getName() )
				->parse();
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getSubheader() {
		$textMsgKey = $this->isActivated() ?
			'growthexperiments-homepage-impact-subheader-text' :
			'growthexperiments-homepage-impact-subheader-text-no-edit';
		$subtextMsgKey = $this->isActivated() ?
			'growthexperiments-homepage-impact-subheader-subtext' :
			'growthexperiments-homepage-impact-subheader-subtext-no-edit';

		return Html::element(
			'p',
			[ 'class' => 'growthexperiments-homepage-impact-subheader-text' ],
			$this->getContext()
				->msg( $textMsgKey )
				->params( $this->getContext()->getUser()->getName() )
				->text()
		) . Html::element(
			'p',
			[ 'class' => 'growthexperiments-homepage-impact-subheader-subtext' ],
			$this->getContext()
				->msg( $subtextMsgKey )
				->params( $this->getContext()->getUser()->getName() )
				->text()
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getSubheaderTag() {
		return 'div';
	}

	/**
	 * @inheritDoc
	 */
	protected function getFooter() {
		$user = $this->getContext()->getUser();
		$msgKey = $this->isActivated() ?
			'growthexperiments-homepage-impact-contributions-link' :
			'growthexperiments-homepage-impact-contributions-link-no-edit';
		return Html::rawElement(
			'a',
			[
				'href' => SpecialPage::getTitleFor( 'Contributions', $user->getName() )->getLinkURL(),
				'data-link-id' => 'impact-contributions',
			],
			$this->getContext()
				->msg( $msgKey )
				->numParams( $user->getEditCount() )
				->params( $user->getName() )
				->parse()
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getCssClasses() {
		return array_merge(
			parent::getCssClasses(),
			$this->isActivated() ?
				[ 'growthexperiments-homepage-impact-activated' ] :
				[]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function getState() {
		return (bool)$this->getArticleContributions() ?
			self::MODULE_STATE_ACTIVATED :
			self::MODULE_STATE_UNACTIVATED;
	}

	private function isActivated() {
		return $this->getState() === self::MODULE_STATE_ACTIVATED;
	}

	/**
	 * @return array Top 5 recently edited articles with pageviews and images
	 * @throws MWException
	 * @throws Exception
	 */
	public function getArticleContributions() {
		if ( $this->contribs === null ) {
			$this->contribs = $this->queryArticleEdits();
			if ( count( $this->contribs ) ) {
				// Add pageviews data
				$this->addPageViews( $this->contribs );

				// Sort by pageviews DESC
				usort( $this->contribs, function ( $a, $b ) {
					return ( $b['views'] ?? -1 ) <=> ( $a['views'] ?? -1 );
				} );

				// Take top 5
				$this->contribs = array_slice( $this->contribs, 0, 5 );

				// Add lead images
				$this->addImages( $this->contribs );
			}
		}
		return $this->contribs;
	}

	/**
	 * Query the last 10 edited pages and the timestamp of the first edit for those pages.
	 *
	 * @return array like [ 'title' => <Title object>, 'ts' => <DateTime object> ]
	 * @throws Exception
	 */
	private function queryArticleEdits() {
		$dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$actorMigration = ActorMigration::newMigration();
		$actorQuery = $actorMigration->getWhere( $dbr, 'rev_user', $this->getContext()->getUser() );
		$subquery = $dbr->buildSelectSubquery(
			array_merge( [ 'revision' ], $actorQuery[ 'tables' ], [ 'page' ] ),
			[ 'rev_page', 'page_title', 'page_namespace', 'rev_timestamp' ],
			[
				$actorQuery[ 'conds' ],
				'rev_deleted' => 0,
				'page_namespace' => 0,
			],
			__METHOD__,
			[ 'ORDER BY' => 'rev_timestamp DESC', 'limit' => 1000 ],
			[ 'page' => [ 'JOIN', [ 'rev_page = page_id' ] ] ] + $actorQuery[ 'joins' ]
		);
		$result = $dbr->select(
			[ 'latest_edits' => $subquery ],
			[
				'rev_page',
				'page_title',
				'page_namespace',
				'max_ts' => 'MAX(rev_timestamp)',
				'min_ts' => 'MIN(rev_timestamp)',
			],
			[],
			__METHOD__,
			[
				'GROUP BY' => 'rev_page',
				'ORDER BY' => 'max_ts DESC',
				'LIMIT' => 10,
			]
		);
		$contribs = [];
		foreach ( $result as $row ) {
			$contribs[] = [
				'title' => Title::newFromRow( $row ),
				'ts' => new DateTime( $row->min_ts ),
			];
		}
		return $contribs;
	}

	/**
	 * Add pageviews information to the array of recent contributions.
	 *
	 * @param array &$contribs Recent contributions
	 */
	private function addPageViews( &$contribs ) {
		/** @var PageViewService $pageViewService */
		$pageViewService = MediaWikiServices::getInstance()->getService( 'PageViewService' );
		$titles = array_map( function ( $contrib ) {
			return $contrib[ 'title' ];
		}, $contribs );
		$days = min( 60, $this->daysSince( end( $contribs )[ 'ts' ] ) );
		$data = $pageViewService->getPageData( $titles, $days );
		if ( $data->isGood() ) {
			foreach ( $contribs as &$contrib ) {
				$viewsByDay = $data->getValue()[ $contrib[ 'title' ]->getPrefixedDBkey() ] ?? [];
				if ( $viewsByDay ) {
					$editDate = $contrib[ 'ts' ];
					// go back to the beginning of the day of the edit
					$editDate->setTime( 0, 0 );
					$viewsByDaySinceEdit = array_filter(
						$viewsByDay,
						function ( $views, $date ) use ( $editDate ) {
							return new DateTime( $date ) >= $editDate;
						},
						ARRAY_FILTER_USE_BOTH
					);
					if ( $viewsByDaySinceEdit ) {
						$contrib['views'] = array_reduce(
							$viewsByDaySinceEdit,
							function ( $total, $views ) {
								return $total + ( is_numeric( $views ) ? $views : 0 );
							},
							0
						);
					} else {
						$contrib[ 'views' ] = null;
					}
				} else {
					$contrib[ 'views' ] = null;
				}
			}
		}
	}

	/**
	 * Add image URLs to the array of recent contributions
	 * Depends on the PageImages extension.
	 *
	 * @param array &$contribs Recent contributions
	 * @throws MWException
	 */
	private function addImages( &$contribs ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'PageImages' ) ) {
			return;
		}

		foreach ( $contribs as &$contrib ) {
			$title = $contrib[ 'title' ];
			$imageFile = PageImages::getPageImage( $title );
			if ( $imageFile ) {
				$ratio = $imageFile->getWidth() / $imageFile->getHeight();
				$options = [
					'width' => $ratio > 1 ?
						self::THUMBNAIL_SIZE / $imageFile->getHeight() * $imageFile->getWidth() :
						self::THUMBNAIL_SIZE
				];
				$thumb = $imageFile->transform( $options );
				if ( $thumb ) {
					$contrib['image_url'] = $thumb->getUrl();
				}
			}
		}
	}

	/**
	 * @param DateTime $timestamp
	 * @return int Number of days since, and including, the given timestamp
	 * @throws Exception
	 */
	private function daysSince( DateTime $timestamp ) {
		$now = new DateTime();
		$diff = $now->diff( $timestamp );
		return $diff->days;
	}

	/**
	 * @param Title $title
	 * @param DateTime $start
	 * @return string Full URL for the PageViews tool for the given title and start date
	 * @throws Exception
	 */
	private function getPageViewToolsUrl( $title, $start ) {
		$baseUrl = 'https://tools.wmflabs.org/pageviews/';
		$now = new DateTime();
		return wfAppendQuery( $baseUrl, [
			'project' => $this->getContext()->getConfig()->get( 'ServerName' ),
			'userlang' => $this->getContext()->getLanguage()->getCode(),
			'start' => $start->format( 'Y-m-d' ),
			'end' => $now->format( 'Y-m-d' ),
			'pages' => $title->getPrefixedDBkey(),
		] );
	}
}

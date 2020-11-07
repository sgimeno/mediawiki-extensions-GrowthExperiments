<?php

namespace GrowthExperiments\NewcomerTasks\AddLink;

use FormatJson;
use InvalidArgumentException;
use JsonContent;
use LogicException;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use StatusValue;
use Title;
use TitleValue;

/**
 * A link recommendation provider for testing purposes. Looks for an /addlink.json subpage of
 * the target page, and returns the contents of that page as link data.
 */
class SubpageLinkRecommendationProvider implements LinkRecommendationProvider {

	/** @var WikiPageFactory */
	private $wikiPageFactory;

	/** @var LinkRecommendationProvider */
	private $fallbackLinkRecommendationProvider;

	/**
	 * @param WikiPageFactory $wikiPageFactory
	 * @param LinkRecommendationProvider $fallbackLinkRecommendationProvider
	 */
	public function __construct(
		WikiPageFactory $wikiPageFactory,
		LinkRecommendationProvider $fallbackLinkRecommendationProvider
	) {
		$this->wikiPageFactory = $wikiPageFactory;
		$this->fallbackLinkRecommendationProvider = $fallbackLinkRecommendationProvider;
	}

	/**
	 * @inheritDoc
	 */
	public function get( LinkTarget $title ) {
		$subpageTitle = new TitleValue( $title->getNamespace(), $title->getDBkey() . '/addlink.json' );
		try {
			$subpage = $this->wikiPageFactory->newFromLinkTarget( $subpageTitle );
			if ( !$subpage ) {
				// This can only happen if some hook handler is seriously broken,
				// but it's a documented return type so make static analyzers happy.
				throw new LogicException( 'Could not create WikiPage' );
			}
		} catch ( InvalidArgumentException $e ) {
			// happens for nonsensical namespaces, like Media:
			return StatusValue::newFatal( 'rawmessage', $e->getMessage() );
		}

		if ( !$subpage->exists() ) {
			if ( $this->fallbackLinkRecommendationProvider ) {
				return $this->fallbackLinkRecommendationProvider->get( $title );
			} else {
				// This is a development-only provider, no point in translating its messages.
				return StatusValue::newFatal( 'rawmessage', 'No /addlink.json subpage found' );
			}
		}

		$content = $subpage->getContent();
		if ( !$content instanceof JsonContent ) {
			return StatusValue::newFatal( 'rawmessage', '/addlink.json subpage is not a JSON page.' );
		}
		$dataStatus = FormatJson::parse( $content->getText(), FormatJson::FORCE_ASSOC );
		if ( !$dataStatus->isOK() ) {
			return $dataStatus;
		}
		$data = $dataStatus->getValue();

		// Turn $title into a real Title
		$title = $subpage->getTitle()->getBaseTitle();
		return new LinkRecommendation( $title, $title->getArticleID(), $title->getLatestRevID(), $data );
	}

	/**
	 * Convenience method for setting up hooks. Should only be used in development setups.
	 */
	public static function setup() {
		$services = MediaWikiServices::getInstance();
		// There isn't a convenient way to call setup() early enough for setting a
		// MediaWikiServices hook here to have any effect. Instead, just run it manually.
		self::onMediaWikiServices( $services );
		$services->getHookContainer()->register( 'ContentHandlerDefaultModelFor',
			self::class . '::onContentHandlerDefaultModelFor' );
	}

	/**
	 * MediaWikiServices hook handler, for development setups only.
	 * @param MediaWikiServices $services
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/MediaWikiServices
	 */
	public static function onMediaWikiServices( MediaWikiServices $services ) {
		$services->addServiceManipulator( 'GrowthExperimentsLinkRecommendationProvider', function (
			LinkRecommendationProvider $linkRecommendationProvider, MediaWikiServices $services
		) {
			return new self( $services->getWikiPageFactory(), $linkRecommendationProvider );
		} );
	}

	/**
	 * ContentHandlerDefaultModelFor hook handler, for development setups only.
	 * @param Title $title
	 * @param string &$model
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ContentHandlerDefaultModelFor
	 */
	public static function onContentHandlerDefaultModelFor( Title $title, &$model ) {
		// This is for development, so we want to ignore $wgNamespacesWithSubpages.
		$titleText = $title->getText();
		$titleParts = explode( '/', $titleText );
		$subpage = end( $titleParts );
		if ( $subpage === 'addlink.json' && $subpage !== $titleText ) {
			$model = CONTENT_MODEL_JSON;
		}
	}

}

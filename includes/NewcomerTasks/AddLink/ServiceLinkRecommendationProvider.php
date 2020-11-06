<?php

namespace GrowthExperiments\NewcomerTasks\AddLink;

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use RequestContext;
use StatusValue;
use TitleFactory;
use WikitextContent;

/**
 * A link recommendation provider that uses the link recommendation service.
 * @see https://wikitech.wikimedia.org/wiki/Add_Link
 */
class ServiceLinkRecommendationProvider implements LinkRecommendationProvider {

	/** @var TitleFactory */
	private $titleFactory;

	/** @var RevisionLookup */
	private $revisionLookup;

	/** @var HttpRequestFactory */
	private $httpRequestFactory;

	/** @var string */
	private $url;

	/** @var string */
	private $lang;

	/**
	 * @param TitleFactory $titleFactory
	 * @param RevisionLookup $revisionLookup
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param string $url Link recommendation service root URL
	 * @param string $lang Wiki language
	 */
	public function __construct(
		TitleFactory $titleFactory,
		RevisionLookup $revisionLookup,
		HttpRequestFactory $httpRequestFactory,
		string $url,
		string $lang
	) {
		$this->titleFactory = $titleFactory;
		$this->revisionLookup = $revisionLookup;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->url = $url;
		$this->lang = $lang;
	}

	/** @inheritDoc */
	public function get( LinkTarget $title ) {
		$title = $this->titleFactory->newFromLinkTarget( $title );
		$pageId = $title->getArticleID();
		$titleText = $title->getPrefixedText();
		$revId = $title->getLatestRevID();

		if ( !$revId ) {
			return StatusValue::newFatal( 'growthexperiments-addlink-pagenotfound', $titleText );
		}
		$content = $this->revisionLookup->getRevisionById( $revId )->getContent( SlotRecord::MAIN );
		if ( !$content ) {
			return StatusValue::newFatal( 'growthexperiments-addlink-revdeleted', $revId, $titleText );
		} elseif ( !( $content instanceof WikitextContent ) ) {
			return StatusValue::newFatal( 'growthexperiments-addlink-wrongmodel', $revId, $titleText );
		}
		$wikitext = $content->getText();

		$args = [
			'wikitext' => $wikitext,
			'revid' => $revId,
			'pageid' => $pageId,
			// TODO make this configurable (on-wiki?)
			'threshold' => 0.5,
			'lang' => $this->lang,
			'page_title' => $titleText,
		];
		$request = $this->httpRequestFactory->create(
			$this->url . '/query',
			[
				'method' => 'POST',
				'postData' => json_encode( $args ),
				'originalRequest' => RequestContext::getMain()->getRequest(),
			],
			__METHOD__
		);
		$request->setHeader( 'Content-Type', 'application/json' );
		$status = $request->execute();
		if ( !$status->isOK() ) {
			return $status;
		}
		$response = $request->getContent();

		$data = json_decode( $response, true );
		if ( $data === null ) {
			return StatusValue::newFatal( 'growthexperiments-addlink-invalidjson', $titleText );
		}
		if ( array_key_exists( 'error', $data ) ) {
			return StatusValue::newFatal( 'growthexperiments-addlink-serviceerror',
				$titleText, $data['error'] );
		}
		// TODO validate/process data
		return new LinkRecommendation( $title, $pageId, $revId, $data );
	}

}

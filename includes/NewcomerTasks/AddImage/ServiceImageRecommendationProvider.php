<?php

namespace GrowthExperiments\NewcomerTasks\AddImage;

use File;
use GrowthExperiments\NewcomerTasks\TaskType\ImageRecommendationTaskType;
use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Linker\LinkTarget;
use RequestContext;
use StatusValue;
use TitleFactory;
use TitleValue;
use Wikimedia\Assert\Assert;

/**
 * Provides image recommendations via the Image Suggestion API.
 * @see https://image-suggestion-api.wmcloud.org/?doc
 * @see https://phabricator.wikimedia.org/project/profile/5253/
 */
class ServiceImageRecommendationProvider implements ImageRecommendationProvider {

	/** @var TitleFactory */
	private $titleFactory;

	/** @var HttpRequestFactory */
	private $httpRequestFactory;

	/** @var string */
	private $url;

	/** @var string|null */
	private $proxyUrl;

	/** @var string */
	private $wikiProject;

	/** @var string */
	private $wikiLanguage;

	/** @var ImageRecommendationMetadataProvider */
	private $metadataProvider;

	/** @var int|null */
	private $requestTimeout;

	/** @var bool */
	private $useTitles;

	/**
	 * @param TitleFactory $titleFactory
	 * @param HttpRequestFactory $httpRequestFactory
	 * @param string $url Image recommendation service root URL
	 * @param string|null $proxyUrl HTTP proxy to use for $url
	 * @param string $wikiProject Wiki project (e.g. 'wikipedia')
	 * @param string $wikiLanguage Wiki language code
	 * @param ImageRecommendationMetadataProvider $metadataProvider Image metadata provider
	 * @param int|null $requestTimeout Service request timeout in seconds.
	 * @param bool $useTitles Use titles (the /:wiki/:lang/pages/:title API endpoint)
	 *   instead of IDs (the /:wiki/:lang/pages endpoint)?
	 */
	public function __construct(
		TitleFactory $titleFactory,
		HttpRequestFactory $httpRequestFactory,
		string $url,
		?string $proxyUrl,
		string $wikiProject,
		string $wikiLanguage,
		ImageRecommendationMetadataProvider $metadataProvider,
		?int $requestTimeout,
		bool $useTitles = false
	) {
		$this->titleFactory = $titleFactory;
		$this->httpRequestFactory = $httpRequestFactory;
		$this->url = $url;
		$this->proxyUrl = $proxyUrl;
		$this->wikiProject = $wikiProject;
		$this->wikiLanguage = $wikiLanguage;
		$this->metadataProvider = $metadataProvider;
		$this->requestTimeout = $requestTimeout;
		$this->useTitles = $useTitles;
	}

	/** @inheritDoc */
	public function get( LinkTarget $title, TaskType $taskType ) {
		Assert::parameterType( ImageRecommendationTaskType::class, $taskType, '$taskType' );
		$title = $this->titleFactory->newFromLinkTarget( $title );
		$titleText = $title->getPrefixedDBkey();
		$titleTextSafe = strip_tags( $titleText );
		if ( !$title->exists() ) {
			// These errors might show up to the end user, but provide no useful information;
			// they are merely there to support debugging. So we keep them English-only to
			// to reduce the translator burden.
			return StatusValue::newFatal( 'rawmessage',
				'Recommendation could not be loaded for non-existing page: ' . $titleTextSafe );
		}
		if ( !$this->url ) {
			return StatusValue::newFatal( 'rawmessage',
				'Image Suggestions API is not configured' );
		}

		$pathArgs = [
			'image-suggestions',
			'v0',
			$this->wikiProject,
			$this->wikiLanguage,
			'pages',
		];
		$queryArgs = [
			'source' => 'ima',
		];

		if ( $this->useTitles ) {
			$pathArgs[] = $titleText;
		} else {
			$queryArgs['id'] = $title->getArticleID();
		}

		$request = $this->httpRequestFactory->create(
			wfAppendQuery( $this->url . '/' . implode( '/', array_map( static function ( $arg ) {
					return rawurlencode( $arg );
			}, $pathArgs ) ), $queryArgs ),
			[
				'method' => 'GET',
				'proxy' => $this->proxyUrl,
				'originalRequest' => RequestContext::getMain()->getRequest(),
				'timeout' => $this->requestTimeout,
			],
			__METHOD__
		);
		$request->setHeader( 'Accept', 'application/json' );

		$status = $request->execute();
		if ( !$status->isOK() && $request->getStatus() < 400 ) {
			return $status;
		}
		$response = $request->getContent();
		$data = json_decode( $response, true );
		if ( $data === null ) {
			return StatusValue::newFatal( 'rawmessage',
				'Invalid JSON response for page: ' . $titleTextSafe );
		} elseif ( $request->getStatus() >= 400 ) {
			return StatusValue::newFatal( 'rawmessage',
				'API returned HTTP code ' . $request->getStatus() . ' for page '
				. $titleTextSafe . ': ' . ( strip_tags( $data['detail'] ?? '(no reason given)' ) ) );
		}

		return self::processApiResponseData( $title, $titleText, $data, $this->metadataProvider );
	}

	/**
	 * Process the data returned by the Image Suggestions API and return an ImageRecommendation
	 * or an error.
	 * @param LinkTarget $title Title for which to generate the image recommendation for.
	 *   The title in the API response will be ignored.
	 * @param string $titleText Title text, for logging.
	 * @param array $data API response body
	 * @param ImageRecommendationMetadataProvider $metadataProvider
	 * @return ImageRecommendation|StatusValue
	 */
	public static function processApiResponseData(
		LinkTarget $title,
		string $titleText,
		array $data,
		ImageRecommendationMetadataProvider $metadataProvider
	) {
		$titleTextSafe = strip_tags( $titleText );
		if ( !$data['pages'] ) {
			return StatusValue::newFatal( 'rawmessage',
				'No recommendation found for page: ' . $titleTextSafe );
		}
		$images = [];
		$datasetId = '';
		$status = StatusValue::newGood();
		foreach ( $data['pages'][0]['suggestions'] as $suggestion ) {
			$filename = $suggestion['filename'];
			$source = $suggestion['source']['details']['from'];
			$projects = $suggestion['source']['details']['found_on'];
			$datasetId = $suggestion['source']['details']['dataset_id'];
			if ( !is_string( $filename ) || !File::normalizeTitle( $filename ) ) {
				return StatusValue::newFatal( 'rawmessage',
					'Invalid filename format for ' . $titleTextSafe . ': ' . strip_tags( $source ) );
			} else {
				$filename = File::normalizeTitle( $filename )->getDBkey();
			}
			if ( !in_array( $source, [
				ImageRecommendationImage::SOURCE_WIKIDATA,
				ImageRecommendationImage::SOURCE_WIKIPEDIA,
				ImageRecommendationImage::SOURCE_COMMONS,
			], true ) ) {
				return StatusValue::newFatal( 'rawmessage',
					'Invalid source type for ' . $titleTextSafe . ': ' . strip_tags( $source ) );
			}
			if ( !is_string( $projects ) ) {
				return StatusValue::newFatal( 'rawmessage',
					'Invalid projects format for ' . $titleTextSafe );
			} elseif ( $projects ) {
				$projects = array_map( static function ( $project ) {
					return preg_replace( '/[^a-zA-Z0-9_-]/', '', $project );
				}, explode( ',', $projects ) );
			} else {
				$projects = [];
			}
			if ( !is_string( $datasetId ) ) {
				return StatusValue::newFatal( 'rawmessage',
					'Invalid datasetId format for ' . $titleTextSafe );
			}

			$imageMetadata = $metadataProvider->getMetadata( $filename );
			if ( is_array( $imageMetadata ) ) {
				$images[] = new ImageRecommendationImage(
					new TitleValue( NS_FILE, $filename ),
					$source,
					$projects,
					$imageMetadata
				);
			} else {
				$status->merge( $imageMetadata );
			}
		}

		if ( !$images ) {
			if ( $status->isGood() ) {
				// $data['pages'][0]['suggestions'] was empty. This shouldn't happen.
				$status->fatal( 'rawmessage', 'No recommendation found for page: ' . $titleTextSafe );
			}
			return $status;
		}
		// If $status is bad but $images is not empty (fetching some but not all images failed),
		// we can just ignore the errors, they won't be a problem for the recommendation workflow.
		return new ImageRecommendation( $title, $images, $datasetId );
	}

}

<?php

use GrowthExperiments\NewcomerTasks\AddImage\SubpageImageRecommendationProvider;
use GrowthExperiments\NewcomerTasks\AddLink\SubpageLinkRecommendationProvider;
use GrowthExperiments\NewcomerTasks\Task\Task;
use GrowthExperiments\NewcomerTasks\TaskSuggester\StaticTaskSuggesterFactory;
use GrowthExperiments\NewcomerTasks\TaskSuggester\TaskSuggesterFactory;
use GrowthExperiments\NewcomerTasks\TaskType\ImageRecommendationTaskType;
use GrowthExperiments\NewcomerTasks\TaskType\LinkRecommendationTaskType;
use GrowthExperiments\NewcomerTasks\TaskType\TaskType;
use MediaWiki\MediaWikiServices;

# Enable under-development features still behind feature flag:
$wgGENewcomerTasksLinkRecommendationsEnabled = true;
$wgGELinkRecommendationsFrontendEnabled = true;
# Prevent pruning of red links (among other things) for subpage provider.
$wgGEDeveloperSetup = true;

$wgHooks['MediaWikiServices'][] = static function ( MediaWikiServices $services ) {
	$imageRecommendationTaskType = new ImageRecommendationTaskType(
		'image-recommendation', GrowthExperiments\NewcomerTasks\TaskType\TaskType::DIFFICULTY_MEDIUM, []
	);
	$linkRecommendationTaskType = new LinkRecommendationTaskType(
		'link-recommendation', TaskType::DIFFICULTY_EASY, []
	);

	# Mock the task suggester to specify what article(s) will be suggested.
	$services->redefineService(
		'GrowthExperimentsTaskSuggesterFactory',
		static function () use (
			$imageRecommendationTaskType, $linkRecommendationTaskType, $services
		): TaskSuggesterFactory {
			return new StaticTaskSuggesterFactory( [
				new Task( $imageRecommendationTaskType, new TitleValue( NS_MAIN, "Ma'amoul" ) ),
				new Task( $imageRecommendationTaskType, new TitleValue( NS_MAIN, "1886_in_Chile" ) ),
				new Task( $linkRecommendationTaskType, new TitleValue( NS_MAIN, 'Douglas Adams' ) ),
				new Task(
					$linkRecommendationTaskType, new TitleValue( NS_MAIN, "The_Hitchhiker's_Guide_to_the_Galaxy" )
				)
			], $services->getTitleFactory() );
		}
	);
};

# Set up SubpageLinkRecommendationProvider, which will take the recommendation from the article's /addlink.json subpage,
# e.g. [[Douglas Adams/addlink.json]]. The output of https://addlink-simple.toolforge.org can be copied there.
$wgHooks['MediaWikiServices'][] = SubpageLinkRecommendationProvider::class . '::onMediaWikiServices';
$wgHooks['ContentHandlerDefaultModelFor'][] =
	SubpageLinkRecommendationProvider::class . '::onContentHandlerDefaultModelFor';
# Same for image recommendations, with addimage.json and http://image-suggestion-api.wmcloud.org/?doc
$wgHooks['MediaWikiServices'][] = SubpageImageRecommendationProvider::class . '::onMediaWikiServices';
$wgHooks['ContentHandlerDefaultModelFor'][] =
	SubpageImageRecommendationProvider::class . '::onContentHandlerDefaultModelFor';
// Set up service URL for images.
$wgGEImageRecommendationServiceUrl = 'https://image-suggestion-api.wmcloud.org';
// Use Commons as a foreign file repository.
$wgUseInstantCommons = true;
// Set up service URL for links.
$wgGELinkRecommendationServiceUrl = 'https://api.wikimedia.org/service/linkrecommendation';

// Conditionally load Parsoid in CI
global $wgWikimediaJenkinsCI;
if ( $wgWikimediaJenkinsCI && !is_dir( "$IP/services/parsoid" ) ) {
	$PARSOID_INSTALL_DIR = "$IP/vendor/wikimedia/parsoid";
	wfLoadExtension( 'Parsoid', "$PARSOID_INSTALL_DIR/extension.json" );
}

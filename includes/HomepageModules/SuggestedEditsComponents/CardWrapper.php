<?php

namespace GrowthExperiments\HomepageModules\SuggestedEditsComponents;

use GrowthExperiments\NewcomerTasks\Task\TaskSet;
use OOUI\Tag;

class CardWrapper {

	/** @var TaskSet|\StatusValue */
	private $taskSet;

	/** @var \MessageLocalizer */
	private $messageLocalizer;

	/** @var string */
	private $dir;

	/** @var bool */
	private $topicMatching;

	/** @var NavigationWidgetFactory */
	private $navigationWidgetFactory;

	/** @var bool */
	private $isDesktop;

	/**
	 * @param \MessageLocalizer $messageLocalizer
	 * @param bool $topicMatching
	 * @param string $dir
	 * @param TaskSet|\StatusValue $taskSet
	 * @param NavigationWidgetFactory $navigationWidgetFactory
	 * @param bool $isDesktop
	 */
	public function __construct(
		\MessageLocalizer $messageLocalizer, bool $topicMatching, string $dir, $taskSet,
		NavigationWidgetFactory $navigationWidgetFactory, bool $isDesktop
	) {
		$this->taskSet = $taskSet;
		$this->messageLocalizer = $messageLocalizer;
		$this->dir = $dir;
		$this->topicMatching = $topicMatching;
		$this->navigationWidgetFactory = $navigationWidgetFactory;
		$this->isDesktop = $isDesktop;
	}

	/**
	 * @return string
	 * @throws \OOUI\Exception
	 */
	public function render() {
		$card = CardWidgetFactory::newFromTaskSet(
			$this->messageLocalizer,
			$this->topicMatching,
			$this->dir,
			$this->taskSet
		);
		$suggestedEditsClass = ( new Tag( 'div' ) )
			->addClasses( [ 'suggested-edits-card' ] )
			->appendContent( $card );
		$contents = [ $suggestedEditsClass ];
		if ( $this->isDesktop ) {
			$contents = [
				$this->navigationWidgetFactory->getPreviousNextButtonHtml( 'Previous' ),
				$suggestedEditsClass,
				$this->navigationWidgetFactory->getPreviousNextButtonHtml( 'Next' )
			];
		}
		return ( new Tag( 'div' ) )->addClasses( [
			'suggested-edits-card-wrapper',
			$card instanceof EditCardWidget ? '' : 'pseudo-card'
		] )->appendContent(
			$contents
		);
	}
}

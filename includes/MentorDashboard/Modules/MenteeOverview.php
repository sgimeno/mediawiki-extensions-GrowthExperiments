<?php

namespace GrowthExperiments\MentorDashboard\Modules;

use Html;

class MenteeOverview extends BaseModule {

	/** @var string Option name to store filters. This is hardcoded client-side. */
	public const FILTERS_PREF = 'growthexperiments-mentee-overview-filters';

	/**
	 * @inheritDoc
	 */
	protected function getHeaderText() {
		return $this->msg( 'growthexperiments-mentor-dashboard-mentee-overview-headline' )->text();
	}

	/**
	 * @inheritDoc
	 */
	protected function getSubheaderText() {
		return $this->msg( 'growthexperiments-mentor-dashboard-mentee-overview-intro' )->text();
	}

	/**
	 * @inheritDoc
	 */
	protected function getSubheaderTag() {
		return 'p';
	}

	/**
	 * @inheritDoc
	 */
	protected function getBody() {
		return Html::rawElement(
			'div',
			[
				'class' => 'growthexperiments-mentor-dashboard-module-mentee-overview-content'
			],
			Html::element(
				'p',
				[ 'class' => 'growthexperiments-mentor-dashboard-no-js-fallback' ],
				$this->msg( 'growthexperiments-mentor-dashboard-mentee-overview-no-js-fallback' )->text()
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getMobileSummaryBody() {
		return $this->getBody();
	}
}

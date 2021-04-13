<?php

namespace GrowthExperiments\HomepageModules;

use GrowthExperiments\ExperimentUserManager;
use GrowthExperiments\Util;
use Html;
use IContextSource;
use MediaWiki\MediaWikiServices;
use OOUI\ButtonWidget;

class Userpage extends BaseTaskModule {

	/**
	 * @inheritDoc
	 */
	public function __construct( IContextSource $context, ExperimentUserManager $experimentUserManager ) {
		parent::__construct( 'start-userpage', $context, $experimentUserManager );
	}

	/**
	 * @inheritDoc
	 */
	public function isCompleted() {
		return $this->getContext()->getUser()->getUserPage()->exists();
	}

	/**
	 * @inheritDoc
	 */
	protected function getHeaderIconName() {
		return 'edit';
	}

	/**
	 * @inheritDoc
	 */
	protected function getHeaderText() {
		$msgKey = $this->isCompleted() ?
			'growthexperiments-homepage-userpage-header-done' :
			'growthexperiments-homepage-userpage-header';
		return $this->getContext()->msg( $msgKey )
			->params( $this->getContext()->getUser()->getName() )
			->text();
	}

	/**
	 * @inheritDoc
	 */
	protected function getBody() {
		$msg = $this->isCompleted() ?
			'growthexperiments-homepage-userpage-body-done' :
			'growthexperiments-homepage-userpage-body';
		$messageText = $this->getContext()->msg( $msg )
			->params( $this->getContext()->getUser()->getName() )
			->escaped();

		return $messageText . $this->getGuidelinesLink();
	}

	/**
	 * @inheritDoc
	 */
	protected function getFooter() {
		if ( $this->isCompleted() ) {
			$buttonMsg = 'growthexperiments-homepage-userpage-button-done';
			$buttonFlags = [];
			$linkId = 'userpage-edit';
		} else {
			$buttonMsg = 'growthexperiments-homepage-userpage-button';
			$buttonFlags = [ 'progressive' ];
			$linkId = 'userpage-create';
		}
		$button = new ButtonWidget( [
			'label' => $this->getContext()->msg( $buttonMsg )->text(),
			'flags' => $buttonFlags,
			'href' => $this->getContext()->getUser()->getUserPage()->getEditURL(),
		] );
		$button->setAttributes( [ 'data-link-id' => $linkId ] );

		return $button;
	}

	/**
	 * @return string HTML
	 */
	private function getGuidelinesLink() {
		$wikiId = wfWikiID();
		$url = "https://www.wikidata.org/wiki/Special:GoToLinkedPage/$wikiId/Q4592334";

		if ( Util::isMobile( $this->getContext()->getSkin() ) ) {
			/** @var \MobileContext $mobileCtx */
			$mobileCtx = MediaWikiServices::getInstance()->getService( 'MobileFrontend.Context' );
			$url = $mobileCtx->getMobileUrl( $url );
		}

		return Html::rawElement(
			'div',
			[ 'class' => 'growthexperiments-homepage-userpage-guidelines' ],
			Html::element(
				'a',
				[
					'href' => $url,
					'data-link-id' => 'userpage-guidelines'
				],
				$this->getContext()->msg( 'growthexperiments-homepage-userpage-guidelines' )->text()
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function getModuleStyles() {
		return array_merge(
			parent::getModuleStyles(),
			[ 'oojs-ui.styles.icons-editing-core' ]
		);
	}
}

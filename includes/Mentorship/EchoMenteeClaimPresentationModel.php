<?php

namespace GrowthExperiments\Mentorship;

class EchoMenteeClaimPresentationModel extends \EchoEventPresentationModel {

	/**
	 * @inheritDoc
	 */
	public function getIconType() {
		return 'growthexperiments-mentor';
	}

	/**
	 * @inheritDoc
	 */
	public function getHeaderMessage() {
		return $this->getMessageWithAgent( 'growthexperiments-notification-header-mentee-claimed' )
			->params( $this->event->getTitle()->getText() );
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyMessage() {
		if ( $this->event->getExtra()['reason'] !== '' ) {
			return $this->msg( 'growthexperiments-notification-body-mentee-claimed' )
				->params( $this->event->getExtra()['reason'] );
		}
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function getPrimaryLink() {
		return [
			'url' => $this->event->getTitle()->getLocalURL(),
			'label' => $this->event->getTitle()->getText(),
		];
	}
}

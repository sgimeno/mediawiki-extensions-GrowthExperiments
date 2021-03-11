module.exports = ( function () {
	'use strict';

	var hasHeroImage = false,
		userName = mw.user.getName(),
		taskTypes = require( './TaskTypes.json' ),
		taskTypeData = taskTypes[ 'link-recommendation' ] || {};

	/**
	 * Create an OOUI PanelLayout with the specified title and content
	 *
	 * @param {string} title Localized text for panel title
	 * @param {jQuery} $content Content for the panel
	 * @return {OO.ui.PanelLayout}
	 */
	function createPanel( title, $content ) {
		return new OO.ui.PanelLayout( {
			content: [
				$( '<div>' ).attr( 'class', 'addlink-onboarding-content-title' ).text( title ),
				$content.addClass( 'addlink-onboarding-content-body' )
			],
			padded: true,
			classes: [ 'addlink-onboarding-content' ],
			data: {}
		} );
	}

	/**
	 * Get the class name for the corresponding hero image for the specified panel
	 *
	 * @param {number} panelNumber Panel for which the hero image class is for
	 * @return {string}
	 */
	function getHeroClass( panelNumber ) {
		// The following classes are used here:
		// * addlink-onboarding-content-image1
		// * addlink-onboarding-content-image2
		// * addlink-onboarding-content-image3
		return 'addlink-onboarding-content-image' + panelNumber;
	}

	/**
	 * Get a dictionary of localized texts used in the intro panel
	 *
	 * @return {Object}
	 */
	function getIntroPanelMessages() {
		return {
			title: mw.message( 'growthexperiments-addlink-onboarding-content-intro-title' ).text(),
			paragraph1: mw.message( 'growthexperiments-addlink-onboarding-content-intro-body-paragraph1', userName ).text(),
			paragraph2: mw.message( 'growthexperiments-addlink-onboarding-content-intro-body-paragraph2' ).text(),
			exampleLabel: mw.message( 'growthexperiments-addlink-onboarding-content-intro-body-example-label' ).text(),
			exampleHtml: mw.message( 'growthexperiments-addlink-onboarding-content-intro-body-example-text' ).parse()
		};
	}

	/**
	 * Create an OOUI PanelLayout for the intro panel
	 *
	 * @return {OO.ui.PanelLayout}
	 */
	function createIntroPanel() {
		var messages = getIntroPanelMessages(),
			$content = $( '<div>' ).append( [
				$( '<p>' ).text( messages.paragraph1 ),
				$( '<div>' ).attr( 'class', 'addlink-onboarding-content-example-label' ).text( messages.exampleLabel ),
				$( '<div>' ).attr( 'class', 'addlink-onboarding-content-example' ).html( messages.exampleHtml ),
				$( '<p>' ).text( messages.paragraph2 )
			] ),
			panel = createPanel( messages.title, $content );
		if ( hasHeroImage ) {
			panel.data.heroClass = getHeroClass( 1 );
		}
		return panel;
	}

	/**
	 * Get a dictionary of localized texts used in the about suggested links panel
	 *
	 * @return {Object}
	 */
	function getAboutSuggestedLinksPanelMessages() {
		return {
			title: mw.message( 'growthexperiments-addlink-onboarding-content-about-suggested-links-title' ).text(),
			paragraph1: mw.message( 'growthexperiments-addlink-onboarding-content-about-suggested-links-body', userName ).text(),
			learnMoreLinkText: mw.message( 'growthexperiments-addlink-onboarding-content-about-suggested-links-body-learn-more-link-text' ).text(),
			learnMoreLinkUrl: taskTypeData.learnMoreLink ? mw.util.getUrl( taskTypeData ) : null
		};
	}

	/**
	 * Create an OOUI PanelLayout for the about suggested links panel
	 *
	 * @return {OO.ui.PanelLayout}
	 */
	function createAboutSuggestedLinksPanel() {
		var messages = getAboutSuggestedLinksPanelMessages(),
			$content = $( '<div>' ).append( $( '<p>' ).text( messages.paragraph1 ) ),
			panel = createPanel( messages.title, $content );

		if ( messages.learnMoreLinkText && messages.learnMoreLinkUrl ) {
			$content.append( $( '<a>' ).text( messages.learnMoreLinkText ).attr( {
				href: messages.learnMoreLinkUrl,
				class: 'addlink-onboarding-content-link',
				target: '_blank'
			} ) );
		}
		if ( hasHeroImage ) {
			panel.data.heroClass = getHeroClass( 2 );
		}
		return panel;
	}

	/**
	 * Get a dictionary of localized texts used in the linking guidelines panel
	 *
	 * @return {Object}
	 */
	function getLinkingGuidelinesPanelMessages() {
		return {
			title: mw.message( 'growthexperiments-addlink-onboarding-content-linking-guidelines-title' ).text(),
			body: mw.message(
				'growthexperiments-addlink-onboarding-content-linking-guidelines-body',
				userName
			).parse()
		};
	}

	/**
	 * Create an OOUI PanelLayout for the linking guidelines panel
	 *
	 * @return {OO.ui.PanelLayout}
	 */
	function createLinkingGuidelinesPanel() {
		var messages = getLinkingGuidelinesPanelMessages(),
			$content = $( '<div>' ),
			$list,
			panel;
		$list = $( '<ul>' ).html( messages.body ).addClass( 'addlink-onboarding-content-list' );
		$list.find( 'li' ).addClass( 'addlink-onboarding-content-list-item' );
		$content.append( $list );
		panel = createPanel( messages.title, $content );
		if ( hasHeroImage ) {
			panel.data.heroClass = getHeroClass( 3 );
		}
		return panel;
	}

	return {
		/**
		 * Return an array of OOUI PanelLayouts for Add a Link onboarding screens
		 *
		 * @param {Object} [config]
		 * @param {boolean} [config.includeImage] Whether hero image class should be included in the panel data
		 * @return {OO.ui.PanelLayout[]}
		 */
		getPanels: function ( config ) {
			hasHeroImage = config && config.includeImage;
			return [
				createIntroPanel(),
				createAboutSuggestedLinksPanel(),
				createLinkingGuidelinesPanel()
			];
		}
	};
}() );
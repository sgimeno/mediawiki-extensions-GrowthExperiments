( function () {
	'use strict';

	module.exports = {
		setupLanguageSelector: function () {
			var ULSTagMultiselectWidget = require( './ULSTagMultiselectWidget.js' ),
				shouldUseLanguageInfoOverlay = OO.ui.isMobile() &&
					mw.mobileFrontend.require( 'mobile.startup' ).languageInfoOverlay,
				/** @type {OO.Router} */
				router = require( 'mediawiki.router' ),
				langCodeMap = $.uls.data.getAutonyms(),
				languageMax = 10,
				widgetInstance, $warning;

			widgetInstance = new ULSTagMultiselectWidget( {
				placeholder: mw.message( 'welcomesurvey-question-languages-placeholder' )
					.params( [ mw.language.convertNumber( languageMax ) ] )
					.text(),
				inputPosition: 'outline',
				tagLimit: languageMax,
				allowedValues: Object.keys( langCodeMap ),
				allowArbitrary: false,
				allowEditTags: false
			} );

			if ( shouldUseLanguageInfoOverlay ) {
				widgetInstance.on( 'inputFocus', function () {
					// FIXME: navigate is deprecated but navigateTo doesn't seem to trigger
					// the language searcher overlay.
					router.navigate( '/languages/all/no-suggestions' );
				} );
			}
			mw.hook( 'mobileFrontend.languageSearcher.linkClick' ).add( function ( lang ) {
				widgetInstance.addLanguageByCode( lang );
			} );

			$warning = $( '<div>' )
				.addClass( 'warning' )
				.text( mw.message( 'welcomesurvey-question-languages-maximum' ).text() )
				.css( 'display', 'none' );

			// eslint-disable-next-line no-jquery/no-global-selector
			$( '.welcomesurvey-languages .oo-ui-checkboxMultiselectInputWidget' )
				.css( 'display', 'none' )
				.after( widgetInstance.$element, $warning );

			// eslint-disable-next-line no-jquery/no-global-selector
			$( '.welcomesurvey-languages' ).addClass( 'welcomesurvey-languages-loaded' );
		}
	};
}() );

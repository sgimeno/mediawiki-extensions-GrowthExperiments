( function () {
	'use strict';

	var QuickStartTipsTabPanelLayout = require( './ext.growthExperiments.SuggestedEdits.QuickStartTipsTabPanelLayout.js' );

	/**
	 * @param {string} taskTypeID The task type ID
	 * @param {string} editorInterface The editor interface
	 * @return {jQuery}
	 */
	function getTips( taskTypeID, editorInterface ) {
		var indexLayout = new OO.ui.IndexLayout( {
				framed: false,
				expanded: true,
				classes: [ 'suggested-edits-panel-quick-start-tips-pager' ]
			} ),
			stackLayout = new OO.ui.StackLayout( {
				classes: [ 'suggested-edits-panel-quick-start-tips-content' ],
				continuous: true,
				scrollable: false
			} ),
			tipPanels = [],
			tipPanel,
			contentPanel = new OO.ui.PanelLayout( {
				padded: false,
				expanded: true
			} ),
			// Assume VE if in reading mode, since clicking Edit won't trigger
			// a page reload, and we currently don't vary messages by reading
			// interface
			apiPath = [
				mw.config.get( 'wgScriptPath' ),
				'rest.php',
				'growthexperiments',
				'v0',
				'quickstarttips',
				mw.config.get( 'skin' ),
				editorInterface,
				taskTypeID,
				mw.config.get( 'wgUserLanguage' )
			].join( '/' ),
			key;

		return $.get( apiPath ).then( function ( quickStartTipsData ) {
			for ( key in quickStartTipsData ) {
				tipPanel = new QuickStartTipsTabPanelLayout( 'tipset-' + String( key ), {
					label: String( key ),
					data: quickStartTipsData[ key ]
				} );
				tipPanels.push( tipPanel );
			}
			indexLayout.addTabPanels( tipPanels );
			contentPanel.$element.append( indexLayout.$element );
			stackLayout.addItems( [
				new OO.ui.PanelLayout( {
					padded: false,
					expanded: true,
					$content: $( '<h4>' ).addClass( 'suggested-edits-panel-quick-start-tips' )
						.text( mw.message( 'growthexperiments-help-panel-suggestededits-quick-start-tips' ).text() )
				} ),
				contentPanel
			] );
			return stackLayout.$element;
		}, function ( err, details ) {
			mw.log.error( 'Unable to load quick start tips', err, details );
		} );
	}

	module.exports = {
		getTips: getTips
	};
}() );
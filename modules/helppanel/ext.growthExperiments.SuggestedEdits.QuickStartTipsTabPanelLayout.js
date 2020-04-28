( function () {
	'use strict';

	function QuickStartTipsTabPanelLayout( name, config ) {
		var key, panel;
		QuickStartTipsTabPanelLayout.super.call( this, name,
			$.extend( { scrollable: false }, config )
		);
		this.stackLayout = new OO.ui.StackLayout( {
			continuous: true,
			scrollable: false
		} );
		for ( key in config.data ) {
			panel = new OO.ui.PanelLayout( {
				padded: false,
				expanded: true
			} );
			panel.$element.append( config.data[ key ] );
			this.stackLayout.addItems( [ panel ] );
		}
		this.$element.append( this.stackLayout.$element );
	}

	OO.inheritClass( QuickStartTipsTabPanelLayout, OO.ui.TabPanelLayout );

	module.exports = QuickStartTipsTabPanelLayout;

}() );

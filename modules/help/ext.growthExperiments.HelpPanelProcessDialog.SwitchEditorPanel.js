( function () {
	'use strict';

	/**
	 * @param {Object} config The standard config to pass to OO.ui.PanelLayout,
	 *  plus configuration specific to build the switch editor panel.
	 * @param {string} config.preferredEditor The preferred editor for use with
	 * suggested edits.
	 * @constructor
	 */
	function SwitchEditorPanel( config ) {
		SwitchEditorPanel.super.call( this, config );
		this.preferredEditor = config.preferredEditor;
		this.build();
	}

	OO.inheritClass( SwitchEditorPanel, OO.ui.PanelLayout );

	/**
	 * @param {boolean} inEditMode
	 * @param {string} currentEditor
	 */
	SwitchEditorPanel.prototype.toggle = function ( inEditMode, currentEditor ) {
		var shouldShow,
			currentTitle = new mw.Title( mw.config.get( 'wgPageName' ) );
		this.currentEditor = currentEditor;
		if ( this.currentEditor === 'wikitext-2017' ) {
			this.currentEditor = 'wikitext';
		}
		shouldShow = inEditMode && this.currentEditor !== this.preferredEditor && !currentTitle.isTalkPage();
		this.$element.toggle( shouldShow );
	};

	/**
	 * Build the switch editor panel.
	 *
	 * @internal
	 */
	SwitchEditorPanel.prototype.build = function () {
		var $content = $( '<div>' )
				.addClass( 'suggested-edits-panel-switch-editor-panel' )
				.append(
					$( '<p>' ).html( mw.message(
						// Messages that can be used here:
						// * growthexperiments-help-panel-suggested-edits-switch-editor-to-visualeditor
						// * growthexperiments-help-panel-suggested-edits-switch-editor-to-wikitext
						'growthexperiments-help-panel-suggested-edits-switch-editor-to-' + this.preferredEditor,
						new OO.ui.IconWidget( {
							icon: 'alert',
							classes: [ 'oo-ui-image-warning' ]
						} ).$element ).parse() )
				),
			$switchLink = $( '<a>' ).attr( {
				classes: [ 'suggested-edits-panel-switch-editor-panel-link' ],
				'data-link-id': 'switch-editor',
				href: '#'
			} );
		$switchLink.on( 'click', this.onClick.bind( this ) );

		if ( this.shouldShowSwitchLink() ) {
			$content.append(
				$( '<p>' ).html( $switchLink.text(
					// Messages that can be used here:
					// * growthexperiments-help-panel-suggested-edits-switch-editor-to-visualeditor-link-text
					// * growthexperiments-help-panel-suggested-edits-switch-editor-to-wikitext-link-text
					mw.message(
						'growthexperiments-help-panel-suggested-edits-switch-editor-to-' +
						this.preferredEditor + '-link-text'
					).text()
				) )
			);
		}
		this.$element.append( $content );
	};

	/**
	 * Determine if the switch editor link should be shown in the guidance panel.
	 * @internal
	 * @return {boolean}
	 */
	SwitchEditorPanel.prototype.shouldShowSwitchLink = function () {
		// TODO: Mobile doesn't have an easy way to set a switch link, and
		// since we already default the user to VE presumably they know how
		// to switch back.
		if ( OO.ui.isMobile() ) {
			return false;
		}
		if ( mw.user.options.get( 'visualeditor-betatempdisable' ) ) {
			return false;
		}
		return !!mw.libs.ve;
	};

	/**
	 * Handle clicks to the "Switch editor" link.
	 *
	 * @internal
	 */
	SwitchEditorPanel.prototype.onClick = function () {
		if ( this.currentEditor === 'wikitext' ) {
			mw.libs.ve.activateVe( 'visual' );
		} else if ( this.currentEditor === 'visualeditor' ) {
			// FIXME: This does not preserve content modifications made in VE,
			// unlike manually toggling in the UI or clicking "Edit Source"
			// eslint-disable-next-line no-undef
			ve.init.target.switchToWikitextEditor();
		}
	};

	module.exports = SwitchEditorPanel;
}() );

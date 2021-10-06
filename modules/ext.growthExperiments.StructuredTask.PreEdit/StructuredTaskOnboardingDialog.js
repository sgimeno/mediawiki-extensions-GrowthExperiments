var MultiPaneDialog = require( '../ui-components/MultiPaneDialog.js' );

/**
 * Overlay onboarding dialog for structured tasks
 *
 * @extends MultiPaneDialog
 *
 * @param {Object} [dialogConfig]
 * @param {boolean} [dialogConfig.hasSlideTransition] Use slide transition between panels
 * @param {mw.libs.ge.StructuredTaskLogger} [dialogConfig.logger] Instrumentation logger for the dialog
 * @param {string} [dialogConfig.progressMessageKey] Key name for the progress indicator text
 * @param {string[]} [dialogConfig.classes] Classname(s) of the dialog
 *
 * @param {Object} [onboardingConfig]
 * @param {OO.ui.PanelLayout[]} onboardingConfig.panels Panels to show
 * @param {string} [onboardingConfig.prefName] Name of user preferences for the task's onboarding
 * @constructor
 */
function StructuredTaskOnboardingDialog( dialogConfig, onboardingConfig ) {
	dialogConfig = dialogConfig || {};
	dialogConfig.progressMessageKey = dialogConfig.progressMessageKey || 'growthexperiments-addlink-onboarding-dialog-progress';
	this.logger = dialogConfig.logger;
	if ( !Array.isArray( dialogConfig.classes ) ) {
		dialogConfig.classes = [];
	}
	dialogConfig.classes.push( 'structuredtask-onboarding-dialog' );
	StructuredTaskOnboardingDialog.super.call( this, dialogConfig );

	if ( onboardingConfig && onboardingConfig.prefName ) {
		this.prefName = onboardingConfig.prefName;
	}
	this.panels = onboardingConfig.panels;
}

OO.inheritClass( StructuredTaskOnboardingDialog, MultiPaneDialog );

StructuredTaskOnboardingDialog.static.name = 'StructuredTaskOnboardingDialog';
StructuredTaskOnboardingDialog.static.size = 'medium';
StructuredTaskOnboardingDialog.static.title = mw.msg( 'growthexperiments-addlink-onboarding-dialog-title' );
StructuredTaskOnboardingDialog.static.actions = [
	{
		action: 'cancel',
		label: mw.message( 'growthexperiments-addlink-onboarding-dialog-label-skip-all' ).text(),
		framed: false,
		flags: [ 'primary' ],
		classes: [ 'structuredtask-onboarding-dialog-skip-button' ]
	}
];

/** @inheritdoc */
StructuredTaskOnboardingDialog.prototype.getFooterElement = function () {
	this.checkBoxInput = new OO.ui.CheckboxInputWidget( {
		selected: false,
		value: 'dismissOnboarding'
	} );

	if ( this.prefName ) {
		this.checkBoxInput.on( 'change', function ( isSelected ) {
			new mw.Api().saveOption( this.prefName, isSelected ? '1' : '0' );
		}.bind( this ) );
	}

	this.$dismissField = new OO.ui.FieldLayout( this.checkBoxInput, {
		label: mw.message( 'growthexperiments-addlink-onboarding-dialog-dismiss-checkbox' ).text(),
		align: 'inline',
		classes: [ 'structuredtask-onboarding-dialog-footer-widget' ]
	} ).$element;

	this.$prevButton = new OO.ui.ButtonWidget( {
		label: mw.message( 'growthexperiments-addlink-onboarding-dialog-label-previous' ).text(),
		icon: 'previous',
		invisibleLabel: true,
		classes: [ 'structuredtask-onboarding-dialog-footer-widget' ]
	} ).$element;
	this.$prevButton.on( 'click', function () {
		this.showPrevPanel();
	}.bind( this ) );

	this.$nextButton = new OO.ui.ButtonWidget( {
		label: mw.message( 'growthexperiments-addlink-onboarding-dialog-label-next' ).text(),
		icon: 'next',
		invisibleLabel: true,
		flags: [ 'progressive', 'primary' ],
		classes: [ 'structuredtask-onboarding-dialog-footer-widget', 'align-end' ]
	} ).$element;
	this.$nextButton.on( 'click', function () {
		this.showNextPanel();
	}.bind( this ) );

	this.$getStartedButton = new OO.ui.ButtonWidget( {
		label: mw.message( 'growthexperiments-addlink-onboarding-dialog-get-started-button' ).text(),
		flags: [ 'progressive', 'primary' ],
		classes: [ 'structuredtask-onboarding-dialog-footer-widget', 'align-end' ]
	} ).$element;
	this.$getStartedButton.on( 'click', function () {
		this.closeDialog( 'get_started' );
	}.bind( this ) );

	return new OO.ui.PanelLayout( {
		padded: true,
		expanded: false,
		content: [ this.$dismissField, this.$prevButton, this.$nextButton, this.$getStartedButton ],
		classes: [ 'structuredtask-onboarding-dialog-footer' ]
	} ).$element;
};

/** @inheritdoc */
StructuredTaskOnboardingDialog.prototype.getReadyProcess = function ( data ) {
	// Record an impression event when the dialog is opened
	this.logger.log( 'impression', this.getLogActionData(), this.getLogMetadata() );
	return StructuredTaskOnboardingDialog.super.prototype.getReadyProcess.call( this, data );
};

/** @inheritdoc */
StructuredTaskOnboardingDialog.prototype.getActionProcess = function ( action ) {
	return StructuredTaskOnboardingDialog.super.prototype.getActionProcess.call( this, action )
		.next( function () {
			this.closeDialog( action === 'cancel' ? 'skip_all' : 'close' );
		}, this );
};

/** @inheritdoc */
StructuredTaskOnboardingDialog.prototype.updateViewState = function () {
	var $skipButton = this.$head.find( '.structuredtask-onboarding-dialog-skip-button' );

	StructuredTaskOnboardingDialog.super.prototype.updateViewState.call( this );

	this.$getStartedButton.hide();
	this.$dismissField.hide();

	$skipButton.show();

	var $learnMoreLink = this.panels[ this.currentPanelIndex ].$element.find(
		'.onboarding-content-link'
	);
	if ( $learnMoreLink ) {
		$learnMoreLink.on( 'click', function () {
			this.logger.log( 'link_click', this.getLogActionData(), this.getLogMetadata() );
		}.bind( this ) );
	}

	if ( this.currentPanelIndex === this.panels.length - 1 ) {
		this.$getStartedButton.show();
		this.$nextButton.hide();
		$skipButton.hide();
	} else if ( this.currentPanelIndex === 0 ) {
		this.$dismissField.show();
		this.$prevButton.hide();
	} else {
		this.$prevButton.show();
		this.$nextButton.show();
	}
};

/** @inheritdoc */
StructuredTaskOnboardingDialog.prototype.showPanelIndex = function ( index ) {
	StructuredTaskOnboardingDialog.super.prototype.showPanelIndex.call( this, index );
	this.logger.log( 'impression', this.getLogActionData(), this.getLogMetadata() );
};

/**
 * Close dialog and fire an event indicating that onboarding has been completed
 *
 * @param {string} action One of 'skip_all', 'get_started', or 'close'
 */
StructuredTaskOnboardingDialog.prototype.closeDialog = function ( action ) {
	this.logger.log( action, this.getLogActionData(), this.getLogMetadata() );
	this.close();
	mw.hook( 'growthExperiments.structuredTask.onboardingCompleted' ).fire();
};

/**
 * Get action_data to use with StructuredTaskLogger
 *
 * @return {{dont_show_again: boolean}}
 */
StructuredTaskOnboardingDialog.prototype.getLogActionData = function () {
	return {
		// eslint-disable-next-line camelcase
		dont_show_again: this.checkBoxInput.selected
	};
};

/**
 * Get metadata override to use with StructuredTaskLogger
 *
 * @return {{active_interface: string}}
 */
StructuredTaskOnboardingDialog.prototype.getLogMetadata = function () {
	return {
		// eslint-disable-next-line camelcase
		active_interface: 'onboarding_step_' + ( this.currentPanelIndex + 1 ) + '_dialog'
	};
};

module.exports = StructuredTaskOnboardingDialog;
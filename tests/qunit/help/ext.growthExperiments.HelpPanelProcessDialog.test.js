'use strict';
const linkRecommendationSuggestedEditSession = {
	taskType: 'link-recommendation',
	active: true,
	connect: function () {},
	save: function () {}
};
const copyEditRecommendationSuggestedEditSession = {
	taskType: 'copyedit',
	active: true,
	connect: function () {},
	save: function () {}
};
const suggestedEditsPanel = {
	toggleFooter: function () {},
	toggleSwitchEditorPanel: function () {}
};

let HelpPanelProcessDialog, TaskTypesAbFilter, sandbox;

QUnit.module( 'ext.growthExperiments.HelpPanelProcessDialog.js', QUnit.newMwEnvironment( {
	beforeEach: function () {
		HelpPanelProcessDialog = require( '../../../modules/help/ext.growthExperiments.HelpPanelProcessDialog.js' );
		TaskTypesAbFilter = require( '../../../modules/homepage/suggestededits/TaskTypesAbFilter.js' );
		sandbox = sinon.sandbox.create();
		sandbox.stub( TaskTypesAbFilter, 'filterTaskTypes' ).returns( {} );
	},

	afterEach: function () {
		sandbox.restore();
	}
} ) );

QUnit.test( 'getDefaultPanelForSuggestedEditSession for link-recommendation', function ( assert ) {
	const helpPanelProcessDialog = new HelpPanelProcessDialog( {
		suggestedEditSession: linkRecommendationSuggestedEditSession
	} );
	assert.strictEqual( helpPanelProcessDialog.getDefaultPanelForSuggestedEditSession(), 'suggested-edits' );
} );

QUnit.test( 'getDefaultPanelForSuggestedEditSession for copyedit', function ( assert ) {
	const helpPanelProcessDialog = new HelpPanelProcessDialog( {
		suggestedEditSession: copyEditRecommendationSuggestedEditSession
	} );
	assert.notOk( helpPanelProcessDialog.getDefaultPanelForSuggestedEditSession() );
} );

QUnit.test( 'updateEditMode for link-recommendation', function ( assert ) {
	const helpPanelProcessDialog = new HelpPanelProcessDialog( {
		suggestedEditSession: linkRecommendationSuggestedEditSession,
		logger: {
			isEditing: function () {
				return true;
			},
			getEditor: function () {
				return 'visual';
			}
		}
	} );
	helpPanelProcessDialog.suggestededitsPanel = suggestedEditsPanel;
	sandbox.stub( helpPanelProcessDialog, 'updateMode' );
	const spy = sandbox.spy( helpPanelProcessDialog, 'swapPanel' );
	helpPanelProcessDialog.updateEditMode();
	assert.ok( spy.notCalled );
} );

QUnit.test( 'updateEditMode for copyedit, isEditing', function ( assert ) {
	const helpPanelProcessDialog = new HelpPanelProcessDialog( {
		suggestedEditSession: copyEditRecommendationSuggestedEditSession,
		logger: {
			isEditing: function () {
				return true;
			},
			getEditor: function () {
				return 'visual';
			}
		}
	} );
	helpPanelProcessDialog.suggestededitsPanel = suggestedEditsPanel;
	sandbox.stub( helpPanelProcessDialog, 'updateMode' );
	const spy = sandbox.stub( helpPanelProcessDialog, 'swapPanel' );
	helpPanelProcessDialog.updateEditMode();
	assert.ok( spy.calledWith( 'home' ) );
} );

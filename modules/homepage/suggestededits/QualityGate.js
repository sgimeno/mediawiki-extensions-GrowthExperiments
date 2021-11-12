'use strict';

/**
 * @param {Object} config
 * @param {string[]} config.gates
 * @param {Object} config.gateConfig
 * @param {Object} config.loggers Loggers for each task type.
 * @param {Object} config.loggerMetadataOverrides Overrides to pass to the logger.log() call.
 * @constructor
 */
function QualityGate( config ) {
	this.config = config;
	this.checkHandlers = {
		'image-recommendation': {
			dailyLimit: function () {
				return this.checkDailyLimitForTaskType( 'image-recommendation' );
			}.bind( this ),
			mobileOnly: function () {
				return this.checkMobileOnlyGate( 'image-recommendation' );
			}.bind( this )
		}
	};
	this.errorHandlers = {
		'image-recommendation': {
			dailyLimit: function () {
				return this.showImageRecommendationDailyLimitAlertDialog();
			}.bind( this ),
			mobileOnly: function () {
				return this.showImageRecommendationMobileOnlyDialog();
			}.bind( this )
		}
	};
	this.loggers = config.loggers;
}

/**
 * Check all quality gates for a task type.
 *
 * The checkers are defined in this.checkHandlers; the gates to check are defined in each task
 * type (see TaskType.php getQualityGateIds() )
 *
 * @param {string} taskType
 * @return {boolean} Whether the task passed the gates.
 */
QualityGate.prototype.checkAll = function ( taskType ) {
	return this.config.gates.every( function ( gate ) {
		if ( this.checkHandlers[ taskType ][ gate ] ) {
			if ( !this.checkHandlers[ taskType ][ gate ]() ) {
				this.handleGateFailure( taskType, gate );
				return false;
			}
		}
		return true;
	}.bind( this ) );
};

/**
 * Check if the task type passes the daily limit gate.
 *
 * "dailyLimit" is set to true if the user has exceeded the maxTasksPerDay value in
 * NewcomerTasks.json. The value
 * is exported in QualityGateDecorator.php
 *
 * @param {string} taskType
 * @return {boolean} Whether the task passed the gate.
 */
QualityGate.prototype.checkDailyLimitForTaskType = function ( taskType ) {
	return !this.config.gateConfig[ taskType ].dailyLimit;
};

/**
 * Check if the user is on desktop or mobile.
 *
 * "mobileOnly" is set to true if the user is on a mobile skin. This is exported in
 * QualityGateDecorator.php.
 *
 * @param {string} taskType
 * @return {boolean} Whether the task passed the gate.
 */
QualityGate.prototype.checkMobileOnlyGate = function ( taskType ) {
	return this.config.gateConfig[ taskType ].mobileOnly;
};

/**
 * Handle failure for a particular gate.
 *
 * @param {string} taskType
 * @param {string} gate The ID of the gate, e.g. 'dailyLimit'. Corresponds to an entry in
 *   this.errorHandlers.
 */
QualityGate.prototype.handleGateFailure = function ( taskType, gate ) {
	this.errorHandlers[ taskType ][ gate ]();
};

/**
 * Show an alert dialog for dailyLimit gate for image-recommendation task type.
 */
QualityGate.prototype.showImageRecommendationDailyLimitAlertDialog = function () {
	this.loggers[ 'image-recommendation' ].log( 'impression', 'dailyLimit', this.config.loggerMetadataOverrides );

	OO.ui.alert( mw.message( 'growthexperiments-addimage-daily-task-limit-exceeded' ).parse(), {
		actions: [ {
			action: 'accept', label: mw.message( 'growthexperiments-addimage-daily-task-limit-exceeded-dialog-button' ).text(), flags: 'primary'
		} ]
	} );
};

/**
 * Show an alert dialog for the mobileOnly gate for image-recommendation task type.
 */
QualityGate.prototype.showImageRecommendationMobileOnlyDialog = function () {
	this.loggers[ 'image-recommendation' ].log( 'impression', 'mobileOnly', this.config.loggerMetadataOverrides );

	OO.ui.alert( mw.message( 'growthexperiments-addimage-mobile-only' ).parse(), {
		actions: [ {
			action: 'accept', label: mw.message( 'growthexperiments-addimage-mobile-only-dialog-button' ).text(), flags: 'primary'
		} ]
	} );
};

module.exports = QualityGate;
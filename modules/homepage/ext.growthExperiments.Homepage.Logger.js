( function () {

	var Utils = require( '../utils/ext.growthExperiments.Utils.js' );

	/**
	 * @param {boolean} enabled
	 * @param {string} homepagePageviewToken
	 * @constructor
	 */
	function HomepageModuleLogger( enabled, homepagePageviewToken ) {
		this.enabled = enabled;
		this.userId = mw.user.getId();
		this.userEditCount = mw.config.get( 'wgUserEditCount' );
		this.isMobile = OO.ui.isMobile();
		this.homepagePageviewToken = homepagePageviewToken;
		this.exclusions = {
			start: [ 'impression' ]
		};
		this.events = [];
	}

	/**
	 * Log an event to the HomepageModule schema
	 *
	 * @param {string} module Name of the module
	 * @param {string} mode Rendering mode See constants in HomepageModule.php
	 * @param {string} action User action
	 * @param {Object} [extraData] Additional data related to the action or the state of the module
	 */
	HomepageModuleLogger.prototype.log = function ( module, mode, action, extraData ) {
		var event, state, data;
		if ( !this.enabled ) {
			return;
		}

		if ( this.exclusions[ module ] && this.exclusions[ module ].indexOf( action ) !== -1 ) {
			return;
		}

		data = $.extend(
			{},
			mw.config.get( 'wgGEHomepageModuleActionData-' + module ),
			extraData || {}
		);

		event = {
			/* eslint-disable camelcase */
			action: action,
			action_data: Utils.serializeActionData( data ),
			user_id: this.userId,
			user_editcount: this.userEditCount,
			user_variant: Utils.getUserVariant(),
			module: module,
			is_mobile: this.isMobile,
			mode: mode,
			homepage_pageview_token: this.homepagePageviewToken
			/* eslint-enable camelcase */
		};
		state = mw.config.get( 'wgGEHomepageModuleState-' + module );
		if ( state ) {
			// Don't pass things like event.state = '', that causes validation errors
			event.state = state;
		}
		mw.track( 'event.HomepageModule', event );
		this.events.push( event );
	};

	/**
	 * Get events sent to mw.track by the logger.
	 *
	 * @return {Object[]}
	 */
	HomepageModuleLogger.prototype.getEvents = function () {
		return this.events;
	};

	module.exports = HomepageModuleLogger;
}() );

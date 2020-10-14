'use strict';

( function () {
	/**
	 * Logger for the NewcomerTask EventLogging schema.
	 *
	 * @class mw.libs.ge.NewcomerTaskLogger
	 * @constructor
	 */
	function NewcomerTaskLogger() {
		this.events = [];
	}

	/**
	 * Log a task returned by the task API.
	 *
	 * @param {Object} task A task object, as returned by GrowthTasksApi
	 * @param {number} [position] The position of the task in the task queue.
	 * @return {string} A token stored under NewcomerTask.newcomer_task_token to identify this log
	 *   event. Typically used to bind it to another log event such as a homepage module action.
	 */
	NewcomerTaskLogger.prototype.log = function ( task, position ) {
		var data;

		if ( task.token ) {
			// already logged
			return task.token;
		}
		task.token = mw.user.generateRandomSessionId();
		data = this.getLogData( task, position );
		mw.track( 'event.NewcomerTask', data );
		this.events.push( data );
		return task.token;
	};

	/**
	 * Convert a task into log data.
	 *
	 * @param {Object} task A task object, as returned by GrowthTasksApi
	 * @param {number} [position] The position of the task in the task queue.
	 * @return {Object} Log data
	 */
	NewcomerTaskLogger.prototype.getLogData = function ( task, position ) {
		/* eslint-disable camelcase */
		var logData = {
			newcomer_task_token: task.token,
			task_type: task.tasktype,
			maintenance_templates: task.maintenanceTemplates,
			revision_id: task.revisionId,
			page_id: task.pageId,
			page_title: task.title,
			has_image: !!task.thumbnailSource,
			pageviews: task.pageviews,
			ordinal_position: position || 0
		};
		if ( task.topics && task.topics.length ) {
			logData.topic = task.topics[ 0 ][ 0 ];
			logData.match_score = task.topics[ 0 ][ 1 ];
		}
		return logData;
		/* eslint-enable camelcase */
	};

	/**
	 * Get events sent to mw.track by the logger.
	 *
	 * @return {Object[]}
	 */
	NewcomerTaskLogger.prototype.getEvents = function () {
		return this.events;
	};

	module.exports = NewcomerTaskLogger;
}() );

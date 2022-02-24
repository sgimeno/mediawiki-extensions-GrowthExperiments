var GrowthTasksApi = require( '../../ext.growthExperiments.Homepage.SuggestedEdits/GrowthTasksApi.js' );
var api = new GrowthTasksApi( {} );

// initial state
var storeState = {
	all: []
};

// getters
var getters = {};

// actions
var actions = {
	getAllTasks: function ( context, options ) {
		return api.fetchTasks( options.taskTypes, options.topics )
			.then( function ( tasks ) {
				context.commit( 'setTasks', tasks );
				// TODO return true or nothing and use store from components
				return $.Deferred().resolve( tasks ).promise();
			} );
	}
};

// mutations
var mutations = {
	setTasks: function ( state, tasks ) {
		state.all = tasks;
	}
};

module.exports = {
	// prefixes actions with the module name, ie: tasks/getAllTasks
	namespaced: true,
	state: storeState,
	getters: getters,
	actions: actions,
	mutations: mutations
};

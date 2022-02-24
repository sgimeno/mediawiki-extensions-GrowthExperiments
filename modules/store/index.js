var createStore = require( 'vuex' ).createStore;
var tasks = require( './modules/tasks.js' );

var store = createStore( {
	modules: {
		tasks: tasks
	}
} );

module.exports = store;

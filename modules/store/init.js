var Vue = require( 'vue' );
var Vuex = require( 'vuex' );
var store = require( './index.js' );

Vue.use( Vuex );
// TODO try DI approaches instead of this
window.mw.libs.ge = window.mw.libs.ge || {};
window.mw.libs.ge.store = store;

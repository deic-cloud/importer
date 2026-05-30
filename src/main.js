import Vue from 'vue'
import App from './App.vue'

Vue.prototype.t = t
Vue.prototype.n = n
Vue.prototype.OC = OC

const app = new Vue({
	el: '#importer-root',
	render: h => h(App),
})

export { app }

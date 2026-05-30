import Vue from 'vue'
import PersonalSettings from './PersonalSettings.vue'

Vue.prototype.t = t
Vue.prototype.n = n
Vue.prototype.OC = OC

const app = new Vue({
	el: '#importer-personal-root',
	render: h => h(PersonalSettings),
})

export { app }

import {createApp} from 'vue'
import App from './App.vue'
import {createRouter, createWebHistory} from 'vue-router'
import axios from 'axios'
import './assets/main.css'
import keycloak from "@/keycloak.js";
import Schedule from "@/components/Schedule.vue";
import Logos from "@/components/Logos.vue";
import {createPinia} from "pinia";
import SelectEvent from "@/components/SelectEvent.vue";
import dayjs from "dayjs";
import 'dayjs/locale/de';
import Rooms from "@/components/Rooms.vue";
import EventOverview from "@/components/EventOverview.vue";
import PublishControl from "@/components/PublishControl.vue";
import Admin from "@/components/Admin.vue";
import Teams from "@/components/Teams.vue";
import VueKonva from "vue-konva";
import EditSlide from "@/components/EditSlide.vue";
import Preview from "@/components/molecules/Preview.vue";
import VueKonva from "vue-konva";
import FabricEditor from "@/components/FabricEditor.vue";
import SlideContentRenderer from "@/components/slideTypes/SlideContentRenderer.vue"

keycloak.init({onLoad: 'login-required'}).then(authenticated => {
    if (!authenticated) {
        window.location.reload()
    }


    // save token to use with axios
    localStorage.setItem('kc_token', keycloak.token)

    // refresh token periodically
    setInterval(() => {
        keycloak.updateToken(60).then(refreshed => {
            if (refreshed) {
                localStorage.setItem('kc_token', keycloak.token)
            }
        })
    }, 10000)

    const app = createApp(App)
    const pinia = createPinia()

    app.use(VueKonva);

    axios.defaults.baseURL = import.meta.env.VITE_API_BASE_URL
    axios.defaults.withCredentials = true

    app.config.globalProperties.$axios = axios
    axios.interceptors.request.use(config => {
        // Ensure Content-Type is set for POST requests
        if (config.method === "post") {
            console.log("Interceptor: Setting Content-Type for POST request to:", config.url)
            config.headers["Content-Type"] = "application/json"
            console.log("Interceptor: Headers after setting:", config.headers)
        }
        const token = localStorage.getItem('kc_token')
        if (token) {
            config.headers.Authorization = `Bearer ${token}`
        }
        return config
    })

    const routes = [
        {path: '/event', component: EventOverview},
        {path: '/schedule', component: Schedule},
        {path: '/teams', component: Teams},
        {path: '/logos', component: Logos},
        {path: '/events', component: SelectEvent},
        {path: '/rooms', component: Rooms},
        {path: '/publish', component: PublishControl},
        {path: '/admin', component: Admin},
        {path: '/preview/:planId', component: Preview, props: true},
        {path: '/editSlide', component: FabricEditor},
        {path: '/carousel/:eventId', component: SlideContentRenderer, props: true},
    ]

    const router = createRouter({
        history: createWebHistory(),
        routes,
    })

    dayjs.locale('de')

    app.use(router)
    app.use(pinia)
    app.mount('#app')
})

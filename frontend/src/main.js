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
import Preview from "@/components/molecules/Preview.vue";
import Carousel from "@/components/Carousel.vue";
import EditSlide from "@/components/EditSlide.vue";
import PlanLayout from "@/components/PlanLayout.vue";
import PresentationSettings from "@/components/molecules/PresentationSettings.vue";
import PublicEvent from "@/components/PublicEvent.vue";
import EventNotFound from "@/components/EventNotFound.vue";

const routes = [
    {path: '/carousel/:eventId', component: Carousel, props: true, meta: {public: true}},
    {
        path: '/plan',
        component: PlanLayout,
        redirect: '/plan/event',
        children: [
            {path: 'event', component: EventOverview},
            {path: 'schedule', component: Schedule},
            {path: 'teams', component: Teams},
            {path: 'logos', component: Logos},
            {path: 'events', component: SelectEvent},
            {path: 'rooms', component: Rooms},
            {path: 'publish', component: PublishControl},
            {path: 'admin', component: Admin},
            {path: 'presentation', component: PresentationSettings},
            {path: 'preview/:planId', component: Preview, props: true},
            {path: 'editSlide/:slideId', component: EditSlide, props: true},
        ]
    },
    // Redirect old routes to new plan/ prefixed routes
    {path: '/event', redirect: '/plan/event'},
    {path: '/schedule', redirect: '/plan/schedule'},
    {path: '/teams', redirect: '/plan/teams'},
    {path: '/logos', redirect: '/plan/logos'},
    {path: '/events', redirect: '/plan/events'},
    {path: '/rooms', redirect: '/plan/rooms'},
    {path: '/publish', redirect: '/plan/publish'},
    {path: '/admin', redirect: '/plan/admin'},
    {path: '/presentation', redirect: '/plan/presentation'},
    {path: '/preview/:planId', redirect: to => `/plan/preview/${to.params.planId}`},
    {path: '/editSlide/:slideId', redirect: to => `/plan/editSlide/${to.params.slideId}`},
    
    // Public slug-based routes (must be after all specific routes)
    {path: '/:slug', component: PublicEvent, props: true, meta: {public: true}},
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach((to, from, next) => {
    if (to.meta?.public) {
        next();
        return;
    }
    if (keycloak.authenticated) {
        next();
        return;
    }
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
        }, 10000);
        next();
    });
});

const app = createApp(App)
const pinia = createPinia()

    axios.defaults.baseURL = import.meta.env.VITE_API_BASE_URL
    axios.defaults.withCredentials = true
    
    app.config.globalProperties.$axios = axios
    axios.interceptors.request.use(config => {
        // Only set Content-Type for JSON requests, not FormData
        if (config.method === "post" && !(config.data instanceof FormData)) {
            config.headers["Content-Type"] = "application/json"
        }
        const token = localStorage.getItem('kc_token')
        if (token) {
            config.headers.Authorization = `Bearer ${token}`
        }
        return config
    })

axios.defaults.baseURL = import.meta.env.VITE_API_BASE_URL
axios.defaults.withCredentials = true

dayjs.locale('de')

app.use(router)
app.use(pinia)
app.mount('#app')

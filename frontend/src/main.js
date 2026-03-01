import {createApp} from 'vue'
import App from './App.vue'
import {createRouter, createWebHistory} from 'vue-router'
import axios from 'axios'
import './assets/main.css'
import keycloak from "@/keycloak.js";
import Schedule from "@/components/Schedule.vue";
import Logos from "@/components/Logos.vue";
import {createPinia, setActivePinia} from "pinia";
import SelectEvent from "@/components/SelectEvent.vue";
import dayjs from "dayjs";
import 'dayjs/locale/de';
import Rooms from "@/components/Rooms.vue";
import EventOverview from "@/components/EventOverview.vue";
import PublishControl from "@/components/PublishControl.vue";
import EventDayControl from "@/components/EventDayControl.vue";
// Admin is lazy-loaded - only loads when /admin route is accessed
// This reduces initial bundle size since most users are not admins
import Teams from "@/components/Teams.vue";
import Preview from "@/components/molecules/Preview.vue";
import Carousel from "@/components/Carousel.vue";
import EditSlide from "@/components/EditSlide.vue";
import PlanLayout from "@/components/PlanLayout.vue";
import PresentationSettings from "@/components/molecules/PresentationSettings.vue";
import PublicEvent from "@/components/PublicEvent.vue";
import EventNotFound from "@/components/EventNotFound.vue";
import UnauthorizedAccess from "@/components/UnauthorizedAccess.vue";
import PublicScores from "@/components/PublicScores.vue";
import {useEventStore} from "@/stores/event";
import StandaloneSlide from "@/components/StandaloneSlide.vue";
import {registerSW} from 'virtual:pwa-register'

const routes = [
    {path: '/carousel/:eventId', component: Carousel, props: true, meta: {public: true}},
    {path: '/carousel/:eventId/:slideId', component: StandaloneSlide, props: true, meta: {public: true}},
    {path: '/scores/:eventId', component: PublicScores, props: true, meta: {public: true}},
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
            {path: 'live', component: EventDayControl},
            // Lazy-load Admin component - only loads when route is accessed
            // This significantly reduces initial bundle size since most users are not admins
            {path: 'admin', component: () => import('@/components/Admin.vue')},
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
    {path: '/event-day', redirect: '/plan/live'},
    {path: '/live', redirect: '/plan/live'},
    {path: '/admin', redirect: '/plan/admin'},
    {path: '/presentation', redirect: '/plan/presentation'},
    {path: '/preview/:planId', redirect: to => `/plan/preview/${to.params.planId}`},
    {path: '/editSlide/:slideId', redirect: to => `/plan/editSlide/${to.params.slideId}`},

    // Public slug-based routes (must be after all specific routes)
    {path: '/:slug', component: PublicEvent, props: true, meta: {public: true}},
    // Unauthorized access route
    {path: '/unauthorized', component: UnauthorizedAccess, meta: {public: true}},
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Create pinia instance early so it can be used in router guard
const pinia = createPinia()
setActivePinia(pinia)

function isTodayWithinEvent(event) {
    if (!event?.date) return false

    const start = dayjs(event.date).startOf('day')
    if (!start.isValid()) return false

    const eventDays = Math.max(Number(event.days || 1), 1)
    const end = start.add(eventDays - 1, 'day').endOf('day')
    const now = dayjs()

    return !now.isBefore(start) && !now.isAfter(end)
}

router.beforeEach(async (to, from, next) => {
    // Allow public routes (including unauthorized page)
    if (to.meta?.public || to.path === '/unauthorized') {
        next();
        return;
    }

    // Handle authentication
    if (!keycloak.authenticated) {
        try {
            const authenticated = await keycloak.init({onLoad: 'login-required'});
            if (!authenticated) {
                window.location.reload()
                return;
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
        } catch (error) {
            console.error('Keycloak initialization failed:', error);
            window.location.reload()
            return;
        }
    }

    // Ensure token is in localStorage - even if already authenticated
    // This is needed because the token might not be in localStorage from a previous session
    if (keycloak.authenticated && keycloak.token) {
        localStorage.setItem('kc_token', keycloak.token);
    }

    // Check if event is selected for non-public routes
    // Skip check for the events selection page itself
    if (to.path !== '/plan/events' && to.path !== '/events' && to.path.startsWith('/plan')) {
        // Use the store - pinia is already active
        const eventStore = useEventStore();

        // Try to fetch selected event if not already loaded
        if (!eventStore.selectedEvent) {
            await eventStore.fetchSelectedEvent();
        }

        // If still no event selected, redirect to event selection page
        if (!eventStore.selectedEvent) {
            next('/plan/events');
            return;
        }

        // Day-of default view: on first load, open am Tag instead of Veranstaltung
        const isInitialNavigation = from.matched.length === 0
        if (isInitialNavigation && to.path === '/plan/event' && isTodayWithinEvent(eventStore.selectedEvent)) {
            next('/plan/live')
            return
        }
    }

    next();
});

const app = createApp(App)

registerSW({immediate: true})

axios.defaults.baseURL = '/api'
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

// Response interceptor to handle 403 Forbidden errors
axios.interceptors.response.use(
    response => response,
    error => {
        if (error.response?.status === 403) {
            // Store error message for display
            const errorMessage = error.response.data?.error || 'Zugriff verweigert'
            sessionStorage.setItem('unauthorized_error', errorMessage)

            // Only redirect if not already on unauthorized page
            if (window.location.pathname !== '/unauthorized') {
                // Redirect to unauthorized page
                window.location.href = '/unauthorized?error=' + encodeURIComponent(errorMessage)
            }
        }
        return Promise.reject(error)
    }
)

dayjs.locale('de')

app.use(router)
app.use(pinia)
app.mount('#app')

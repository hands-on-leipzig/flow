import {createApp} from 'vue'
import App from './App.vue'
import {createRouter, createWebHistory} from 'vue-router'
import axios from 'axios'
import './assets/main.css'
import './assets/glass-layout.css'
import './assets/volunteers.css'
import './assets/settings-split-layout.css'
import keycloak from "@/keycloak.js";
import Schedule from "@/components/Schedule.vue";
import ScheduleGeneral from "@/components/ScheduleGeneral.vue";
import ScheduleTimes from "@/components/ScheduleTimes.vue";
import ScheduleIntegration from "@/components/ScheduleIntegration.vue";
import ScheduleAfternoon from "@/components/ScheduleAfternoon.vue";
import ScheduleExpert from "@/components/ScheduleExpert.vue";
import ScheduleProtected from "@/components/ScheduleProtected.vue";
import ScheduleFreeActivities from "@/components/ScheduleFreeActivities.vue";
import Logos from "@/components/Logos.vue";
import {createPinia, setActivePinia} from "pinia";
import SelectEvent from "@/components/SelectEvent.vue";
import dayjs from "dayjs";
import 'dayjs/locale/de';
import Rooms from "@/components/Rooms.vue";
import HomeOverview from "@/components/HomeOverview.vue";
import PublishControl from "@/components/PublishControl.vue";
import PublishDistribution from "@/components/publish/PublishDistribution.vue";
import PublishWlan from "@/components/publish/PublishWlan.vue";
import PublishDigital from "@/components/publish/PublishDigital.vue";
import PublishAnalog from "@/components/publish/PublishAnalog.vue";
import PublishNameTags from "@/components/publish/PublishNameTags.vue";
import EventDayShell from "@/components/EventDayShell.vue";
import EventDayCheckIn from "@/components/EventDayCheckIn.vue";
import EventDayCockpit from "@/components/EventDayCockpit.vue";
import CheckInReception from "@/components/CheckInReception.vue";
import CockpitApp from "@/components/CockpitApp.vue";
import {ADMIN_DEFAULT_SECTION} from '@/constants/adminNav'
// Admin is lazy-loaded - only loads when /admin route is accessed
// This reduces initial bundle size since most users are not admins
import Teams from "@/components/Teams.vue";
import TeamsProgram from "@/components/teams/TeamsProgram.vue";
import VolunteersPeople from "@/components/VolunteersPeople.vue";
import VolunteersRoster from "@/components/VolunteersRoster.vue";
import VolunteersStaffing from "@/components/VolunteersStaffing.vue";
import Preview from "@/components/molecules/Preview.vue";
import PlanPopout from "@/components/PlanPopout.vue";
import Carousel from "@/components/Carousel.vue";
import EditSlide from "@/components/EditSlide.vue";
import PlanLayout from "@/components/PlanLayout.vue";
import PublicEvent from "@/components/PublicEvent.vue";
import PublicSchedule from "@/components/PublicSchedule.vue";
import EventNotFound from "@/components/EventNotFound.vue";
import UnauthorizedAccess from "@/components/UnauthorizedAccess.vue";
import PublicScores from "@/components/PublicScores.vue";
import Profile from "@/components/Profile.vue";
import AccessManagement from "@/components/AccessManagement.vue";
import {useEventStore} from "@/stores/event";
import {useProgramsStore} from "@/stores/programs";
import {firstTeamsPath} from "@/utils/eventPrograms";
import StandaloneSlide from "@/components/StandaloneSlide.vue";
import {registerSW} from 'virtual:pwa-register'
import '@hands-on/glass/styles.css'
import {initTheme} from '@hands-on/glass/theme'

initTheme()

const routes = [
    {path: '/carousel/:eventId', component: Carousel, props: true, meta: {public: true}},
    {path: '/carousel/:eventId/:slideId', component: StandaloneSlide, props: true, meta: {public: true}},
    {path: '/scores/:eventId', component: PublicScores, props: true, meta: {public: true}},
    {path: '/public-schedule/:planId', component: PublicSchedule, props: true, meta: {public: true}},
    // Slim second-screen plan view (auth required, no app chrome / event bootstrap)
    {path: '/plan/popout/:planId', component: PlanPopout, props: true, meta: {popout: true}},
    {
        path: '/plan',
        component: PlanLayout,
        redirect: '/plan/overview',
        children: [
            {path: 'overview', component: HomeOverview},
            {path: 'event', redirect: '/plan/overview'},
            {
                path: 'schedule',
                component: Schedule,
                children: [
                    {path: '', name: 'schedule-general', component: ScheduleGeneral},
                    {path: 'integration', name: 'schedule-integration', component: ScheduleIntegration},
                    {path: 'times', name: 'schedule-times', component: ScheduleTimes},
                    {path: 'afternoon', name: 'schedule-afternoon', component: ScheduleAfternoon},
                    {path: 'expert', name: 'schedule-expert', component: ScheduleExpert},
                    {path: 'protected', name: 'schedule-protected', component: ScheduleProtected},
                    {path: 'blocks', redirect: {name: 'schedule-free'}},
                    {path: 'free', name: 'schedule-free', component: ScheduleFreeActivities},
                    {path: 'slots', name: 'schedule-slots', component: () => import('@/components/ScheduleSlotActivities.vue')},
                ],
            },
            {
                path: 'teams',
                component: Teams,
                redirect: () => firstTeamsPath(useEventStore().selectedEvent),
                children: [
                    {path: ':program', name: 'teams-program', component: TeamsProgram},
                ],
            },
            {path: 'volunteers', name: 'volunteers-people', component: VolunteersPeople},
            {path: 'volunteers/roster', name: 'volunteers-roster', component: VolunteersRoster},
            {path: 'volunteers/staffing', name: 'volunteers-staffing', component: VolunteersStaffing},
            {path: 'logos', redirect: '/plan/publish/logos'},
            {path: 'events', component: SelectEvent},
            {path: 'rooms', component: Rooms},
            {
                path: 'publish',
                component: PublishControl,
                children: [
                    {path: '', name: 'publish-distribution', component: PublishDistribution},
                    {path: 'wlan', name: 'publish-wlan', component: PublishWlan},
                    {path: 'digital', name: 'publish-digital', component: PublishDigital},
                    {path: 'analog', name: 'publish-analog', component: PublishAnalog},
                    {path: 'namensschilder', name: 'publish-namensschilder', component: PublishNameTags},
                    {path: 'logos', name: 'publish-logos', component: Logos},
                ],
            },
            {
                path: 'live',
                component: EventDayShell,
                redirect: {name: 'live-check-in'},
                children: [
                    {path: 'check-in', name: 'live-check-in', component: EventDayCheckIn},
                    {path: 'cockpit', name: 'live-cockpit', component: EventDayCockpit},
                ],
            },
            {path: 'slots', redirect: '/plan/schedule/slots'},
            {path: 'profile', component: Profile},
            {path: 'access', component: AccessManagement},
            // Lazy-load Admin — one component for all sections so tab state stays mounted
            {path: 'admin', redirect: `/plan/admin/${ADMIN_DEFAULT_SECTION}`},
            {path: 'admin/:section', component: () => import('@/components/Admin.vue')},
            {path: 'presentation', redirect: '/plan/publish/digital'},
            {path: 'preview/:planId', component: Preview, props: true},
            {path: 'editSlide/:slideId', component: EditSlide, props: true},
        ]
    },
    // Redirect old routes to new plan/ prefixed routes
    {path: '/overview', redirect: '/plan/overview'},
    {path: '/event', redirect: '/plan/overview'},
    {path: '/schedule', redirect: '/plan/schedule'},
    {path: '/schedule/blocks', redirect: '/plan/schedule/free'},
    {path: '/schedule/integration', redirect: '/plan/schedule/integration'},
    {path: '/schedule/times', redirect: '/plan/schedule/times'},
    {path: '/schedule/afternoon', redirect: '/plan/schedule/afternoon'},
    {path: '/schedule/expert', redirect: '/plan/schedule/expert'},
    {path: '/schedule/protected', redirect: '/plan/schedule/protected'},
    {path: '/schedule/free', redirect: '/plan/schedule/free'},
    {path: '/slots', redirect: '/plan/schedule/slots'},
    {path: '/schedule/slots', redirect: '/plan/schedule/slots'},
    {path: '/teams', redirect: '/plan/teams'},
    {path: '/teams/explore', redirect: '/plan/teams/explore'},
    {path: '/teams/challenge', redirect: '/plan/teams/challenge'},
    {path: '/teams/future8', redirect: '/plan/teams/future_8'},
    {path: '/logos', redirect: '/plan/publish/logos'},
    {path: '/events', redirect: '/plan/events'},
    {path: '/rooms', redirect: '/plan/rooms'},
    {path: '/publish', redirect: '/plan/publish'},
    {path: '/publish/wlan', redirect: '/plan/publish/wlan'},
    {path: '/publish/digital', redirect: '/plan/publish/digital'},
    {path: '/publish/analog', redirect: '/plan/publish/analog'},
    {path: '/publish/namensschilder', redirect: '/plan/publish/namensschilder'},
    {path: '/publish/logos', redirect: '/plan/publish/logos'},
    {path: '/event-day', redirect: '/plan/live/cockpit'},
    {path: '/live', redirect: '/plan/live/cockpit'},
    {path: '/admin', redirect: '/plan/admin'},
    {path: '/presentation', redirect: '/plan/publish/digital'},
    {path: '/preview/:planId', redirect: to => `/plan/preview/${to.params.planId}`},
    {path: '/editSlide/:slideId', redirect: to => `/plan/editSlide/${to.params.slideId}`},

    // Public slug-based routes (must be after all specific routes)
    {path: '/:slug/check-in', component: CheckInReception, props: true, meta: {public: true}},
    {path: '/:slug/cockpit', component: CockpitApp, props: true, meta: {public: true}},
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
    await useProgramsStore().ensureLoaded()

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
    // Skip check for the events selection page itself and slim pop-out windows
    if (
        !to.meta?.popout &&
        to.path !== '/plan/events' &&
        to.path !== '/events' &&
        to.path !== '/plan/profile' &&
        to.path !== '/plan/access' &&
        to.path.startsWith('/plan')
    ) {
        // Use the store - pinia is already active
        const eventStore = useEventStore();

        if (!eventStore.selectedEvent) {
            await eventStore.fetchSelectedEvent();
        } else {
            await eventStore.validateSelectedEventSeason();
        }

        // If still no event selected, redirect to event selection page
        if (!eventStore.selectedEvent) {
            next(eventStore.staleSeasonCleared
                ? '/plan/events?reason=stale-season'
                : '/plan/events');
            return;
        }

        // Day-of default view: on first load, open am Tag instead of Übersicht
        const isInitialNavigation = from.matched.length === 0
        if (isInitialNavigation && to.path === '/plan/overview' && isTodayWithinEvent(eventStore.selectedEvent)) {
            next('/plan/live/cockpit')
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

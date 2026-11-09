<script setup lang="ts">
import {onMounted, ref, computed, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import {useEventStore} from '@/stores/event'
import {useNoticeStore} from '@/stores/notice'
import {usePlanCacheStore} from '@/stores/planCache'
import {useProgramsStore} from '@/stores/programs'
import {useAuth} from '@/composables/useAuth'
import {useApps} from '@/composables/useApps'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import {useAdminInlineVisibility} from '@/composables/useAdminInlineVisibility'
import {imageUrl, programLogoSrc} from '@/utils/images'
import AppSwitcher from '@/components/AppSwitcher.vue'
import {eventPrograms, programDisplayName, teamPathFor, firstTeamsPath, programCompact, hasAfternoon} from '@/utils/eventPrograms'
import {
  ADMIN_SECTIONS,
  ADMIN_OPS_NAV,
  ADMIN_ENTWICKLUNG_NAV,
  ADMIN_DEFAULT_SECTION,
  adminSectionPath,
  isAdminSectionAvailable,
} from '@/constants/adminNav'
import type {AdminNavNode, AdminSection} from '@/constants/adminNav'
import {useAdminEnvironment} from '@/composables/useAdminEnvironment'
import keycloak from '@/keycloak.js'
import {theme, setTheme} from '@hands-on/glass/theme'
import AppShell from '@hands-on/glass/app-shell'
import ScorLogo from '@/components/scor/ScorLogo.vue'
import {useWiseRoles} from '@/composables/useWiseRoles'
import {useWiseSync, wiseSyncLabel} from '@/composables/useWiseSync'
import SidebarFooter from '@hands-on/glass/sidebar-footer'
import SidebarNavItem from '@hands-on/glass/sidebar-nav-item'

const eventStore = useEventStore()
const noticeStore = useNoticeStore()
const programsStore = useProgramsStore()
const planCache = usePlanCacheStore()
const {isAdmin, initializeUserRoles} = useAuth()
const {canFlow, canScor, ensure: ensureApps} = useApps()
const {planLocked} = useScheduleWorkspace()
const {
  showAdminInline,
  adminInlinePreference,
  setAdminInlinePreference,
} = useAdminInlineVisibility()
const {isDevEnvironment, isLocal, ensureLoaded: ensureAdminEnvironment} = useAdminEnvironment()
const router = useRouter()
const route = useRoute()

const userLabel = computed(() => {
  const parsed = keycloak?.tokenParsed as Record<string, unknown> | undefined
  const name = typeof parsed?.name === 'string' ? parsed.name.trim() : ''
  if (name) return name
  const preferred = typeof parsed?.preferred_username === 'string' ? parsed.preferred_username.trim() : ''
  return preferred || 'FLOW'
})

async function checkDataReadiness() {
  if (!eventStore.selectedEvent?.id) return
  await eventStore.refreshReadiness(eventStore.selectedEvent.id)
}

type NavChild = {
  name: string
  path: string
  icon?: string
  iconSrc?: string
  disabled?: boolean
  title?: string
}

type NavEntry = {
  name: string
  path?: string
  icon: string
  disabled?: boolean
  title?: string
  children?: NavChild[]
}

const teamNavChildren = computed<NavChild[]>(() => {
  const programs = eventPrograms(eventStore.selectedEvent).map((program) => ({
    name: programDisplayName(program),
    path: teamPathFor(program),
    icon: 'bi-people',
    iconSrc: programLogoSrc(program),
  }))
  return [
    {name: 'Teamdaten', path: '/plan/teams/data', icon: 'bi-table'},
    ...programs,
  ]
})

const navEntries = computed<NavEntry[]>(() => [
  {name: 'Übersicht', path: '/plan/overview', icon: 'bi-house-door'},
  {
    name: 'Ablauf',
    path: '/plan/schedule',
    icon: 'bi-list-check',
    children: [
      {name: 'Allgemein', path: '/plan/schedule', icon: 'bi-sliders2-vertical'},
      ...(eventPrograms(eventStore.selectedEvent).length > 1
        ? [{name: 'Integration', path: '/plan/schedule/integration', icon: 'bi-puzzle'}]
        : []),
      {name: 'Zeiten', path: '/plan/schedule/times', icon: 'bi-clock'},
      ...(hasAfternoon(eventStore.selectedEvent, programsStore.afternoonFirstPrograms)
        ? [{name: 'Nachmittag', path: '/plan/schedule/afternoon', icon: 'bi-sunset'}]
        : []),
      {name: 'Expertenparameter', path: '/plan/schedule/expert', icon: 'bi-gear-wide-connected'},
      ...(showAdminInline.value && !planLocked.value
        ? [{name: 'Geschützte Parameter', path: '/plan/schedule/protected', icon: 'bi-shield-lock'}]
        : []),
    ],
  },
  {
    name: 'Zusätzliche Aktivitäten',
    path: '/plan/schedule/free',
    icon: 'bi-calendar-plus',
    children: [
      {name: 'Freie Blöcke', path: '/plan/schedule/free', icon: 'bi-calendar2-plus'},
      {name: 'Slot-Blöcke', path: '/plan/schedule/slots', icon: 'bi-grid-3x3-gap'},
    ],
  },
  {
    name: 'Teams',
    path: firstTeamsPath(eventStore.selectedEvent),
    icon: 'bi-people',
    children: teamNavChildren.value,
  },
  {name: 'Räume', path: '/plan/rooms', icon: 'bi-door-open'},
  {
    name: 'Helfer:innen',
    path: '/plan/volunteers',
    icon: 'bi-person-heart',
    children: [
      {name: 'Personen', path: '/plan/volunteers', icon: 'bi-person-lines-fill'},
      {name: 'Helfer:innenliste', path: '/plan/volunteers/roster', icon: 'bi-clipboard-check'},
      {name: 'Zuordnung', path: '/plan/volunteers/staffing', icon: 'bi-diagram-3'},
    ],
  },
  {
    name: 'Ausgabe',
    path: '/plan/publish',
    icon: 'bi-broadcast',
    children: [
      {name: 'Öffentliche Seite', path: '/plan/publish', icon: 'bi-link-45deg'},
      {name: 'Logos', path: '/plan/publish/logos', icon: 'bi-images'},
      {name: 'WLAN vor Ort', path: '/plan/publish/wlan', icon: 'bi-wifi'},
      {name: 'Digital', path: '/plan/publish/digital', icon: 'bi-display'},
      {name: 'Drucksachen', path: '/plan/publish/analog', icon: 'bi-printer'},
      {name: 'Namensschilder', path: '/plan/publish/namensschilder', icon: 'bi-person-badge'},
    ],
  },
  {
    name: 'am Tag',
    path: '/plan/live/check-in',
    icon: 'bi-play-circle',
    children: [
      {name: 'Check-In', path: '/plan/live/check-in', icon: 'bi-person-check'},
      {name: 'Cockpit', path: '/plan/live/cockpit', icon: 'bi-speedometer2'},
    ],
  },
])

const isAdminMode = computed(() => route.path.startsWith('/plan/admin'))
const isScorMode = computed(() => route.path === '/scor' || route.path.startsWith('/scor/'))
const showBackToOverview = computed(() => isAdminMode.value)

const scorNavEntries = computed<NavEntry[]>(() => [
  {name: 'Veranstaltungen', path: '/scor', icon: 'bi-calendar-event'},
])

const wiseCollapsed = ref(true)

function onShellCollapsed(value: boolean) {
  if (!isScorMode.value) return
  wiseCollapsed.value = value
  try {
    localStorage.setItem('wise-sidebar-collapsed', value ? '1' : '0')
  } catch {
    /* private mode */
  }
}

const {roles: wiseRoles} = useWiseRoles()
const {sync: wiseSync} = useWiseSync()
const wiseEventId = computed(() => {
  const id = route.params.eventId
  return typeof id === 'string' && id !== '' ? id : null
})

function wiseStoredSicht() {
  if (!wiseEventId.value) return null
  try {
    return sessionStorage.getItem(`wise-sicht-${wiseEventId.value}`)
  } catch {
    return null
  }
}

const wiseBack = computed(() => {
  if (!wiseEventId.value) return null
  if (route.path.includes('/match/')) return {label: 'Schiri', to: `/scor/${wiseEventId.value}`}
  if (route.path.includes('/jury/')) return {label: 'Jury', to: `/scor/${wiseEventId.value}?sicht=jury`}
  return null
})

function goWiseBack() {
  if (!wiseBack.value) return
  void router.push(wiseBack.value.to)
}

const wiseSicht = computed(() => {
  if (!wiseEventId.value) return null
  if (route.path.includes('/match/')) return 'schiri'
  if (route.path.includes('/jury/')) return 'jury'
  if (route.query.sicht === 'jury' || route.query.sicht === 'ergebnisse') return route.query.sicht
  const stored = wiseStoredSicht()
  if (stored === 'jury' || stored === 'ergebnisse' || stored === 'schiri') return stored
  if (wiseRoles.value?.referee) return 'schiri'
  if (wiseRoles.value?.jury) return 'jury'
  return 'ergebnisse'
})

function flowTabOn(id: 'overview' | 'schedule' | 'teams' | 'live') {
  const path = route.path
  if (id === 'overview') return path === '/plan/overview'
  if (id === 'schedule') {
    return path.startsWith('/plan/schedule')
        && !path.startsWith('/plan/schedule/free')
        && !path.startsWith('/plan/schedule/slots')
  }
  if (id === 'teams') return path.startsWith('/plan/teams')
  return path.startsWith('/plan/live')
}

function openWiseSicht(sicht: 'schiri' | 'jury' | 'ergebnisse') {
  if (!wiseEventId.value) return
  try {
    sessionStorage.setItem(`wise-sicht-${wiseEventId.value}`, sicht)
  } catch {
    /* private mode */
  }
  mobileMenuOpen.value = false
  const path = sicht === 'schiri'
      ? `/scor/${wiseEventId.value}`
      : `/scor/${wiseEventId.value}?sicht=${sicht}`
  goToPath(path)
}

function toAdminNavEntry(item: AdminSection): NavEntry {
  const available = isAdminSectionAvailable(item, isDevEnvironment.value, isLocal)
  return {
    name: item.label,
    path: item.path,
    icon: item.icon,
    disabled: !available,
    title: available
      ? undefined
      : `${item.label} ist nur auf Dev oder lokal verfügbar`,
  }
}

function adminSectionByKey(key: string): AdminSection | undefined {
  return ADMIN_SECTIONS.find((item) => item.key === key)
}

function mapAdminNav(nodes: AdminNavNode[]): NavEntry[] {
  return nodes.map((node) => {
    if (node.kind === 'folder') {
      const children = node.children
        .map((key) => adminSectionByKey(key))
        .filter((item): item is AdminSection => !!item)
        .map((item) => {
          const entry = toAdminNavEntry(item)
          return {
            name: entry.name,
            path: entry.path ?? item.path,
            icon: entry.icon,
            disabled: entry.disabled,
            title: entry.title,
          }
        })
      const folderDisabled = children.length > 0 && children.every((child) => child.disabled)
      return {
        name: node.label,
        path: children[0]?.path,
        icon: node.icon,
        disabled: folderDisabled,
        title: folderDisabled ? children[0]?.title : undefined,
        children,
      }
    }
    const section = adminSectionByKey(node.key)
    if (!section) {
      return {name: node.key, icon: 'bi-question', disabled: true}
    }
    return toAdminNavEntry(section)
  })
}

const adminOpsNavEntries = computed<NavEntry[]>(() => mapAdminNav(ADMIN_OPS_NAV))

const adminEntwicklungNavEntries = computed<NavEntry[]>(() => mapAdminNav(ADMIN_ENTWICKLUNG_NAV))

const currentNavEntries = computed(() => (isAdminMode.value ? adminOpsNavEntries.value : navEntries.value))

function entryWarning(entry: NavEntry): boolean {
  if (entry.children?.length) {
    return entry.children.some((child) => hasWarning(child.path))
  }
  return !!entry.path && hasWarning(entry.path)
}

/** Parents with children: only highlight via active child (not own default path). */
function entryActive(entry: NavEntry): boolean {
  if (entry.children?.length) {
    return entry.children.some((child) => isActive(child.path))
  }
  return !!entry.path && isActive(entry.path)
}

function childNavProps(child: NavChild) {
  return {
    id: child.path,
    label: child.name,
    icon: child.icon,
    iconSrc: child.iconSrc,
    path: child.path,
    active: isActive(child.path),
    warning: hasWarning(child.path),
    disabled: !!child.disabled,
    title: child.title,
  }
}

onMounted(async () => {
  try {
    const stored = localStorage.getItem('wise-sidebar-collapsed')
    if (stored === '0') wiseCollapsed.value = false
  } catch {
    /* private mode */
  }
  initializeUserRoles()
  await ensureApps()
  if (!canFlow.value || isScorMode.value) return
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  // Sidebar warnings only — keep this light so Übersicht/SharePoint stay first
  await checkDataReadiness()
})

watch(isAdmin, (admin) => {
  if (admin) void ensureAdminEnvironment()
}, {immediate: true})

watch(isAdminMode, (adminMode) => {
  if (adminMode) void ensureAdminEnvironment()
})

watch(
    () => eventStore.selectedEvent?.id,
    async (newId, oldId) => {
      if (oldId && newId !== oldId) {
        planCache.clear()
      }
      if (newId) {
        await checkDataReadiness()
      }
    }
)

function hasWarning(tabPath: string): boolean {
  const dots = noticeStore.dots
  const path = normalizePlanPath(tabPath)

  if (path.startsWith('/plan/teams')) {
    const slug = path.replace(/^\/plan\/teams\/?/, '')
    if (!slug || slug === 'data') return false
    return !!dots.teams_by_program?.[programCompact(slug)]
  }

  switch (path) {
    case '/plan/schedule':
      return !!dots.schedule
    case '/plan/rooms':
      return !!dots.rooms
    case '/plan/volunteers/staffing':
      return !!dots.volunteers_staffing
    default:
      return false
  }
}

const mobileMenuOpen = ref(false)

function normalizePlanPath(path: string): string {
  const raw = (path || '').trim()
  if (!raw) return '/plan/overview'
  if (raw.startsWith('/plan/') || raw === '/plan') return raw.replace(/\/$/, '') || '/plan'
  if (raw.startsWith('/')) return (`/plan${raw}`).replace(/\/$/, '')
  return (`/plan/${raw}`).replace(/\/$/, '')
}

function isActive(path: string) {
  if (path.startsWith('/scor')) {
    const current = route.path.replace(/\/$/, '') || '/'
    const [pathOnly, query = ''] = path.split('?')
    const sicht = new URLSearchParams(query).get('sicht')
    if (pathOnly === '/scor') return current === '/scor'
    const onEvent = current === pathOnly || current.startsWith(`${pathOnly}/`)
    const juryView = route.query.sicht === 'jury' || current.includes('/jury/')
    const resultsView = route.query.sicht === 'ergebnisse'
    if (sicht === 'jury') return onEvent && juryView
    if (sicht === 'ergebnisse') return onEvent && resultsView && !current.includes('/jury/') && !current.includes('/match/')
    return onEvent && !juryView && !resultsView
  }
  const target = normalizePlanPath(path)
  const current = route.path.replace(/\/$/, '') || '/'
  return current === target
}

function goToPath(path: string) {
  mobileMenuOpen.value = false
  if (path.startsWith('/scor')) {
    if (route.fullPath === path) return
    void router.push(path)
    return
  }
  const target = normalizePlanPath(path)
  if (isActive(target)) return
  void router.push(target)
}

function onNavSelect(entry: NavEntry) {
  if (entry.disabled || !entry.path) return
  goToPath(entry.path)
}

function onNavChildSelect(child: { path?: string; label?: string }) {
  if (child?.path) goToPath(child.path)
}

function toggleMobileMenu() {
  mobileMenuOpen.value = !mobileMenuOpen.value
}

function logout() {
  localStorage.removeItem('kc_token')

  if (keycloak.authenticated) {
    keycloak.logout({
      redirectUri: window.location.origin
    })
  } else {
    window.location.reload()
  }
}
</script>

<template>
  <AppShell
      :class="{'glass-app--wise-compact': isScorMode}"
      :open="mobileMenuOpen"
      :collapsed="isScorMode ? wiseCollapsed : undefined"
      menu-aria-label="Menü öffnen"
      @toggle="toggleMobileMenu"
      @update:open="mobileMenuOpen = $event"
      @update:collapsed="onShellCollapsed"
  >
    <template v-if="isScorMode" #mobile-top>
      <button v-if="wiseBack" type="button" class="wise-back" @click="goWiseBack">
        <i class="bi bi-chevron-left" aria-hidden="true"/>
        {{ wiseBack.label }}
      </button>
      <ScorLogo/>
    </template>
    <template v-if="isScorMode" #mobile-top-actions>
      <span
          v-if="wiseSync !== 'idle'"
          class="wise-sync"
          :class="'is-' + wiseSync"
          role="status"
          :aria-label="wiseSyncLabel(wiseSync)"
      >
        <span class="wise-sync__dot" aria-hidden="true"/>
      </span>
      <AppSwitcher
          button-only
          :can-flow="canFlow"
          :can-scor="canScor"
          :scor-mode="isScorMode"
      />
    </template>
    <template #mobile-tabs="{ open, toggle }">
      <template v-if="!isScorMode">
        <button
            type="button"
            class="glass-app__mobile-tab"
            :class="{'glass-app__mobile-tab--active': !open && flowTabOn('overview')}"
            @click="goToPath('/plan/overview')"
        >
          <i class="bi bi-house-door" aria-hidden="true"/>
          <span class="glass-app__mobile-tab-label">Übersicht</span>
        </button>
        <button
            type="button"
            class="glass-app__mobile-tab"
            :class="{'glass-app__mobile-tab--active': !open && flowTabOn('schedule')}"
            @click="goToPath('/plan/schedule')"
        >
          <i class="bi bi-list-check" aria-hidden="true"/>
          <span class="glass-app__mobile-tab-label">Ablauf</span>
        </button>
        <button
            type="button"
            class="glass-app__mobile-tab"
            :class="{'glass-app__mobile-tab--active': !open && flowTabOn('teams')}"
            @click="goToPath(firstTeamsPath(eventStore.selectedEvent))"
        >
          <i class="bi bi-people" aria-hidden="true"/>
          <span class="glass-app__mobile-tab-label">Teams</span>
        </button>
        <button
            type="button"
            class="glass-app__mobile-tab"
            :class="{'glass-app__mobile-tab--active': !open && flowTabOn('live')}"
            @click="goToPath('/plan/live/check-in')"
        >
          <i class="bi bi-play-circle" aria-hidden="true"/>
          <span class="glass-app__mobile-tab-label">am Tag</span>
        </button>
      </template>
      <button
          v-if="isScorMode && wiseEventId && (!wiseRoles || wiseRoles.referee)"
          type="button"
          class="glass-app__mobile-tab"
          :class="{'glass-app__mobile-tab--active': !open && wiseSicht === 'schiri'}"
          @click="openWiseSicht('schiri')"
      >
        <i class="bi bi-clipboard-check" aria-hidden="true"/>
        <span class="glass-app__mobile-tab-label">Schiri</span>
      </button>
      <button
          v-if="isScorMode && wiseEventId && wiseRoles?.jury"
          type="button"
          class="glass-app__mobile-tab"
          :class="{'glass-app__mobile-tab--active': !open && wiseSicht === 'jury'}"
          @click="openWiseSicht('jury')"
      >
        <i class="bi bi-people" aria-hidden="true"/>
        <span class="glass-app__mobile-tab-label">Jury</span>
      </button>
      <button
          v-if="isScorMode && wiseEventId"
          type="button"
          class="glass-app__mobile-tab"
          :class="{'glass-app__mobile-tab--active': !open && wiseSicht === 'ergebnisse'}"
          @click="openWiseSicht('ergebnisse')"
      >
        <i class="bi bi-award" aria-hidden="true"/>
        <span class="glass-app__mobile-tab-label">Ergebnisse</span>
      </button>
      <button
          type="button"
          class="glass-app__mobile-tab glass-app__mobile-tab--more"
          :class="{'glass-app__mobile-tab--active': open}"
          :aria-pressed="open"
          aria-label="Mehr"
          @click="toggle"
      >
        <i class="bi" :class="open ? 'bi-x-lg' : 'bi-three-dots'" aria-hidden="true"/>
        <span class="glass-app__mobile-tab-label">Mehr</span>
      </button>
    </template>
    <template #brand>
      <AppSwitcher
          :can-flow="canFlow"
          :can-scor="canScor"
          :scor-mode="isScorMode"
      />
    </template>

    <template v-if="showBackToOverview" #nav-pinned>
      <SidebarNavItem
          label="Zurück zur Übersicht"
          icon="bi-arrow-left"
          @select="goToPath('/plan/overview')"
        />
    </template>

    <template #nav="{ collapsed: navCollapsed }">
      <template v-if="isAdminMode">
        <SidebarNavItem
            v-for="entry in adminOpsNavEntries"
            :key="entry.path ?? entry.name"
            :label="entry.name"
            :icon="entry.icon"
            :active="entryActive(entry)"
            :warning="entryWarning(entry)"
            :disabled="!!entry.disabled"
            :title="entry.title"
            :children="entry.children?.map(childNavProps)"
            @select="onNavSelect(entry)"
            @select-child="onNavChildSelect"
        />
        <div
            class="admin-nav-group"
            :class="{ 'admin-nav-group--collapsed': !!navCollapsed }"
            role="group"
            aria-label="Entwicklung"
        >
          <div class="admin-nav-group__header" :title="navCollapsed ? 'Entwicklung (nur Dev/Lokal)' : undefined">
            <span v-if="!navCollapsed" class="admin-nav-group__label">Entwicklung</span>
            <span
                v-if="!navCollapsed"
                class="glass-chip !px-2 !py-0.5 !text-xs shrink-0"
            >nur Dev/Lokal</span>
            <span v-else class="admin-nav-group__rail-mark" aria-hidden="true"/>
          </div>
          <SidebarNavItem
              v-for="entry in adminEntwicklungNavEntries"
              :key="entry.path ?? entry.name"
              :label="entry.name"
              :icon="entry.icon"
              :active="entryActive(entry)"
              :warning="entryWarning(entry)"
              :disabled="!!entry.disabled"
              :title="entry.title"
              :children="entry.children?.map(childNavProps)"
              @select="onNavSelect(entry)"
              @select-child="onNavChildSelect"
          />
        </div>
      </template>
      <template v-else-if="isScorMode">
        <SidebarNavItem
            v-for="entry in scorNavEntries"
            :key="entry.path ?? entry.name"
            :label="entry.name"
            :icon="entry.icon"
            :active="!!entry.path && isActive(entry.path) && !wiseEventId"
            @select="onNavSelect(entry)"
        />
        <SidebarNavItem
            v-if="wiseEventId && (!wiseRoles || wiseRoles.referee)"
            label="Schiri"
            icon="bi-clipboard-check"
            :active="wiseSicht === 'schiri'"
            @select="openWiseSicht('schiri')"
        />
        <SidebarNavItem
            v-if="wiseEventId && wiseRoles?.jury"
            label="Jury"
            icon="bi-people"
            :active="wiseSicht === 'jury'"
            @select="openWiseSicht('jury')"
        />
        <SidebarNavItem
            v-if="wiseEventId"
            label="Ergebnisse"
            icon="bi-award"
            :active="wiseSicht === 'ergebnisse'"
            @select="openWiseSicht('ergebnisse')"
        />
      </template>
      <template v-else>
        <SidebarNavItem
            v-for="entry in currentNavEntries"
            :key="entry.path ?? entry.name"
            :label="entry.name"
            :icon="entry.icon"
            :active="entryActive(entry)"
            :warning="entryWarning(entry)"
            :disabled="!!entry.disabled"
            :title="entry.title"
            :children="entry.children?.map(childNavProps)"
            @select="onNavSelect(entry)"
            @select-child="onNavChildSelect"
        />
      </template>
    </template>

    <template #lower="{ collapsed }">
      <SidebarFooter
          identity-aria-label="Account"
          settings-aria-label="Einstellungen"
      >
        <template #identity="{ close }">
          <div class="glass-sidebar-footer__menu-header">
            <span class="glass-sidebar-footer__menu-title">{{ userLabel }}</span>
            <span class="glass-sidebar-footer__menu-subtitle">{{ isScorMode ? 'WISE' : 'FLOW' }}</span>
          </div>
          <button
              v-if="canFlow"
              type="button"
              class="glass-sidebar-footer__menu-item"
              role="menuitem"
              @click="goToPath('/plan/profile'); close()"
          >
            <i class="bi bi-person" aria-hidden="true"/>
            <span>Profil</span>
          </button>
          <button
              v-if="canFlow"
              type="button"
              class="glass-sidebar-footer__menu-item"
              role="menuitem"
              @click="goToPath('/plan/access'); close()"
          >
            <i class="bi bi-shield-lock" aria-hidden="true"/>
            <span>Zugangsverwaltung</span>
          </button>
          <button
              type="button"
              class="glass-sidebar-footer__menu-item glass-sidebar-footer__menu-item--danger"
              role="menuitem"
              @click="logout(); close()"
          >
            <i class="bi bi-box-arrow-right" aria-hidden="true"/>
            <span>Ausloggen</span>
          </button>
        </template>

        <template #settings="{ close }">
          <div class="glass-sidebar-footer__prefs">
            <div class="glass-sidebar-footer__prefs-block">
              <span class="glass-sidebar-footer__menu-label">Theme</span>
              <div class="glass-sidebar-footer__prefs-row" role="group" aria-label="Theme">
                <button
                    type="button"
                    class="glass-sidebar-footer__pref-btn"
                    :class="{ active: theme === 'light' }"
                    :aria-pressed="theme === 'light'"
                    @click="setTheme('light')"
                >
                  <i class="bi bi-sun-fill" aria-hidden="true"/>
                  <span>Hell</span>
                </button>
                <button
                    type="button"
                    class="glass-sidebar-footer__pref-btn"
                    :class="{ active: theme === 'dark' }"
                    :aria-pressed="theme === 'dark'"
                    @click="setTheme('dark')"
                >
                  <i class="bi bi-moon-fill" aria-hidden="true"/>
                  <span>Dunkel</span>
                </button>
              </div>
            </div>
          </div>
          <button
              v-if="isAdmin && !isScorMode"
              type="button"
              class="glass-sidebar-footer__menu-item"
              role="menuitemcheckbox"
              :aria-checked="adminInlinePreference"
              :title="adminInlinePreference
                ? 'Preview-Admin-Tools und Geschützte Parameter ausblenden'
                : 'Preview-Admin-Tools und Geschützte Parameter einblenden'"
              @click="setAdminInlinePreference(!adminInlinePreference)"
          >
            <i
                class="bi"
                :class="adminInlinePreference ? 'bi-eye-fill' : 'bi-eye-slash'"
                aria-hidden="true"
            />
            <span>Inline-Admin {{ adminInlinePreference ? 'an' : 'aus' }}</span>
          </button>
          <button
              v-if="isAdmin && !isAdminMode"
              type="button"
              class="glass-sidebar-footer__menu-item"
              role="menuitem"
              @click="goToPath(adminSectionPath(ADMIN_DEFAULT_SECTION)); close()"
          >
            <i class="bi bi-shield-lock" aria-hidden="true"/>
            <span>Admin</span>
          </button>
        </template>

        <template v-if="canFlow && !isScorMode" #extra="{ close }">
          <button
              type="button"
              class="glass-sidebar-footer__icon-btn"
              :class="{ 'glass-sidebar-footer__icon-btn--active': isActive('/plan/help') }"
              aria-label="Hilfe"
              title="Hilfe"
              @click="goToPath('/plan/help'); close()"
          >
            <i class="bi bi-question-circle" aria-hidden="true"/>
          </button>
        </template>

        <template #partners>
          <a
              href="https://www.hands-on-technology.org"
              target="_blank"
              rel="noopener noreferrer"
              class="glass-sidebar__partner-link"
          >
            <img
                :src="imageUrl('/flow/hot.png')"
                alt="HANDS on TECHNOLOGY"
                class="glass-sidebar__partner-logo glass-sidebar__partner-logo--secondary"
            />
          </a>
        </template>
      </SidebarFooter>
    </template>

    <slot />
  </AppShell>
</template>


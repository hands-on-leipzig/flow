<script setup lang="ts">
import {onMounted, ref, computed, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import {useEventStore} from '@/stores/event'
import {usePlanCacheStore} from '@/stores/planCache'
import {useProgramsStore} from '@/stores/programs'
import {useAuth} from '@/composables/useAuth'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import {useAdminInlineVisibility} from '@/composables/useAdminInlineVisibility'
import {imageUrl, programLogoSrc} from '@/utils/images'
import {eventPrograms, programDisplayName, teamPathFor, firstTeamsPath, programCompact, hasAfternoon} from '@/utils/eventPrograms'
import {
  ADMIN_SECTIONS,
  ADMIN_OPS_NAV,
  ADMIN_ENTWICKLUNG_SECTIONS,
  ADMIN_DEFAULT_SECTION,
  adminSectionPath,
  isAdminSectionAvailable,
} from '@/constants/adminNav'
import type {AdminSection} from '@/constants/adminNav'
import {useAdminEnvironment} from '@/composables/useAdminEnvironment'
import keycloak from '@/keycloak.js'
import HelpModal from '@/components/atoms/HelpModal.vue'
import {theme, setTheme} from '@hands-on/glass/theme'
import AppShell from '@hands-on/glass/app-shell'
import SidebarFooter from '@hands-on/glass/sidebar-footer'
import SidebarNavItem from '@hands-on/glass/sidebar-nav-item'

const eventStore = useEventStore()
const programsStore = useProgramsStore()
const planCache = usePlanCacheStore()
const {isAdmin, initializeUserRoles} = useAuth()
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

const readiness = ref({
  explore_teams_ok: true,
  challenge_teams_ok: true,
  future_8_teams_ok: true,
  room_mapping_ok: true,
  staffing_ok: true,
})

async function checkDataReadiness() {
  if (!eventStore.selectedEvent?.id) return
  const data = await eventStore.refreshReadiness(eventStore.selectedEvent.id)
  if (data) {
    readiness.value = {
      explore_teams_ok: !!data.explore_teams_ok,
      challenge_teams_ok: !!data.challenge_teams_ok,
      future_8_teams_ok: data.future_8_teams_ok !== false,
      room_mapping_ok: !!data.room_mapping_ok,
      staffing_ok: data.staffing_ok !== false,
    }
  } else {
    readiness.value = {
      explore_teams_ok: false,
      challenge_teams_ok: false,
      future_8_teams_ok: false,
      room_mapping_ok: false,
      staffing_ok: false,
    }
  }
}

type NavChild = {
  name: string
  path: string
  icon?: string
  iconSrc?: string
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
      {name: 'Slots', path: '/plan/schedule/slots', icon: 'bi-grid-3x3-gap'},
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
      {name: 'Veröffentlichung', path: '/plan/publish', icon: 'bi-link-45deg'},
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
const showBackToOverview = computed(() => isAdminMode.value)

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

const adminOpsNavEntries = computed<NavEntry[]>(() =>
  ADMIN_OPS_NAV.map((node) => {
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
          }
        })
      return {
        name: node.label,
        path: children[0]?.path,
        icon: node.icon,
        children,
      }
    }
    const section = adminSectionByKey(node.key)
    if (!section) {
      return {name: node.key, icon: 'bi-question', disabled: true}
    }
    return toAdminNavEntry(section)
  }),
)

const adminEntwicklungNavEntries = computed<NavEntry[]>(() =>
  ADMIN_ENTWICKLUNG_SECTIONS.map(toAdminNavEntry),
)

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
  }
}

onMounted(async () => {
  initializeUserRoles()
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
    () => eventStore.readiness,
    (newVal) => {
      if (newVal) {
        readiness.value = {
          explore_teams_ok: !!newVal.explore_teams_ok,
          challenge_teams_ok: !!newVal.challenge_teams_ok,
          future_8_teams_ok: newVal.future_8_teams_ok !== false,
          room_mapping_ok: !!newVal.room_mapping_ok,
          staffing_ok: newVal.staffing_ok !== false,
        }
      }
    },
    {deep: true, immediate: true}
)

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
  if (!readiness.value) return false
  const path = normalizePlanPath(tabPath)

  if (path.startsWith('/plan/teams')) {
    const slug = path.replace(/^\/plan\/teams\/?/, '')
    if (!slug) {
      return eventPrograms(eventStore.selectedEvent).some((program) =>
        hasWarning(teamPathFor(program))
      )
    }
    // DRAHT vs FLOW only — plan capacity mismatch belongs on Ablauf/Allgemein
    return !!eventStore.selectedEvent?.discrepancyByProgram?.[programCompact(slug)]
  }

  switch (path) {
    case '/plan/schedule':
      return !readiness.value.explore_teams_ok
        || !readiness.value.challenge_teams_ok
        || !readiness.value.future_8_teams_ok
    case '/plan/rooms':
      return !readiness.value.room_mapping_ok
    case '/plan/volunteers/staffing':
      return !readiness.value.staffing_ok
    default:
      return false
  }
}

const showHelpModal = ref(false)
const mobileMenuOpen = ref(false)

function openHelpModal() {
  showHelpModal.value = true
  mobileMenuOpen.value = false
}

function closeHelpModal() {
  showHelpModal.value = false
}

function normalizePlanPath(path: string): string {
  const raw = (path || '').trim()
  if (!raw) return '/plan/overview'
  if (raw.startsWith('/plan/') || raw === '/plan') return raw.replace(/\/$/, '') || '/plan'
  if (raw.startsWith('/')) return (`/plan${raw}`).replace(/\/$/, '')
  return (`/plan/${raw}`).replace(/\/$/, '')
}

function isActive(path: string) {
  const target = normalizePlanPath(path)
  const current = route.path.replace(/\/$/, '') || '/'
  return current === target
}

function goToPath(path: string) {
  const target = normalizePlanPath(path)
  mobileMenuOpen.value = false
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
      :open="mobileMenuOpen"
      menu-aria-label="Menü öffnen"
      @toggle="toggleMobileMenu"
      @update:open="mobileMenuOpen = $event"
  >
    <template #brand>
      <RouterLink to="/plan/overview" class="glass-sidebar__brand" @click="mobileMenuOpen = false">
        <img :src="imageUrl('/flow/flow.png')" alt="FLOW" class="glass-sidebar__brand-logo"/>
      </RouterLink>
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
              @select="onNavSelect(entry)"
          />
        </div>
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
            <span class="glass-sidebar-footer__menu-subtitle">FLOW</span>
          </div>
          <button
              type="button"
              class="glass-sidebar-footer__menu-item"
              role="menuitem"
              @click="goToPath('/plan/profile'); close()"
          >
            <i class="bi bi-person" aria-hidden="true"/>
            <span>Profil</span>
          </button>
          <button
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
              v-if="isAdmin"
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
          <button
              type="button"
              class="glass-sidebar-footer__menu-item"
              role="menuitem"
              @click="openHelpModal(); close()"
          >
            <i class="bi bi-question-circle" aria-hidden="true"/>
            <span>Hilfe</span>
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

    <HelpModal :show="showHelpModal" @close="closeHelpModal"/>
  </AppShell>
</template>


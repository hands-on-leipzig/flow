<script setup lang="ts">
import {TabGroup, TabList, Tab, Menu, MenuButton, MenuItems, MenuItem} from '@headlessui/vue'
import {onMounted, ref, computed, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import {useEventStore} from '@/stores/event'
import {useAuth} from '@/composables/useAuth'
import axios from 'axios'
import dayjs from 'dayjs'
import {imageUrl, programLogoSrc, programLogoAlt} from '@/utils/images'
import {getAbbreviatedCompetitionType} from '@/utils/eventTitle'
import keycloak from '@/keycloak.js'
import HelpModal from '@/components/atoms/HelpModal.vue'

const eventStore = useEventStore()
const {isAdmin, initializeUserRoles} = useAuth()
const router = useRouter()
const route = useRoute()

// Event dropdown state
const selectableEvents = ref<any[]>([])
const loadingEvents = ref(false)
const userRegionalPartners = ref<number[]>([])

const showEventDropdown = computed(
    () => (dropdownEventsFlat.value.length > 1 || isAdmin.value) && eventStore.selectedEvent
)
const eventSearchQuery = ref('')
const eventSearchInputDesktop = ref<HTMLInputElement | null>(null)
const eventSearchInputMobilePanel = ref<HTMLInputElement | null>(null)

const dropdownEventsFlat = computed(() => {
  if (!selectableEvents.value.length) return []
  return selectableEvents.value.flatMap((rp: any) =>
      (rp.events || []).map((e: any) => ({
        ...e,
        regional_partner_id: rp.regional_partner?.id,
        regional_partner_name: rp.regional_partner?.name
      }))
  )
})

async function fetchSelectableEvents() {
  loadingEvents.value = true
  try {
    const response = await axios.get('/events/selectable')
    selectableEvents.value = response.data || []
    if (isAdmin.value) {
      try {
        const rpResponse = await axios.get('/user/regional-partners')
        if (rpResponse.data?.regional_partners) {
          userRegionalPartners.value = rpResponse.data.regional_partners.map((rp: any) => rp.id)
        }
      } catch {
        if (import.meta.env.DEV) console.debug('Failed to fetch regional partners')
      }
    }
  } catch (error) {
    console.error('Failed to fetch selectable events:', error)
  } finally {
    loadingEvents.value = false
  }
}

const dropdownEvents = computed(() => {
  if (!dropdownEventsFlat.value.length) return dropdownEventsFlat.value
  if (isAdmin.value && userRegionalPartners.value.length > 0) {
    return dropdownEventsFlat.value.filter(
        (e: any) => userRegionalPartners.value.includes(e.regional_partner_id)
    )
  }
  return dropdownEventsFlat.value
})

const filteredDropdownEvents = computed(() => {
  const query = eventSearchQuery.value.trim().toLowerCase()
  if (!query) return dropdownEvents.value

  return dropdownEvents.value.filter((ev: any) => {
    const name = ev.name?.toLowerCase() || ''
    const regionalPartner = ev.regional_partner_name?.toLowerCase() || ''
    const date = dayjs(ev.date).format('DD.MM.YY').toLowerCase()
    return name.includes(query) || regionalPartner.includes(query) || date.includes(query)
  })
})

async function selectEventFromDropdown(event: any, regionalPartnerId: number) {
  try {
    await axios.post('/user/select-event', {
      event: event.id,
      regional_partner: regionalPartnerId
    })
    await eventStore.fetchSelectedEvent()
    if (route.path.includes('/event')) {
      await router.replace('/event')
    } else {
      router.push('/event')
    }
  } catch (error) {
    console.error('Failed to select event:', error)
  }
}

function eventDropdownLabel() {
  const ev = eventStore.selectedEvent
  if (!ev) return 'Veranstaltung auswählen...'
  const type = getAbbreviatedCompetitionType(ev)
  const date = dayjs(ev.date).format('DD.MM.YY')
  return `${type} ${date}`.trim()
}

function focusSearchAfterDropdownOpen(event: MouseEvent, variant: 'desktop' | 'mobilePanel') {
  if (!isAdmin.value) return
  const trigger = event.currentTarget as HTMLElement | null
  if (!trigger) return

  const tryFocus = () => {
    if (trigger.getAttribute('aria-expanded') !== 'true') return
    const input =
        variant === 'desktop'
            ? eventSearchInputDesktop.value
            : eventSearchInputMobilePanel.value
    if (!input) return
    input.focus()
    input.setSelectionRange(0, input.value.length)
  }

  ;[0, 40, 120, 220].forEach((ms) => setTimeout(tryFocus, ms))
}

// --- Readiness State ---
const readiness = ref({
  explore_teams_ok: true,
  challenge_teams_ok: true,
  room_mapping_ok: true
})

// --- Backend-Check (jetzt über Store) ---
async function checkDataReadiness() {
  if (!eventStore.selectedEvent?.id) return
  const data = await eventStore.refreshReadiness(eventStore.selectedEvent.id)
  if (data) {
    readiness.value = {
      explore_teams_ok: !!data.explore_teams_ok,
      challenge_teams_ok: !!data.challenge_teams_ok,
      room_mapping_ok: !!data.room_mapping_ok,
    }
  } else {
    readiness.value = {
      explore_teams_ok: false,
      challenge_teams_ok: false,
      room_mapping_ok: false,
    }
  }
}

// --- Tabs definieren (Admin moved to Mehr menu) ---
const tabs = computed(() => {
  const allTabs = [
    {name: 'Veranstaltung', path: '/event'},
    {name: 'Ablauf', path: '/schedule'},
    {name: 'Teams', path: '/teams'},
    {name: 'Räume', path: '/rooms'},
    {name: 'Logos', path: '/logos'},
    {name: 'Veröffentlichung', path: '/publish'},
  ]
  return allTabs
})

// --- Lifecycle ---
onMounted(async () => {
  initializeUserRoles()
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  await checkDataReadiness()
  await fetchSelectableEvents()
})

// 👇 Watch: Wenn der Store-Readiness-State sich ändert → Navigation aktualisieren
watch(
    () => eventStore.readiness,
    (newVal) => {
      if (newVal) {
        readiness.value = {
          explore_teams_ok: !!newVal.explore_teams_ok,
          challenge_teams_ok: !!newVal.challenge_teams_ok,
          room_mapping_ok: !!newVal.room_mapping_ok,
        }
      }
    },
    {deep: true, immediate: true}
)
// 👇 Watcher: prüft beim Navigieren neu
watch(
    () => route.path,
    async () => {
      if (eventStore.selectedEvent?.id) {
        await checkDataReadiness()
      }
    }
)

watch(
    () => eventStore.selectedEvent?.id,
    () => fetchSelectableEvents()
)

watch(
    () => showEventDropdown.value,
    (isVisible) => {
      if (!isVisible) {
        eventSearchQuery.value = ''
      }
    }
)

// --- Helper für rote Punkte ---
function hasWarning(tabPath: string): boolean {
  if (!readiness.value) return false

  switch (tabPath) {
    case '/teams':
      return eventStore.selectedEvent?.hasTeamDiscrepancy
    case '/schedule':
      return !readiness.value.explore_teams_ok || !readiness.value.challenge_teams_ok
    case '/rooms':
      return !readiness.value.room_mapping_ok
    default:
      return false
  }
}

// --- Help Modal State ---
const showHelpModal = ref(false)

function openHelpModal() {
  showHelpModal.value = true
}

function closeHelpModal() {
  showHelpModal.value = false
}

// --- UI Navigation ---
const selectedTab = ref('Schedule')
const mobileMenuOpen = ref(false)

function isActive(path: string) {
  const cleanPath = path.replace(/^\//, '')
  return route.path.endsWith('/' + cleanPath) || route.path === '/plan/' + cleanPath
}

function goTo(tab: { name: string; path: string }) {
  selectedTab.value = tab.name
  router.push(tab.path)
  // Close mobile menu after navigation
  mobileMenuOpen.value = false
}

function toggleMobileMenu() {
  mobileMenuOpen.value = !mobileMenuOpen.value
}

function logout() {
  // Clear local storage
  localStorage.removeItem('kc_token')

  // Logout from Keycloak IDP - this will redirect to Keycloak logout endpoint
  // After logout, user will be redirected back to the app (or to Keycloak login page)
  if (keycloak.authenticated) {
    keycloak.logout({
      redirectUri: window.location.origin
    })
  } else {
    // If keycloak is not authenticated, just reload
    window.location.reload()
  }
}
</script>

<template>
  <div class="sticky top-0 z-50 bg-white shadow-sm border-b">
    <!-- Desktop Navigation (lg and up only) -->
    <div class="hidden lg:flex items-center justify-between px-3 xl:px-4 py-2">
      <div class="flex items-center gap-4 lg:gap-8 flex-1 min-w-0">
        <div class="flex items-center gap-2 lg:gap-4 flex-shrink-0">
          <img :src="imageUrl('/flow/flow.png')" alt="Logo" class="h-6 lg:h-8 w-auto"/>
          <img :src="imageUrl('/flow/hot+fll.png')" alt="Logo" class="h-6 lg:h-8 w-auto"/>
        </div>

        <TabGroup v-model="selectedTab" as="div">
          <TabList class="flex space-x-2">
            <Tab
                v-for="tab in tabs"
                :key="tab.path"
                class="px-4 py-2 rounded hover:bg-gray-100 relative"
                :class="{ 'bg-gray-200 font-medium': isActive(tab.path) }"
                @click="goTo(tab)"
            >
              {{ tab.name }}
              <div
                  v-if="hasWarning(tab.path)"
                  class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"
                  title="Achtung: Es gibt offene Punkte in diesem Bereich"
              ></div>
            </Tab>
          </TabList>
        </TabGroup>
      </div>
      <!-- Event dropdown (only when multiple events or admin) -->
      <Menu v-if="showEventDropdown" as="div" class="relative inline-block text-left flex-shrink-0">
        <MenuButton
            @click="focusSearchAfterDropdownOpen($event, 'desktop')"
            class="group inline-flex items-center justify-between gap-2 px-1 py-1.5 text-sm font-medium text-gray-700 bg-transparent border-0 border-b border-gray-300 hover:border-gray-500 focus:outline-none focus:ring-0 focus:border-blue-500 transition-colors"
        >
          <span class="text-left whitespace-nowrap">{{ eventDropdownLabel() }}</span>
          <svg class="w-4 h-4 ml-1 flex-shrink-0 text-gray-500 group-hover:text-gray-700 transition-colors" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd"
                  d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                  clip-rule="evenodd"/>
          </svg>
        </MenuButton>
        <MenuItems
            class="absolute right-0 z-50 mt-2 origin-top-right rounded-xl bg-white shadow-xl ring-1 ring-black ring-opacity-5 focus:outline-none w-[380px] max-w-[calc(100vw-2rem)] max-h-[70vh] overflow-y-auto">
          <div class="py-2">
            <div v-if="isAdmin" class="px-3 pb-2">
              <input
                  ref="eventSearchInputDesktop"
                  v-model="eventSearchQuery"
                  type="text"
                  placeholder="Veranstaltung suchen..."
                  class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
              />
            </div>
            <div v-if="loadingEvents" class="px-4 py-6 text-center text-sm text-gray-500">Lade...</div>
            <div v-else-if="filteredDropdownEvents.length === 0" class="px-4 py-6 text-center text-sm text-gray-500">
              Keine Veranstaltungen gefunden.
            </div>
            <template v-else>
              <MenuItem
                  v-for="ev in filteredDropdownEvents"
                  :key="ev.id"
                  v-slot="{ active }"
              >
                <button
                    @click="selectEventFromDropdown(ev, ev.regional_partner_id)"
                    :class="[
                  'w-full text-left px-4 py-3 transition-colors',
                  active ? 'bg-gray-50' : '',
                  eventStore.selectedEvent?.id === ev.id ? 'bg-blue-50 border-l-4 border-blue-500' : ''
                ]"
                >
                  <div class="flex justify-between items-start gap-2 min-w-0">
                    <div class="flex-1 min-w-0">
                      <div class="font-medium truncate">{{ ev.name }}</div>
                      <div class="text-xs text-gray-500">{{ dayjs(ev.date).format('DD.MM.YY') }} ·
                        {{ ev.regional_partner_name }}
                      </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                      <img v-if="ev.event_explore" :src="programLogoSrc('E')" :alt="programLogoAlt('E')"
                           class="w-5 h-5"/>
                      <img v-if="ev.event_challenge" :src="programLogoSrc('C')" :alt="programLogoAlt('C')"
                           class="w-5 h-5"/>
                      <span v-if="eventStore.selectedEvent?.id === ev.id"
                            class="w-5 h-5 rounded-full bg-blue-600 text-white text-xs flex items-center justify-center">✓</span>
                    </div>
                  </div>
                </button>
              </MenuItem>
              <MenuItem v-if="isAdmin" v-slot="{ active }">
                <button
                    @click="router.push({ path: '/events' })"
                    :class="['w-full text-left px-4 py-3 text-sm border-t border-gray-100', active ? 'bg-blue-50' : 'text-blue-600 hover:bg-blue-50']"
                >
                  Mehr Veranstaltungen...
                </button>
              </MenuItem>
            </template>
          </div>
        </MenuItems>
      </Menu>
      <Menu as="div" class="relative inline-block text-left">
        <MenuButton
            class="inline-flex justify-center w-full px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900">
          Mehr
        </MenuButton>
        <MenuItems
            class="absolute right-0 z-10 mt-2 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none w-fit">
          <div class="py-1">
            <MenuItem v-if="isAdmin">
              <button
                  @click="goTo({ name: 'Admin', path: '/admin' })"
                  class="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 text-left whitespace-nowrap w-full"
              >
                Admin
              </button>
            </MenuItem>
            <MenuItem>
              <button
                  @click="openHelpModal"
                  class="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 text-left whitespace-nowrap w-full"
              >
                Hilfe
              </button>
            </MenuItem>
            <MenuItem>
              <button
                  @click="logout"
                  class="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 text-left whitespace-nowrap"
              >
                Logout
              </button>
            </MenuItem>
          </div>
        </MenuItems>
      </Menu>
    </div>

    <!-- Narrow / Mobile Navigation (below lg) -->
    <div class="flex lg:hidden items-center gap-2 px-3 py-2 min-h-[48px]">
      <div class="flex items-center gap-2 flex-shrink-0">
        <img :src="imageUrl('/flow/flow.png')" alt="Logo" class="h-6 w-auto"/>
        <img :src="imageUrl('/flow/hot+fll.png')" alt="Logo" class="h-6 w-auto"/>
      </div>
      <button
          type="button"
          class="ml-auto inline-flex items-center justify-center p-2.5 rounded-lg text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500 flex-shrink-0"
          aria-label="Menü öffnen"
          @click="toggleMobileMenu"
      >
        <svg v-if="!mobileMenuOpen" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
        <svg v-else class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <!-- Mobile menu panel -->
    <Transition
        enter-active-class="transition ease-out duration-200"
        enter-from-class="opacity-0 -translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition ease-in duration-150"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 -translate-y-2"
    >
      <div
          v-show="mobileMenuOpen"
          class="lg:hidden border-t border-gray-200 bg-white px-3 py-3 shadow-inner"
      >
        <Menu v-if="showEventDropdown" as="div" class="relative mb-3 pb-3 border-b border-gray-200">
          <MenuButton
              @click="focusSearchAfterDropdownOpen($event, 'mobilePanel')"
              class="group inline-flex items-center justify-between gap-2 w-full min-w-0 px-1 py-2 text-sm font-medium text-gray-700 bg-transparent border-0 border-b border-gray-300 hover:border-gray-500 focus:outline-none focus:ring-0 focus:border-blue-500 transition-colors">
            <span class="flex-1 text-left whitespace-nowrap">{{ eventDropdownLabel() }}</span>
            <svg class="w-4 h-4 flex-shrink-0 text-gray-500 group-hover:text-gray-700 transition-colors" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd"
                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                    clip-rule="evenodd"/>
            </svg>
          </MenuButton>
          <MenuItems
              class="mt-2 w-full max-h-[60vh] overflow-y-auto rounded-xl bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
            <div class="py-2">
              <div v-if="isAdmin" class="px-3 pb-2">
                <input
                    ref="eventSearchInputMobilePanel"
                    v-model="eventSearchQuery"
                    type="text"
                    placeholder="Veranstaltung suchen..."
                    class="w-full px-3 py-2 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
              </div>
              <div v-if="loadingEvents" class="px-4 py-6 text-center text-sm text-gray-500">Lade...</div>
              <div v-else-if="filteredDropdownEvents.length === 0" class="px-4 py-6 text-center text-sm text-gray-500">
                Keine Veranstaltungen gefunden.
              </div>
              <template v-else>
                <MenuItem v-for="ev in filteredDropdownEvents" :key="ev.id" v-slot="{ active }">
                  <button @click="selectEventFromDropdown(ev, ev.regional_partner_id); mobileMenuOpen = false"
                          :class="['w-full text-left px-4 py-3 text-sm', active ? 'bg-gray-50' : '']">
                    <div class="font-medium truncate">{{ ev.name }}</div>
                    <div class="text-xs text-gray-500">{{ dayjs(ev.date).format('DD.MM.YY') }} ·
                      {{ ev.regional_partner_name }}
                    </div>
                  </button>
                </MenuItem>
                <MenuItem v-if="isAdmin" v-slot="{ active }">
                  <button @click="router.push({ path: '/events' }); mobileMenuOpen = false"
                          :class="['w-full text-left px-4 py-3 text-sm border-t border-gray-100', active ? 'bg-blue-50' : 'text-blue-600']">
                    Mehr Veranstaltungen...
                  </button>
                </MenuItem>
              </template>
            </div>
          </MenuItems>
        </Menu>
        <nav class="flex flex-col gap-1">
          <button
              v-for="tab in tabs"
              :key="tab.path"
              type="button"
              :class="[
              'flex items-center gap-2 px-4 py-3 rounded-lg text-left text-sm font-medium transition-colors',
              isActive(tab.path) ? 'bg-gray-200 text-gray-900' : 'text-gray-700 hover:bg-gray-100'
            ]"
              @click="goTo(tab)"
          >
            <span>{{ tab.name }}</span>
            <span v-if="hasWarning(tab.path)" class="w-2 h-2 bg-red-500 rounded-full flex-shrink-0"
                  title="Offene Punkte"></span>
          </button>
        </nav>
        <div class="mt-3 pt-3 border-t border-gray-200 flex flex-col gap-1">
          <button v-if="isAdmin" type="button"
                  class="px-4 py-3 rounded-lg text-sm text-gray-700 hover:bg-gray-100 text-left"
                  @click="goTo({ name: 'Admin', path: '/admin' })">
            Admin
          </button>
          <button type="button" class="px-4 py-3 rounded-lg text-sm text-gray-700 hover:bg-gray-100 text-left"
                  @click="openHelpModal(); mobileMenuOpen = false">
            Hilfe
          </button>
          <button type="button" class="px-4 py-3 rounded-lg text-sm text-gray-700 hover:bg-gray-100 text-left"
                  @click="router.push({ path: '/events' }); mobileMenuOpen = false">
            Veranstaltung wechseln
          </button>
          <button type="button" class="px-4 py-3 rounded-lg text-sm text-gray-700 hover:bg-gray-100 text-left"
                  @click="logout">
            Logout
          </button>
        </div>
      </div>
    </Transition>

    <!-- Help Modal -->
    <HelpModal :show="showHelpModal" @close="closeHelpModal"/>
  </div>
</template>


<style scoped>
/* Additional styles if needed */
</style>

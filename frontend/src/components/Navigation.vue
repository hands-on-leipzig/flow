<script setup lang="ts">
import { TabGroup, TabList, Tab, Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue'
import { onMounted, ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useEventStore } from '@/stores/event'
import { useAuth } from '@/composables/useAuth'
import axios from 'axios'
import dayjs from 'dayjs'
import { imageUrl, programLogoSrc, programLogoAlt } from '@/utils/images'
import keycloak from '@/keycloak.js'
import HelpModal from '@/components/atoms/HelpModal.vue'

const eventStore = useEventStore()
const { isAdmin, initializeUserRoles } = useAuth()
const router = useRouter()
const route = useRoute()

// Event dropdown state
const selectableEvents = ref<any[]>([])
const loadingEvents = ref(false)
const userRegionalPartners = ref<number[]>([]) // Store user's regional partner IDs for admin filtering

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

// --- Tabs definieren ---
const tabs = computed(() => {
  const allTabs = [
    { name: 'Veranstaltung', path: '/event' },
    { name: 'Ablauf', path: '/schedule' },
    { name: 'Teams', path: '/teams' },
    { name: 'Räume', path: '/rooms' },
    { name: 'Logos', path: '/logos' },
    { name: 'Veröffentlichung', path: '/publish' },
    { name: 'Admin', path: '/admin' },
  ]
  return allTabs.filter(tab => tab.path !== '/admin' || isAdmin.value)
})

// --- Fetch selectable events for dropdown ---
async function fetchSelectableEvents() {
  loadingEvents.value = true
  try {
    const response = await axios.get('/events/selectable')
    selectableEvents.value = response.data
    
    // For admins, get their regional partners to filter events to show only their own
    if (isAdmin.value) {
      try {
        const rpResponse = await axios.get('/user/regional-partners')
        if (rpResponse.data?.regional_partners) {
          userRegionalPartners.value = rpResponse.data.regional_partners.map((rp: any) => rp.id)
        }
      } catch (err) {
        console.error('Failed to fetch user regional partners:', err)
        // If we can't get user's regional partners, show all events (fallback)
      }
    }
  } catch (error) {
    console.error('Failed to fetch selectable events:', error)
  } finally {
    loadingEvents.value = false
  }
}

// --- Filter events for dropdown ---
const dropdownEvents = computed(() => {
  if (!selectableEvents.value.length) return []
  
  if (isAdmin.value) {
    // For admins: show only events from their own regional partners
    const filtered = selectableEvents.value
      .filter((rp: any) => {
        // If we have user regional partners, filter by them
        if (userRegionalPartners.value.length > 0) {
          return userRegionalPartners.value.includes(rp.regional_partner.id)
        }
        // If we can't determine user's regional partners, show all (fallback)
        return true
      })
      .flatMap((rp: any) => 
        rp.events.map((event: any) => ({
          ...event,
          regional_partner_id: rp.regional_partner.id,
          regional_partner_name: rp.regional_partner.name
        }))
      )
    
    return filtered
  } else {
    // For non-admins: show all their events (already filtered by API)
    return selectableEvents.value.flatMap((rp: any) => 
      rp.events.map((event: any) => ({
        ...event,
        regional_partner_id: rp.regional_partner.id,
        regional_partner_name: rp.regional_partner.name
      }))
    )
  }
})

// --- Select event from dropdown ---
async function selectEventFromDropdown(event: any, regionalPartnerId: number) {
  try {
    await axios.post('/user/select-event', {
      event: event.id,
      regional_partner: regionalPartnerId
    })
    await eventStore.fetchSelectedEvent()
    // Navigate to event overview if not already there
    if (!route.path.includes('/event')) {
      router.push('/event')
    }
  } catch (error) {
    console.error('Failed to select event:', error)
  }
}

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
  { deep: true, immediate: true }
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

// 👇 Watcher: Refresh events when selected event changes
watch(
  () => eventStore.selectedEvent?.id,
  async () => {
    await fetchSelectableEvents()
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

function isActive(path: string) {
  const cleanPath = path.replace(/^\//, '')
  return route.path.endsWith('/' + cleanPath) || route.path === '/plan/' + cleanPath
}

function goTo(tab) {
  selectedTab.value = tab.name
  router.push(tab.path)
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
  <div class="sticky top-0 z-50 flex items-center justify-between border-b px-2 py-2 bg-white shadow-sm">
    <div class="flex items-center gap-8">
      <img :src="imageUrl('/flow/flow.png')" alt="Logo" class="h-8 w-auto"/>
      <img :src="imageUrl('/flow/hot+fll.png')" alt="Logo" class="h-8 w-auto"/>

      <TabGroup v-model="selectedTab" as="div">
        <TabList class="flex space-x-2">
          <Tab
            v-for="tab in tabs"
            :key="tab.path"
            :to="tab.path"
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
    <!-- Event Selection Dropdown -->
    <Menu as="div" class="relative inline-block text-left">
      <MenuButton
        class="group inline-flex items-center justify-between px-4 py-2 text-sm font-medium text-gray-700 bg-gradient-to-r from-white to-gray-50/50 backdrop-blur-sm rounded-lg hover:from-white hover:to-white hover:shadow-lg transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 min-w-[320px]"
      >
        <span v-if="eventStore.selectedEvent" class="text-left flex-1 truncate">
          {{ eventStore.selectedEvent?.level_rel?.name }}
          {{ eventStore.selectedEvent?.name }}
          am
          {{ dayjs(eventStore.selectedEvent?.date).format('dddd, DD.MM.YYYY') }}
        </span>
        <span v-else class="text-gray-500 italic text-left flex-1">
          Veranstaltung auswählen...
        </span>
        <svg
          class="w-5 h-5 ml-2 -mr-1 text-gray-400 group-hover:text-gray-600 transition-transform duration-200 group-hover:rotate-180 flex-shrink-0"
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 20 20"
          fill="currentColor"
        >
          <path
            fill-rule="evenodd"
            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
            clip-rule="evenodd"
          />
        </svg>
      </MenuButton>
      <MenuItems
        class="absolute right-0 z-50 mt-3 origin-top-right rounded-2xl bg-white/95 backdrop-blur-md shadow-2xl ring-1 ring-gray-200/50 focus:outline-none w-[420px] max-w-[calc(100vw-2rem)] max-h-[600px] overflow-y-auto overflow-x-hidden"
      >
        <div class="py-2">
          <div v-if="loadingEvents" class="px-4 py-8 text-center">
            <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600"></div>
            <p class="mt-2 text-sm text-gray-500">Lade Veranstaltungen...</p>
          </div>
          <div v-else-if="dropdownEvents.length === 0" class="px-4 py-8 text-center">
            <p class="text-sm text-gray-500">Keine Veranstaltungen verfügbar</p>
          </div>
          <template v-else>
            <MenuItem
              v-for="event in dropdownEvents"
              :key="event.id"
              v-slot="{ active, focus }"
            >
              <button
                @click="selectEventFromDropdown(event, event.regional_partner_id)"
                :class="[
                  'w-full text-left px-4 py-3 transition-all duration-200 min-w-0',
                  active || focus ? 'bg-gradient-to-r from-blue-50 to-blue-50/50' : '',
                  eventStore.selectedEvent?.id === event.id 
                    ? 'bg-gradient-to-r from-blue-50 via-blue-50/80 to-transparent border-l-4 border-blue-500 shadow-sm' 
                    : 'hover:bg-gradient-to-r hover:from-gray-50 hover:to-transparent'
                ]"
              >
                <div class="flex justify-between items-start gap-3 min-w-0">
                  <div class="flex-1 min-w-0 overflow-hidden">
                    <div class="font-medium text-gray-900 mb-1 truncate">
                      {{ event.name }}
                    </div>
                    <div class="text-xs text-gray-500 mb-1 truncate">
                      {{ dayjs(event.date).format('dddd, DD.MM.YYYY') }}
                    </div>
                    <div class="text-xs text-gray-500 truncate">
                      {{ event.level?.name }} • {{ event.regional_partner_name }}
                    </div>
                  </div>
                  <div class="flex items-center gap-2 ml-2 flex-shrink-0">
                    <div class="flex items-center gap-1.5">
                      <img
                        v-if="event.event_explore"
                        :src="programLogoSrc('E')"
                        :alt="programLogoAlt('E')"
                        class="w-6 h-6 opacity-90 hover:opacity-100 transition-opacity"
                        title="FIRST LEGO League Explore"
                      />
                      <img
                        v-if="event.event_challenge"
                        :src="programLogoSrc('C')"
                        :alt="programLogoAlt('C')"
                        class="w-6 h-6 opacity-90 hover:opacity-100 transition-opacity"
                        title="FIRST LEGO League Challenge"
                      />
                    </div>
                    <div 
                      v-if="eventStore.selectedEvent?.id === event.id" 
                      class="flex items-center justify-center w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold"
                    >
                      ✓
                    </div>
                  </div>
                </div>
              </button>
            </MenuItem>
            
            <!-- "More" option for admins -->
            <MenuItem v-if="isAdmin" v-slot="{ active }">
              <div class="border-t border-gray-200 mt-2 pt-2">
                <button
                  @click="router.push({ path: '/events' })"
                  :class="[
                    'w-full text-left px-4 py-3 rounded-lg mx-2 transition-all duration-150',
                    active ? 'bg-blue-50 text-blue-700' : 'text-blue-600 hover:bg-blue-50 hover:text-blue-700',
                    'font-medium text-sm'
                  ]"
                >
                  <div class="flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Mehr Veranstaltungen...
                  </div>
                </button>
              </div>
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
      <MenuItems class="absolute right-0 z-10 mt-2 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none w-fit">
        <div class="py-1">
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
                @click="router.push({ path: '/events' })"
                class="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 text-left whitespace-nowrap"
            >
              Veranstaltung wechseln
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
    <!-- Help Modal -->
    <HelpModal :show="showHelpModal" @close="closeHelpModal" />
  </div>
</template>



<style scoped>
/* Additional styles if needed */
</style>

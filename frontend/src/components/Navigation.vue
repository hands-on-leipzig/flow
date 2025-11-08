<script setup lang="ts">
import { TabGroup, TabList, Tab, Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue'
import { onMounted, ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useEventStore } from '@/stores/event'
import { useAuth } from '@/composables/useAuth'
import axios from 'axios'
import dayjs from 'dayjs'
import { imageUrl } from '@/utils/images'
import keycloak from '@/keycloak.js'

const eventStore = useEventStore()
const { isAdmin, initializeUserRoles } = useAuth()
const router = useRouter()
const route = useRoute()

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

// --- Lifecycle ---
onMounted(async () => {
  initializeUserRoles()
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  await checkDataReadiness()
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

// --- UI Navigation ---
const selectedTab = ref('Schedule')
const mobileMenuOpen = ref(false)

function isActive(path: string) {
  const cleanPath = path.replace(/^\//, '')
  return route.path.endsWith('/' + cleanPath) || route.path === '/plan/' + cleanPath
}

function goTo(tab) {
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
    <!-- Desktop Navigation -->
    <div class="hidden md:flex items-center justify-between px-3 lg:px-4 py-2">
      <div class="flex items-center gap-4 lg:gap-8 flex-1 min-w-0">
        <div class="flex items-center gap-2 lg:gap-4 flex-shrink-0">
          <img :src="imageUrl('/flow/flow.png')" alt="Logo" class="h-6 lg:h-8 w-auto"/>
          <img :src="imageUrl('/flow/hot+fll.png')" alt="Logo" class="h-6 lg:h-8 w-auto"/>
        </div>

        <TabGroup v-model="selectedTab" as="div" class="flex-1 min-w-0">
          <TabList class="flex space-x-1 lg:space-x-2 overflow-x-auto">
            <Tab
              v-for="tab in tabs"
              :key="tab.path"
              :to="tab.path"
              class="px-2 lg:px-4 py-1.5 lg:py-2 rounded text-sm lg:text-base hover:bg-gray-100 relative whitespace-nowrap flex-shrink-0"
              :class="{ 'bg-gray-200 font-medium': isActive(tab.path) }"
              @click="goTo(tab)"
            >
              {{ tab.name }}
              <div
                v-if="hasWarning(tab.path)"
                class="absolute top-0.5 right-0.5 w-1.5 h-1.5 lg:w-2 lg:h-2 bg-red-500 rounded-full"
                title="Achtung: Es gibt offene Punkte in diesem Bereich"
              ></div>
            </Tab>
          </TabList>
        </TabGroup>
      </div>
      
      <div class="hidden lg:flex items-center gap-2 px-4 text-sm text-gray-700 flex-shrink-0">
        <span class="whitespace-nowrap">
          {{ eventStore.selectedEvent?.level_rel?.name }}
          {{ eventStore.selectedEvent?.name }}
          am
          {{ dayjs(eventStore.selectedEvent?.date).format('dddd, DD.MM.YYYY') }}
        </span>
      </div>
      
      <Menu as="div" class="relative inline-block text-left flex-shrink-0">
        <MenuButton
          class="inline-flex justify-center w-full px-3 lg:px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900"
        >
          Mehr
        </MenuButton>
        <MenuItems class="absolute right-0 z-10 mt-2 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none w-fit">
          <div class="py-1">
            <MenuItem>
              <button
                  @click="router.push({ path: '/events' })"
                  class="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 text-left whitespace-nowrap w-full"
              >
                Veranstaltung wechseln
              </button>
            </MenuItem>
            <MenuItem>
              <button
                  @click="logout"
                  class="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 text-left whitespace-nowrap w-full"
              >
                Logout
              </button>
            </MenuItem>
          </div>
        </MenuItems>
      </Menu>
    </div>

    <!-- Mobile Navigation -->
    <div class="md:hidden">
      <!-- Mobile Header Bar -->
      <div class="flex items-center justify-between px-3 py-2">
        <div class="flex items-center gap-2">
          <img :src="imageUrl('/flow/flow.png')" alt="Logo" class="h-6 w-auto"/>
          <img :src="imageUrl('/flow/hot+fll.png')" alt="Logo" class="h-6 w-auto"/>
        </div>
        
        <div class="flex items-center gap-2">
          <!-- Event Info (Compact) -->
          <div class="text-xs text-gray-600 text-right max-w-[140px] truncate">
            <div class="font-medium truncate">{{ eventStore.selectedEvent?.name }}</div>
            <div class="text-gray-500">{{ dayjs(eventStore.selectedEvent?.date).format('DD.MM.YYYY') }}</div>
          </div>
          
          <!-- Hamburger Menu Button -->
          <button
            @click="toggleMobileMenu"
            class="p-2 rounded-md text-gray-700 hover:bg-gray-100 focus:outline-none"
            aria-label="Toggle menu"
          >
            <svg v-if="!mobileMenuOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Mobile Menu Dropdown -->
      <div
        v-if="mobileMenuOpen"
        class="border-t bg-white shadow-lg max-h-[calc(100vh-80px)] overflow-y-auto"
      >
        <!-- Navigation Tabs -->
        <div class="py-2">
          <div
            v-for="tab in tabs"
            :key="tab.path"
            @click="goTo(tab)"
            class="flex items-center justify-between px-4 py-3 text-base hover:bg-gray-100 cursor-pointer relative"
            :class="{ 'bg-gray-200 font-medium': isActive(tab.path) }"
          >
            <span>{{ tab.name }}</span>
            <div
              v-if="hasWarning(tab.path)"
              class="w-2 h-2 bg-red-500 rounded-full"
              title="Achtung: Es gibt offene Punkte in diesem Bereich"
            ></div>
          </div>
        </div>

        <!-- Divider -->
        <div class="border-t my-2"></div>

        <!-- More Menu Items -->
        <div class="py-2">
          <button
            @click="router.push({ path: '/events' }); mobileMenuOpen = false"
            class="w-full text-left px-4 py-3 text-base text-gray-700 hover:bg-gray-100"
          >
            Veranstaltung wechseln
          </button>
          <button
            @click="logout"
            class="w-full text-left px-4 py-3 text-base text-gray-700 hover:bg-gray-100"
          >
            Logout
          </button>
        </div>

        <!-- Event Info (Full) -->
        <div class="border-t px-4 py-3 bg-gray-50">
          <div class="text-sm text-gray-700">
            <div class="font-medium">{{ eventStore.selectedEvent?.level_rel?.name }}</div>
            <div>{{ eventStore.selectedEvent?.name }}</div>
            <div class="text-gray-600">{{ dayjs(eventStore.selectedEvent?.date).format('dddd, DD.MM.YYYY') }}</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>



<style scoped>
/* Additional styles if needed */
</style>

<script setup lang="ts">
import { TabGroup, TabList, Tab, Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue'
import { onMounted, ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useEventStore } from '@/stores/event'
import { useAuth } from '@/composables/useAuth'
import { imageUrl } from '@/utils/images'
import keycloak from '@/keycloak.js'
import HelpModal from '@/components/atoms/HelpModal.vue'

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

// --- Tabs definieren (Admin moved to Mehr menu) ---
const tabs = computed(() => {
  const allTabs = [
    { name: 'Veranstaltung', path: '/event' },
    { name: 'Ablauf', path: '/schedule' },
    { name: 'Teams', path: '/teams' },
    { name: 'Räume', path: '/rooms' },
    { name: 'Logos', path: '/logos' },
    { name: 'Veröffentlichung', path: '/publish' },
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
    <Menu as="div" class="relative inline-block text-left">
      <MenuButton
      class="inline-flex justify-center w-full px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900">
      Mehr
      </MenuButton>
      <MenuItems class="absolute right-0 z-10 mt-2 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none w-fit">
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
    </div>

    <!-- Narrow / Mobile Navigation (below lg) -->
    <div class="flex lg:hidden items-center justify-between gap-2 px-3 py-2 min-h-[48px]">
      <div class="flex items-center gap-2 flex-shrink-0">
        <img :src="imageUrl('/flow/flow.png')" alt="Logo" class="h-6 w-auto"/>
        <img :src="imageUrl('/flow/hot+fll.png')" alt="Logo" class="h-6 w-auto"/>
      </div>
      <button
        type="button"
        class="inline-flex items-center justify-center p-2.5 rounded-lg text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500 flex-shrink-0"
        aria-label="Menü öffnen"
        @click="toggleMobileMenu"
      >
        <svg v-if="!mobileMenuOpen" class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
        <svg v-else class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
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
            <span v-if="hasWarning(tab.path)" class="w-2 h-2 bg-red-500 rounded-full flex-shrink-0" title="Offene Punkte"></span>
          </button>
        </nav>
        <div class="mt-3 pt-3 border-t border-gray-200 flex flex-col gap-1">
          <button v-if="isAdmin" type="button" class="px-4 py-3 rounded-lg text-sm text-gray-700 hover:bg-gray-100 text-left" @click="goTo({ name: 'Admin', path: '/admin' })">
            Admin
          </button>
          <button type="button" class="px-4 py-3 rounded-lg text-sm text-gray-700 hover:bg-gray-100 text-left" @click="openHelpModal(); mobileMenuOpen = false">
            Hilfe
          </button>
          <button type="button" class="px-4 py-3 rounded-lg text-sm text-gray-700 hover:bg-gray-100 text-left" @click="router.push({ path: '/events' }); mobileMenuOpen = false">
            Veranstaltung wechseln
          </button>
          <button type="button" class="px-4 py-3 rounded-lg text-sm text-gray-700 hover:bg-gray-100 text-left" @click="logout">
            Logout
          </button>
        </div>
      </div>
    </Transition>

    <!-- Help Modal -->
    <HelpModal :show="showHelpModal" @close="closeHelpModal" />
  </div>
</template>



<style scoped>
/* Additional styles if needed */
</style>

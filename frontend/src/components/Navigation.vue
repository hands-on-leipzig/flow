<script setup lang="ts">
import { TabGroup, TabList, Tab, Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue'
import { onMounted, ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useEventStore } from '@/stores/event'
import { useAuth } from '@/composables/useAuth'
import axios from 'axios'
import dayjs from 'dayjs'
import { imageUrl } from '@/utils/images'

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

function isActive(path: string) {
  const cleanPath = path.replace(/^\//, '')
  return route.path.endsWith('/' + cleanPath) || route.path === '/plan/' + cleanPath
}

function goTo(tab) {
  selectedTab.value = tab.name
  router.push(tab.path)
}

function logout() {
  localStorage.removeItem('kc_token')
  window.location.reload()
}
</script>

<template>
  <div class="flex items-center justify-between border-b px-2 py-2 bg-white shadow-sm">
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
    <div>
      {{ eventStore.selectedEvent?.level_rel?.name }}
      {{ eventStore.selectedEvent?.name }}
      am
      {{ dayjs(eventStore.selectedEvent?.date).format('dddd, DD.MM.YYYY') }}
    </div>
    <Menu as="div" class="relative inline-block text-left">
      <MenuButton
      class="inline-flex justify-center w-full px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900">
      Mehr
      </MenuButton>
      <MenuItems class="absolute right-0 z-10 mt-2 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none w-fit">
        <div class="py-1">
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
</template>



<style scoped>
/* Additional styles if needed */
</style>

<script setup>
import {TabGroup, TabList, Tab, TabPanels, TabPanel, Menu, MenuButton, MenuItems, MenuItem} from '@headlessui/vue'
import {onMounted, ref} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import {useEventStore} from '@/stores/event'
import dayjs from "dayjs";

const eventStore = useEventStore()
onMounted(async () => {
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }

})

const tabs = [
  {name: 'Zeitplan', path: '/schedule'},
  {name: 'Logos', path: '/logos'},
  {name: 'Räume', path: '/rooms'}
]
const selectedTab = ref('Schedule')
const router = useRouter()
const route = useRoute()

function isActive(path) {
  return route.path.startsWith(path)
}

function goTo(tab) {
  selectedTab.value = tab
  router.push({path: tab.toLowerCase()})
}
</script>

<template>
  <div class="flex items-center justify-between border-b px-4 py-2 bg-white shadow-sm">
    <div class="flex items-center gap-14">
      <img src="../assets/FLOW_v.1.0.png" alt="Logo" class="h-8 w-auto"/>
      <img src="../assets/logo_hot_fll.png" alt="Logo" class="h-8 w-auto"/>

      <TabGroup v-model="selectedTab" as="div">
        <TabList class="flex space-x-4">
          <Tab
              v-for="tab in tabs"
              :key="tab.path"
              :to="tab.path"
              class="px-4 py-2 rounded hover:bg-gray-100"
              :class="{ 'bg-gray-200 font-medium': isActive(tab.path) }"
              @click="goTo(tab.path)"
          >
            {{ tab.name }}
          </Tab>
        </TabList>
      </TabGroup>
    </div>
    <div>
      Veranstaltung
      {{ eventStore.selectedEvent?.name }}
      am
      {{ dayjs(eventStore.selectedEvent?.date).format('dddd, DD.MM.YYYY') }}
      <!--{{
        "Veranstaltung " + eventStore.selectedEvent?.name + " am " + dayjs(eventStore.selectedEvent?.date).format('dddd, DD.MM.YYYY')
      }}-->
    </div>
    <Menu as="div" class="relative inline-block text-left">
      <MenuButton
          class="inline-flex justify-center w-full px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900">
        Mehr
      </MenuButton>
      <MenuItems
          class="absolute right-0 z-10 mt-2 w-40 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
        <div class="py-1">
          <MenuItem>
            <button @click="router.push({ path: 'events' })"
                    class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
              Event wählen
            </button>
          </MenuItem>
          <MenuItem>
            <button @click="logout" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
              Logout
            </button>
          </MenuItem>
        </div>
      </MenuItems>
    </Menu>
  </div>
</template>

<script>
function logout() {
  localStorage.removeItem('kc_token')
  window.location.reload()
}
</script>

<style scoped>
/* Additional styles if needed */
</style>

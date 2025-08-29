<script setup>
import {ref, onMounted, computed} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import dayjs from "dayjs";

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const challengeData = ref(null)
const exploreData = ref(null)

onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  const drahtData = await axios.get(`/events/${event.value?.id}/draht-data`)

  exploreData.value = drahtData.data.event_explore
  challengeData.value = drahtData.data.event_challenge

  event.value.address = drahtData.data.address
  event.value.contact = drahtData.data.contact
  event.value.information = drahtData.data.information

  event.value.wifi_ssid ??= ''
  event.value.wifi_password ??= ''

  await fetchTableNames()
})

const updateEventField = async (field, value) => {
  try {
    await axios.put(`/events/${event.value?.id}`, {
      [field]: value
    })
  } catch (e) {
    console.error('WLAN update failed:', e)
  }
}

const tableNames = ref(['', '', '', ''])

const fetchTableNames = async () => {
  if (!event.value?.id) return
  try {
    const response = await axios.get(`/events/${event.value.id}/table-names`)
    const tables = response.data.table_names

    const names = Array(4).fill('')
    tables.forEach(t => {
      if (t.table_number >= 1 && t.table_number <= 4) {
        names[t.table_number - 1] = t.table_name ?? ''
      }
    })
    tableNames.value = names
  } catch (e) {
    console.error('Fehler beim Laden der Tischbezeichnungen:', e)
    tableNames.value = Array(4).fill('')
  }
}

const updateTableName = async () => {
  if (!event.value?.id) return

  try {
    const payload = {
      table_names: tableNames.value.map((name, i) => ({
        table_number: i + 1,
        table_name: name ?? '',
      })),
    }

    await axios.put(`/events/${event.value.id}/table-names`, payload)
  } catch (e) {
    console.error('Fehler beim Speichern der Tischnamen:', e)
  }
}
</script>

<template>
  <div class="p-6 space-y-6">
    <!-- Event Info -->
    <div>
      <h1 class="text-2xl font-bold">Veranstaltung {{ event?.name }}</h1>
      <div class="grid grid-cols-3 gap-4 mt-4">
        <div class="p-4 border rounded shadow">
          <h3 class="font-semibold mb-2">Daten</h3>
          <p>Datum: {{ dayjs(event?.date).format('dddd, DD.MM.YYYY') }}</p>
          <p v-if="event?.days > 1">bis: {{ dayjs(event?.enddate).format('dddd, DD.MM.YYYY') }}</p>
          <p>Art: {{ event?.level_rel.name }}</p>
          <p>Saison: {{ event?.season_rel.name }}</p>
        </div>
        <div class="p-4 border rounded shadow">
          <h3 class="font-semibold mb-2">Adresse</h3>
          <p>{{ event?.address }}</p>
        </div>
        <div class="p-4 border rounded shadow">
          <h3 class="text-lg font-semibold mb-4">Kontakt</h3>
          <div class="grid gap-4">
            <div
                v-for="(person, index) in event?.contact"
                :key="index"
                class="p-3 border rounded-md bg-gray-50 shadow-sm"
            >
              <div class="flex items-center justify-between mb-1">
                <span class="font-semibold text-blue-800 text-sm">{{ person.contact }}</span>
                <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Kontaktperson</span>
              </div>
              <div class="text-sm text-gray-700 flex items-center gap-1">
                <svg class="w-4 h-4 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                  <path
                      d="M2.94 5.5a1.5 1.5 0 011.5-1.5h11.12a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5H4.44a1.5 1.5 0 01-1.5-1.5v-9zm1.62.4v.28l5.5 3.44 5.5-3.44v-.28H4.56zm0 1.48v6.12h10.88V7.38L10 10.75 4.56 7.38z"/>
                </svg>

                {{ person.contact_email }}
              </div>
              <p v-if="person.contact_infos" class="text-xs text-gray-600 mt-1">{{ person.contact_infos }}</p>
            </div>
          </div>
        </div>
        <div class="p-4 border rounded shadow">
          <h2 class="text-lg font-semibold mb-2">WLAN Zugangsdaten</h2>
          <div class="grid grid-cols-2 gap-4" v-if="event">
            <div>
              <label class="block text-sm text-gray-700 mb-1">SSID</label>
              <input
                  v-model="event.wifi_ssid"
                  @blur="updateEventField('wifi_ssid', event.wifi_ssid)"
                  class="w-full border px-3 py-1 rounded text-sm"
                  type="text"
                  placeholder="z. B. TH_EVENT_WLAN"
              />
            </div>
            <div>
              <label class="block text-sm text-gray-700 mb-1">Passwort</label>
              <input
                  v-model="event.wifi_password"
                  @blur="updateEventField('wifi_password', event.wifi_password)"
                  class="w-full border px-3 py-1 rounded text-sm"
                  type="text"
                  placeholder="z. B. $N#Uh)eA~ado]tyMXTkG"
              />
            </div>
            
          </div>
        </div>

        <!-- Neue Kiste für Robot-Game-Tische -->
        <div class="p-4 border rounded shadow">
          <h2 class="text-lg font-semibold mb-2">Bezeichnung der Robot-Game-Tische</h2>
          <div class="grid grid-cols-2 gap-4">
            <div v-for="(name, index) in tableNames" :key="index">
              <label class="block text-sm text-gray-700 mb-1">Tisch {{ index + 1 }}</label>
              <input
                v-model="tableNames[index]"
                @blur="updateTableName"
                class="w-full border px-3 py-1 rounded text-sm"
                type="text"
                :placeholder='"leer lassen für \"Tisch " + (index + 1) + "\""'
              />
            </div>
          </div>
        </div>

        

      </div>
    </div>
  </div>
</template>
<style scoped>
</style>
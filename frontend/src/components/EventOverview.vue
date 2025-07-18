<script setup>
import {ref, onMounted, computed} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import dayjs from "dayjs";

import TeamList from "@/components/molecules/TeamList.vue";
import {RadioGroup} from "@headlessui/vue";


const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const challengeData = ref(null)
const exploreData = ref(null)
const exploreTeams = ref([])
const challengeTeams = ref([])
const publishedPlanId = ref('')
const schedules = ref([])

onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  const drahtData = await axios.get(`/events/${event.value?.id}/draht-data`)

  exploreData.value = drahtData.data.event_explore
  challengeData.value = drahtData.data.event_challenge

  exploreTeams.value = Object.entries(drahtData.data.teams_explore || {}).map(([id, t]) => ({
    id: Number(id),
    number: id,
    name: t.name
  }))

  challengeTeams.value = Object.entries(drahtData.data.teams_challenge || {}).map(([id, t]) => ({
    id: Number(id),
    number: id,
    name: t.name
  }))

  event.value.address = drahtData.data.address
  event.value.contact = drahtData.data.contact
  event.value.information = drahtData.data.information

  event.value.wifi_ssid ??= ''
  event.value.wifi_password ??= ''
})

const updateEventField = async (field, value) => {
  //if (!event.id) return
  try {
    await axios.put(`/events/${event.value?.id}`, {
      [field]: value
    })
  } catch (e) {
    console.error('WLAN update failed:', e)
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
                  placeholder="z. B. Gäste-WLAN"
              />
            </div>
            <div>
              <label class="block text-sm text-gray-700 mb-1">Passwort</label>
              <input
                  v-model="event.wifi_password"
                  @blur="updateEventField('wifi_password', event.wifi_password)"
                  class="w-full border px-3 py-1 rounded text-sm"
                  type="text"
                  placeholder="z. B. 12345678"
              />
            </div>
          </div>
        </div>

        <div class="p-4 border rounded shadow">
          <h2 class="text-lg font-semibold mb-2">Explore Modus</h2>
          <div>
            <radio-group>
              <input type="radio" id="vormittag" name="modus"/>&nbsp;
              <label for="vormittag">Vormittag</label>
              <br>
              <input type="radio" id="nachmittag" name="modus"/>&nbsp;
              <label for="nachmittag">Nachmittag</label>
            </radio-group>
          </div>
        </div>

        <div class="p-4 border rounded shadow">
          <h2 class="text-lg font-semibold mb-4">Challenge Setup</h2>
          <div class="flex gap-8 flex-wrap">
            <!-- Robot-Game Tische -->
            <div class="flex-grow">
              <p class="font-medium mb-2">Robot-Game-Tische</p>
              <label class="block">
                <input type="radio" name="tables" value="2" class="mr-2"/>
                2 Tische
              </label>
              <label class="block">
                <input type="radio" name="tables" value="4" class="mr-2"/>
                4 Tische
              </label>
            </div>

            <!-- Jury-Spuren -->
            <div class="flex-grow">
              <p class="font-medium mb-2">Jury-Spuren</p>
              <template v-for="n in 7" :key="n">
                <label class="block">
                  <input type="radio" name="spuren" :value="n" class="mr-2"/>
                  {{ n }}-spurig
                </label>
              </template>
            </div>
          </div>
        </div>


      </div>
    </div>
    <div class="grid grid-cols-2 gap-4 mt-4">
      <TeamList :teams="exploreTeams" :program="'explore'"/>
      <TeamList :teams="challengeTeams" :program="'challenge'"/>
    </div>
  </div>
</template>
<style scoped>
</style>
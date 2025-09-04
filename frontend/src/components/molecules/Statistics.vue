<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const data = ref(null)
const loading = ref(true)
const error = ref(null)

// Offene IDs je Ebene
const openSeason = ref(null)
const openPartner = ref(null)
const openEvent = ref(null)

onMounted(async () => {
  try {
    const res = await axios.get('/stats/plans')
    data.value = res.data
  } catch (e) {
    error.value = 'Fehler beim Laden der Daten.'
    console.error(e)
  } finally {
    loading.value = false
  }
})

function toggleSeason(seasonKey) {
  openSeason.value = openSeason.value === seasonKey ? null : seasonKey
  openPartner.value = null
  openEvent.value = null
}

function togglePartner(partnerId) {
  openPartner.value = openPartner.value === partnerId ? null : partnerId
  openEvent.value = null
}

function toggleEvent(eventId) {
  openEvent.value = openEvent.value === eventId ? null : eventId
}
</script>

<template>
  <div>
    <div v-if="loading" class="text-gray-500">Lade Daten …</div>
    <div v-else-if="error" class="text-red-500">{{ error }}</div>
    <div v-else>
      <div
        v-for="season in data.seasons"
        :key="`${season.season_year}-${season.season_name}`"
        class="mb-8"
      >
        <h2
          class="text-2xl font-bold mb-4 cursor-pointer"
          @click="toggleSeason(`${season.season_year}-${season.season_name}`)"
        >
          Saison {{ season.season_year }} – {{ season.season_name }} — {{ season.partners.length }} RPs
        </h2>

        <div v-if="openSeason === `${season.season_year}-${season.season_name}`">
          <div
            v-for="partner in season.partners"
            :key="partner.partner_id"
            class="mb-6 border rounded p-4 bg-white shadow-sm"
          >
            <h3
              class="text-lg font-bold mb-2 cursor-pointer"
              @click="togglePartner(partner.partner_id)"
            >
              {{ partner.partner_name }} — {{ partner.events.length }} Events
            </h3>

            <div v-if="openPartner === partner.partner_id">
              <div
                v-for="event in partner.events"
                :key="event.event_id"
                class="mb-4 pl-4 border-l-2 border-gray-300"
              >
                <div
                  class="text-sm font-semibold cursor-pointer"
                  @click="toggleEvent(event.event_id)"
                >
                  {{ event.event_name }} — {{ event.event_date }} — {{ event.plans.length }} Plans
                </div>

                <div v-if="openEvent === event.event_id" class="mt-2">
                  <div v-if="event.plans.length > 0" class="space-y-2">
                    <div
                      v-for="plan in event.plans"
                      :key="plan.plan_id"
                      class="text-sm text-gray-700 border p-2 rounded bg-gray-50"
                    >
                      <div>
                        <strong>Plan:</strong> {{ plan.plan_name }} (ID: {{ plan.plan_id }})
                      </div>
                      <div><strong>Status:</strong> {{ plan.generator_status }}</div>
                      <div class="text-gray-500 text-xs">
                        Erstellt: {{ plan.plan_created }} <br />
                        Letzte Änderung: {{ plan.plan_last_change }}
                      </div>
                    </div>
                  </div>

                  <div v-else class="text-sm text-gray-400 italic">
                    Kein Plan vorhanden.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
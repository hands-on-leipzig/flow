<script setup>
import {ref, onMounted, computed} from 'vue'
import axios from 'axios'
import {useRouter, useRoute} from 'vue-router'
import {useEventStore} from '@/stores/event'
import {useAuth} from '@/composables/useAuth'
import dayjs from "dayjs";
import LoaderFlow from "@/components/atoms/LoaderFlow.vue";
import { programLogoSrc, programLogoAlt } from '@/utils/images'  
import {showGlassToast} from '@/composables/useGlassToast'


const regionalPartners = ref([])
const eventStore = useEventStore()
const router = useRouter()
const route = useRoute()
const showStaleSeasonNotice = computed(() =>
    route.query.reason === 'stale-season' || eventStore.staleSeasonCleared
)
const loading = ref(true)
const { isAdmin } = useAuth()

// Create Event Modal
const showCreateModal = ref(false)
const creating = ref(false)
const createForm = ref({
  name: '',
  regional_partner: '',
  level: '',
  date: '',
  days: 1,
  programIds: []
})

const createEventData = ref({
  regional_partners: [],
  levels: []
})

// Success notification
const showSuccessToast = ref(false)

onMounted(async () => {
  try {
    const {data} = await axios.get('/events/selectable')
    regionalPartners.value = data
  } finally {
    loading.value = false
  }
  
  if (isAdmin.value) {
    await loadCreateEventData()
  }
})

async function loadCreateEventData() {
  try {
    const { data } = await axios.get('/events/create-data')
    createEventData.value = data
  } catch (error) {
    console.error('Error loading create event data:', error)
  }
}

async function selectEvent(eventId, regionalPartnerId) {
  await axios.post('/user/select-event', {
    event: eventId,
    regional_partner: regionalPartnerId
  })
  eventStore.staleSeasonCleared = false
  await eventStore.fetchSelectedEvent()
  router.push('/overview')
}

async function createEvent() {
  if (!createForm.value.name || !createForm.value.regional_partner || !createForm.value.level || !createForm.value.date) {
    showGlassToast('Please fill in all required fields', 'info')
    return
  }

  creating.value = true
  try {
    // Prepare the data to send
    const eventData = {
      name: createForm.value.name,
      regional_partner: createForm.value.regional_partner,
      level: createForm.value.level,
      date: createForm.value.date,
      days: createForm.value.days,
      programs: createForm.value.programIds.map((id) => ({ first_program: id }))
    }
    
    const { data } = await axios.post('/events', eventData)
    
    // Reset form
    createForm.value = {
      name: '',
      regional_partner: '',
      level: '',
      date: '',
      days: 1,
      programIds: []
    }
    
    showCreateModal.value = false
    
    // Reload events to show the new one
    const {data: eventsData} = await axios.get('/events/selectable')
    regionalPartners.value = eventsData
    
    // Show success notification
    showSuccessToast.value = true
    setTimeout(() => {
      showSuccessToast.value = false
    }, 3000)
    
  } catch (error) {
    console.error('Error creating event:', error)
    showGlassToast('Error creating event: ' + (error.response?.data?.message || error.message), 'error')
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <div class="p-6 overflow-y-auto max-h-screen max-w-screen">
    <div
      v-if="showStaleSeasonNotice"
      class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900"
    >
      Deine zuletzt gewählte Veranstaltung gehört zur letzten Saison. Bitte wähle eine Veranstaltung der aktuellen Saison.
    </div>

    <div class="flex justify-between items-center mb-4">
      <h1 class="text-2xl font-bold">Veranstaltung wählen</h1>
      
      <!-- Create Event Button (Admin Only) -->
      <button
        v-if="isAdmin"
        @click="showCreateModal = true"
        class="glass-btn-accent !px-4 !py-2 !text-base"
      >
        + Neue Veranstaltung
      </button>
    </div>

    <div v-if="loading" class="flex justify-center">
      <LoaderFlow/>
    </div>

    <div v-else v-for="rp in regionalPartners" :key="rp.regional_partner.id" class="mb-6">
      <h2 class="text-xl font-semibold">{{ rp.regional_partner.name }}</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-2">
        <div
            v-for="event in rp.events"
            :key="event.id"
            class="glass-selectable-card liquid-surface-inner hover:bg-[var(--color-bg-hover)]"
            @click="selectEvent(event.id, rp.regional_partner.id)"
        >

          <!-- Flex-Container: Text links, zwei Bilder rechts -->
          <div class="flex justify-between items-start">
            <!-- Linker Bereich: Text -->
            <div>
              <h3 class="font-medium text-lg">{{ event.name }}</h3>
              <p class="text-sm text-[var(--color-text-subtle)]">{{ dayjs(event.date).format('dddd, DD.MM.YYYY') }}</p>
              <p class="text-sm text-[var(--color-text-subtle)]">{{ event.level.name }}</p>
              <p class="text-sm text-[var(--color-text-subtle)]">{{ event.season.name }} ({{ event.season.year }})</p>
            </div>

            <!-- Rechter Bereich: Bilder nebeneinander, bedingt sichtbar -->
            <div class="flex ml-4 space-x-2">
              <img
                v-for="program in (event.programs || [])"
                :key="program.first_program"
                :src="programLogoSrc(program.first_program)"
                :alt="programLogoAlt(program.name || program.first_program)"
                class="w-20 h-20 flex-shrink-0"
              />
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Create Event Modal -->
    <div v-if="showCreateModal" class="glass-scrim fixed inset-0 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg p-6 w-full max-w-md mx-4">
        <h2 class="text-xl font-bold mb-4">Neue Veranstaltung erstellen</h2>
        
        <form @submit.prevent="createEvent" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-[var(--color-text-muted)] mb-1">Name *</label>
            <input
              v-model="createForm.name"
              type="text"
              required
              class="w-full px-3 py-2 border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="Veranstaltungsname"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-[var(--color-text-muted)] mb-1">Regional Partner *</label>
            <select
              v-model="createForm.regional_partner"
              required
              class="w-full px-3 py-2 border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">Regional Partner wählen...</option>
              <option
                v-for="partner in createEventData.regional_partners"
                :key="partner.id"
                :value="partner.id"
              >
                {{ partner.display_name }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-[var(--color-text-muted)] mb-1">Level *</label>
            <select
              v-model="createForm.level"
              required
              class="w-full px-3 py-2 border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">Level wählen...</option>
              <option
                v-for="level in createEventData.levels"
                :key="level.id"
                :value="level.id"
              >
                {{ level.name }}
              </option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-[var(--color-text-muted)] mb-1">Datum *</label>
            <input
              v-model="createForm.date"
              type="date"
              required
              class="w-full px-3 py-2 border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-[var(--color-text-muted)] mb-1">Tage</label>
            <input
              v-model.number="createForm.days"
              type="number"
              min="1"
              max="10"
              class="w-full px-3 py-2 border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <div class="space-y-3">
            <label class="block text-sm font-medium text-[var(--color-text-muted)] mb-2">Programme</label>
            <div
              v-for="program in (createEventData.programs || [])"
              :key="program.id"
              class="flex items-center space-x-2"
            >
              <input
                type="checkbox"
                :id="'program_' + program.id"
                :value="program.id"
                v-model="createForm.programIds"
                class="w-4 h-4 text-blue-600 bg-[var(--color-bg-muted)] border-[var(--color-border)] rounded focus:ring-blue-500 focus:ring-2"
              />
              <label :for="'program_' + program.id" class="text-sm text-[var(--color-text-muted)] flex items-center">
                <img :src="programLogoSrc(program.id)" :alt="programLogoAlt(program.name)" class="w-5 h-5 mr-2" />
                <span>{{ program.name }}</span>
              </label>
            </div>
          </div>

          <div class="flex justify-end space-x-3 pt-4">
            <button
              type="button"
              @click="showCreateModal = false"
              class="px-4 py-2 text-[var(--color-text-muted)] hover:text-[var(--color-text)] transition-colors"
            >
              Abbrechen
            </button>
            <button
              type="submit"
              :disabled="creating"
              class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md font-medium transition-colors disabled:opacity-50"
            >
              {{ creating ? 'Erstelle...' : 'Erstellen' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Success Notification Banner -->
    <div v-if="showSuccessToast" class="fixed top-4 right-4 z-50 bg-green-50 border border-green-200 rounded-lg shadow-lg p-4 min-w-80 max-w-md">
      <div class="flex items-center gap-3">
        <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
        <span class="text-green-800 font-medium">Veranstaltung erfolgreich erstellt!</span>
      </div>
    </div>
  </div>
</template>
<style scoped>
</style>


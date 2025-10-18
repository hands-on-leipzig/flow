<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Event Not Found -->
    <EventNotFound v-if="showNotFound" />
    
    <!-- Loading State -->
    <div v-else-if="loading" class="flex items-center justify-center min-h-screen">
      <div class="text-center">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
        <p class="text-gray-600">Lade Veranstaltungsinformationen...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="flex items-center justify-center min-h-screen">
      <div class="text-center">
        <div class="mx-auto h-16 w-16 text-red-400 mb-4">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
          </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Fehler beim Laden</h1>
        <p class="text-gray-600 mb-4">{{ error }}</p>
        <button
          @click="loadEvent"
          class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors"
        >
          Erneut versuchen
        </button>
      </div>
    </div>

    <!-- Event Content -->
    <div v-else-if="event" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Header Section -->
      <div class="bg-white rounded-lg shadow-sm border border-gray-200 mb-8 overflow-hidden">
        <div class="px-6 py-8">
          <!-- Event Title -->
          <div class="text-center mb-6">
            <h1 class="text-4xl font-bold text-gray-900 mb-2">
              {{ event.name }}
            </h1>
            <div class="flex items-center justify-center space-x-4 text-gray-600">
              <div class="flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                {{ formatDate(event.date) }}
              </div>
              <div class="flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                {{ event.regionalPartnerRel?.name }}
              </div>
            </div>
          </div>

          <!-- Program Badges -->
          <div class="flex justify-center space-x-4 mb-6">
            <div v-if="event.event_explore" class="flex items-center bg-green-100 text-green-800 px-4 py-2 rounded-full">
              <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-5 h-5 mr-2" />
              <span class="font-medium">Explore</span>
            </div>
            <div v-if="event.event_challenge" class="flex items-center bg-blue-100 text-blue-800 px-4 py-2 rounded-full">
              <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-5 h-5 mr-2" />
              <span class="font-medium">Challenge</span>
            </div>
          </div>

          <!-- Level Badge -->
          <div class="text-center">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
              {{ event.levelRel?.name }}
            </span>
          </div>
        </div>
      </div>

      <!-- Content Sections -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Event Information -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <h2 class="text-xl font-semibold text-gray-900 mb-4">
            Veranstaltungsinformationen
          </h2>
          <dl class="space-y-3">
            <div>
              <dt class="text-sm font-medium text-gray-500">Veranstaltung</dt>
              <dd class="text-sm text-gray-900">{{ event.name }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Datum</dt>
              <dd class="text-sm text-gray-900">{{ formatDate(event.date) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Dauer</dt>
              <dd class="text-sm text-gray-900">{{ event.days }} Tag{{ event.days > 1 ? 'e' : '' }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Regionalpartner</dt>
              <dd class="text-sm text-gray-900">{{ event.regionalPartnerRel?.name }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Region</dt>
              <dd class="text-sm text-gray-900">{{ event.regionalPartnerRel?.region }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Saison</dt>
              <dd class="text-sm text-gray-900">{{ event.seasonRel?.year }}</dd>
            </div>
          </dl>
        </div>

        <!-- Programs -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
          <h2 class="text-xl font-semibold text-gray-900 mb-4">
            Programme
          </h2>
          <div class="space-y-4">
            <div v-if="event.event_explore" class="flex items-center p-4 bg-green-50 rounded-lg">
              <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-8 h-8 mr-3" />
              <div>
                <h3 class="font-medium text-green-900">FIRST LEGO League Explore</h3>
                <p class="text-sm text-green-700">Für Kinder von 6-10 Jahren</p>
              </div>
            </div>
            <div v-if="event.event_challenge" class="flex items-center p-4 bg-blue-50 rounded-lg">
              <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-8 h-8 mr-3" />
              <div>
                <h3 class="font-medium text-blue-900">FIRST LEGO League Challenge</h3>
                <p class="text-sm text-blue-700">Für Kinder von 9-16 Jahren</p>
              </div>
            </div>
            <div v-if="!event.event_explore && !event.event_challenge" class="text-center text-gray-500 py-4">
              Keine Programme verfügbar
            </div>
          </div>
        </div>
      </div>

      <!-- QR Code Section -->
      <div v-if="event.link" class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="text-center">
          <h2 class="text-xl font-semibold text-gray-900 mb-4">
            Teilen Sie diese Veranstaltung
          </h2>
          <div class="flex flex-col items-center space-y-4">
            <div class="bg-gray-100 p-4 rounded-lg">
              <img :src="event.qrcode" alt="QR Code" class="w-32 h-32" />
            </div>
            <p class="text-sm text-gray-600">
              Scannen Sie den QR-Code, um diese Seite zu teilen
            </p>
            <div class="flex items-center space-x-2 text-sm text-gray-500">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.367 2.684 3 3 0 00-5.367-2.684z"></path>
              </svg>
              <span>{{ event.link }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="mt-8 text-center text-sm text-gray-500">
        <p>
          Diese Seite wird automatisch generiert. Bei Fragen wenden Sie sich an den Veranstalter.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import dayjs from 'dayjs'
import 'dayjs/locale/de'
import EventNotFound from './EventNotFound.vue'

dayjs.locale('de')

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const error = ref(null)
const event = ref(null)
const showNotFound = ref(false)

const loadEvent = async () => {
  loading.value = true
  error.value = null
  
  try {
    console.log('Loading event with slug:', route.params.slug)
    console.log('Axios base URL:', axios.defaults.baseURL)
    const url = `/api/events/slug/${route.params.slug}`
    console.log('Making request to:', url)
    const response = await axios.get(url)
    console.log('API response:', response.data)
    event.value = response.data
  } catch (err) {
    console.error('API error:', err)
    console.error('Error response:', err.response)
    if (err.response?.status === 404) {
      showNotFound.value = true
    } else {
      error.value = 'Fehler beim Laden der Veranstaltungsdaten'
    }
  } finally {
    loading.value = false
  }
}

const formatDate = (dateString) => {
  return dayjs(dateString).format('DD. MMMM YYYY')
}

const programLogoSrc = (program) => {
  const logos = {
    'E': '/flow/fll_explore_v.png',
    'C': '/flow/fll_challenge_v.png'
  }
  return logos[program] || '/flow/first_v.png'
}

const programLogoAlt = (program) => {
  const alts = {
    'E': 'FIRST LEGO League Explore Logo',
    'C': 'FIRST LEGO League Challenge Logo'
  }
  return alts[program] || 'FIRST LEGO League Logo'
}

onMounted(() => {
  loadEvent()
})
</script>

<style scoped>
/* Additional custom styles if needed */
</style>

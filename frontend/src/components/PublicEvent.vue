<template>
  <div class="bg-gray-50">
    <!-- Event Not Found -->
    <EventNotFound v-if="showNotFound" />
    
    <!-- Loading State -->
    <div v-else-if="loading" class="min-h-screen flex items-center justify-center">
      <div class="text-center">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
        <p class="text-gray-600">Lade Veranstaltungsinformationen...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="min-h-screen flex items-center justify-center">
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
    <div v-else-if="event">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 pb-16">
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

          <!-- Program Logo -->
          <div class="flex justify-center mb-6">
            <div v-if="event.event_explore && !event.event_challenge" class="flex items-center">
              <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-16 h-16" />
            </div>
            <div v-else-if="event.event_challenge && !event.event_explore" class="flex items-center">
              <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-16 h-16" />
            </div>
            <div v-else-if="event.event_explore && event.event_challenge" class="flex items-center space-x-4">
              <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-12 h-12" />
              <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-12 h-12" />
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

      <!-- Level 1: Basic Event Information (always visible) -->
      <div v-if="scheduleInfo" class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">
          Veranstaltungsinformationen
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <h3 class="font-semibold mb-2">Datum & Ort</h3>
            <p class="text-gray-700">{{ formatDateOnly(scheduleInfo.date) }}</p>
            <div v-if="scheduleInfo.address" class="mt-2 text-sm text-gray-600 whitespace-pre-line">
              {{ scheduleInfo.address }}
            </div>
          </div>
          <div v-if="scheduleInfo.contact?.length">
            <h3 class="font-semibold mb-2">Kontakt</h3>
            <div class="space-y-2">
              <div v-for="(contact, index) in scheduleInfo.contact" :key="index" class="text-sm">
                <div class="font-medium">{{ contact.contact }}</div>
                <div class="text-gray-600">{{ contact.contact_email }}</div>
                <div v-if="contact.contact_infos" class="text-gray-500 text-xs">{{ contact.contact_infos }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Level 2: Registration Numbers and Teams -->
      <div v-if="isContentVisible(2) && scheduleInfo" class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-6">
          Angemeldete Teams
        </h2>
        
        <!-- Explore Teams -->
        <div v-if="scheduleInfo.teams.explore.list?.length" class="mb-8">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-900 flex items-center">
              <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-6 h-6 mr-2" />
              FIRST LEGO League Explore
            </h3>
            <div v-if="scheduleInfo.teams.explore.capacity > 0" class="text-sm text-gray-600">
              <span class="font-bold">{{ scheduleInfo.teams.explore.registered }}</span>
              von <span class="font-bold">{{ scheduleInfo.teams.explore.capacity }}</span> Plätzen belegt
            </div>
          </div>
          <div v-if="scheduleInfo.teams.explore.capacity > 0" class="w-full bg-gray-200 rounded-full h-2 mb-4">
            <div 
              class="bg-green-600 h-2 rounded-full" 
              :style="{ width: `${Math.min(100, (scheduleInfo.teams.explore.registered / scheduleInfo.teams.explore.capacity) * 100)}%` }"
            ></div>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Team-Nr.</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Team-Name</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Institution</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ort</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="team in scheduleInfo.teams.explore.list" :key="team.team_number_hot || team.name" class="hover:bg-gray-50">
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {{ team.team_number_hot || '-' }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {{ team.name }}
                  </td>
                  <td class="px-6 py-4 text-sm text-gray-900">
                    {{ team.organization || '-' }}
                  </td>
                  <td class="px-6 py-4 text-sm text-gray-900">
                    {{ team.location || '-' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Challenge Teams -->
        <div v-if="scheduleInfo.teams.challenge.list?.length">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-900 flex items-center">
              <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-6 h-6 mr-2" />
              FIRST LEGO League Challenge
            </h3>
            <div v-if="scheduleInfo.teams.challenge.capacity > 0" class="text-sm text-gray-600">
              <span class="font-bold">{{ scheduleInfo.teams.challenge.registered }}</span>
              von <span class="font-bold">{{ scheduleInfo.teams.challenge.capacity }}</span> Plätzen belegt
            </div>
          </div>
          <div v-if="scheduleInfo.teams.challenge.capacity > 0" class="w-full bg-gray-200 rounded-full h-2 mb-4">
            <div 
              class="bg-red-600 h-2 rounded-full" 
              :style="{ width: `${Math.min(100, (scheduleInfo.teams.challenge.registered / scheduleInfo.teams.challenge.capacity) * 100)}%` }"
            ></div>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Team-Nr.</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Team-Name</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Institution</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ort</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="team in scheduleInfo.teams.challenge.list" :key="team.team_number_hot || team.name" class="hover:bg-gray-50">
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    {{ team.team_number_hot || '-' }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {{ team.name }}
                  </td>
                  <td class="px-6 py-4 text-sm text-gray-900">
                    {{ team.organization || '-' }}
                  </td>
                  <td class="px-6 py-4 text-sm text-gray-900">
                    {{ team.location || '-' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Level 4: Schedule Information -->
      <div v-if="isContentVisible(4) && scheduleInfo?.plan" class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">
          Wichtige Zeiten
        </h2>
        <div v-if="scheduleInfo.plan.last_change" class="text-sm text-gray-500 mb-4">
          Letzte Änderung: {{ formatDateTime(scheduleInfo.plan.last_change) }}
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Explore Times -->
          <div v-if="scheduleInfo.plan.explore">
            <h3 class="font-semibold mb-3 text-gray-900">FIRST LEGO League Explore</h3>
            <div class="space-y-2">
              <div v-if="scheduleInfo.plan.explore.briefing?.teams" class="flex justify-between text-sm">
                <span>Coach-Briefing:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.explore.briefing.teams) }}</span>
              </div>
              <div v-if="scheduleInfo.plan.explore.briefing?.judges" class="flex justify-between text-sm">
                <span>Gutachter:innen-Briefing:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.explore.briefing.judges) }}</span>
              </div>
              <div v-if="scheduleInfo.plan.explore.opening" class="flex justify-between text-sm">
                <span>Eröffnung:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.explore.opening) }}</span>
              </div>
              <div v-if="scheduleInfo.plan.explore.end" class="flex justify-between text-sm">
                <span>Ende:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.explore.end) }}</span>
              </div>
            </div>
          </div>

          <!-- Challenge Times -->
          <div v-if="scheduleInfo.plan.challenge">
            <h3 class="font-semibold mb-3 text-gray-900">FIRST LEGO League Challenge</h3>
            <div class="space-y-2">
              <div v-if="scheduleInfo.plan.challenge.briefing?.teams" class="flex justify-between text-sm">
                <span>Coach-Briefing:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.briefing.teams) }}</span>
              </div>
              <div v-if="scheduleInfo.plan.challenge.briefing?.judges" class="flex justify-between text-sm">
                <span>Jury-Briefing:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.briefing.judges) }}</span>
              </div>
              <div v-if="scheduleInfo.plan.challenge.briefing?.referees" class="flex justify-between text-sm">
                <span>Schiedsrichter-Briefing:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.briefing.referees) }}</span>
              </div>
              <div v-if="scheduleInfo.plan.challenge.opening" class="flex justify-between text-sm">
                <span>Eröffnung:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.opening) }}</span>
              </div>
              <div v-if="scheduleInfo.plan.challenge.end" class="flex justify-between text-sm">
                <span>Ende:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.end) }}</span>
              </div>
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
              <img :src="`data:image/png;base64,${event.qrcode}`" alt="QR Code" class="w-32 h-32" />
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
      </div>
    </div>

    <!-- Footer -->
    <div class="bg-white border-t border-gray-200 py-6">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center text-sm text-gray-500">
          <p>
            Diese Seite wird automatisch generiert. Bei Fragen wenden Sie sich an den Veranstalter.
          </p>
        </div>
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
import { programLogoSrc, programLogoAlt } from '@/utils/images'

dayjs.locale('de')

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const error = ref(null)
const event = ref(null)
const scheduleInfo = ref(null)
const showNotFound = ref(false)

const loadEvent = async () => {
  loading.value = true
  error.value = null
  
  try {
    console.log('Loading event with slug:', route.params.slug)
    console.log('Axios base URL:', axios.defaults.baseURL)
    const url = `/events/slug/${route.params.slug}`
    console.log('Making request to:', url)
    const response = await axios.get(url)
    console.log('API response:', response.data)
    event.value = response.data
    
    // Load publication level-specific data
    if (event.value?.id) {
      try {
        const scheduleResponse = await axios.get(`/publish/public-information/${event.value.id}`)
        scheduleInfo.value = scheduleResponse.data
        console.log('Schedule info loaded:', scheduleInfo.value)
        
        // If publication level is 4, redirect to zeitplan.cgi
        if (scheduleInfo.value?.level === 4) {
          console.log('Publication level is 4, redirecting to zeitplan.cgi')
          // Get the plan ID for this event
          const planResponse = await axios.get(`/plans/public/${event.value.id}`)
          const planId = planResponse.data?.id
          
          if (planId) {
            // Redirect to zeitplan.cgi with the plan ID
            window.location.href = `/output/zeitplan.cgi?plan=${planId}`
            return
          } else {
            console.warn('No plan found for event, staying on public page')
          }
        }
      } catch (scheduleErr) {
        console.warn('Could not load schedule information:', scheduleErr)
        // Don't fail the entire load if schedule info fails
      }
    }
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

// Helper functions for publication levels
const formatDateOnly = (dateString) => {
  return dayjs(dateString).format('DD. MMMM YYYY')
}

const formatTimeOnly = (dateString, showSeconds = false) => {
  return dayjs(dateString).format(showSeconds ? 'HH:mm:ss' : 'HH:mm')
}

const formatDateTime = (dateString) => {
  return dayjs(dateString).format('DD. MMMM YYYY HH:mm')
}

// Check if content should be visible based on publication level
const isContentVisible = (level) => {
  if (!scheduleInfo.value) return false
  return scheduleInfo.value.level >= level
}

onMounted(() => {
  loadEvent()
})
</script>

<style scoped>
/* Additional custom styles if needed */
</style>

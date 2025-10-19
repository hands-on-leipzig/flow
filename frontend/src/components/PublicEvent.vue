<template>
  <div class="bg-gray-50">
    <!-- Loading State -->
    <div v-if="loading" class="min-h-screen flex items-center justify-center">
      <div class="text-center">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
        <p class="text-gray-600">Veranstaltung wird geladen...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="min-h-screen flex items-center justify-center">
      <div class="text-center">
        <div class="text-red-600 text-6xl mb-4">⚠️</div>
        <h2 class="text-2xl font-bold text-gray-900 mb-2">Fehler beim Laden</h2>
        <p class="text-gray-600 mb-4">{{ error }}</p>
        <button @click="loadEvent" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
          Erneut versuchen
        </button>
      </div>
    </div>

    <!-- Event Content -->
    <div v-else-if="event" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 pb-16">
      <!-- Header with Program Logos -->
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ event.name }}</h1>
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
      </div>

      <!-- Level 1: Basic Event Information -->
      <div v-if="isContentVisible(1) && scheduleInfo" class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
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

      <!-- Level 1: Teams -->
      <div v-if="isContentVisible(1) && scheduleInfo" class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
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

      <!-- Level 2: Begin and End Times -->
      <div v-if="isContentVisible(2) && scheduleInfo?.plan" class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">
          Veranstaltungszeiten
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Explore Times -->
          <div v-if="scheduleInfo.plan.explore">
            <h3 class="font-semibold mb-3 text-gray-900">FIRST LEGO League Explore</h3>
            <div class="space-y-2">
              <div v-if="scheduleInfo.plan.explore.opening" class="flex justify-between text-sm">
                <span>Beginn:</span>
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
              <div v-if="scheduleInfo.plan.challenge.opening" class="flex justify-between text-sm">
                <span>Beginn:</span>
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

      <!-- Level 3: Briefing Times -->
      <div v-if="isContentVisible(3) && scheduleInfo?.plan" class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">
          Briefing-Zeiten
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Explore Briefings -->
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
            </div>
          </div>

          <!-- Challenge Briefings -->
          <div v-if="scheduleInfo.plan.challenge">
            <h3 class="font-semibold mb-3 text-gray-900">FIRST LEGO League Challenge</h3>
            <div class="space-y-2">
              <div v-if="scheduleInfo.plan.challenge.briefing?.teams" class="flex justify-between text-sm">
                <span>Coach-Briefing:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.briefing.teams) }}</span>
              </div>
              <div v-if="scheduleInfo.plan.challenge.briefing?.judges" class="flex justify-between text-sm">
                <span>Gutachter:innen-Briefing:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.briefing.judges) }}</span>
              </div>
              <div v-if="scheduleInfo.plan.challenge.briefing?.referees" class="flex justify-between text-sm">
                <span>Schiedsrichter:innen-Briefing:</span>
                <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.briefing.referees) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Level 4: Public Plan (redirects to zeitplan.cgi) -->
      <div v-if="isContentVisible(4) && scheduleInfo?.plan" class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <div class="text-center">
          <h2 class="text-xl font-semibold text-gray-900 mb-4">
            Vollständiger Veranstaltungsplan
          </h2>
          <p class="text-gray-600 mb-4">
            Der vollständige Veranstaltungsplan wird geladen...
          </p>
          <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div>
        </div>
      </div>

      <!-- QR Code Section -->
      <div v-if="event.link" class="mt-8 bg-white rounded-lg shadow-sm border border-gray-200 p-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">
          QR-Code für diese Veranstaltung
        </h2>
        <div class="flex flex-col items-center">
          <img :src="`data:image/png;base64,${event.qrcode}`" alt="QR Code" class="w-32 h-32" />
          <p class="text-sm text-gray-600 mt-2">Scannen Sie den QR-Code, um diese Seite zu öffnen</p>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div class="bg-white border-t border-gray-200 py-6">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center text-sm text-gray-500">
          <p>&copy; 2024 Hands on Technology. Alle Rechte vorbehalten.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import { programLogoSrc, programLogoAlt } from '@/utils/images'

const route = useRoute()
const event = ref(null)
const scheduleInfo = ref(null)
const loading = ref(true)
const error = ref(null)

const loadEvent = async () => {
  try {
    loading.value = true
    error.value = null

    // Load event by slug
    const eventResponse = await axios.get(`/events/slug/${route.params.slug}`)
    event.value = eventResponse.data

    // Load schedule information with publication level
    const scheduleResponse = await axios.get(`/publish/public-information/${event.value.id}`)
    scheduleInfo.value = scheduleResponse.data

    // If level 4, redirect to zeitplan.cgi
    if (scheduleInfo.value?.level === 4) {
      try {
        const planResponse = await axios.get(`/plans/public/${event.value.id}`)
        const planId = planResponse.data.plan_id
        
        // Redirect to zeitplan.cgi with plan ID
        window.location.href = `/zeitplan.cgi?plan=${planId}`
        return
      } catch (planError) {
        console.error('Error fetching plan ID:', planError)
        // Continue showing the page if plan fetch fails
      }
    }

  } catch (err) {
    console.error('Error loading event:', err)
    error.value = err.response?.data?.error || 'Fehler beim Laden der Veranstaltung'
  } finally {
    loading.value = false
  }
}

// Format date to show only date part
const formatDateOnly = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  return date.toLocaleDateString('de-DE', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  })
}

// Format time to show only time part
const formatTimeOnly = (timeString) => {
  if (!timeString) return ''
  const date = new Date(timeString)
  return date.toLocaleTimeString('de-DE', {
    hour: '2-digit',
    minute: '2-digit'
  })
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
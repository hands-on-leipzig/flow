<script setup>
import {ref, computed, onMounted, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import axios from 'axios'
import {programLogoSrc, programLogoAlt, imageUrl} from '@/utils/images'
import {formatTimeOnly} from '@/utils/dateTimeFormat'
import EventMap from '@/components/molecules/EventMap.vue'

const route = useRoute()
const router = useRouter()
const event = ref(null)
const scheduleInfo = ref(null)
const loading = ref(true)
const error = ref(null)
const publicPlanId = ref(null)
const eventLogos = ref([])

const loadEvent = async () => {
  try {
    loading.value = true
    error.value = null

    // Load event by slug
    const eventResponse = await axios.get(`/events/slug/${route.params.slug}`)
    event.value = eventResponse.data

    // ✅ LOG ACCESS IMMEDIATELY AFTER EVENT IS LOADED
    // This works for ALL levels (1-4), including level 4 with iframe
    // Log before schedule info fetch so we capture access even if that fails
    try {
      // Determine source
      let source = 'unknown';
      if (route.query.source === 'qr') {
        source = 'qr';
      } else if (document.referrer) {
        source = 'referrer';
      } else {
        source = 'direct';
      }

      // Collect client-side data
      const clientData = {
        event_id: event.value.id,
        source: source,
        screen_width: window.screen.width,
        screen_height: window.screen.height,
        viewport_width: window.innerWidth,
        viewport_height: window.innerHeight,
        device_pixel_ratio: window.devicePixelRatio || 1,
        touch_support: 'ontouchstart' in window || navigator.maxTouchPoints > 0,
        connection_type: navigator.connection?.effectiveType ||
            navigator.connection?.type ||
            null
      };

      // Log access (fire and forget - don't await)
      axios.post('/one-link-access', clientData).catch(err => {
        console.error('Failed to log access:', err);
        // Silent failure - don't disrupt user experience
      });
    } catch (err) {
      // Silent failure - don't prevent page from loading
      console.error('Error preparing access log:', err);
    }

    // Load schedule information with publication level
    const scheduleResponse = await axios.get(`/publish/public-information/${event.value.id}`)
    scheduleInfo.value = scheduleResponse.data

    // If level 4, fetch plan ID for embedding (no redirect)
    if (scheduleInfo.value?.level === 4) {
      try {
        const planResponse = await axios.get(`/plans/public/${event.value.id}`)
        publicPlanId.value = planResponse.data.id
      } catch (planError) {
        console.error('Error fetching plan ID:', planError)
        // If plan can't be found, show 404 page
        if (planError.response?.status === 404) {
          error.value = 'Plan nicht gefunden'
        } else {
          // For other errors, continue showing the page
          console.warn('Plan fetch failed, but continuing with page display')
        }
      }
    }

    // Load logos for the event
    try {
      const logosResponse = await axios.get(`/events/${event.value.id}/logos`)
      eventLogos.value = logosResponse.data
    } catch (logoError) {
      console.error('Error fetching logos:', logoError)
      // Continue without logos if fetch fails
      eventLogos.value = []
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

// Get timeline items for Explore morning group, sorted chronologically
const getExploreMorningTimelineItems = () => {
  const plan = scheduleInfo.value?.plan
  if (!plan?.explore_morning || !Array.isArray(plan.explore_morning) || plan.explore_morning.length === 0) {
    return []
  }

  // Map backend format to frontend format
  return plan.explore_morning.map(item => {
    const timestamp = new Date(item.value).getTime()
    let type = 'briefing'
    if (item.label?.toLowerCase().includes('eröffnung') || item.label?.toLowerCase().includes('opening')) {
      type = 'opening'
    } else if (item.label?.toLowerCase().includes('ende') || item.label?.toLowerCase().includes('end')) {
      type = 'end'
    }

    return {
      time: formatTimeOnly(item.value, true),
      label: item.label || '',
      type: type,
      timestamp: timestamp,
      description: item.description || null
    }
  }).sort((a, b) => a.timestamp - b.timestamp)
}

// Get timeline items for Explore afternoon group, sorted chronologically
const getExploreAfternoonTimelineItems = () => {
  const plan = scheduleInfo.value?.plan
  if (!plan?.explore_afternoon || !Array.isArray(plan.explore_afternoon) || plan.explore_afternoon.length === 0) {
    return []
  }

  // Map backend format to frontend format
  return plan.explore_afternoon.map(item => {
    const timestamp = new Date(item.value).getTime()
    let type = 'briefing'
    if (item.label?.toLowerCase().includes('eröffnung') || item.label?.toLowerCase().includes('opening')) {
      type = 'opening'
    } else if (item.label?.toLowerCase().includes('ende') || item.label?.toLowerCase().includes('end')) {
      type = 'end'
    }

    return {
      time: formatTimeOnly(item.value, true),
      label: item.label || '',
      type: type,
      timestamp: timestamp,
      description: item.description || null
    }
  }).sort((a, b) => a.timestamp - b.timestamp)
}

// Get timeline items for single Explore group (fallback when no morning/afternoon), sorted chronologically
const getExploreSingleTimelineItems = () => {
  const plan = scheduleInfo.value?.plan
  if (!plan?.explore || !Array.isArray(plan.explore) || plan.explore.length === 0) {
    return []
  }

  // Map backend format to frontend format
  return plan.explore.map(item => {
    const timestamp = new Date(item.value).getTime()
    let type = 'briefing'
    if (item.label?.toLowerCase().includes('eröffnung') || item.label?.toLowerCase().includes('opening')) {
      type = 'opening'
    } else if (item.label?.toLowerCase().includes('ende') || item.label?.toLowerCase().includes('end')) {
      type = 'end'
    }

    return {
      time: formatTimeOnly(item.value, true),
      label: item.label || '',
      type: type,
      timestamp: timestamp,
      description: item.description || null
    }
  }).sort((a, b) => a.timestamp - b.timestamp)
}

// Get all Explore timeline items (for compatibility with existing code)
const getExploreTimelineItems = () => {
  const morningItems = getExploreMorningTimelineItems()
  const afternoonItems = getExploreAfternoonTimelineItems()
  const singleItems = getExploreSingleTimelineItems()

  // If we have morning or afternoon, return those (combined for compatibility)
  if (morningItems.length > 0 || afternoonItems.length > 0) {
    return [...morningItems, ...afternoonItems].sort((a, b) => a.timestamp - b.timestamp)
  }

  // Otherwise return single explore items
  return singleItems
}

// Get timeline items for Challenge program, sorted chronologically
const getChallengeTimelineItems = () => {
  const plan = scheduleInfo.value?.plan
  if (!plan?.challenge || !Array.isArray(plan.challenge) || plan.challenge.length === 0) return []

  // Backend returns challenge as an array of {value, label, sequence}
  // Map backend format to frontend format
  return plan.challenge.map(item => {
    const timestamp = new Date(item.value).getTime()
    let type = 'briefing'
    if (item.label?.toLowerCase().includes('beginn') || item.label?.toLowerCase().includes('opening')) {
      type = 'opening'
    } else if (item.label?.toLowerCase().includes('ende') || item.label?.toLowerCase().includes('end')) {
      type = 'end'
    }

    return {
      time: formatTimeOnly(item.value, true),
      label: item.label || '',
      type: type,
      timestamp: timestamp,
      description: item.description || null
    }
  }).sort((a, b) => a.timestamp - b.timestamp)
}

// Get combined Explore items count (morning + afternoon if both exist)
const combinedExploreItemsCount = computed(() => {
  const morningItems = getExploreMorningTimelineItems()
  const afternoonItems = getExploreAfternoonTimelineItems()
  const singleItems = getExploreSingleTimelineItems()

  // If both morning and afternoon exist, sum them; otherwise use single
  if (morningItems.length > 0 && afternoonItems.length > 0) {
    return morningItems.length + afternoonItems.length
  }

  // Return the max of single explore or whichever of morning/afternoon exists
  return Math.max(morningItems.length, afternoonItems.length, singleItems.length)
})

// Get timeline minimum height based on max items
const timelineMinHeight = computed(() => {
  const morningItems = getExploreMorningTimelineItems()
  const afternoonItems = getExploreAfternoonTimelineItems()
  const singleItems = getExploreSingleTimelineItems()
  const challengeItems = getChallengeTimelineItems()

  // Calculate max items across all explore sections and challenge
  const maxExploreItems = Math.max(morningItems.length, afternoonItems.length, singleItems.length)
  const maxItems = Math.max(maxExploreItems, challengeItems.length)

  // Each item takes approximately 70px (card + compact spacing with gap-3)
  // Base height for timeline line
  return `${maxItems * 70}px`
})

// Get combined Explore height for matching Challenge height
const combinedExploreHeight = computed(() => {
  // Each item takes approximately 70px (card + compact spacing with gap-3)
  // Add some padding for headers and spacing between sections
  const itemHeight = 70
  const headerHeight = 80 // Approximate header height
  const sectionSpacing = 16 // Spacing between morning/afternoon sections (gap-4)

  const morningItems = getExploreMorningTimelineItems()
  const afternoonItems = getExploreAfternoonTimelineItems()
  const singleItems = getExploreSingleTimelineItems()

  let height = 0

  // If both morning and afternoon exist
  if (morningItems.length > 0 && afternoonItems.length > 0) {
    height = headerHeight + (morningItems.length * itemHeight) + sectionSpacing + headerHeight + (afternoonItems.length * itemHeight)
  } else if (singleItems.length > 0) {
    height = headerHeight + (singleItems.length * itemHeight)
  } else {
    // Use whichever exists
    const items = morningItems.length > 0 ? morningItems : afternoonItems
    if (items.length > 0) {
      height = headerHeight + (items.length * itemHeight)
    }
  }

  return `${height}px`
})

// Check if content should be visible based on publication level
const isContentVisible = (level) => {
  if (!scheduleInfo.value) return false
  return scheduleInfo.value.level >= level
}

// Get color from database for Explore teams
const exploreColor = computed(() => {
  if (!scheduleInfo.value?.teams?.explore?.color_hex) return '#00A651' // Default green
  return `#${scheduleInfo.value.teams.explore.color_hex}`
})

// Get color from database for Challenge teams
const challengeColor = computed(() => {
  if (!scheduleInfo.value?.teams?.challenge?.color_hex) return '#ED1C24' // Default red
  return `#${scheduleInfo.value.teams.challenge.color_hex}`
})

// Navigate to home
const goHome = () => {
  router.push('/')
}

onMounted(async () => {
  await loadEvent()
})
</script>

<template>
  <div class="bg-gradient-to-br from-orange-50 via-amber-50 to-yellow-50 w-full min-h-screen">
    <!-- Loading State -->
    <div v-if="loading" class="min-h-screen flex items-center justify-center">
      <div class="text-center">
        <div
            class="animate-spin rounded-full h-16 w-16 border-4 border-[#F78B1F] border-t-transparent mx-auto mb-4"></div>
        <p class="text-[#F78B1F] font-semibold text-lg">Veranstaltung wird geladen...</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="min-h-screen flex items-center justify-center p-4">
      <div class="text-center max-w-2xl mx-auto w-full">
        <!-- Colorful Header -->
        <div
            class="bg-[#F78B1F] rounded-2xl md:rounded-3xl shadow-xl md:shadow-2xl p-6 md:p-8 mb-6 md:mb-8 transform hover:scale-[1.02] transition-transform">
          <div class="text-white text-4xl md:text-6xl mb-3 md:mb-4">🔍</div>
          <h1 class="text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-bold text-white mb-3 md:mb-4 drop-shadow-lg px-2">
            {{ error === 'Plan nicht gefunden' ? 'Plan nicht gefunden' : 'Event nicht gefunden' }}
          </h1>
          <div class="flex justify-center items-center gap-3 md:gap-4 mt-4 md:mt-6">
            <div class="flex items-center gap-2 md:gap-3 bg-white/20 backdrop-blur-sm rounded-full px-4 md:px-6 py-2">
              <img :alt="programLogoAlt('E')" :src="programLogoSrc('E')"
                   class="w-8 h-8 md:w-12 md:h-12 drop-shadow-lg"/>
              <img :alt="programLogoAlt('C')" :src="programLogoSrc('C')"
                   class="w-8 h-8 md:w-12 md:h-12 drop-shadow-lg"/>
            </div>
          </div>
        </div>

        <!-- Friendly Message Card -->
        <div
            class="bg-white rounded-xl md:rounded-2xl shadow-lg md:shadow-xl p-4 md:p-6 lg:p-8 border-2 border-[#F78B1F]/30">
          <p class="text-base md:text-lg text-gray-700 mb-4 md:mb-6 leading-relaxed">
            Hey! 👋 Für die Adresse, die du aufgerufen hast, konnten wir leider
            {{ error === 'Plan nicht gefunden' ? 'keinen Plan' : 'kein Event' }} finden.
          </p>
          <p class="text-sm md:text-base text-gray-600 mb-4 md:mb-6">
            Bitte überprüfe nochmal die Adresse, die du verwendet hast. Vielleicht hat sich ein kleiner Tippfehler
            eingeschlichen?
          </p>

          <div class="bg-orange-50 rounded-lg md:rounded-xl p-4 md:p-6 border-2 border-[#F78B1F]/20">
            <p class="text-xs md:text-sm text-gray-600 font-medium mb-2">Du hast folgende Adresse aufgerufen:</p>
            <p class="text-sm md:text-base lg:text-lg font-mono text-gray-800 break-all bg-white p-2 md:p-3 rounded-lg border border-gray-200">
              {{ route.params.slug || 'N/A' }}
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Level 4: Public Plan (embedded in iframe) - Full screen, no margins -->
    <div v-if="!loading && !error && event && isContentVisible(4) && publicPlanId"
         class="w-full fixed inset-0 flex flex-col"
         style="margin: 0; padding: 0; border: none; z-index: 1000;">
      <iframe
          :src="`/output/zeitplan.cgi?plan=${publicPlanId}`"
          class="flex-1 border-0"
          frameborder="0"
          scrolling="auto"
          style="margin: 0; padding: 0; border: none; width: 100%;"
      ></iframe>
    </div>

    <!-- Event Content (hidden when level 4 is active) -->
    <div v-else-if="event && !(isContentVisible(4) && publicPlanId)"
         class="max-w-7xl mx-auto px-3 sm:px-4 md:px-6 lg:px-8 py-4 md:py-6 lg:py-8 pb-12 md:pb-16">
      <!-- Header with Flow Logo and Event Name -->
      <div class="mb-8 md:mb-12">
        <div
            class="bg-white rounded-2xl md:rounded-3xl shadow-xl md:shadow-2xl p-4 md:p-8 mb-4 md:mb-6 transform transition-transform">
          <div class="flex flex-col md:flex-row items-center gap-3 md:gap-6">
            <img :src="imageUrl('/flow/hot+fll.png')" alt="FLOW Logo"
                 class="h-10 md:h-16 w-auto drop-shadow-lg flex-shrink-0"/>
            <h1 class="text-2xl sm:text-3xl md:text-4xl lg:text-5xl font-bold text-[#F78B1F] drop-shadow-lg flex-1 text-center md:text-center">
              {{
                event.name
              }}</h1>
          </div>
        </div>
      </div>

      <!-- Level 2 & 3: Times on Timeline -->
      <div
          class="mt-6 md:mt-8 bg-white rounded-xl md:rounded-2xl shadow-lg md:shadow-xl border-2 border-[#F78B1F] p-4 md:p-8">
        <h2 class="text-xl md:text-2xl font-bold text-[#F78B1F] mb-4 md:mb-6 flex items-center gap-2">
          Zeitplan
        </h2>

        <div v-if="(isContentVisible(2) || isContentVisible(3)) && scheduleInfo?.plan"
             class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
          <!-- Left Column: Explore (morning + afternoon stacked if both exist) -->
          <div class="flex flex-col gap-4 md:gap-6">
            <!-- 2x Explore: Morning section -->
            <div v-if="getExploreMorningTimelineItems().length > 0"
                 class="bg-gradient-to-br from-green-100 to-emerald-100 rounded-lg md:rounded-xl p-4 md:p-6 border-2 border-green-300 shadow-md md:shadow-lg flex flex-col">
              <h3 class="font-bold text-green-800 mb-4 md:mb-6 text-base md:text-lg flex items-center gap-2">
                <img :alt="programLogoAlt('E')" :src="programLogoSrc('E')" class="w-6 h-6"/>
                <span class="italic">FIRST</span> LEGO League Explore <span style="color: #1e40af;">Vormittag</span>
              </h3>
              <div :style="{ minHeight: timelineMinHeight }" class="relative flex-1">
                <!-- Timeline line -->
                <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-green-400"></div>

                <!-- Timeline items - compact spacing -->
                <div class="relative h-full flex flex-col gap-3">
                  <div
                      v-for="(item, index) in getExploreMorningTimelineItems()"
                      :key="index"
                      class="relative pl-12"
                  >
                    <!-- Timeline dot -->
                    <div
                        :class="item.type === 'opening' ? 'bg-green-500' : item.type === 'end' ? 'bg-red-500' : 'bg-blue-500'"
                        class="absolute left-2 top-2 w-4 h-4 rounded-full border-2 border-green-600 bg-white shadow-md">
                    </div>

                    <!-- Timeline content -->
                    <div class="bg-white rounded-md md:rounded-lg p-2 md:p-3 shadow-sm border border-green-200">
                      <div class="flex items-center justify-between mb-1 flex-wrap gap-1">
                        <span class="text-xs font-semibold text-green-700 uppercase tracking-wide">{{
                            item.label
                          }}</span>
                        <span class="text-base md:text-lg font-bold text-green-800">{{ item.time }}</span>
                      </div>
                      <div v-if="item.description" class="text-xs text-gray-600 mt-1">{{ item.description }}</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- 2x Explore: Afternoon section -->
            <div v-if="getExploreAfternoonTimelineItems().length > 0"
                 class="bg-gradient-to-br from-green-100 to-emerald-100 rounded-lg md:rounded-xl p-4 md:p-6 border-2 border-green-300 shadow-md md:shadow-lg flex flex-col">
              <h3 class="font-bold text-green-800 mb-4 md:mb-6 text-base md:text-lg flex items-center gap-2">
                <img :alt="programLogoAlt('E')" :src="programLogoSrc('E')" class="w-6 h-6"/>
                <span class="italic">FIRST</span> LEGO League Explore <span style="color: #93c5fd;">Nachmittag</span>
              </h3>
              <div :style="{ minHeight: timelineMinHeight }" class="relative flex-1">
                <!-- Timeline line -->
                <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-green-400"></div>

                <!-- Timeline items - compact spacing -->
                <div class="relative h-full flex flex-col gap-3">
                  <div
                      v-for="(item, index) in getExploreAfternoonTimelineItems()"
                      :key="index"
                      class="relative pl-12"
                  >
                    <!-- Timeline dot -->
                    <div
                        :class="item.type === 'opening' ? 'bg-green-500' : item.type === 'end' ? 'bg-red-500' : 'bg-blue-500'"
                        class="absolute left-2 top-2 w-4 h-4 rounded-full border-2 border-green-600 bg-white shadow-md">
                    </div>

                    <!-- Timeline content -->
                    <div class="bg-white rounded-md md:rounded-lg p-2 md:p-3 shadow-sm border border-green-200">
                      <div class="flex items-center justify-between mb-1 flex-wrap gap-1">
                        <span class="text-xs font-semibold text-green-700 uppercase tracking-wide">{{
                            item.label
                          }}</span>
                        <span class="text-base md:text-lg font-bold text-green-800">{{ item.time }}</span>
                      </div>
                      <div v-if="item.description" class="text-xs text-gray-600 mt-1">{{ item.description }}</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Single Explore Section (fallback when no morning/afternoon) -->
            <div v-else-if="getExploreSingleTimelineItems().length > 0"
                 class="bg-gradient-to-br from-green-100 to-emerald-100 rounded-lg md:rounded-xl p-4 md:p-6 border-2 border-green-300 shadow-md md:shadow-lg flex flex-col">
              <h3 class="font-bold text-green-800 mb-4 md:mb-6 text-base md:text-lg flex items-center gap-2">
                <img :alt="programLogoAlt('E')" :src="programLogoSrc('E')" class="w-6 h-6"/>
                FIRST LEGO League Explore
              </h3>
              <div :style="{ minHeight: timelineMinHeight }" class="relative flex-1">
                <!-- Timeline line -->
                <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-green-400"></div>

                <!-- Timeline items - compact spacing -->
                <div class="relative h-full flex flex-col gap-3">
                  <div
                      v-for="(item, index) in getExploreSingleTimelineItems()"
                      :key="index"
                      class="relative pl-12"
                  >
                    <!-- Timeline dot -->
                    <div
                        :class="item.type === 'opening' ? 'bg-green-500' : item.type === 'end' ? 'bg-red-500' : 'bg-blue-500'"
                        class="absolute left-2 top-2 w-4 h-4 rounded-full border-2 border-green-600 bg-white shadow-md">
                    </div>

                    <!-- Timeline content -->
                    <div class="bg-white rounded-md md:rounded-lg p-2 md:p-3 shadow-sm border border-green-200">
                      <div class="flex items-center justify-between mb-1 flex-wrap gap-1">
                        <span class="text-xs font-semibold text-green-700 uppercase tracking-wide">{{
                            item.label
                          }}</span>
                        <span class="text-base md:text-lg font-bold text-green-800">{{ item.time }}</span>
                      </div>
                      <div v-if="item.description" class="text-xs text-gray-600 mt-1">{{ item.description }}</div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <!-- End of Left Column: Explore -->

          <!-- Right Column: Challenge -->
          <div v-if="getChallengeTimelineItems().length > 0"
               class="bg-gradient-to-br from-red-100 to-pink-100 rounded-lg md:rounded-xl p-4 md:p-6 border-2 border-red-300 shadow-md md:shadow-lg flex flex-col"
               :style="{ minHeight: combinedExploreHeight }">
            <h3 class="font-bold text-red-800 mb-4 md:mb-6 text-base md:text-lg flex items-center gap-2">
              <img :alt="programLogoAlt('C')" :src="programLogoSrc('C')" class="w-6 h-6"/>
              FIRST LEGO League Challenge
            </h3>
            <div :style="{ minHeight: timelineMinHeight }" class="relative flex-1">
              <!-- Timeline line -->
              <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-red-400"></div>

              <!-- Timeline items - compact spacing -->
              <div class="relative h-full flex flex-col gap-3">
                <div
                    v-for="(item, index) in getChallengeTimelineItems()"
                    :key="index"
                    class="relative pl-12"
                >
                  <!-- Timeline dot -->
                  <div
                      :class="item.type === 'opening' ? 'bg-green-500' : item.type === 'end' ? 'bg-red-500' : 'bg-blue-500'"
                      class="absolute left-2 top-2 w-4 h-4 rounded-full border-2 border-red-600 bg-white shadow-md">
                  </div>

                  <!-- Timeline content -->
                  <div class="bg-white rounded-md md:rounded-lg p-2 md:p-3 shadow-sm border border-red-200">
                    <div class="flex items-center justify-between mb-1 flex-wrap gap-1">
                      <span class="text-xs font-semibold text-red-700 uppercase tracking-wide">{{ item.label }}</span>
                      <span class="text-base md:text-lg font-bold text-red-800">{{ item.time }}</span>
                    </div>
                    <div v-if="item.description" class="text-xs text-gray-600 mt-1">{{ item.description }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-else>
          <p>Das Veranstaltungsteam hat noch keinen Zeitplan veröffentlicht. Sobald dies geschieht, wirst du ihn hier
            sehen können. Bitte kontaktiere sie direkt, um weitere
            Informationen zu erhalten.</p>
        </div>
      </div>

      <!-- Level 1: Basic Event Information -->
      <div v-if="isContentVisible(1) && scheduleInfo"
           class="mt-6 md:mt-8 bg-white rounded-xl md:rounded-2xl shadow-lg md:shadow-xl border-2 border-[#F78B1F] p-4 md:p-8">
        <h2 class="text-xl md:text-2xl font-bold text-[#F78B1F] mb-4 md:mb-6 flex items-center gap-2">
          Allgemeine Infos
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-8">
          <div class="bg-orange-50 rounded-lg md:rounded-xl p-4 md:p-5 border-2 border-[#F78B1F]/20">
            <h3 class="font-bold text-[#F78B1F] mb-2 md:mb-3 text-base md:text-lg flex items-center gap-2">
              <span><i class="bi bi-pin-map-fill"></i></span>
              Datum & Ort
            </h3>
            <p class="text-gray-800 font-medium text-base md:text-lg">{{ formatDateOnly(scheduleInfo.date) }}</p>
            <!-- EventMap Component -->
            <div v-if="scheduleInfo.address" class="mt-3 md:mt-4">
              <EventMap
                  :address="scheduleInfo.address"
                  :event-id="event.id"
                  :event-name="event.name"
                  :show-q-r-code="true"
              />
            </div>
          </div>
          <div v-if="scheduleInfo.contact?.length"
               class="bg-orange-50 rounded-xl p-5 border-2 border-[#F78B1F]/20">
            <h3 class="font-bold text-[#F78B1F] mb-2 md:mb-3 text-base md:text-lg flex items-center gap-2">
              <span><i class="bi bi-envelope"></i></span>
              Kontakt
            </h3>
            <div class="space-y-3">
              <div v-for="(contact, index) in scheduleInfo.contact" :key="index"
                   class="text-sm bg-white rounded-lg p-3 border border-[#F78B1F]/20">
                <div class="font-semibold text-gray-900">{{ contact.contact }}</div>
                <div class="text-[#F78B1F] font-medium">{{ contact.contact_email }}</div>
                <div v-if="contact.contact_infos" class="text-gray-600 text-xs mt-1">{{ contact.contact_infos }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Level 1: Teams -->
      <div
          v-if="isContentVisible(1) && scheduleInfo && scheduleInfo.teams && ((scheduleInfo.teams.explore?.list?.length > 0 || scheduleInfo.teams.explore?.registered > 0) || (scheduleInfo.teams.challenge?.list?.length > 0 || scheduleInfo.teams.challenge?.registered > 0))"
          class="mt-6 md:mt-8 bg-white rounded-xl md:rounded-2xl shadow-lg md:shadow-xl border-2 border-[#F78B1F] p-4 md:p-8">
        <h2 class="text-xl md:text-2xl font-bold text-[#F78B1F] mb-4 md:mb-8 flex items-center gap-2">
          Angemeldete Teams
        </h2>

        <div v-if="scheduleInfo.teams?.explore?.registered > 0" class="mb-6 md:mb-10">
          <div
              v-if="scheduleInfo.teams.explore.list"
              class="overflow-x-auto rounded-lg md:rounded-xl border-2 md:border-4 shadow-lg md:shadow-xl"
              :style="{
              borderColor: exploreColor,
              boxShadow: `0 10px 15px -3px ${exploreColor}40, 0 4px 6px -2px ${exploreColor}20`
            }"
          >
            <table class="w-full min-w-[600px]" style="table-layout: fixed;">
              <colgroup>
                <col style="width: 15%;">
                <col style="width: 28.33%;">
                <col style="width: 28.33%;">
                <col style="width: 28.34%;">
              </colgroup>
              <thead>
              <tr>
                <th colspan="4" class="px-2 md:px-4 py-2 md:py-4 text-right">
                  <div class="flex items-center justify-end">
                    <img :alt="programLogoAlt('E')" :src="imageUrl('/flow/fll_explore_h.png')"
                         class="h-12 md:h-20 w-auto object-contain"/>
                  </div>
                </th>
              </tr>
              </thead>
              <tbody class="bg-white">

              <tr v-for="(team, index) in scheduleInfo.teams.explore.list" :key="team.team_number_hot"
                  :style="{ 
                    borderTop: index > 0 ? '1px solid ' + exploreColor + '30' : 'none',
                    '--hover-color': exploreColor + '15'
                  }"
                  class="hover:transition-colors"
                  :class="`hover:bg-[var(--hover-color)]`">
                <td class="px-2 md:px-4 py-2 md:py-4 whitespace-nowrap text-sm md:text-base font-bold text-center"
                    :style="{ color: exploreColor }">
                  {{ team.team_number_hot || '-' }}
                </td>
                <td class="px-2 md:px-4 py-2 md:py-4 text-sm md:text-base font-medium text-gray-900 break-words text-left">
                  <div class="flex items-center gap-2">
                    <i class="bi bi-people-fill text-gray-500"></i>
                    <span>{{ team.name }}</span>
                  </div>
                </td>
                <td class="px-2 md:px-4 py-2 md:py-4 text-sm md:text-base text-gray-700 break-words text-left">
                  <div class="flex items-center gap-2">
                    <i class="bi bi-building-fill text-gray-500"></i>
                    <span>{{ team.organization || '-' }}</span>
                  </div>
                </td>
                <td class="px-2 md:px-4 py-2 md:py-4 text-sm md:text-base text-gray-700 break-words text-left">
                  <div class="flex items-center gap-2">
                    <i class="bi bi-pin-map-fill text-gray-500"></i>
                    <span>{{ team.location || '-' }}</span>
                  </div>
                </td>
              </tr>
              </tbody>
            </table>
          </div>
          <div v-else
               class="rounded-lg md:rounded-xl border-2 border-[#F78B1F]/20 p-4 md:p-6 bg-orange-50">
            <div class="flex items-center gap-2 mb-2">
              <img :alt="programLogoAlt('E')" :src="imageUrl('/flow/fll_explore_h.png')"
                   class="h-8 md:h-12 w-auto object-contain"/>
              <p class="text-sm md:text-base text-gray-700">
                {{ scheduleInfo.teams.explore.registered }} Team(s) angemeldet
              </p>
            </div>
          </div>
        </div>

        <div v-if="scheduleInfo.teams?.challenge?.registered > 0">
          <div
              v-if="scheduleInfo.teams.challenge.list"
              class="overflow-x-auto rounded-lg md:rounded-xl border-2 md:border-4 shadow-lg md:shadow-xl"
              :style="{
              borderColor: challengeColor,
              boxShadow: `0 10px 15px -3px ${challengeColor}40, 0 4px 6px -2px ${challengeColor}20`
            }"
          >
            <table class="w-full min-w-[600px]" style="table-layout: fixed;">
              <colgroup>
                <col style="width: 15%;">
                <col style="width: 28.33%;">
                <col style="width: 28.33%;">
                <col style="width: 28.34%;">
              </colgroup>
              <thead>
              <tr>
                <th colspan="4" class="px-2 md:px-4 py-2 md:py-4 text-right">
                  <div class="flex items-center justify-end">
                    <img :alt="programLogoAlt('C')" :src="imageUrl('/flow/fll_challenge_h.png')"
                         class="h-12 md:h-20 w-auto object-contain"/>
                  </div>
                </th>
              </tr>
              </thead>
              <tbody class="bg-white">
              <tr v-for="(team, index) in scheduleInfo.teams.challenge.list" :key="team.team_number_hot || team.name"
                  :style="{ 
                    borderTop: index > 0 ? '1px solid ' + challengeColor + '30' : 'none',
                    '--hover-color': challengeColor + '15'
                  }"
                  class="hover:transition-colors"
                  :class="`hover:bg-[var(--hover-color)]`">
                <td class="px-2 md:px-4 py-2 md:py-4 whitespace-nowrap text-sm md:text-base font-bold text-center"
                    :style="{ color: challengeColor }">
                  {{ team.team_number_hot || '-' }}
                </td>
                <td class="px-2 md:px-4 py-2 md:py-4 text-sm md:text-base font-medium text-gray-900 break-words text-left">
                  <div class="flex items-center gap-2">
                    <i class="bi bi-people-fill text-gray-500"></i>
                    <span>{{ team.name }}</span>
                  </div>
                </td>
                <td class="px-2 md:px-4 py-2 md:py-4 text-sm md:text-base text-gray-700 break-words text-left">
                  <div class="flex items-center gap-2">
                    <i class="bi bi-building-fill text-gray-500"></i>
                    <span>{{ team.organization || '-' }}</span>
                  </div>
                </td>
                <td class="px-2 md:px-4 py-2 md:py-4 text-sm md:text-base text-gray-700 break-words text-left">
                  <div class="flex items-center gap-2">
                    <i class="bi bi-pin-map-fill text-gray-500"></i>
                    <span>{{ team.location || '-' }}</span>
                  </div>
                </td>
              </tr>
              </tbody>
            </table>
          </div>
          <div v-else
               class="rounded-lg md:rounded-xl border-2 border-red-300/20 p-4 md:p-6 bg-red-50">
            <div class="flex items-center gap-2 mb-2">
              <img :alt="programLogoAlt('C')" :src="imageUrl('/flow/fll_challenge_h.png')"
                   class="h-8 md:h-12 w-auto object-contain"/>
              <p class="text-sm md:text-base text-gray-700">
                {{ scheduleInfo.teams.challenge.registered }} Team(s) angemeldet
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Event Logos Footer - at the very bottom -->
      <div class="bg-[#F78B1F] py-6 md:py-8 mt-8 md:mt-12 shadow-2xl">
        <div class="max-w-7xl mx-auto px-3 sm:px-4 md:px-6 lg:px-8">
          <!-- Event Logos -->
          <div v-if="eventLogos.length > 0"
               class="flex flex-wrap items-center justify-center gap-4 md:gap-6 lg:gap-8 mb-6 md:mb-8">
            <a
                v-for="logo in eventLogos"
                :key="logo.id"
                :href="logo.link || '#'"
                :rel="logo.link ? 'noopener noreferrer' : ''"
                :target="logo.link ? '_blank' : '_self'"
                class="flex items-center justify-center bg-white rounded-lg md:rounded-xl p-2 md:p-3 lg:p-4 shadow-md md:shadow-lg hover:shadow-xl hover:scale-105 md:hover:scale-110 transition-all transform"
            >
              <img
                  :alt="logo.title || 'Logo'"
                  :src="logo.url"
                  class="h-10 md:h-12 lg:h-14 max-w-24 md:max-w-32 lg:max-w-36 object-contain"
              />
            </a>
          </div>
        </div>
      </div>

    </div> <!-- End of Event Content (lvl 1-3) -->
  </div>
</template>
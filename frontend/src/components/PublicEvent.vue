<script setup>
import {ref, computed, onMounted, onBeforeUnmount, watch, nextTick, Teleport} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import axios from 'axios'
import {programLogoSrc, programLogoAlt, imageUrl} from '@/utils/images'
import QRCode from 'qrcode'

const route = useRoute()
const router = useRouter()
const event = ref(null)
const scheduleInfo = ref(null)
const loading = ref(true)
const error = ref(null)
const publicPlanId = ref(null)
const eventLogos = ref([])
const mapCoordinates = ref(null)
const mapInstance = ref(null)
const showMapMenu = ref(false)
const showQRCode = ref(false)
const copySuccessMessage = ref('')
const qrCodeRef = ref(null)
const mapMenuButton = ref(null)
const menuPosition = ref({top: '0px', left: '0px'})

// Check if device is Apple (iOS/macOS)
const isAppleDevice = ref(/iPad|iPhone|iPod|Macintosh/.test(navigator.userAgent))

// Check if Web Share API is available
const canShare = ref('share' in navigator)

// Copy success timeout
let copySuccessTimeout = null

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

// Format time to show only time part
const formatTimeOnly = (timeString) => {
  if (!timeString) return ''
  const date = new Date(timeString)
  return date.toLocaleTimeString('de-DE', {
    hour: '2-digit',
    minute: '2-digit'
  })
}

// Get timeline items for Explore program, sorted chronologically
const getExploreTimelineItems = () => {
  if (!scheduleInfo.value?.plan?.explore) return []

  const items = []
  const plan = scheduleInfo.value.plan.explore

  // Add opening time
  if (plan.opening) {
    items.push({
      time: formatTimeOnly(plan.opening),
      label: 'Beginn',
      type: 'opening',
      timestamp: new Date(plan.opening).getTime()
    })
  }

  // Add briefing times
  if (plan.briefing?.teams) {
    items.push({
      time: formatTimeOnly(plan.briefing.teams),
      label: 'Coach-Briefing',
      type: 'briefing',
      description: 'Briefing für Coaches',
      timestamp: new Date(plan.briefing.teams).getTime()
    })
  }

  if (plan.briefing?.judges) {
    items.push({
      time: formatTimeOnly(plan.briefing.judges),
      label: 'Gutachter:innen-Briefing',
      type: 'briefing',
      description: 'Briefing für Gutachter:innen',
      timestamp: new Date(plan.briefing.judges).getTime()
    })
  }

  // Add end time
  if (plan.end) {
    items.push({
      time: formatTimeOnly(plan.end),
      label: 'Ende',
      type: 'end',
      timestamp: new Date(plan.end).getTime()
    })
  }

  // Sort by timestamp
  return items.sort((a, b) => a.timestamp - b.timestamp)
}

// Get timeline items for Challenge program, sorted chronologically
const getChallengeTimelineItems = () => {
  if (!scheduleInfo.value?.plan?.challenge) return []

  const items = []
  const plan = scheduleInfo.value.plan.challenge

  // Add opening time
  if (plan.opening) {
    items.push({
      time: formatTimeOnly(plan.opening),
      label: 'Beginn',
      type: 'opening',
      timestamp: new Date(plan.opening).getTime()
    })
  }

  // Add briefing times
  if (plan.briefing?.teams) {
    items.push({
      time: formatTimeOnly(plan.briefing.teams),
      label: 'Coach-Briefing',
      type: 'briefing',
      description: 'Briefing für Coaches',
      timestamp: new Date(plan.briefing.teams).getTime()
    })
  }

  if (plan.briefing?.judges) {
    items.push({
      time: formatTimeOnly(plan.briefing.judges),
      label: 'Gutachter:innen-Briefing',
      type: 'briefing',
      description: 'Briefing für Gutachter:innen',
      timestamp: new Date(plan.briefing.judges).getTime()
    })
  }

  if (plan.briefing?.referees) {
    items.push({
      time: formatTimeOnly(plan.briefing.referees),
      label: 'Schiedsrichter:innen-Briefing',
      type: 'briefing',
      description: 'Briefing für Schiedsrichter:innen',
      timestamp: new Date(plan.briefing.referees).getTime()
    })
  }

  // Add end time
  if (plan.end) {
    items.push({
      time: formatTimeOnly(plan.end),
      label: 'Ende',
      type: 'end',
      timestamp: new Date(plan.end).getTime()
    })
  }

  // Sort by timestamp (chronological order)
  return items.sort((a, b) => a.timestamp - b.timestamp)
}

// Get timeline minimum height based on max items
const timelineMinHeight = computed(() => {
  const exploreItems = getExploreTimelineItems()
  const challengeItems = getChallengeTimelineItems()
  const maxItems = Math.max(exploreItems.length, challengeItems.length)

  // Each item takes approximately 100px (card + spacing)
  // Base height for timeline line
  return `${maxItems * 100}px`
})

// Check if content should be visible based on publication level
const isContentVisible = (level) => {
  if (!scheduleInfo.value) return false
  return scheduleInfo.value.level >= level
}

// Navigate to home
const goHome = () => {
  router.push('/')
}

// Geocode address using backend API (proxies to OpenStreetMap Nominatim API)
const geocodeAddress = async (address) => {
  if (!address) return null

  try {
    const response = await axios.get('/geocode', {
      params: {
        address: address
      }
    })

    if (response.data && response.data.lat && response.data.lon) {
      return {
        lat: response.data.lat,
        lon: response.data.lon
      }
    }
    return null
  } catch (err) {
    console.error('Error geocoding address:', err)
    return null
  }
}

// Initialize map with Leaflet
const initializeMap = async (address) => {
  if (!address) return

  // Remove existing map if it exists
  if (mapInstance.value) {
    mapInstance.value.remove()
    mapInstance.value = null
  }

  // Load Leaflet CSS and JS if not already loaded
  if (!window.L) {
    const link = document.createElement('link')
    link.rel = 'stylesheet'
    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'
    document.head.appendChild(link)

    const script = document.createElement('script')
    script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
    script.onload = () => {
      // Wait a bit for Leaflet to initialize
      setTimeout(() => createMap(address), 100)
    }
    document.body.appendChild(script)
  } else {
    createMap(address)
  }
}

const createMap = async (address) => {
  if (!window.L) return

  // Geocode address
  const coords = await geocodeAddress(address)
  if (!coords) return

  mapCoordinates.value = coords

  // Wait for DOM to be ready
  await new Promise(resolve => setTimeout(resolve, 100))

  const mapId = `map-${event.value?.id}`
  const mapElement = document.getElementById(mapId)
  if (!mapElement) return

  // Create map
  const map = window.L.map(mapId).setView([coords.lat, coords.lon], 15)
  mapInstance.value = map

  // Add OpenStreetMap tiles
  window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19
  }).addTo(map)

  // Add marker
  window.L.marker([coords.lat, coords.lon])
      .addTo(map)
      .bindPopup(address)
      .openPopup()
}

// Watch for scheduleInfo changes to initialize map
watch(() => scheduleInfo.value?.address, async (newAddress) => {
  if (newAddress && event.value?.id) {
    await nextTick()
    // Wait a bit for DOM to render
    setTimeout(() => {
      initializeMap(newAddress)
    }, 300)
  }
}, {immediate: true})

onMounted(async () => {
  await loadEvent()
  // Add click outside listener for map menu
  document.addEventListener('click', handleClickOutside)
})

// Open in Google Maps
const openInGoogleMaps = () => {
  if (!mapCoordinates.value) return
  const url = `https://www.google.com/maps/search/?api=1&query=${mapCoordinates.value.lat},${mapCoordinates.value.lon}`
  window.open(url, '_blank')
  showMapMenu.value = false
}

// Open in Apple Maps
const openInAppleMaps = () => {
  if (!mapCoordinates.value) return
  const url = `https://maps.apple.com/?q=${mapCoordinates.value.lat},${mapCoordinates.value.lon}`
  window.open(url, '_blank')
  showMapMenu.value = false
}

// Open in OpenStreetMap
const openInOpenStreetMap = () => {
  if (!mapCoordinates.value) return
  const url = `https://www.openstreetmap.org/?mlat=${mapCoordinates.value.lat}&mlon=${mapCoordinates.value.lon}&zoom=15`
  window.open(url, '_blank')
  showMapMenu.value = false
}

// Copy coordinates to clipboard
const copyCoordinates = async () => {
  if (!mapCoordinates.value) return
  const coords = `${mapCoordinates.value.lat}, ${mapCoordinates.value.lon}`
  try {
    await navigator.clipboard.writeText(coords)
    copySuccessMessage.value = 'Koordinaten kopiert!'
    showMapMenu.value = false
    if (copySuccessTimeout) clearTimeout(copySuccessTimeout)
    copySuccessTimeout = setTimeout(() => {
      copySuccessMessage.value = ''
    }, 2000)
  } catch (err) {
    console.error('Failed to copy coordinates:', err)
    alert('Koordinaten konnten nicht kopiert werden')
  }
}

// Copy address to clipboard
const copyAddress = async () => {
  if (!scheduleInfo.value?.address) return
  try {
    await navigator.clipboard.writeText(scheduleInfo.value.address)
    copySuccessMessage.value = 'Adresse kopiert!'
    showMapMenu.value = false
    if (copySuccessTimeout) clearTimeout(copySuccessTimeout)
    copySuccessTimeout = setTimeout(() => {
      copySuccessMessage.value = ''
    }, 2000)
  } catch (err) {
    console.error('Failed to copy address:', err)
    alert('Adresse konnte nicht kopiert werden')
  }
}

// Share location using Web Share API
const shareLocation = async () => {
  if (!mapCoordinates.value || !scheduleInfo.value?.address) return

  const shareData = {
    title: event.value?.name || 'Veranstaltungsort',
    text: scheduleInfo.value.address,
    url: `https://www.google.com/maps/search/?api=1&query=${mapCoordinates.value.lat},${mapCoordinates.value.lon}`
  }

  try {
    if (navigator.share) {
      await navigator.share(shareData)
      showMapMenu.value = false
    }
  } catch (err) {
    if (err.name !== 'AbortError') {
      console.error('Error sharing:', err)
    }
  }
}

// Get menu position based on button position
const updateMenuPosition = () => {
  if (!mapMenuButton.value) {
    menuPosition.value = {visibility: 'hidden'}
    return
  }

  const rect = mapMenuButton.value.getBoundingClientRect()
  menuPosition.value = {
    top: `${rect.bottom + 8}px`,
    left: `${rect.right - 256}px`, // 256px = w-64 (menu width)
  }
}

const getMenuPosition = () => menuPosition.value

// Close menu when clicking outside
const handleClickOutside = (event) => {
  if (showMapMenu.value && mapMenuButton.value && !mapMenuButton.value.contains(event.target)) {
    // Check if click is outside the menu
    const menuElement = document.querySelector('[data-map-menu]')
    if (menuElement && !menuElement.contains(event.target)) {
      showMapMenu.value = false
    }
  }
}

// Generate QR code for location
const generateQRCode = async () => {
  if (!mapCoordinates.value) {
    console.error('No map coordinates available')
    return
  }

  // Wait for the ref to be available
  let attempts = 0
  while (!qrCodeRef.value && attempts < 10) {
    await new Promise(resolve => setTimeout(resolve, 50))
    attempts++
  }

  if (!qrCodeRef.value) {
    console.error('QR code ref not available')
    return
  }

  const googleMapsUrl = `https://www.google.com/maps/search/?api=1&query=${mapCoordinates.value.lat},${mapCoordinates.value.lon}`

  try {
    // Clear any existing canvas content
    const canvas = qrCodeRef.value
    if (canvas instanceof HTMLCanvasElement) {
      const ctx = canvas.getContext('2d')
      if (ctx) {
        ctx.clearRect(0, 0, canvas.width, canvas.height)
      }
    }

    // Generate QR code to canvas
    await QRCode.toCanvas(canvas, googleMapsUrl, {
      width: 256,
      margin: 2,
      color: {
        dark: '#000000',
        light: '#FFFFFF'
      }
    })
  } catch (err) {
    console.error('Error generating QR code:', err)
    // Fallback: try to generate as data URL and display as image
    try {
      const dataUrl = await QRCode.toDataURL(googleMapsUrl, {
        width: 256,
        margin: 2,
        color: {
          dark: '#000000',
          light: '#FFFFFF'
        }
      })
      if (qrCodeRef.value && qrCodeRef.value instanceof HTMLCanvasElement) {
        const img = new Image()
        img.src = dataUrl
        img.onload = () => {
          const ctx = qrCodeRef.value.getContext('2d')
          ctx.clearRect(0, 0, qrCodeRef.value.width, qrCodeRef.value.height)
          ctx.drawImage(img, 0, 0)
        }
      }
    } catch (fallbackErr) {
      console.error('Error with fallback QR code generation:', fallbackErr)
    }
  }
}

// Watch for QR code modal to show
watch(showQRCode, async (newVal) => {
  if (newVal && mapCoordinates.value) {
    await nextTick()
    // Add a small delay to ensure the modal is fully rendered
    setTimeout(() => {
      generateQRCode()
    }, 100)
  }
})

// Watch for menu to open and update position
watch(showMapMenu, async (newVal) => {
  if (newVal) {
    await nextTick()
    updateMenuPosition()
  }
})

// Cleanup map on unmount
onBeforeUnmount(() => {
  if (mapInstance.value) {
    mapInstance.value.remove()
    mapInstance.value = null
  }
  if (copySuccessTimeout) {
    clearTimeout(copySuccessTimeout)
  }
  document.removeEventListener('click', handleClickOutside)
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
      <div class="text-center max-w-2xl mx-auto">
        <!-- Colorful Header -->
        <div class="bg-[#F78B1F] rounded-3xl shadow-2xl p-8 mb-8 transform hover:scale-[1.02] transition-transform">
          <div class="text-white text-6xl mb-4">🔍</div>
          <h1 class="text-4xl md:text-5xl font-bold text-white mb-4 drop-shadow-lg">
            {{ error === 'Plan nicht gefunden' ? 'Plan nicht gefunden' : 'Event nicht gefunden' }}
          </h1>
          <div class="flex justify-center items-center gap-4 mt-6">
            <div class="flex items-center gap-3 bg-white/20 backdrop-blur-sm rounded-full px-6 py-2">
              <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-12 h-12 drop-shadow-lg"/>
              <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-12 h-12 drop-shadow-lg"/>
            </div>
          </div>
        </div>

        <!-- Friendly Message Card -->
        <div class="bg-white rounded-2xl shadow-xl p-8 border-2 border-[#F78B1F]/30">
          <p class="text-lg text-gray-700 mb-6 leading-relaxed">
            Hey! 👋 Für die Adresse, die du aufgerufen hast, konnten wir leider
            {{ error === 'Plan nicht gefunden' ? 'keinen Plan' : 'kein Event' }} finden.
          </p>
          <p class="text-base text-gray-600 mb-6">
            Bitte überprüfe nochmal die Adresse, die du verwendet hast. Vielleicht hat sich ein kleiner Tippfehler
            eingeschlichen?
          </p>

          <div class="bg-orange-50 rounded-xl p-6 border-2 border-[#F78B1F]/20">
            <p class="text-sm text-gray-600 font-medium mb-2">Du hast folgende Adresse aufgerufen:</p>
            <p class="text-lg font-mono text-gray-800 break-all bg-white p-3 rounded-lg border border-gray-200">
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
          style="margin: 0; padding: 0; border: none; width: 100%;"
          frameborder="0"
          scrolling="auto"
      ></iframe>
      <!-- Event Logos Footer for Level 4 -->
      <div v-if="eventLogos.length > 0" class="bg-white border-t border-gray-200 py-4 px-4">
        <div class="flex flex-wrap items-center justify-center gap-6 max-w-7xl mx-auto">
          <a
              v-for="logo in eventLogos"
              :key="logo.id"
              :href="logo.link || '#'"
              :target="logo.link ? '_blank' : '_self'"
              :rel="logo.link ? 'noopener noreferrer' : ''"
              class="flex items-center justify-center hover:opacity-80 transition-opacity"
          >
            <img
                :src="logo.url"
                :alt="logo.title || 'Logo'"
                class="h-12 max-w-32 object-contain"
            />
          </a>
        </div>
      </div>
    </div>

    <!-- Event Content (hidden when level 4 is active) -->
    <div v-else-if="event && !(isContentVisible(4) && publicPlanId)"
         class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 pb-16">
      <!-- Header with Flow Logo and Event Name -->
      <div class="mb-12">
        <div
            class="bg-white rounded-3xl shadow-2xl p-8 mb-6 transform transition-transform">
          <div class="flex items-center gap-6">
            <img :src="imageUrl('/flow/hot+fll.png')" alt="FLOW Logo" class="h-16 w-auto drop-shadow-lg flex-shrink-0"/>
            <h1 class="text-4xl md:text-5xl font-bold text-[#F78B1F] drop-shadow-lg flex-1 text-center">{{
                event.name
              }}</h1>
          </div>
        </div>
      </div>

      <!-- Level 2 & 3: Times on Timeline -->
      <div v-if="(isContentVisible(2) || isContentVisible(3)) && scheduleInfo?.plan"
           class="mt-8 bg-white rounded-2xl shadow-xl border-2 border-[#F78B1F] p-8">
        <h2 class="text-2xl font-bold text-[#F78B1F] mb-6 flex items-center gap-2">
          <span class="text-3xl">⏰</span>
          Veranstaltungszeiten
        </h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Explore: Timeline -->
          <div v-if="scheduleInfo.plan.explore && getExploreTimelineItems().length > 0"
               class="bg-gradient-to-br from-green-100 to-emerald-100 rounded-xl p-6 border-2 border-green-300 shadow-lg flex flex-col">
            <h3 class="font-bold text-green-800 mb-6 text-lg flex items-center gap-2">
              <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-6 h-6"/>
              FIRST LEGO League Explore
            </h3>
            <div class="relative flex-1" :style="{ minHeight: timelineMinHeight }">
              <!-- Timeline line -->
              <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-green-400"></div>

              <!-- Timeline items - evenly spaced -->
              <div class="relative h-full flex flex-col justify-between">
                <div
                    v-for="(item, index) in getExploreTimelineItems()"
                    :key="index"
                    class="relative pl-12"
                    :style="{ marginTop: index === 0 ? '0' : 'auto', marginBottom: index === getExploreTimelineItems().length - 1 ? '0' : 'auto' }"
                >
                  <!-- Timeline dot -->
                  <div class="absolute left-2 top-2 w-4 h-4 rounded-full border-2 border-green-600 bg-white shadow-md"
                       :class="item.type === 'opening' ? 'bg-green-500' : item.type === 'end' ? 'bg-red-500' : 'bg-blue-500'">
                  </div>

                  <!-- Timeline content -->
                  <div class="bg-white rounded-lg p-3 shadow-sm border border-green-200">
                    <div class="flex items-center justify-between mb-1">
                      <span class="text-xs font-semibold text-green-700 uppercase tracking-wide">{{ item.label }}</span>
                      <span class="text-lg font-bold text-green-800">{{ item.time }}</span>
                    </div>
                    <div v-if="item.description" class="text-xs text-gray-600 mt-1">{{ item.description }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Challenge: Timeline -->
          <div v-if="scheduleInfo.plan.challenge && getChallengeTimelineItems().length > 0"
               class="bg-gradient-to-br from-red-100 to-pink-100 rounded-xl p-6 border-2 border-red-300 shadow-lg flex flex-col">
            <h3 class="font-bold text-red-800 mb-6 text-lg flex items-center gap-2">
              <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-6 h-6"/>
              FIRST LEGO League Challenge
            </h3>
            <div class="relative flex-1" :style="{ minHeight: timelineMinHeight }">
              <!-- Timeline line -->
              <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-red-400"></div>

              <!-- Timeline items - evenly spaced -->
              <div class="relative h-full flex flex-col justify-between">
                <div
                    v-for="(item, index) in getChallengeTimelineItems()"
                    :key="index"
                    class="relative pl-12"
                    :style="{ marginTop: index === 0 ? '0' : 'auto', marginBottom: index === getChallengeTimelineItems().length - 1 ? '0' : 'auto' }"
                >
                  <!-- Timeline dot -->
                  <div class="absolute left-2 top-2 w-4 h-4 rounded-full border-2 border-red-600 bg-white shadow-md"
                       :class="item.type === 'opening' ? 'bg-green-500' : item.type === 'end' ? 'bg-red-500' : 'bg-blue-500'">
                  </div>

                  <!-- Timeline content -->
                  <div class="bg-white rounded-lg p-3 shadow-sm border border-red-200">
                    <div class="flex items-center justify-between mb-1">
                      <span class="text-xs font-semibold text-red-700 uppercase tracking-wide">{{ item.label }}</span>
                      <span class="text-lg font-bold text-red-800">{{ item.time }}</span>
                    </div>
                    <div v-if="item.description" class="text-xs text-gray-600 mt-1">{{ item.description }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Level 1: Basic Event Information -->
      <div v-if="isContentVisible(1) && scheduleInfo"
           class="mt-8 bg-white rounded-2xl shadow-xl border-2 border-[#F78B1F] p-8 transform hover:shadow-2xl transition-shadow">
        <h2 class="text-2xl font-bold text-[#F78B1F] mb-6 flex items-center gap-2">
          <span class="text-3xl">📅</span>
          Veranstaltungsinformationen
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
          <div class="bg-orange-50 rounded-xl p-5 border-2 border-[#F78B1F]/20">
            <h3 class="font-bold text-[#F78B1F] mb-3 text-lg flex items-center gap-2">
              <span>📍</span>
              Datum & Ort
            </h3>
            <p class="text-gray-800 font-medium text-lg">{{ formatDateOnly(scheduleInfo.date) }}</p>
            <div v-if="!mapCoordinates && scheduleInfo.address"
                 class="mt-3 text-sm text-gray-700 whitespace-pre-line bg-white rounded-lg p-3 border border-[#F78B1F]/20">
              {{ scheduleInfo.address }}
            </div>
            <!-- OpenStreetMap Map -->
            <div v-if="scheduleInfo.address"
                 class="mt-4 rounded-lg overflow-hidden border-2 border-[#F78B1F] shadow-lg relative"
                 style="height: 300px;">
              <div v-if="!mapCoordinates" class="w-full h-full flex items-center justify-center bg-gray-100">
                <p class="text-gray-500 text-sm">Karte wird geladen...</p>
              </div>
              <div :id="'map-' + event.id" v-else class="w-full h-full"></div>

              <!-- Map Options Menu Button (inside map container, upper right corner) -->
              <div v-if="mapCoordinates" class="absolute top-2 right-2 z-[1000]">
                <div class="relative">
                  <button
                      ref="mapMenuButton"
                      @click="showMapMenu = !showMapMenu"
                      class="bg-white hover:bg-gray-50 rounded-lg shadow-lg p-2 border border-gray-200 flex items-center gap-2 transition-colors"
                      title="Karten-Optionen"
                  >
                    <svg class="w-5 h-5 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/>
                    </svg>
                  </button>
                </div>
              </div>
            </div>

            <!-- Dropdown Menu (outside map container to prevent clipping, positioned relative to button) -->
            <Teleport to="body">
              <div
                  v-if="showMapMenu && mapCoordinates && mapMenuButton"
                  data-map-menu
                  :style="getMenuPosition()"
                  class="fixed w-64 bg-white rounded-lg shadow-xl border border-gray-200 overflow-hidden z-[9999]"
              >
                <div class="py-1">
                  <!-- Open in Google Maps -->
                  <button
                      @click="openInGoogleMaps"
                      class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
                  >
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                      <path
                          d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                    </svg>
                    <span>In Google Maps öffnen</span>
                  </button>

                  <!-- Open in Apple Maps -->
                  <button
                      v-if="isAppleDevice"
                      @click="openInAppleMaps"
                      class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
                  >
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                      <path
                          d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                    </svg>
                    <span>In Apple Maps öffnen</span>
                  </button>

                  <!-- Open in OpenStreetMap -->
                  <button
                      @click="openInOpenStreetMap"
                      class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
                  >
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                      <path
                          d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
                    </svg>
                    <span>Auf OpenStreetMap öffnen</span>
                  </button>

                  <!-- Divider -->
                  <div class="border-t border-gray-200 my-1"></div>

                  <!-- Copy Coordinates -->
                  <button
                      @click="copyCoordinates"
                      class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <span>Koordinaten kopieren</span>
                  </button>

                  <!-- Copy Address -->
                  <button
                      @click="copyAddress"
                      class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <span>Adresse kopieren</span>
                  </button>

                  <!-- Share Location -->
                  <button
                      v-if="canShare"
                      @click="shareLocation"
                      class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                    </svg>
                    <span>Teilen</span>
                  </button>

                  <!-- QR Code -->
                  <button
                      @click="showMapMenu = false; showQRCode = true"
                      class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/>
                    </svg>
                    <span>QR-Code anzeigen</span>
                  </button>
                </div>
              </div>
            </Teleport>
          </div>
          <div v-if="scheduleInfo.contact?.length"
               class="bg-orange-50 rounded-xl p-5 border-2 border-[#F78B1F]/20">
            <h3 class="font-bold text-[#F78B1F] mb-3 text-lg flex items-center gap-2">
              <span>✉️</span>
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
      <div v-if="isContentVisible(1) && scheduleInfo"
           class="mt-8 bg-white rounded-2xl shadow-xl border-2 border-[#F78B1F] p-8">
        <h2 class="text-2xl font-bold text-[#F78B1F] mb-8 flex items-center gap-2">
          <span class="text-3xl">👥</span>
          Angemeldete Teams
        </h2>

        <!-- Explore Teams -->
        <div v-if="scheduleInfo.teams.explore.list?.length" class="mb-10">
          <div class="bg-gradient-to-r from-green-400 to-emerald-500 rounded-xl p-4 mb-5 shadow-lg">
            <div class="flex items-center justify-between">
              <h3 class="font-bold text-white text-lg flex items-center gap-2">
                <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-8 h-8 drop-shadow-lg"/>
                FIRST LEGO League Explore
              </h3>
              <div v-if="scheduleInfo.teams.explore.capacity > 0"
                   class="text-sm text-white font-semibold bg-white/20 backdrop-blur-sm rounded-lg px-3 py-1">
                <span class="font-bold text-lg">{{ scheduleInfo.teams.explore.registered }}</span>
                von <span class="font-bold">{{ scheduleInfo.teams.explore.capacity }}</span> Plätzen
              </div>
            </div>
          </div>
          <div v-if="scheduleInfo.teams.explore.capacity > 0"
               class="w-full bg-gray-200 rounded-full h-4 mb-6 shadow-inner">
            <div
                class="bg-gradient-to-r from-green-500 to-emerald-600 h-4 rounded-full shadow-lg transition-all duration-500"
                :style="{ width: `${Math.min(100, (scheduleInfo.teams.explore.registered / scheduleInfo.teams.explore.capacity) * 100)}%` }"
            ></div>
          </div>
          <div class="overflow-x-auto rounded-xl border-2 border-green-200 shadow-lg">
            <table class="min-w-full divide-y divide-green-100">
              <thead class="bg-gradient-to-r from-green-500 to-emerald-500">
              <tr>
                <th class="w-16"></th>
                <th class="px-6 py-4 text-left text-xs font-bold text-white uppercase tracking-wider">Team-Name</th>
                <th class="px-6 py-4 text-left text-xs font-bold text-white uppercase tracking-wider">Institution</th>
                <th class="px-6 py-4 text-left text-xs font-bold text-white uppercase tracking-wider">Ort</th>
              </tr>
              </thead>
              <tbody class="bg-white divide-y divide-green-50">
              <tr v-for="team in scheduleInfo.teams.explore.list" :key="team.team_number_hot || team.name"
                  class="hover:bg-green-50 transition-colors">
                <td class="w-16 px-2 py-4 whitespace-nowrap text-sm font-bold text-green-700 text-right">
                  {{ team.team_number_hot || '-' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                  {{ team.name }}
                </td>
                <td class="px-6 py-4 text-sm text-gray-700">
                  {{ team.organization || '-' }}
                </td>
                <td class="px-6 py-4 text-sm text-gray-700">
                  {{ team.location || '-' }}
                </td>
              </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Challenge Teams -->
        <div v-if="scheduleInfo.teams.challenge.list?.length">
          <div class="bg-gradient-to-r from-red-400 to-pink-500 rounded-xl p-4 mb-5 shadow-lg">
            <div class="flex items-center justify-between">
              <h3 class="font-bold text-white text-lg flex items-center gap-2">
                <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-8 h-8 drop-shadow-lg"/>
                FIRST LEGO League Challenge
              </h3>
              <div v-if="scheduleInfo.teams.challenge.capacity > 0"
                   class="text-sm text-white font-semibold bg-white/20 backdrop-blur-sm rounded-lg px-3 py-1">
                <span class="font-bold text-lg">{{ scheduleInfo.teams.challenge.registered }}</span>
                von <span class="font-bold">{{ scheduleInfo.teams.challenge.capacity }}</span> Plätzen
              </div>
            </div>
          </div>
          <div v-if="scheduleInfo.teams.challenge.capacity > 0"
               class="w-full bg-gray-200 rounded-full h-4 mb-6 shadow-inner">
            <div
                class="bg-gradient-to-r from-red-500 to-pink-600 h-4 rounded-full shadow-lg transition-all duration-500"
                :style="{ width: `${Math.min(100, (scheduleInfo.teams.challenge.registered / scheduleInfo.teams.challenge.capacity) * 100)}%` }"
            ></div>
          </div>
          <div class="overflow-x-auto rounded-xl border-2 border-red-200 shadow-lg">
            <table class="min-w-full divide-y divide-red-100">
              <thead class="bg-gradient-to-r from-red-500 to-pink-500">
              <tr>
                <th class="w-16"></th>
                <th class="px-6 py-4 text-left text-xs font-bold text-white uppercase tracking-wider">Team-Name</th>
                <th class="px-6 py-4 text-left text-xs font-bold text-white uppercase tracking-wider">Institution</th>
                <th class="px-6 py-4 text-left text-xs font-bold text-white uppercase tracking-wider">Ort</th>
              </tr>
              </thead>
              <tbody class="bg-white divide-y divide-red-50">
              <tr v-for="team in scheduleInfo.teams.challenge.list" :key="team.team_number_hot || team.name"
                  class="hover:bg-red-50 transition-colors">
                <td class="w-16 px-2 py-4 whitespace-nowrap text-sm font-bold text-red-700 text-right">
                  {{ team.team_number_hot || '-' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                  {{ team.name }}
                </td>
                <td class="px-6 py-4 text-sm text-gray-700">
                  {{ team.organization || '-' }}
                </td>
                <td class="px-6 py-4 text-sm text-gray-700">
                  {{ team.location || '-' }}
                </td>
              </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>

    <!-- QR Code Modal -->
    <div
        v-if="showQRCode && mapCoordinates"
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
        @click="showQRCode = false"
    >
      <div class="bg-white rounded-lg p-6 max-w-md mx-4" @click.stop>
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-semibold text-gray-900">QR-Code für Standort</h3>
          <button
              @click="showQRCode = false"
              class="text-gray-400 hover:text-gray-600 transition-colors"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        <div class="flex flex-col items-center gap-4">
          <div class="bg-white p-4 rounded-lg min-h-[256px] min-w-[256px] flex items-center justify-center">
            <canvas ref="qrCodeRef" class="max-w-full max-h-full"></canvas>
            <div v-if="!mapCoordinates" class="text-gray-400 text-sm absolute">Lade Standort...</div>
          </div>
          <p class="text-sm text-gray-600 text-center">
            Scannen Sie den QR-Code, um den Standort in Google Maps zu öffnen
          </p>
        </div>
      </div>
    </div>

    <!-- Copy Success Toast -->
    <div
        v-if="copySuccessMessage"
        class="fixed bottom-4 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg z-50 flex items-center gap-2"
    >
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
      </svg>
      <span>{{ copySuccessMessage }}</span>
    </div>

    <!-- Event Logos Footer - at the very bottom -->
    <div class="bg-[#F78B1F] py-8 mt-12 shadow-2xl">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Event Logos -->
        <div v-if="eventLogos.length > 0" class="flex flex-wrap items-center justify-center gap-8 mb-8">
          <a
              v-for="logo in eventLogos"
              :key="logo.id"
              :href="logo.link || '#'"
              :target="logo.link ? '_blank' : '_self'"
              :rel="logo.link ? 'noopener noreferrer' : ''"
              class="flex items-center justify-center bg-white rounded-xl p-4 shadow-lg hover:shadow-xl hover:scale-110 transition-all transform"
          >
            <img
                :src="logo.url"
                :alt="logo.title || 'Logo'"
                class="h-14 max-w-36 object-contain"
            />
          </a>
        </div>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, watch, onMounted, onBeforeUnmount, nextTick, Teleport } from 'vue'
import axios from 'axios'
import QRCode from 'qrcode'

const props = defineProps({
  address: {
    type: String,
    required: true
  },
  eventId: {
    type: Number,
    required: true
  },
  eventName: {
    type: String,
    default: 'Veranstaltungsort'
  },
  showQRCode: {
    type: Boolean,
    default: false
  }
})

const mapCoordinates = ref(null)
const mapInstance = ref(null)
const showMapMenu = ref(false)
const showQRCodeModal = ref(false)
const copySuccessMessage = ref('')
const qrCodeRef = ref(null)
const mapMenuButton = ref(null)
const menuPosition = ref({top: '0px', left: '0px'})
const usedAddress = ref(null)
const fullAddress = ref(null)

// Check if device is Apple (iOS/macOS)
const isAppleDevice = ref(/iPad|iPhone|iPod|Macintosh/.test(navigator.userAgent))

// Check if Web Share API is available
const canShare = ref('share' in navigator)

// Copy success timeout
let copySuccessTimeout = null

// Extract German address format (street + PLZ + city)
// German addresses have format:
// - First line: always the street
// - Optional second and third lines
// - Then: PLZ and city
const extractGermanAddress = (address) => {
  if (!address) return null
  
  // Split by newlines
  const lines = address.split('\n').map(line => line.trim()).filter(line => line.length > 0)
  
  if (lines.length === 0) return null
  
  // First line is always the street
  const street = lines[0]
  
  // PLZ is typically 5 digits, followed by city name
  const plzPattern = /\b\d{5}\b/
  
  // Find the line containing PLZ (usually the last line, but could be second-to-last)
  // Search from the end backwards
  let plzCity = null
  for (let i = lines.length - 1; i >= 1; i--) {
    if (plzPattern.test(lines[i])) {
      plzCity = lines[i]
      break
    }
  }
  
  // If we found PLZ+City, combine with street
  if (plzCity) {
    return `${street}, ${plzCity}`
  }
  
  // Fallback: if no PLZ found but we have at least 2 lines, use first and last
  if (lines.length >= 2) {
    return `${street}, ${lines[lines.length - 1]}`
  }
  
  // Last resort: return just the street
  return street
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

  // Store full address
  fullAddress.value = address

  // Try geocoding with full address first
  let coords = await geocodeAddress(address)
  let addressUsed = address

  // If full address fails, try with stripped German address
  if (!coords) {
    const strippedAddress = extractGermanAddress(address)
    if (strippedAddress && strippedAddress !== address) {
      console.log('Full address failed, trying stripped address:', strippedAddress)
      coords = await geocodeAddress(strippedAddress)
      if (coords) {
        addressUsed = strippedAddress
      }
    }
  }

  if (!coords) {
    console.error('Failed to geocode address after retries')
    return
  }

  // Track which address was used
  usedAddress.value = addressUsed
  mapCoordinates.value = coords

  // Wait for DOM to be ready
  await new Promise(resolve => setTimeout(resolve, 100))

  const mapId = `map-${props.eventId}`
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

  // Add marker with the address that was actually used
  window.L.marker([coords.lat, coords.lon])
      .addTo(map)
      .bindPopup(fullAddress.value) // Show full address in popup
      .openPopup()
}

// Watch for address changes to initialize map
watch(() => props.address, async (newAddress) => {
  if (newAddress && props.eventId) {
    await nextTick()
    // Wait a bit for DOM to render
    setTimeout(() => {
      initializeMap(newAddress)
    }, 300)
  }
}, {immediate: true})

onMounted(() => {
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
  if (!props.address) return
  try {
    await navigator.clipboard.writeText(props.address)
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
  if (!mapCoordinates.value || !props.address) return

  const shareData = {
    title: props.eventName,
    text: props.address,
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
    const menuElement = document.querySelector(`[data-map-menu="${props.eventId}"]`)
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
watch(showQRCodeModal, async (newVal) => {
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
  <div v-if="address" class="space-y-3">
    <!-- Show full address above map if using stripped address -->
    <div v-if="mapCoordinates && usedAddress && fullAddress && usedAddress !== fullAddress"
         class="text-sm text-gray-700 whitespace-pre-line bg-white rounded-lg p-3 border border-gray-200">
      {{ fullAddress }}
    </div>
    <!-- Show address while loading or if map failed -->
    <div v-else-if="!mapCoordinates && address"
         class="text-sm text-gray-700 whitespace-pre-line bg-white rounded-lg p-3 border border-gray-200">
      {{ address }}
    </div>
    
    <!-- OpenStreetMap Map -->
    <div class="rounded-lg overflow-hidden border-2 border-gray-300 shadow-lg relative"
         style="height: 250px; min-height: 250px;">
      <div v-if="!mapCoordinates" class="w-full h-full flex items-center justify-center bg-gray-100">
        <p class="text-gray-500 text-sm">Karte wird geladen...</p>
      </div>
      <div v-else :id="'map-' + eventId" class="w-full h-full"></div>

      <!-- Map Options Menu Button (inside map container, upper right corner) -->
      <div v-if="mapCoordinates" class="absolute top-2 right-2 z-[1000]">
        <div class="relative">
          <button
              ref="mapMenuButton"
              class="bg-white hover:bg-gray-50 rounded-lg shadow-lg p-2 border border-gray-200 flex items-center gap-2 transition-colors"
              title="Karten-Optionen"
              @click="showMapMenu = !showMapMenu"
          >
            <svg class="w-5 h-5 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                  d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"
                  stroke-linecap="round" stroke-linejoin="round"
                  stroke-width="2"/>
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Dropdown Menu (outside map container to prevent clipping, positioned relative to button) -->
    <Teleport to="body">
      <div
          v-if="showMapMenu && mapCoordinates && mapMenuButton"
          :style="getMenuPosition()"
          class="fixed w-64 bg-white rounded-lg shadow-xl border border-gray-200 overflow-hidden z-[9999]"
          :data-map-menu="eventId"
      >
        <div class="py-1">
          <!-- Open in Google Maps -->
          <button
              class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
              @click="openInGoogleMaps"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
              <path
                  d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
            </svg>
            <span>In Google Maps öffnen</span>
          </button>

          <!-- Open in Apple Maps -->
          <button
              v-if="isAppleDevice"
              class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
              @click="openInAppleMaps"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
              <path
                  d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
            </svg>
            <span>In Apple Maps öffnen</span>
          </button>

          <!-- Open in OpenStreetMap -->
          <button
              class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
              @click="openInOpenStreetMap"
          >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
              <path
                  d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
            </svg>
            <span>Auf OpenStreetMap öffnen</span>
          </button>

          <!-- Divider -->
          <div class="border-t border-gray-200 my-1"></div>

          <!-- Copy Coordinates -->
          <button
              class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
              @click="copyCoordinates"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                  d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
                  stroke-linecap="round" stroke-linejoin="round"
                  stroke-width="2"/>
            </svg>
            <span>Koordinaten kopieren</span>
          </button>

          <!-- Copy Address -->
          <button
              class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
              @click="copyAddress"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                  d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
                  stroke-linecap="round" stroke-linejoin="round"
                  stroke-width="2"/>
            </svg>
            <span>Adresse kopieren</span>
          </button>

          <!-- Share Location -->
          <button
              v-if="canShare"
              class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
              @click="shareLocation"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                  d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"
                  stroke-linecap="round" stroke-linejoin="round"
                  stroke-width="2"/>
            </svg>
            <span>Teilen</span>
          </button>

          <!-- QR Code (only if enabled) -->
          <button
              v-if="showQRCode"
              class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 flex items-center gap-2 transition-colors"
              @click="showMapMenu = false; showQRCodeModal = true"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                  d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"
                  stroke-linecap="round" stroke-linejoin="round"
                  stroke-width="2"/>
            </svg>
            <span>QR-Code anzeigen</span>
          </button>
        </div>
      </div>
    </Teleport>

    <!-- QR Code Modal (only if QR code is enabled) -->
    <Teleport to="body">
      <div
          v-if="showQRCodeModal && mapCoordinates && showQRCode"
          class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
          @click="showQRCodeModal = false"
      >
        <div class="bg-white rounded-lg md:rounded-xl p-4 md:p-6 max-w-md w-full mx-4" @click.stop>
          <div class="flex justify-between items-center mb-3 md:mb-4">
            <h3 class="text-base md:text-lg font-semibold text-gray-900">QR-Code für Standort</h3>
            <button
                class="text-gray-400 hover:text-gray-600 transition-colors"
                @click="showQRCodeModal = false"
            >
              <svg class="w-5 h-5 md:w-6 md:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
              </svg>
            </button>
          </div>
          <div class="flex flex-col items-center gap-3 md:gap-4">
            <div
                class="bg-white p-3 md:p-4 rounded-lg min-h-[200px] md:min-h-[256px] min-w-[200px] md:min-w-[256px] flex items-center justify-center">
              <canvas ref="qrCodeRef" class="max-w-full max-h-full"></canvas>
              <div v-if="!mapCoordinates" class="text-gray-400 text-xs md:text-sm absolute">Lade Standort...</div>
            </div>
            <p class="text-xs md:text-sm text-gray-600 text-center">
              Scannen Sie den QR-Code, um den Standort in Google Maps zu öffnen
            </p>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Copy Success Toast -->
    <Teleport to="body">
      <div
          v-if="copySuccessMessage"
          class="fixed bottom-4 right-4 bg-green-500 text-white px-3 md:px-4 py-2 rounded-lg shadow-lg z-50 flex items-center gap-2 text-sm md:text-base max-w-[calc(100vw-2rem)]"
      >
        <svg class="w-4 h-4 md:w-5 md:h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
        </svg>
        <span class="truncate">{{ copySuccessMessage }}</span>
      </div>
    </Teleport>
  </div>
</template>

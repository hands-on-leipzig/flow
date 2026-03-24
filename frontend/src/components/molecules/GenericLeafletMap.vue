<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  markers: {
    type: Array,
    default: () => []
  },
  zoom: {
    type: Number,
    default: 15
  },
  height: {
    type: String,
    default: '250px'
  },
  minHeight: {
    type: String,
    default: '250px'
  },
  isLoading: {
    type: Boolean,
    default: false
  },
  tileLayerUrl: {
    type: String,
    default: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
  },
  tileAttribution: {
    type: String,
    default: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  },
  maxZoom: {
    type: Number,
    default: 19
  }
})

const emit = defineEmits(['map-ready', 'map-error'])

const containerRef = ref(null)
const mapInstance = ref(null)
const markerLayer = ref(null)

const mapStyle = computed(() => ({
  height: props.height,
  minHeight: props.minHeight
}))

const parseCoord = (value) => {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

const validMarkers = computed(() => {
  return props.markers
    .map((marker) => {
      const lat = parseCoord(marker?.lat)
      const lon = parseCoord(marker?.lon)

      if (lat === null || lon === null) {
        return null;
      }

      return {
        ...marker,
        lat,
        lon
      }
    })
    .filter(Boolean)
})

const loadLeaflet = async () => {
  if (typeof window === 'undefined') {
    return null
  }

  if (window.L) {
    return window.L
  }

  if (!window.__leafletCssLoaded) {
    const link = document.createElement('link')
    link.rel = 'stylesheet'
    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'
    document.head.appendChild(link)
    window.__leafletCssLoaded = true
  }

  if (!window.__leafletLoadPromise) {
    window.__leafletLoadPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script')
      script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
      script.onload = () => resolve(window.L)
      script.onerror = reject
      document.body.appendChild(script)
    })
  }

  return window.__leafletLoadPromise
}

const clearMarkers = () => {
  if (markerLayer.value && mapInstance.value) {
    markerLayer.value.clearLayers()
  }
}

const renderMarkers = (L) => {
  if (!mapInstance.value || !markerLayer.value) {
    return
  }

  clearMarkers()

  if (validMarkers.value.length === 0) {
    return
  }

  const bounds = []

  validMarkers.value.forEach((marker) => {
    const leafletMarker = L.marker([marker.lat, marker.lon]).addTo(markerLayer.value)
    if (marker.popup) {
      leafletMarker.bindPopup(marker.popup)
    }
    bounds.push([marker.lat, marker.lon])
  })

  if (bounds.length === 1) {
    mapInstance.value.setView(bounds[0], props.zoom)
    const marker = markerLayer.value.getLayers()[0]
    if (marker) {
      marker.openPopup()
    }
    return
  }

  mapInstance.value.fitBounds(bounds, { padding: [20, 20] })
}

const ensureMap = async () => {
  if (!containerRef.value || mapInstance.value) {
    return
  }

  try {
    const L = await loadLeaflet()
    if (!L || !containerRef.value) {
      return
    }

    mapInstance.value = L.map(containerRef.value).setView([0, 0], 2)
    L.tileLayer(props.tileLayerUrl, {
      attribution: props.tileAttribution,
      maxZoom: props.maxZoom
    }).addTo(mapInstance.value)

    markerLayer.value = L.layerGroup().addTo(mapInstance.value)
    renderMarkers(L)
    emit('map-ready', mapInstance.value)
  } catch (error) {
    emit('map-error', error)
  }
}

watch(validMarkers, async () => {
  if (!mapInstance.value) {
    await ensureMap()
    return
  }

  const L = window.L
  if (L) {
    renderMarkers(L)
  }
}, { deep: true })

onMounted(async () => {
  await ensureMap()
})

onBeforeUnmount(() => {
  if (mapInstance.value) {
    mapInstance.value.remove()
    mapInstance.value = null
  }
})
</script>

<template>
  <div class="w-full h-full relative" :style="mapStyle">
    <div ref="containerRef" class="w-full h-full"></div>

    <div v-if="isLoading || validMarkers.length === 0" class="absolute inset-0 flex items-center justify-center bg-gray-100">
      <p class="text-gray-500 text-sm">Karte wird geladen...</p>
    </div>
  </div>
</template>


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
  },
  staticMap: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['map-ready', 'map-error'])

const containerRef = ref(null)
const mapInstance = ref(null)
const markerLayer = ref(null)
let resizeObserver = null
let resizeRaf = null

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

const venuePinIcon = (L) => L.divIcon({
  className: 'venue-pin',
  html: '<svg class="venue-pin__shape" viewBox="0 0 24 36" width="22" height="32" aria-hidden="true"><path d="M12 0C5.4 0 0 5.4 0 12c0 9 12 24 12 24s12-15 12-24C24 5.4 18.6 0 12 0z" fill="#111"/><circle cx="12" cy="11" r="4.5" fill="#fff"/></svg>',
  iconSize: [22, 32],
  iconAnchor: [11, 32],
  tooltipAnchor: [0, -2],
})

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
    const leafletMarker = L.marker([marker.lat, marker.lon], marker.venue ? {icon: venuePinIcon(L)} : undefined).addTo(markerLayer.value)
    if (marker.label) {
      leafletMarker.bindTooltip(marker.label, {
        permanent: true,
        direction: 'top',
        offset: [0, -2],
        className: 'map-pin-label',
        opacity: 1,
      })
    } else if (marker.popup) {
      leafletMarker.bindPopup(marker.popup)
    }
    bounds.push([marker.lat, marker.lon])
  })

  if (bounds.length === 1) {
    mapInstance.value.setView(bounds[0], props.zoom)
    const marker = markerLayer.value.getLayers()[0]
    if (marker && !validMarkers.value[0]?.label) {
      marker.openPopup()
    }
    return
  }

  const hasLabel = validMarkers.value.some((marker) => marker.label)
  mapInstance.value.fitBounds(bounds, {padding: hasLabel ? [40, 28] : [20, 20]})
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

    mapInstance.value = L.map(containerRef.value, {
      zoomControl: !props.staticMap,
      attributionControl: !props.staticMap,
      dragging: !props.staticMap,
      touchZoom: !props.staticMap,
      scrollWheelZoom: !props.staticMap,
      doubleClickZoom: !props.staticMap,
      boxZoom: !props.staticMap,
      keyboard: !props.staticMap,
      zoomAnimation: !props.staticMap,
      fadeAnimation: !props.staticMap,
      markerZoomAnimation: !props.staticMap
    }).setView([0, 0], 2)
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

const rerenderMap = async () => {
  if (!mapInstance.value) {
    await ensureMap()
  }

  if (!mapInstance.value) {
    return
  }

  mapInstance.value.invalidateSize()

  const L = window.L
  if (L) {
    renderMarkers(L)
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

  if (typeof ResizeObserver !== 'undefined' && containerRef.value) {
    resizeObserver = new ResizeObserver((entries) => {
      const entry = entries[0]
      if (!entry) {
        return
      }

      const { width, height } = entry.contentRect
      if (width <= 0 || height <= 0) {
        return
      }

      if (resizeRaf) {
        cancelAnimationFrame(resizeRaf)
      }

      resizeRaf = requestAnimationFrame(() => {
        rerenderMap()
      })
    })

    resizeObserver.observe(containerRef.value)
  }
})

onBeforeUnmount(() => {
  if (resizeObserver) {
    resizeObserver.disconnect()
    resizeObserver = null
  }

  if (resizeRaf) {
    cancelAnimationFrame(resizeRaf)
    resizeRaf = null
  }

  if (mapInstance.value) {
    mapInstance.value.remove()
    mapInstance.value = null
  }
})
</script>

<template>
  <div class="w-full h-full relative" :style="mapStyle">
    <div ref="containerRef" class="w-full h-full" :class="{ 'pointer-events-none': staticMap }"></div>

    <div v-if="isLoading || validMarkers.length === 0" class="absolute inset-0 flex items-center justify-center bg-[var(--color-bg-muted)]">
      <p class="text-[var(--color-text-subtle)] text-sm">Karte wird geladen…</p>
    </div>
  </div>
</template>

<style>
.leaflet-div-icon.venue-pin {
  background: transparent;
  border: none;
}

.venue-pin__shape {
  display: block;
  filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.45));
}

.leaflet-tooltip.map-pin-label {
  width: 0;
  height: 0;
  margin: 0;
  padding: 0;
  border: none;
  background: transparent;
  box-shadow: none;
  overflow: visible;
  line-height: 0;
}

.leaflet-tooltip.map-pin-label.leaflet-tooltip-top {
  margin-top: 0;
}

.leaflet-tooltip.map-pin-label::before {
  content: none;
}

.map-pin-label__box {
  position: absolute;
  left: 0;
  bottom: 0;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.15rem;
  width: max-content;
  background: #fff;
  border-radius: 4px;
  box-shadow: 0 1px 4px rgba(15, 23, 42, 0.28);
  color: #222;
  font-size: 0.85rem;
  font-weight: 600;
  line-height: 1.25;
  padding: 0.2rem 0.4rem;
  white-space: nowrap;
}

.map-pin-label__box::after {
  content: "";
  position: absolute;
  left: 50%;
  bottom: 0;
  margin-left: -6px;
  margin-bottom: -12px;
  border: 6px solid transparent;
  border-top-color: #fff;
}
</style>


<script setup>
import {ref, onMounted, computed} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import {usePlanCacheStore} from '@/stores/planCache'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import IconDangerButton from '@/components/atoms/IconDangerButton.vue'
import ItemCard from '@/components/molecules/ItemCard.vue'
import ToggleSwitch from '@/components/atoms/ToggleSwitch.vue'
import {showGlassToast} from '@/composables/useGlassToast'

defineOptions({name: 'Logos'})

const logos = ref([])
const eventStore = useEventStore()
const planCache = usePlanCacheStore()
const selectedEvent = computed(() => eventStore.selectedEvent)
const uploadFile = ref(null)
const fileInput = ref(null)
const selectedLogoForPreview = ref(null)
const logoToDelete = ref(null)
const isUploading = ref(false)

// Drag and drop state
const draggedLogo = ref(null)
const draggedOverLogo = ref(null)
const dropPosition = ref(null) // 'before' or 'after'
const isDragging = ref(false)

const fetchLogos = async ({force = false} = {}) => {
  if (force) planCache.invalidateLogos()
  logos.value = await planCache.getLogos()
}

const uploadLogo = async () => {
  if (!uploadFile.value) return

  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent?.regional_partner) {
    showGlassToast('Bitte wähle zuerst ein Event aus, bevor du ein Logo hochlädst.', 'info')
    return
  }

  // Validate file
  if (uploadFile.value.size > 2 * 1024 * 1024) {
    showGlassToast('Datei ist zu groß. Maximum: 2MB', 'error')
    return
  }

  if (!uploadFile.value.type.startsWith('image/') && !/\.svg$/i.test(uploadFile.value.name)) {
    showGlassToast('Datei muss ein Bild sein', 'error')
    return
  }

  isUploading.value = true

  try {
    const formData = new FormData()
    formData.append('file', uploadFile.value)
    formData.append('regional_partner', currentEvent.regional_partner)

    const response = await axios.post('/logos', formData)
    const uploadedLogo = response.data

    await fetchLogos({force: true})

    // Automatically toggle the uploaded logo on for the current event
    if (currentEvent?.id && uploadedLogo?.id) {
      try {
        await axios.post(`/logos/${uploadedLogo.id}/toggle-event`, {
          event_id: currentEvent.id
        })
        await fetchLogos({force: true}) // Refresh to update the toggle state
      } catch (toggleError) {
        console.error('Error toggling logo after upload:', toggleError)
        // Don't fail the whole operation if toggle fails
      }
    }

    // Clear the file input after successful upload
    uploadFile.value = null
    if (fileInput.value) {
      fileInput.value.value = ''
    }
  } catch (error) {
    console.error('Error uploading logo:', error)
    if (error.response?.status === 422) {
      showGlassToast('Validierungsfehler: ' + JSON.stringify(error.response.data, null, 2), 'error')
    } else {
      showGlassToast('Fehler beim Hochladen: ' + error.message, 'error')
    }
  } finally {
    isUploading.value = false
  }
}

const updateLogo = async (logo) => {
  try {
    // Normalize link to always start with https://
    let normalizedLink = logo.link
    if (normalizedLink && normalizedLink.trim()) {
      normalizedLink = normalizedLink.trim()
      // If it doesn't start with http:// or https://, prepend https://
      if (!normalizedLink.match(/^https?:\/\//i)) {
        normalizedLink = 'https://' + normalizedLink
      }
      // Update the logo object to reflect the normalized link
      logo.link = normalizedLink
    }

    await axios.patch(`/logos/${logo.id}`, {
      title: logo.title,
      link: normalizedLink
    })
  } catch (error) {
    console.error('Error updating logo:', error)
  }
}

const toggleEventLogo = async (logo) => {
  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent?.id) {
    console.error('No event selected')
    return
  }

  try {
    await axios.post(`/logos/${logo.id}/toggle-event`, {
      event_id: currentEvent.id
    })
    await fetchLogos({force: true})
  } catch (error) {
    console.error('Error toggling logo event:', error)
  }
}

const confirmDeleteLogo = (logo) => {
  logoToDelete.value = logo
}

const cancelDeleteLogo = () => {
  logoToDelete.value = null
}

const deleteLogoMessage = computed(() => {
  if (!logoToDelete.value) return ''
  const name = (logoToDelete.value.title || '').trim() || 'Unbenannt'
  return `„${name}“ wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

const currentEventId = computed(() => selectedEvent.value?.id || eventStore.selectedEvent?.id || null)

function isLogoOnEvent(logo) {
  const eventId = currentEventId.value
  if (!eventId) return false
  return (logo.events || []).some(e => e.id === eventId)
}

async function commitUpload(file) {
  if (!file || isUploading.value) return
  uploadFile.value = file
  await uploadLogo()
  if (fileInput.value) fileInput.value.value = ''
}

function openComposerPicker() {
  if (isUploading.value) return
  fileInput.value?.click()
}

const deleteLogo = async () => {
  if (!logoToDelete.value) return

  try {
    await axios.delete(`/logos/${logoToDelete.value.id}`)
    await fetchLogos({force: true})
    logoToDelete.value = null
  } catch (error) {
    console.error('Error deleting logo:', error)
    // Use translated error message from backend
    const errorMessage = error.response?.data?.message || 'Ein Fehler ist aufgetreten.'
    const errorDetails = error.response?.data?.details || null

    if (errorDetails) {
      showGlassToast(`${errorMessage}\n\n${errorDetails}`, 'error')
    } else {
      showGlassToast(errorMessage, 'error')
    }
    // Keep modal open on error so user can try again or cancel
  }
}

const clearDragState = () => {
  draggedLogo.value = null
  draggedOverLogo.value = null
  dropPosition.value = null
  isDragging.value = false
}

// Drag and drop methods
const handleDragStart = (event, logo) => {
  // Prevent dragging if starting from an interactive element
  if (event.target.closest('input, button, label, img')) {
    event.preventDefault()
    return false
  }

  draggedLogo.value = logo
  isDragging.value = true
  event.dataTransfer.effectAllowed = 'move'
  event.dataTransfer.setData('text/plain', logo.id.toString())
  event.dataTransfer.setData('application/json', JSON.stringify({logoId: logo.id}))

  const elem = event.currentTarget
  elem.style.opacity = '0.5'
  elem.style.transform = 'rotate(5deg) scale(1.05)'

  return true
}

const handleDragEnd = (event) => {
  if (event.currentTarget?.style) {
    event.currentTarget.style.opacity = ''
    event.currentTarget.style.transform = ''
  }
  clearDragState()
}

const handleDragOver = (event) => {
  event.preventDefault()
  event.dataTransfer.dropEffect = 'move'
}

const handleDragEnter = (event, logo) => {
  event.preventDefault()
  draggedOverLogo.value = logo

  // Determine drop position based on mouse position
  const rect = event.currentTarget.getBoundingClientRect()
  const mouseY = event.clientY
  const centerY = rect.top + rect.height / 2

  dropPosition.value = mouseY < centerY ? 'before' : 'after'
}

const handleDragLeave = (event) => {
  // Only clear if we're actually leaving the element (not just moving to a child)
  if (!event.currentTarget.contains(event.relatedTarget)) {
    draggedOverLogo.value = null
    dropPosition.value = null
  }
}

/** Apply sort_order locally so the list updates before the API round-trip. */
const applyLocalSortOrder = (orderedLogos, eventId) => {
  const orderById = new Map(orderedLogos.map((logo, index) => [logo.id, index]))
  logos.value = logos.value.map((logo) => {
    const nextOrder = orderById.get(logo.id)
    if (nextOrder === undefined) return logo
    return {
      ...logo,
      events: logo.events.map((event) => {
        if (event.id !== eventId) return event
        return {
          ...event,
          pivot: {
            ...(event.pivot || {}),
            sort_order: nextOrder,
          },
        }
      }),
    }
  })
}

const handleDrop = async (event, targetLogo) => {
  event.preventDefault()
  event.stopPropagation()

  if (!draggedLogo.value || !targetLogo || draggedLogo.value.id === targetLogo.id) {
    return
  }

  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent) {
    showGlassToast('Bitte wähle zuerst ein Event aus.', 'info')
    return
  }

  const assignedLogos = sortedLogos.value.filter(logo =>
      logo.events.some(e => e.id === currentEvent.id)
  )

  const draggedIndex = assignedLogos.findIndex(logo => logo.id === draggedLogo.value.id)
  const targetIndex = assignedLogos.findIndex(logo => logo.id === targetLogo.id)
  const position = dropPosition.value

  if (draggedIndex === -1 || targetIndex === -1 || draggedIndex === targetIndex) {
    return
  }

  const newOrder = [...assignedLogos]
  const [draggedItem] = newOrder.splice(draggedIndex, 1)

  let insertIndex
  if (draggedIndex < targetIndex) {
    const adjustedTargetIndex = targetIndex - 1
    // Same insert for before/after when moving forward (legacy behaviour)
    insertIndex = adjustedTargetIndex + 1
  } else if (position === 'after') {
    insertIndex = targetIndex + 1
  } else {
    insertIndex = targetIndex
  }

  insertIndex = Math.max(0, Math.min(insertIndex, newOrder.length))
  newOrder.splice(insertIndex, 0, draggedItem)

  // Optimistic UI: reorder immediately, then persist
  applyLocalSortOrder(newOrder, currentEvent.id)
  clearDragState()
  planCache.invalidateLogos()

  const logoOrders = newOrder.map((logo, index) => ({
    logo_id: logo.id,
    sort_order: index,
  }))

  try {
    await axios.post('/logos/update-sort-order', {
      event_id: currentEvent.id,
      logo_orders: logoOrders,
    })
  } catch (error) {
    console.error('Error updating logo order:', error)
    showGlassToast('Fehler beim Aktualisieren der Reihenfolge: ' + error.message, 'error')
    await fetchLogos({force: true})
  }
}

const handleFileChange = (e) => {
  const file = e.target.files?.[0]
  if (file) void commitUpload(file)
}

const handleComposerDrop = (e) => {
  e.preventDefault()
  if (isUploading.value) return
  const file = e.dataTransfer?.files?.[0]
  if (file) void commitUpload(file)
}

const openLogoPreview = (logo) => {
  selectedLogoForPreview.value = logo
}

const closeLogoPreview = () => {
  selectedLogoForPreview.value = null
}


// Sort logos by their sort_order for the current event
const sortedLogos = computed(() => {
  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent) {
    return logos.value
  }

  return [...logos.value].sort((a, b) => {
    const aEvent = a.events.find(e => e.id === currentEvent.id)
    const bEvent = b.events.find(e => e.id === currentEvent.id)

    // If both logos are assigned to the current event, sort by sort_order
    if (aEvent && bEvent) {
      const aOrder = aEvent.pivot?.sort_order || 0
      const bOrder = bEvent.pivot?.sort_order || 0
      return aOrder - bOrder
    }

    // If only one is assigned, put assigned ones first
    if (aEvent && !bEvent) return -1
    if (!aEvent && bEvent) return 1

    // If neither is assigned, maintain original order
    return 0
  })
})

// Computed property to determine which logos should move to make space
const logosWithSpaceMaking = computed(() => {
  if (!isDragging.value || !draggedOverLogo.value || !dropPosition.value) {
    return sortedLogos.value
  }

  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent) return sortedLogos.value

  const assignedLogos = sortedLogos.value.filter(logo =>
      logo.events.some(e => e.id === currentEvent.id)
  )

  const targetIndex = assignedLogos.findIndex(logo => logo.id === draggedOverLogo.value.id)
  if (targetIndex === -1) return sortedLogos.value

  // Create a visual representation where logos move to make space
  const result = [...sortedLogos.value]

  if (dropPosition.value === 'before') {
    // Move logos to the right to make space before the target
    for (let i = 0; i < targetIndex; i++) {
      const logo = result.find(l => l.id === assignedLogos[i].id)
      if (logo) {
        logo._spaceMakingOffset = 'translateX(20px)'
      }
    }
  } else {
    // Move logos to the left to make space after the target
    for (let i = targetIndex + 1; i < assignedLogos.length; i++) {
      const logo = result.find(l => l.id === assignedLogos[i].id)
      if (logo) {
        logo._spaceMakingOffset = 'translateX(-20px)'
      }
    }
  }

  return result
})


onMounted(async () => {
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  await fetchLogos()
})
</script>

<template>
  <div class="space-y-5">
    <!-- No event selected warning -->
    <div
        v-if="!selectedEvent && !eventStore.selectedEvent"
        class="glass-alert-warning flex items-start gap-2"
    >
      <i class="bi bi-exclamation-triangle-fill mt-0.5" aria-hidden="true"></i>
      <div>
        <div class="text-sm font-semibold">Kein Event ausgewählt</div>
        <p class="text-xs mt-0.5 opacity-90">Bitte wähle zuerst ein Event aus, um Logos hochzuladen.</p>
      </div>
    </div>

    <template v-else>
      <!-- Split Layout: Left (List) and Right (Sortable) -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <!-- Left Side: All Logos List -->
        <div class="glass-card liquid-surface-inner">
          <h2 class="glass-card__heading">Logos verwalten</h2>
          <p class="glass-settings-hint !mb-4">
            Aktivierte Logos erscheinen im öffentlichen Plan.
          </p>

          <div class="space-y-2">
            <div
                class="logo-composer"
                data-logo-composer
                @click="openComposerPicker"
                @dragover.prevent
                @drop="handleComposerDrop"
            >
              <ItemCard dashed>
                <template #title>
                  <span class="logo-composer__title">
                    {{ isUploading ? 'Lade hoch…' : 'Neues Logo' }}
                  </span>
                </template>
                <p class="item-card__hint">
                  PNG, JPG oder SVG · max. 2&nbsp;MB. Datei wählen oder hierher ziehen.
                </p>
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/*,.svg"
                    class="sr-only"
                    :disabled="isUploading"
                    @click.stop
                    @change="handleFileChange"
                />
              </ItemCard>
            </div>

            <ItemCard
                v-for="logo in logos"
                :key="logo.id"
                :inactive="!isLogoOnEvent(logo)"
            >
              <template #leading>
                <ToggleSwitch
                    :model-value="isLogoOnEvent(logo)"
                    :disabled="!currentEventId"
                    @update:modelValue="toggleEventLogo(logo)"
                />
              </template>
              <template #title>
                <input
                    v-model="logo.title"
                    type="text"
                    placeholder="Titel"
                    class="item-card__title glass-input glass-input--sm liquid-surface-control"
                    @change="updateLogo(logo)"
                />
              </template>
              <template #trailing>
                <IconDangerButton label="Logo löschen" @click="confirmDeleteLogo(logo)"/>
              </template>

              <div class="logo-card__body">
                <button
                    type="button"
                    class="logo-card__thumb"
                    title="Vorschau"
                    @click="openLogoPreview(logo)"
                >
                  <img :src="logo.url" alt="" class="h-full w-full object-contain p-1"/>
                </button>
                <input
                    v-model="logo.link"
                    type="url"
                    placeholder="https://domain.tld"
                    class="glass-input glass-input--sm liquid-surface-control w-full min-w-0"
                    @change="updateLogo(logo)"
                />
              </div>
            </ItemCard>
          </div>
        </div>

        <!-- Right Side: Assigned Logos (Sortable) -->
        <div class="glass-card liquid-surface-inner">
          <h2 class="glass-card__heading">Logos in Verwendung</h2>
          <p class="glass-settings-hint !mb-1">
            Ziehe Logos, um die Reihenfolge im öffentlichen Plan zu ändern.
          </p>
          <p class="glass-settings-hint !mb-4">
            Das erste Logo wird für die Namensaufkleber verwendet.
          </p>

          <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
            <div
                v-for="logo in logosWithSpaceMaking.filter(logo => logo.events.some(e => e.id === (selectedEvent?.id || eventStore.selectedEvent?.id)))"
                :key="logo.id"
                class="liquid-surface-inner p-3 transition-all duration-300 ease-out relative rounded-[var(--radius)]"
                :class="{
                  'opacity-50 scale-105 rotate-2': draggedLogo?.id === logo.id,
                  'ring-2 ring-[var(--color-accent)] bg-[var(--color-accent-muted)]': draggedOverLogo?.id === logo.id,
                  'cursor-move': !isDragging,
                  'cursor-grabbing': draggedLogo?.id === logo.id && isDragging
                }"
                :style="{ transform: (logo._spaceMakingOffset ? logo._spaceMakingOffset + ' ' : '') }"
                :draggable="true"
                @dragstart="handleDragStart($event, logo)"
                @dragend="handleDragEnd($event)"
                @dragover.prevent="handleDragOver($event)"
                @dragenter.prevent="handleDragEnter($event, logo)"
                @dragleave="handleDragLeave($event)"
                @drop.prevent="handleDrop($event, logo)"
            >
              <div
                  class="absolute top-2 right-2 text-[var(--color-text-subtle)] text-xs cursor-move select-none leading-none"
                  title="Ziehen zum Sortieren"
              >
                ⋮⋮
              </div>

              <div
                  v-if="isDragging && draggedOverLogo?.id === logo.id"
                  class="absolute inset-0 border-2 border-dashed border-[var(--color-accent)] bg-[var(--color-accent-muted)]/60 rounded-[var(--radius)] flex items-center justify-center logo-drop-pulse"
                  :class="{
                    'border-t-4': dropPosition === 'before',
                    'border-b-4': dropPosition === 'after'
                  }"
              >
                <div class="text-[var(--color-accent)] font-semibold text-sm">
                  {{ dropPosition === 'before' ? '↑ Hier ablegen' : '↓ Hier ablegen' }}
                </div>
              </div>

              <button
                  type="button"
                  class="block w-full mb-2"
                  title="Vorschau"
                  @click.stop="openLogoPreview(logo)"
              >
                <img
                    :src="logo.url"
                    alt=""
                    class="h-14 mx-auto object-contain hover:opacity-80 transition-opacity"
                    draggable="false"
                    @mousedown.stop
                    @dragstart.stop
                />
              </button>

              <div class="space-y-0.5 text-center min-w-0">
                <div v-if="logo.title" class="text-sm font-medium text-[var(--color-text)] truncate">
                  {{ logo.title }}
                </div>
                <div
                    v-if="logo.link"
                    class="text-xs text-[var(--color-accent)] truncate"
                    :title="logo.link"
                >
                  {{ logo.link }}
                </div>
                <div v-if="!logo.title && !logo.link" class="text-xs text-[var(--color-text-subtle)] italic">
                  Kein Titel/Link
                </div>
              </div>

              <div class="flex items-center justify-center pt-2">
                <span class="text-xs font-semibold text-[var(--color-accent)]">Aktiv</span>
              </div>
            </div>
          </div>

          <p
              v-if="sortedLogos.filter(logo => logo.events.some(e => e.id === (selectedEvent?.id || eventStore.selectedEvent?.id))).length === 0"
              class="text-sm text-[var(--color-text-subtle)] italic mt-3"
          >
            Keine Logos in Verwendung. Aktiviere Logos links, um sie hier zu sortieren.
          </p>
        </div>
      </div>
    </template>

    <!-- Logo Preview Modal -->
    <div
        v-if="selectedLogoForPreview"
        class="glass-scrim fixed inset-0 flex items-center justify-center z-50 p-4"
        @click="closeLogoPreview"
    >
      <div class="glass-modal glass-modal-lg max-w-4xl w-full max-h-[90vh] overflow-auto" @click.stop>
        <div class="glass-modal-header flex items-center justify-between gap-3">
          <h3 class="text-lg font-semibold">
            {{ selectedLogoForPreview.title || 'Logo-Vorschau' }}
          </h3>
          <button
              type="button"
              class="text-[var(--color-on-accent)]/80 hover:text-[var(--color-on-accent)] text-2xl leading-none"
              aria-label="Schließen"
              @click="closeLogoPreview"
          >
            ×
          </button>
        </div>

        <div class="flex justify-center p-4">
          <img
              :src="selectedLogoForPreview.url"
              :alt="selectedLogoForPreview.title || 'Logo'"
              class="max-w-full max-h-[70vh] object-contain"
          />
        </div>

        <div v-if="selectedLogoForPreview.link" class="px-4 pb-4 text-center">
          <a
              :href="selectedLogoForPreview.link"
              target="_blank"
              rel="noopener noreferrer"
              class="text-[var(--color-accent)] hover:text-[var(--color-accent-hover)] underline break-all"
          >
            {{ selectedLogoForPreview.link }}
          </a>
        </div>
      </div>
    </div>

    <ConfirmationModal
        :show="!!logoToDelete"
        title="Logo löschen"
        :message="deleteLogoMessage"
        type="danger"
        confirm-text="Löschen"
        cancel-text="Abbrechen"
        @confirm="deleteLogo"
        @cancel="cancelDeleteLogo"
    />
  </div>
</template>

<style scoped>
.logo-composer {
  cursor: pointer;
}

.logo-composer__title {
  display: block;
  width: 100%;
  font-weight: 600;
  cursor: pointer;
}

.logo-card__body {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.logo-card__thumb {
  width: 3rem;
  height: 3rem;
  flex-shrink: 0;
  border-radius: calc(var(--radius) - 2px);
  background: var(--color-bg-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.logo-card__thumb:hover {
  opacity: 0.85;
}

.logo-drop-pulse {
  animation: logo-drop-pulse 1.5s ease-in-out infinite;
}

@keyframes logo-drop-pulse {
  0%, 100% {
    opacity: 0.75;
  }
  50% {
    opacity: 1;
  }
}
</style>

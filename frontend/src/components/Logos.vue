<script setup>
import {ref, onMounted, computed, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'

const logos = ref([])
const eventStore = useEventStore()
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

const fetchLogos = async () => {
  const {data} = await axios.get('/logos')
  logos.value = data
}

const uploadLogo = async () => {
  if (!uploadFile.value) return

  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent?.regional_partner) {
    alert('Bitte wähle zuerst ein Event aus, bevor du ein Logo hochlädst.')
    return
  }

  // Validate file
  if (uploadFile.value.size > 2 * 1024 * 1024) {
    alert('Datei ist zu groß. Maximum: 2MB')
    return
  }

  if (!uploadFile.value.type.startsWith('image/')) {
    alert('Datei muss ein Bild sein')
    return
  }

  isUploading.value = true

  try {
    const formData = new FormData()
    formData.append('file', uploadFile.value)
    formData.append('regional_partner', currentEvent.regional_partner)

    const response = await axios.post('/logos', formData)
    const uploadedLogo = response.data

    await fetchLogos()

    // Automatically toggle the uploaded logo on for the current event
    if (currentEvent?.id && uploadedLogo?.id) {
      try {
        await axios.post(`/logos/${uploadedLogo.id}/toggle-event`, {
          event_id: currentEvent.id
        })
        await fetchLogos() // Refresh to update the toggle state
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
      alert('Validierungsfehler: ' + JSON.stringify(error.response.data, null, 2))
    } else {
      alert('Fehler beim Hochladen: ' + error.message)
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
    await fetchLogos()
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
  return `Logo "${logoToDelete.value.title || 'Unbenannt'}" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

const deleteLogo = async () => {
  if (!logoToDelete.value) return

  try {
    await axios.delete(`/logos/${logoToDelete.value.id}`)
    await fetchLogos()
    logoToDelete.value = null
  } catch (error) {
    console.error('Error deleting logo:', error)
    // Use translated error message from backend
    const errorMessage = error.response?.data?.message || 'Ein Fehler ist aufgetreten.'
    const errorDetails = error.response?.data?.details || null

    if (errorDetails) {
      alert(`${errorMessage}\n\n${errorDetails}`)
    } else {
      alert(errorMessage)
    }
    // Keep modal open on error so user can try again or cancel
  }
}

// Drag and drop methods
const handleDragStart = (event, logo) => {
  console.log('Drag start:', logo.id, event.target, event.currentTarget)

  // Prevent dragging if starting from an interactive element
  if (event.target.closest('input, button, label, img')) {
    console.log('Prevented drag from interactive element')
    event.preventDefault()
    return false
  }

  draggedLogo.value = logo
  isDragging.value = true
  event.dataTransfer.effectAllowed = 'move'
  event.dataTransfer.setData('text/plain', logo.id.toString())
  event.dataTransfer.setData('application/json', JSON.stringify({logoId: logo.id}))

  // Apply visual feedback to the dragged element
  const elem = event.currentTarget
  elem.style.opacity = '0.5'
  elem.style.transform = 'rotate(5deg) scale(1.05)'

  return true
}

const handleDragEnd = (event) => {
  // Reset styles
  if (event.target.style) {
    event.target.style.opacity = ''
    event.target.style.transform = ''
  }
  draggedLogo.value = null
  draggedOverLogo.value = null
  dropPosition.value = null
  isDragging.value = false
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

const handleDrop = async (event, targetLogo) => {
  event.preventDefault()
  event.stopPropagation()

  console.log('Drop:', draggedLogo.value?.id, targetLogo.id, dropPosition.value)

  if (!draggedLogo.value || !targetLogo || draggedLogo.value.id === targetLogo.id) {
    console.log('Drop cancelled: invalid conditions')
    return
  }

  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent) {
    alert('Bitte wähle zuerst ein Event aus.')
    return
  }

  // Get logos assigned to this event, sorted by current sort_order
  const assignedLogos = sortedLogos.value.filter(logo =>
      logo.events.some(e => e.id === currentEvent.id)
  )

  console.log('Assigned logos:', assignedLogos.map(l => l.id))

  // Find the indices of the dragged and target logos in the assigned logos array
  const draggedIndex = assignedLogos.findIndex(logo => logo.id === draggedLogo.value.id)
  const targetIndex = assignedLogos.findIndex(logo => logo.id === targetLogo.id)

  console.log('Indices:', draggedIndex, targetIndex, 'dropPosition:', dropPosition.value)

  if (draggedIndex === -1 || targetIndex === -1) {
    console.log('Drop cancelled: index not found')
    return
  }

  // If dragging to the same position, do nothing
  if (draggedIndex === targetIndex) {
    console.log('Drop cancelled: same position')
    return
  }

  // Remove the dragged item first
  const newOrder = [...assignedLogos]
  const [draggedItem] = newOrder.splice(draggedIndex, 1)

  // Calculate where to insert in the array WITHOUT the dragged item
  let insertIndex

  if (draggedIndex < targetIndex) {
    // Dragging forward: after removal, target index shifted left by 1
    const adjustedTargetIndex = targetIndex - 1
    if (dropPosition.value === 'after') {
      // Insert after the adjusted target position
      insertIndex = adjustedTargetIndex + 1
    } else {
      // Insert before the adjusted target position, but add 1 to account for the offset
      // User expects it at the target position, not before it
      insertIndex = adjustedTargetIndex + 1
    }
  } else {
    // Dragging backward: target index unchanged after removal
    if (dropPosition.value === 'after') {
      insertIndex = targetIndex + 1
    } else {
      insertIndex = targetIndex
    }
  }

  // Ensure insertIndex is valid
  insertIndex = Math.max(0, Math.min(insertIndex, newOrder.length))

  console.log('Target index:', targetIndex, 'After removal adjusted:', targetIndex - 1, 'Drop position:', dropPosition.value, 'Insert index:', insertIndex)

  // Insert at the calculated position
  newOrder.splice(insertIndex, 0, draggedItem)

  console.log('New order:', newOrder.map(l => l.id))

  // Update sort order in database
  try {
    const logoOrders = newOrder.map((logo, index) => ({
      logo_id: logo.id,
      sort_order: index
    }))

    console.log('Saving order:', logoOrders)

    await axios.post('/logos/update-sort-order', {
      event_id: currentEvent.id,
      logo_orders: logoOrders
    })

    // Refresh logos to get updated order
    await fetchLogos()
    console.log('Order saved successfully')
  } catch (error) {
    console.error('Error updating logo order:', error)
    alert('Fehler beim Aktualisieren der Reihenfolge: ' + error.message)
  }
}

const handleFileChange = (e) => {
  const file = e.target.files?.[0]
  if (file) {
    uploadFile.value = file
  }
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
  <div class="space-y-6 p-6">
    <!-- No event selected warning -->
    <div v-if="!selectedEvent && !eventStore.selectedEvent"
         class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
      <div class="flex items-center gap-2">
        <svg class="w-5 h-5 text-yellow-600" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd"
                d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                clip-rule="evenodd"/>
        </svg>
        <span class="text-sm font-medium text-yellow-800">Kein Event ausgewählt</span>
      </div>
      <p class="text-xs text-yellow-700 mt-1">Bitte wähle zuerst ein Event aus, um Logos hochzuladen.</p>
    </div>

    <!-- Upload -->
    <div v-else class="flex items-center space-x-4 mb-6">
      <input type="file" @change="handleFileChange" ref="fileInput" accept="image/*" class="border rounded px-3 py-1"
             :disabled="isUploading"/>
      <button @click="uploadLogo" :disabled="!uploadFile || isUploading"
              class="px-4 py-2 bg-blue-600 text-white rounded disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center gap-2">
        <svg v-if="isUploading" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
        </svg>
        <span>{{ isUploading ? 'Lade hoch...' : 'Upload' }}</span>
      </button>
    </div>

    <!-- Split Layout: Left (List) and Right (Sortable) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Left Side: All Logos List -->
      <div>
        <h2 class="text-xl font-semibold text-gray-900 mb-4">Logos verwalten</h2>
        <p class="text-sm text-gray-600 mb-4">Die hier hochgeladenen Logos müssen aktiviert sein, um im öffentlichen
          Plan zu erscheinen.</p>

        <div class="space-y-2">
          <div
              v-for="logo in logos"
              :key="logo.id"
              class="border rounded p-2 shadow bg-white flex items-center gap-3"
          >
            <!-- Logo image -->
            <img
                :src="logo.url"
                alt="Logo"
                class="h-12 w-12 object-contain cursor-pointer hover:opacity-80 transition-opacity flex-shrink-0"
                @click="openLogoPreview(logo)"
            />

            <!-- Input fields stacked vertically -->
            <div class="flex-1 min-w-0 space-y-1">
              <input
                  v-model="logo.title"
                  @change="updateLogo(logo)"
                  class="w-full px-2 py-0.5 text-xs border rounded"
                  placeholder="Titel"
                  type="text"
              />
              <input
                  v-model="logo.link"
                  @change="updateLogo(logo)"
                  class="w-full px-2 py-0.5 text-xs border rounded"
                  placeholder="https://domain.tld"
                  type="url"
              />
            </div>

            <!-- Toggle and delete on the right -->
            <div class="flex items-center gap-2 flex-shrink-0">
              <label class="flex items-center">
                <input
                    type="checkbox"
                    class="toggle-switch"
                    :checked="logo.events.some(e => e.id === (selectedEvent?.id || eventStore.selectedEvent?.id))"
                    @change="toggleEventLogo(logo)"
                />
              </label>
              <button @click="confirmDeleteLogo(logo)" class="text-red-600 hover:text-red-800 text-sm">
                🗑️
              </button>
            </div>
          </div>
        </div>

        <p v-if="logos.length === 0" class="text-sm text-gray-500 italic mt-4">
          Noch keine Logos hochgeladen.
        </p>
      </div>

      <!-- Right Side: Assigned Logos (Sortable) -->
      <div>
        <h2 class="text-xl font-semibold text-gray-900 mb-4">Logos in Verwendung</h2>
        <p class="text-sm text-gray-600 mb-4">Ziehe die Logos, um die Reihenfolge zu ändern, in welcher sie im
          öffentlichen Plan erscheinen.</p>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div
              v-for="logo in logosWithSpaceMaking.filter(logo => logo.events.some(e => e.id === (selectedEvent?.id || eventStore.selectedEvent?.id)))"
              :key="logo.id"
              class="border rounded p-3 shadow bg-white transition-all duration-300 ease-out relative"
              :class="{
              'opacity-50 scale-105 rotate-2': draggedLogo?.id === logo.id,
              'ring-2 ring-blue-500 bg-blue-50': draggedOverLogo?.id === logo.id,
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
            <!-- Drag handle indicator -->
            <div
                class="drag-handle absolute top-2 right-2 text-gray-400 text-xs cursor-move select-none"
                title="Drag to reorder"
            >
              ⋮⋮
            </div>

            <!-- Drop indicator -->
            <div
                v-if="isDragging && draggedOverLogo?.id === logo.id"
                class="absolute inset-0 border-2 border-dashed border-blue-400 bg-blue-100 bg-opacity-50 rounded flex items-center justify-center"
                :class="{
                'border-t-4': dropPosition === 'before',
                'border-b-4': dropPosition === 'after'
              }"
            >
              <div class="text-blue-600 font-semibold text-sm">
                {{ dropPosition === 'before' ? '↑ Drop here' : '↓ Drop here' }}
              </div>
            </div>

            <img
                :src="logo.url"
                alt="Logo"
                class="h-16 mx-auto cursor-pointer hover:opacity-80 transition-opacity mb-2"
                @click.stop="openLogoPreview(logo)"
                draggable="false"
                @mousedown.stop
                @dragstart.stop
            />

            <div class="space-y-1 text-center">
              <div v-if="logo.title" class="text-sm font-medium text-gray-900">
                {{ logo.title }}
              </div>
              <div v-if="logo.link" class="text-xs text-blue-600 truncate" :title="logo.link">
                {{ logo.link }}
              </div>
              <div v-if="!logo.title && !logo.link" class="text-xs text-gray-400 italic">
                Kein Titel/Link
              </div>
            </div>

            <div class="flex items-center justify-center pt-2">
              <span class="text-xs text-green-600 font-medium">✓ Aktiv</span>
            </div>
          </div>
        </div>

        <p v-if="sortedLogos.filter(logo => logo.events.some(e => e.id === (selectedEvent?.id || eventStore.selectedEvent?.id))).length === 0"
           class="text-sm text-gray-500 italic mt-4">
          Keine Logos in Verwendung. Aktiviere Logos links, um sie hier zu sortieren.
        </p>
      </div>
    </div>

    <!-- Logo Preview Modal -->
    <div
        v-if="selectedLogoForPreview"
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
        @click="closeLogoPreview"
    >
      <div class="bg-white rounded-lg p-6 max-w-4xl max-h-[90vh] overflow-auto" @click.stop>
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-semibold">{{ selectedLogoForPreview.title || 'Logo Preview' }}</h3>
          <button
              @click="closeLogoPreview"
              class="text-gray-500 hover:text-gray-700 text-2xl"
          >
            ×
          </button>
        </div>

        <div class="flex justify-center">
          <img
              :src="selectedLogoForPreview.url"
              :alt="selectedLogoForPreview.title || 'Logo'"
              class="max-w-full max-h-[70vh] object-contain"
          />
        </div>

        <div v-if="selectedLogoForPreview.link" class="mt-4 text-center">
          <a
              :href="selectedLogoForPreview.link"
              target="_blank"
              rel="noopener noreferrer"
              class="text-blue-600 hover:text-blue-800 underline"
          >
            {{ selectedLogoForPreview.link }}
          </a>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
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
.toggle-switch {
  appearance: none;
  width: 40px;
  height: 20px;
  background: #ccc;
  border-radius: 9999px;
  position: relative;
  transition: background 0.3s;
}

.toggle-switch:checked {
  background: #4ade80;
}

.toggle-switch::after {
  content: "";
  position: absolute;
  top: 2px;
  left: 2px;
  width: 16px;
  height: 16px;
  background: white;
  border-radius: 9999px;
  transition: transform 0.3s;
}

.toggle-switch:checked::after {
  transform: translateX(20px);
}

/* Enhanced drag and drop animations */
.transition-all {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.transform {
  transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Smooth space-making animations */
.border {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.ring-2 {
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.bg-blue-50 {
  transition: background-color 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Drop indicator animations */
.border-dashed {
  animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% {
    opacity: 0.7;
  }
  50% {
    opacity: 1;
  }
}
</style>

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
  
  try {
    const formData = new FormData()
    formData.append('file', uploadFile.value)
    formData.append('regional_partner', currentEvent.regional_partner)

    await axios.post('/logos', formData)
    await fetchLogos()
    
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
  }
}

const updateLogo = async (logo) => {
  try {
    await axios.patch(`/logos/${logo.id}`, {
      title: logo.title,
      link: logo.link
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

const deleteLogo = async () => {
  if (!logoToDelete.value) return
  
  try {
    await axios.delete(`/logos/${logoToDelete.value.id}`)
    await fetchLogos()
    logoToDelete.value = null
  } catch (error) {
    console.error('Error deleting logo:', error)
    alert('Fehler beim Löschen des Logos: ' + error.message)
  }
}

// Drag and drop methods
const handleDragStart = (event, logo) => {
  draggedLogo.value = logo
  isDragging.value = true
  event.dataTransfer.effectAllowed = 'move'
  event.dataTransfer.setData('text/html', event.target.outerHTML)
  event.target.style.opacity = '0.5'
  event.target.style.transform = 'rotate(5deg) scale(1.05)'
}

const handleDragEnd = (event) => {
  event.target.style.opacity = '1'
  event.target.style.transform = ''
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
  
  if (!draggedLogo.value || !targetLogo || draggedLogo.value.id === targetLogo.id) {
    return
  }
  
  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent) {
    alert('Bitte wähle zuerst ein Event aus.')
    return
  }
  
  // Get logos assigned to this event
  const assignedLogos = logos.value.filter(logo => 
    logo.events.some(e => e.id === currentEvent.id)
  )
  
  // Find the indices of the dragged and target logos in the assigned logos array
  const draggedIndex = assignedLogos.findIndex(logo => logo.id === draggedLogo.value.id)
  const targetIndex = assignedLogos.findIndex(logo => logo.id === targetLogo.id)
  
  if (draggedIndex === -1 || targetIndex === -1) {
    return
  }
  
  // Reorder the logos array
  const newOrder = [...assignedLogos]
  const [draggedItem] = newOrder.splice(draggedIndex, 1)
  newOrder.splice(targetIndex, 0, draggedItem)
  
  // Update sort order in database
  try {
    const logoOrders = newOrder.map((logo, index) => ({
      logo_id: logo.id,
      sort_order: index
    }))
    
    await axios.post('/logos/update-sort-order', {
      event_id: currentEvent.id,
      logo_orders: logoOrders
    })
    
    // Refresh logos to get updated order
    await fetchLogos()
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

const deleteMessage = computed(() => {
  if (!logoToDelete.value) return ''
  return `Möchten Sie das Logo "${logoToDelete.value.title || 'Unbenannt'}" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

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
    <div v-if="!selectedEvent && !eventStore.selectedEvent" class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
      <div class="flex items-center gap-2">
        <svg class="w-5 h-5 text-yellow-600" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
        </svg>
        <span class="text-sm font-medium text-yellow-800">Kein Event ausgewählt</span>
      </div>
      <p class="text-xs text-yellow-700 mt-1">Bitte wähle zuerst ein Event aus, um Logos hochzuladen.</p>
    </div>

    <!-- Upload -->
    <div v-else class="flex items-center space-x-4">
      <input type="file" @change="handleFileChange" ref="fileInput" accept="image/*" class="border rounded px-3 py-1"/>
      <button @click="uploadLogo" :disabled="!uploadFile" class="px-4 py-2 bg-blue-600 text-white rounded disabled:bg-gray-400 disabled:cursor-not-allowed">
        {{ uploadFile ? 'Upload ' + uploadFile.name : 'Upload' }}
      </button>
      <span v-if="uploadFile" class="text-sm text-gray-600">File selected: {{ uploadFile.name }}</span>
    </div>

    <!-- Logos -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
      <div 
        v-for="logo in logosWithSpaceMaking" 
        :key="logo.id" 
        class="border rounded p-4 shadow space-y-2 bg-white transition-all duration-300 ease-out relative"
        :class="{
          'opacity-50 scale-105 rotate-2': draggedLogo?.id === logo.id,
          'ring-2 ring-blue-500 bg-blue-50': draggedOverLogo?.id === logo.id,
          'cursor-move': logo.events.some(e => e.id === (selectedEvent?.id || eventStore.selectedEvent?.id)),
          'transform': logo._spaceMakingOffset
        }"
        :style="{ transform: logo._spaceMakingOffset || '' }"
        :draggable="logo.events.some(e => e.id === (selectedEvent?.id || eventStore.selectedEvent?.id))"
        @dragstart="handleDragStart($event, logo)"
        @dragend="handleDragEnd"
        @dragover="handleDragOver"
        @dragenter="handleDragEnter($event, logo)"
        @dragleave="handleDragLeave"
        @drop="handleDrop($event, logo)"
      >
        <!-- Drag handle indicator -->
        <div 
          v-if="logo.events.some(e => e.id === (selectedEvent?.id || eventStore.selectedEvent?.id))"
          class="absolute top-2 right-2 text-gray-400 text-xs cursor-move"
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
          class="h-20 mx-auto cursor-pointer hover:opacity-80 transition-opacity"
          @click="openLogoPreview(logo)"
        />

        <input
            v-model="logo.title"
            @change="updateLogo(logo)"
            class="w-full px-3 py-1 border rounded"
            placeholder="Titel"
            type="text"
        />

        <input
            v-model="logo.link"
            @change="updateLogo(logo)"
            class="w-full px-3 py-1 border rounded"
            placeholder="Link"
            type="url"
        />

        <div class="flex items-center justify-between">
          <label class="flex items-center space-x-2">
            <span class="text-sm">Zugewiesen</span>
            <input
                type="checkbox"
                class="toggle-switch"
                :checked="logo.events.some(e => e.id === (selectedEvent?.id || eventStore.selectedEvent?.id))"
                @change="toggleEventLogo(logo)"
            />
          </label>

          <button @click="confirmDeleteLogo(logo)" class="text-red-600 hover:text-red-800">
            🗑️
          </button>
        </div>
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
      :message="deleteMessage"
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

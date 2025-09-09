<script setup>
import {ref, onMounted, onUnmounted, computed, nextTick} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import IconAccordionArrow from '@/components/icons/IconAccordionArrow.vue'
import draggable from 'vuedraggable'

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const rooms = ref([])
const roomTypes = ref([])
const typeGroups = ref([])
const assignments = ref({})

const dragOverRoomId = ref(null)
const isDragging = ref(false)
const previewedTypeId = ref(null)

const getProgramColor = (type) => {
  return type?.group?.program?.color || '#888888'
}

onMounted(async () => {
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  const {data} = await axios.get(`/events/${eventId.value}/rooms`)
  rooms.value = data.rooms
  roomTypes.value = data.roomTypes
  typeGroups.value = data.groups

  const result = {}
  data.rooms.forEach(room => {
    room.room_types.forEach(rt => {
      result[rt.id] = room.id
    })
  })
  assignments.value = result
})

const updateRoom = async (room) => {
  await axios.put(`/rooms/${room.id}`, {
    name: room.name,
    navigation_instruction: room.navigation_instruction
  })
}

const assignRoomType = async (typeId, roomId) => {
  assignments.value[typeId] = roomId
  await axios.put(`/rooms/assign-types`, {
    type_id: typeId,
    room_id: roomId,
    event: eventStore.selectedEvent?.id
  })
}

const unassignRoomType = async (typeId) => {
  assignments.value[typeId] = null
  await axios.put(`/rooms/assign-types`, {
    type_id: typeId,
    room_id: null,
    event: eventStore.selectedEvent?.id
  })
}

const expandedGroups = ref(new Set())
const toggleGroup = (groupId) => {
  if (expandedGroups.value.has(groupId)) {
    expandedGroups.value.delete(groupId)
  } else {
    expandedGroups.value.add(groupId)
  }
}

// 🔹 Ghost tile refs
const newRoomName = ref('')
const newRoomNote = ref('')
const newRoomInput = ref(null)
const newRoomNoteInput = ref(null)
const isSaving = ref(false)
const isCreatingRoom = ref(false)
const newRoomCardRef = ref(null)

const createRoom = async () => {
  // Prevent multiple simultaneous room creations
  if (isCreatingRoom.value) return
  
  if (!newRoomName.value.trim() && !newRoomNote.value.trim()) {
    newRoomName.value = ''
    newRoomNote.value = ''
    return
  }

  isCreatingRoom.value = true
  isSaving.value = true
  try {
    const {data} = await axios.post('/rooms', {
      name: newRoomName.value.trim(),
      navigation_instruction: newRoomNote.value.trim(),
      event: eventId.value
    })
    rooms.value.push(data)

    // reset ghost tile
    newRoomName.value = ''
    newRoomNote.value = ''
    await nextTick()
    newRoomInput.value?.focus()
  } finally {
    isSaving.value = false
    isCreatingRoom.value = false
  }
}

const handleDrop = async (event, room) => {
  const type = event.item._underlying_vm_ || event.item.__vue__
  if (type && type.id) {
    await assignRoomType(type.id, room.id)
  }
  dragOverRoomId.value = null
  previewedTypeId.value = null
  isDragging.value = false
}

// 🔹 Delete modal
const showDeleteModal = ref(false)
const roomToDelete = ref(null)

const askDeleteRoom = (room) => {
  roomToDelete.value = room
  showDeleteModal.value = true
}

const confirmDeleteRoom = async () => {
  if (!roomToDelete.value) return
  await axios.delete(`/rooms/${roomToDelete.value.id}`)
  rooms.value = rooms.value.filter(r => r.id !== roomToDelete.value.id)
  showDeleteModal.value = false
  roomToDelete.value = null
}

const cancelDeleteRoom = () => {
  showDeleteModal.value = false
  roomToDelete.value = null
}

// Handle clicks outside the new room card
const handleClickOutside = (event) => {
  if (newRoomCardRef.value && !newRoomCardRef.value.contains(event.target)) {
    // Only create room if there's content to save
    if (newRoomName.value.trim() || newRoomNote.value.trim()) {
      createRoom()
    }
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<template>
  <div class="grid grid-cols-[2fr,1fr] gap-6 p-6">
    <div>
      <h2 class="text-xl font-bold mb-4">Vorhandene Räume</h2>
      <ul class="grid grid-cols-2 gap-4">

        <!-- Existing rooms -->
        <li
            v-for="room in rooms"
            :key="room.id"
            class="p-4 mb-2 border rounded bg-white shadow"
        >
          <div class="flex justify-between items-start">
            <div class="w-full">
              <div class="mb-2">
                <input
                    v-model="room.name"
                    class="text-md font-semibold border-b border-gray-300 w-full focus:outline-none focus:border-blue-500"
                    @blur="updateRoom(room)"
                />
              </div>
              <div>
                <input
                    v-model="room.navigation_instruction"
                    class="text-sm border-b border-gray-300 w-full text-gray-700 focus:outline-none focus:border-blue-500"
                    placeholder="z. B. 2. Etage rechts"
                    @blur="updateRoom(room)"
                />
              </div>
              <div
                  class="flex flex-wrap mt-2 gap-2 min-h-[40px] border rounded p-2 transition-colors"
                  :class="{
                  'bg-blue-100': dragOverRoomId === room.id,
                  'bg-yellow-100': isDragging && dragOverRoomId !== room.id,
                  'bg-gray-50': !isDragging && dragOverRoomId !== room.id
                }"
              >
                <draggable
                    :list="roomTypes.filter(t => assignments[t.id] === room.id)"
                    group="roomtypes"
                    item-key="id"
                    @add="event => handleDrop(event, room)"
                    @start="isDragging = true"
                    @end="isDragging = false"
                    class="flex flex-wrap gap-2 w-full"
                >
                  <template #item="{element}">
                    <span
                        :style="{
                        backgroundColor: getProgramColor(element),
                        color: '#fff',
                        opacity: isDragging && previewedTypeId === String(element.id) ? 0.6 : 1
                      }"
                        class="text-xs px-2 py-1 rounded-full cursor-move flex items-center gap-1"
                    >
                      {{ element.name }}
                      <button class="text-white ml-1 text-sm" @click.stop="unassignRoomType(element.id)">✖</button>
                    </span>
                  </template>
                </draggable>
              </div>
            </div>
            <button class="text-red-600 text-lg" @click="askDeleteRoom(room)">🗑️</button>
          </div>
        </li>

        <!-- Ghost tile -->
        <li 
            ref="newRoomCardRef"
            class="p-4 mb-2 border-dashed border-2 border-gray-300 rounded bg-gray-50 shadow-sm"
        >
          <div class="mb-2">
            <input
                ref="newRoomInput"
                v-model="newRoomName"
                class="text-md font-semibold border-b border-gray-300 w-full focus:outline-none focus:border-blue-500"
                placeholder="Neuer Raum"
                @keyup.enter="createRoom"
                :disabled="isSaving"
            />
          </div>
          <transition name="fade">
            <div v-if="newRoomName.trim().length > 0">
              <input
                  ref="newRoomNoteInput"
                  v-model="newRoomNote"
                  class="text-sm border-b border-gray-300 w-full text-gray-700 focus:outline-none focus:border-blue-500"
                  placeholder="Navigationshinweis"
                  @keyup.enter="createRoom"
                  :disabled="isSaving"
              />
            </div>
          </transition>
        </li>
      </ul>
    </div>

    <!-- Assignment panel (unchanged) -->
    <div>
      <h2 class="text-xl font-bold mb-4">Raumzuordnung</h2>
      <button class="bg-blue-500 text-white px-4 py-1 rounded mb-3"
              @click="typeGroups.forEach(item => expandedGroups.add(item.id))">
        Alle öffnen
      </button>
      &nbsp;
      <button class="bg-blue-500 text-white px-4 py-1 rounded mb-3" @click="expandedGroups.clear()">
        Alle schließen
      </button>
      <div
          v-for="group in typeGroups"
          :key="group.id"
          class="mb-6 bg-gray-50 border rounded-lg p-4 shadow"
      >
        <button
            class="w-full text-left text-lg font-semibold text-black flex justify-between items-center mb-2"
            @click="toggleGroup(group.id)"
        >
          {{ group.name }}
          <span><IconAccordionArrow :opened="expandedGroups.has(group.id)"/></span>
        </button>

        <transition name="accordion">
            <div v-if="expandedGroups.has(group.id)" class="overflow-hidden">
            <draggable
                :list="roomTypes.filter(t => t.group?.id === group.id && !assignments[t.id])"
                group="roomtypes"
                item-key="id"
                class="flex flex-wrap gap-2"
                @start="isDragging = true"
                @end="isDragging = false"
            >
              <template #item="{element}">
                <span
                    :style="{ backgroundColor: getProgramColor(element), color: '#fff' }"
                    class="text-xs px-2 py-1 rounded-full cursor-move"
                >
                  {{ element.name }}
                </span>
              </template>
            </draggable>
          </div>
        </transition>
      </div>
    </div>
  </div>

  <!-- Delete modal -->
  <teleport to="body">
    <div v-if="showDeleteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white p-6 rounded-lg shadow-lg w-96 max-w-full">
        <h3 class="text-lg font-bold mb-4">Raum löschen?</h3>
        <p class="mb-6 text-sm text-gray-700">
          Bist du sicher, dass du den Raum <span class="font-semibold">{{ roomToDelete?.name }}</span> löschen möchtest?
          Diese Aktion kann nicht rückgängig gemacht werden.
        </p>
        <div class="flex justify-end gap-2">
          <button class="px-4 py-2 text-gray-600 hover:text-black" @click="cancelDeleteRoom">Abbrechen</button>
          <button class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700" @click="confirmDeleteRoom">Löschen
          </button>
        </div>
      </div>
    </div>
  </teleport>
</template>

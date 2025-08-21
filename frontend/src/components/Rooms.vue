<script setup>
import {ref, onMounted, computed} from 'vue'
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

const deleteRoom = async (roomId) => {
  await axios.delete(`/rooms/${roomId}`)
  rooms.value = rooms.value.filter(r => r.id !== roomId)
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

const showModal = ref(false)
const newRoomName = ref('')
const newRoomNote = ref('')
const isSaving = ref(false)

const createRoom = async () => {
  if (!newRoomName.value.trim()) return
  isSaving.value = true
  try {
    const {data} = await axios.post('/rooms', {
      name: newRoomName.value.trim(),
      navigation_instruction: newRoomNote.value.trim(),
      event: eventId.value
    })
    rooms.value.push(data)
    // reset for the next ghost tile
    newRoomName.value = ''
    newRoomNote.value = ''
  } finally {
    isSaving.value = false
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

const showDeleteModal = ref(false)
const roomToDelete = ref(null)

const askDeleteRoom = (room) => {
  roomToDelete.value = room
  showDeleteModal.value = true
}

const confirmDeleteRoom = async () => {
  if (!roomToDelete.value) return
  await axios.delete(`/rooms/${roomToDelete.value}`)
  rooms.value = rooms.value.filter(r => r.id !== roomToDelete.value)
  showDeleteModal.value = false
  roomToDelete.value = null
}

const cancelDeleteRoom = () => {
  showDeleteModal.value = false
  roomToDelete.value = null
}
</script>

<template>
  <div class="grid grid-cols-[2fr,1fr] gap-6 p-6">
    <div>
      <h2 class="text-xl font-bold mb-4">Vorhandene Räume</h2>
      <ul class="grid grid-cols-2 gap-4">


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
                    placeholder="z. B. 2. Etage rechts"
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
                    @remove="() => {}"
                    @add="event => handleDrop(event, room)"
                    @dragenter.native="e => { dragOverRoomId = room.id; previewedTypeId = e.dataTransfer?.getData('text/plain') }"
                    @dragleave.native="() => {dragOverRoomId = null; previewedTypeId = null}"
                    @drop.native="() => {dragOverRoomId = null; previewedTypeId = null}"
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
            <button class="text-red-600 text-lg" @click="askDeleteRoom(room.id)">🗑️</button>
          </div>
        </li>
        <li class="p-4 mb-2 border-dashed border-2 border-gray-300 rounded bg-gray-50 shadow-sm">
          <div class="mb-2">
            <input
                v-model="newRoomName"
                class="text-md font-semibold border-b border-gray-300 w-full focus:outline-none focus:border-blue-500"
                placeholder="Neuer Raum"
                @keyup.enter="createRoom"
                @blur="createRoom"
                :disabled="isSaving"
            />
          </div>
          <div>
            <input
                v-model="newRoomNote"
                class="text-sm border-b border-gray-300 w-full text-gray-700 focus:outline-none focus:border-blue-500"
                placeholder="Navigationshinweis"
                @keyup.enter="createRoom"
                @blur="createRoom"
                :disabled="isSaving"
            />
          </div>
        </li>
      </ul>
    </div>

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

  <teleport to="body">
    <div v-if="showModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white p-6 rounded-lg shadow-lg w-96 max-w-full">
        <h3 class="text-lg font-bold mb-4">Neuen Raum hinzufügen</h3>
        <input
            v-model="newRoomName"
            class="w-full border px-3 py-2 rounded mb-4"
            placeholder="Name des Raums"
            type="text"
            @keyup.enter="createRoom"
        />
        <textarea
            v-model="newRoomNote"
            class="w-full border px-3 py-2 rounded mb-4 text-sm"
            placeholder="Navigationshinweis"
            rows="2"
        ></textarea>
        <div class="flex justify-end gap-2">
          <button class="px-4 py-2 text-gray-600 hover:text-black" @click="showModal = false">Abbrechen</button>
          <button
              :disabled="isSaving || !newRoomName.trim()"
              class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 disabled:opacity-50"
              @click="createRoom"
          >
            Hinzufügen
          </button>
        </div>
      </div>
    </div>
  </teleport>

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

<style scoped>
select {
  min-width: 10rem;
}

.fade-enter-active, .fade-leave-active {
  transition: max-height 0.3s ease;
}

.fade-enter-from, .fade-leave-to {
  max-height: 0;
  overflow: hidden;
}

.accordion-enter-active,
.accordion-leave-active {
  transition: all 0.3s ease;
  max-height: 500px;
}

.accordion-enter-from,
.accordion-leave-to {
  max-height: 0;
  opacity: 0;
}

.accordion-enter-to,
.accordion-leave-from {
  max-height: 500px;
  opacity: 1;
}

.draggable-dragging {
  transform: scale(1.05);
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
  opacity: 0.7;
}

.draggable-dropzone {
  transition: background-color 0.2s ease, border 0.2s ease;
  background-color: #fffbea;
  border: 2px dashed #facc15;
}
</style>

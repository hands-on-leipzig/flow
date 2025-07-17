<script setup>
import {ref, onMounted, computed} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const rooms = ref([])
const roomTypes = ref([])
const typeGroups = ref([])
const assignments = ref({})

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
    newRoomName.value = ''
    newRoomNote.value = ''
    showModal.value = false
  } finally {
    isSaving.value = false
  }
}


</script>

<template>
  <div class="grid grid-cols-[2fr,1fr] gap-6 p-6">
    <div>
      <h2 class="text-xl font-bold mb-4">Vorhandene Räume</h2>
      <button class="bg-blue-500 text-white px-4 py-1 rounded mb-3" @click="showModal = true">
        Raum hinzufügen
      </button>

      <ul class="grid grid-cols-2 gap-4">
        <li
            v-for="room in rooms"
            :key="room.id"
            class="p-4 mb-2 border rounded bg-white shadow "
        >
          <div class="flex justify-between items-start">
            <div class="w-full">
              <div class="mb-2">
                <label class="text-xs text-gray-500 uppercase tracking-wide">Raumname</label>
                <input
                    v-model="room.name"
                    class="text-md font-semibold border-b border-gray-300 w-full focus:outline-none focus:border-blue-500"
                    @blur="updateRoom(room)"
                />
              </div>
              <div>
                <label class="text-xs text-gray-500 uppercase tracking-wide">Navigationshinweis</label>
                <input
                    v-model="room.navigation_instruction"
                    class="text-sm border-b border-gray-300 w-full text-gray-700 focus:outline-none focus:border-blue-500"
                    placeholder="z. B. 2. Etage rechts"
                    @blur="updateRoom(room)"
                />
              </div>
              <div class="flex flex-wrap mt-2 gap-2">
                <label class="w-full text-xs text-gray-500 uppercase tracking-wide">wird genutzt für:</label>
                <span
                    v-for="type in roomTypes.filter(t => assignments[t.id] === room.id)"
                    :key="type.id"
                    :style="{ backgroundColor: getProgramColor(type), color: '#fff' }"
                    class="text-xs px-2 py-1 rounded-full"
                >
                  {{ type.name }}
                </span>
              </div>
            </div>
            <button class="text-red-600 text-lg" @click="deleteRoom(room.id)">🗑️</button>
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
      <button class="bg-blue-500 text-white px-4 py-1 rounded mb-3"
              @click="expandedGroups.clear()">
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
          <span>
             <svg v-if="expandedGroups.has(group.id)" class="h-4 w-4" fill="none" stroke="currentColor"
                  viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M5 15l7-7 7 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
              </svg>
              <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                   xmlns="http://www.w3.org/2000/svg">
              <path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
            </svg>
        </span>
        </button>

        <transition name="accordion">
          <div
              v-if="expandedGroups.has(group.id)"
              class="space-y-3 overflow-hidden"
          >
            <div
                v-for="type in roomTypes.filter(t => t.group?.id === group.id)"
                :key="type.id"
                class="flex items-center justify-between"
            >
              <label class="font-medium text-gray-700">{{ type.name }}</label>
              <select
                  :value="assignments[type.id] || ''"
                  class="border border-gray-300 rounded px-2 py-1 text-sm"
                  @change="assignRoomType(type.id, +$event.target.value)"
              >
                <option value="">Bitte wählen</option>
                <option
                    v-for="room in rooms"
                    :key="room.id"
                    :value="room.id"
                >
                  {{ room.name }}
                </option>
              </select>
            </div>
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

</style>

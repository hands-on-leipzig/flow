<script setup>
import { ref, onMounted, onUnmounted, computed, nextTick } from 'vue'
import axios from 'axios'
import { useEventStore } from '@/stores/event'
import draggable from 'vuedraggable'
import { programLogoSrc, programLogoAlt } from '@/utils/images'

// --- Stores & Refs ---
const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const rooms = ref([])
const roomTypes = ref([])
const typeGroups = ref([])
const assignments = ref({})

const dragOverRoomId = ref(null)
const isDragging = ref(false)
const previewedTypeId = ref(null)

// --- Farbzuweisung (vereinfacht) ---
const getProgramColor = (type) => {
  switch (type.first_program) {
    case 2: return '#10B981'; // Grün (Explore)
    case 3: return '#EF4444'; // Rot (Challenge)
    default: return '#9CA3AF'; // Grau (Neutral)
  }
}

// --- Lifecycle: Daten laden ---
onMounted(async () => {
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }

  // Räume laden
  const { data: roomsData } = await axios.get(`/events/${eventId.value}/rooms`)
  rooms.value = Array.isArray(roomsData) ? roomsData : (roomsData?.rooms ?? [])

  // Plan-ID holen
  const { data: planData } = await axios.get(`/plans/event/${eventId.value}`)
  if (!planData?.id) {
    console.warn('Kein Plan für Event gefunden')
    return
  }

  // Raumtypen vom Backend holen
  const { data: roomTypeGroups } = await axios.get(`/room-types/${planData.id}`)

  typeGroups.value = roomTypeGroups
  roomTypes.value = roomTypeGroups.flatMap(group =>
    group.room_types.map(rt => ({
      id: rt.type_id,
      name: rt.type_name,
      first_program: rt.first_program,
      group: { id: group.id, name: group.name }
    }))
  )

  console.log('Fetched room type groups:', typeGroups.value)
  console.log('Flattened room types:', roomTypes.value)

  // Zuordnungen bestehender Räume übernehmen (inkl. Extra Blocks)
  const result = {}
  roomsData.rooms.forEach(room => {
    // Normale Raumtypen
    room.room_types.forEach(rt => {
      result[rt.id] = room.id
    })

    // Extra Blocks (falls vorhanden)
    if (room.extra_blocks && Array.isArray(room.extra_blocks)) {
      room.extra_blocks.forEach(eb => {
        result[eb.id] = room.id
      })
    }
  })
  assignments.value = result
})

// --- Raum bearbeiten ---
const updateRoom = async (room) => {
  await axios.put(`/rooms/${room.id}`, {
    name: room.name,
    navigation_instruction: room.navigation_instruction
  })
}

// --- Zuordnung Raum <-> Typ ---
const assignRoomType = async (typeId, roomId) => {
  assignments.value[typeId] = roomId

  const type = roomTypes.value.find(t => t.id === typeId)
  const isExtraBlock = type?.group?.id === 999

  await axios.put(`/rooms/assign-types`, {
    type_id: typeId,
    room_id: roomId,
    event: eventStore.selectedEvent?.id,
    extra_block: isExtraBlock
  })
}

const unassignRoomType = async (typeId) => {
  assignments.value[typeId] = null

  const type = roomTypes.value.find(t => t.id === typeId)
  const isExtraBlock = type?.group?.id === 999

  await axios.put(`/rooms/assign-types`, {
    type_id: typeId,
    room_id: null,
    event: eventStore.selectedEvent?.id,
    extra_block: isExtraBlock
  })
}

// --- Raum erstellen ---
const newRoomName = ref('')
const newRoomNote = ref('')
const newRoomInput = ref(null)
const newRoomNoteInput = ref(null)
const isSaving = ref(false)
const isCreatingRoom = ref(false)
const newRoomCardRef = ref(null)

const createRoom = async () => {
  if (isCreatingRoom.value) return

  if (!newRoomName.value.trim() && !newRoomNote.value.trim()) {
    newRoomName.value = ''
    newRoomNote.value = ''
    return
  }

  isCreatingRoom.value = true
  isSaving.value = true
  try {
    const { data } = await axios.post('/rooms', {
      name: newRoomName.value.trim(),
      navigation_instruction: newRoomNote.value.trim(),
      event: eventId.value
    })
    rooms.value.push(data)
    newRoomName.value = ''
    newRoomNote.value = ''
    await nextTick()
    newRoomInput.value?.focus()
  } finally {
    isSaving.value = false
    isCreatingRoom.value = false
  }
}

// --- Drag & Drop ---
const handleDrop = async (event, room) => {
  const type = event.item._underlying_vm_ || event.item.__vue__
  if (type && type.id) {
    await assignRoomType(type.id, room.id)
  }
  dragOverRoomId.value = null
  previewedTypeId.value = null
  isDragging.value = false
}

// --- Raum löschen ---
const showDeleteModal = ref(false)
const roomToDelete = ref(null)

const askDeleteRoom = (room) => {
  roomToDelete.value = room
  showDeleteModal.value = true
}

const confirmDeleteRoom = async () => {
  if (!roomToDelete.value) return

  const deletedRoomId = roomToDelete.value.id

  await axios.delete(`/rooms/${deletedRoomId}`)

  // Raum aus Liste entfernen
  rooms.value = rooms.value.filter(r => r.id !== deletedRoomId)

  // 🟢 Alle zugeordneten Typen (normale + extra) freigeben
  Object.keys(assignments.value).forEach(typeId => {
    if (assignments.value[typeId] === deletedRoomId) {
      assignments.value[typeId] = null
    }
  })

  // Modal schließen
  showDeleteModal.value = false
  roomToDelete.value = null
}

const cancelDeleteRoom = () => {
  showDeleteModal.value = false
  roomToDelete.value = null
}

// --- Klick außerhalb des Eingabefelds ---
const handleClickOutside = (event) => {
  if (newRoomCardRef.value && !newRoomCardRef.value.contains(event.target)) {
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


const activeTab = ref('activities')

// Fake-Teams
const fakeExploreTeams = ref([
  { id: 1, name: 'Team Green Light', number: 101 },
  { id: 2, name: 'Mind Explorers', number: 102 }
])

const fakeChallengeTeams = ref([
  { id: 3, name: 'Tech Titans', number: 201 },
  { id: 4, name: 'Robo Masters', number: 202 },
  { id: 5, name: 'Smart Builders', number: 203 }
])



</script>

<template>
  <div class="grid grid-cols-[2fr,1fr] gap-6 p-6">
    <!-- 🟢 Linke Spalte: Räume -->
    <div>
      <h2 class="text-xl font-bold mb-4">Räume</h2>
      <ul class="grid grid-cols-2 gap-4">
        <!-- Bestehende Räume -->
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

              <!-- Zugeordnete Raumtypen -->
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
                  <template #item="{ element }">
                    <span
                      :style="{
                        border: '2px solid ' + getProgramColor(element),
                        backgroundColor: '#fff',
                        color: '#000',
                        opacity: isDragging && previewedTypeId === String(element.id) ? 0.6 : 1
                      }"
                      class="text-xs px-2 py-1 rounded-full cursor-move flex items-center gap-1 font-medium"
                    >
                      <img
                        v-if="programLogoSrc(element.first_program)"
                        :src="programLogoSrc(element.first_program)"
                        :alt="programLogoAlt(element.first_program)"
                        class="w-3 h-3 flex-shrink-0"
                      />
                      {{ element.name }}
                      <button
                        class="ml-1 text-sm"
                        :style="{ color: '#000' }"
                        @click.stop="unassignRoomType(element.id)"
                      >
                        ✖
                      </button>
                    </span>
                  </template>
                </draggable>
              </div>
            </div>

            <!-- Raum löschen -->
            <button
              class="text-red-600 text-lg"
              @click="askDeleteRoom(room)"
              title="Raum löschen"
            >
              🗑️
            </button>
          </div>
        </li>

        <!-- 🟩 Neuer Raum (Ghost Tile) -->
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



    <!-- 🔵 Rechte Spalte: Aktivitäten / Teams -->
    <div>
      <!-- Tabs -->
      <div class="flex mb-4 border-b text-xl font-bold">
        <button
          class="px-4 py-2"
          :class="activeTab === 'activities' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-600'"
          @click="activeTab = 'activities'"
        >
          Aktivitäten
        </button>
        <button
          class="px-4 py-2 ml-4"
          :class="activeTab === 'teams' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-600'"
          @click="activeTab = 'teams'"
        >
          Teams
        </button>
      </div>

      <!-- Aktivitäten-Liste -->
      <div v-if="activeTab === 'activities'">
        <div
          v-for="group in typeGroups"
          :key="group.id"
          class="mb-6 bg-gray-50 border rounded-lg p-4 shadow"
        >
          <div class="text-lg font-semibold text-black mb-3">
            {{ group.name }}
          </div>

          <draggable
            :list="roomTypes.filter(t => t.group?.id === group.id && !assignments[t.id])"
            group="roomtypes"
            item-key="id"
            class="flex flex-wrap gap-2"
            @start="isDragging = true"
            @end="isDragging = false"
          >
            <template #item="{ element }">
              <span
                :style="{
                  border: '2px solid ' + getProgramColor(element),
                  backgroundColor: '#fff',
                  color: '#000'
                }"
                class="text-xs px-2 py-1 rounded-full cursor-move flex items-center gap-1 font-medium"
              >
                <img
                  v-if="programLogoSrc(element.first_program)"
                  :src="programLogoSrc(element.first_program)"
                  :alt="programLogoAlt(element.first_program)"
                  class="w-3 h-3 flex-shrink-0"
                />
                {{ element.name }}
              </span>
            </template>
          </draggable>
        </div>
      </div>



      
<!-- Teams-Liste -->
<div v-else>
  <!-- Tabs übernehmen bereits die Überschrift -->
  <!-- Explore Teams -->
  <div class="mb-6 bg-gray-50 border rounded-lg p-4 shadow" v-if="fakeExploreTeams.length">
    <div class="text-lg font-semibold text-black mb-3">Explore</div>
    <div class="flex flex-wrap gap-2">
      <span
        v-for="team in fakeExploreTeams"
        :key="team.id"
        class="flex items-center border rounded-md text-xs bg-white shadow-sm"
      >
        <!-- Farbiger Seitenbalken -->
        <span
          class="w-1.5 h-full rounded-l-md"
          :style="{ backgroundColor: getProgramColor({ first_program: 2 }) }"
        ></span>

        <!-- Inhalt -->
        <span class="px-2 py-1 flex items-center gap-1">
          <img
            v-if="programLogoSrc(2)"
            :src="programLogoSrc(2)"
            :alt="programLogoAlt(2)"
            class="w-3 h-3 flex-shrink-0"
          />
          {{ team.name }} ({{ team.number }})
        </span>
      </span>
    </div>
  </div>

  <!-- Challenge Teams -->
  <div class="mb-6 bg-gray-50 border rounded-lg p-4 shadow" v-if="fakeChallengeTeams.length">
    <div class="text-lg font-semibold text-black mb-3">Challenge</div>
    <div class="flex flex-wrap gap-2">
      <span
        v-for="team in fakeChallengeTeams"
        :key="team.id"
        class="flex items-center border rounded-md text-xs bg-white shadow-sm"
      >
        <!-- Farbiger Seitenbalken -->
        <span
          class="w-1.5 h-full rounded-l-md"
          :style="{ backgroundColor: getProgramColor({ first_program: 3 }) }"
        ></span>

        <!-- Inhalt -->
        <span class="px-2 py-1 flex items-center gap-1">
          <img
            v-if="programLogoSrc(3)"
            :src="programLogoSrc(3)"
            :alt="programLogoAlt(3)"
            class="w-3 h-3 flex-shrink-0"
          />
          {{ team.name }} ({{ team.number }})
        </span>
      </span>
    </div>
  </div>
</div>



    </div>



  </div>

  <!-- 🔴 Lösch-Modal -->
  <teleport to="body">
    <div
      v-if="showDeleteModal"
      class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
    >
      <div class="bg-white p-6 rounded-lg shadow-lg w-96 max-w-full">
        <h3 class="text-lg font-bold mb-4">Raum löschen?</h3>
        <p class="mb-6 text-sm text-gray-700">
          Bist du sicher, dass du den Raum
          <span class="font-semibold">{{ roomToDelete?.name }}</span> löschen
          möchtest? Diese Aktion kann nicht rückgängig gemacht werden.
        </p>
        <div class="flex justify-end gap-2">
          <button
            class="px-4 py-2 text-gray-600 hover:text-black"
            @click="cancelDeleteRoom"
          >
            Abbrechen
          </button>
          <button
            class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700"
            @click="confirmDeleteRoom"
          >
            Löschen
          </button>
        </div>
      </div>
    </div>
  </teleport>
</template>
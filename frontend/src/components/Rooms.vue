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
const assignments = ref({})

// --- Gemeinsame Struktur für Activities + Teams ---
const assignables = ref([]) // ← gemeinsame Ebene 1 (type = 'activity' | 'team')

// --- Hilfslisten ---
const roomTypes = ref([])
const typeGroups = ref([])
const exploreTeams = ref([])
const challengeTeams = ref([])

const dragOverRoomId = ref(null)
const isDragging = ref(false)
const previewedTypeId = ref(null)

// --- Farbzuweisung ---
const getProgramColor = (item) => {
  switch (item.first_program) {
    case 2: return '#10B981' // Grün (Explore)
    case 3: return '#EF4444' // Rot (Challenge)
    default: return '#9CA3AF' // Grau (Neutral)
  }
}

// --- Lifecycle ---
onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()

  // Räume laden
  const { data: roomsData } = await axios.get(`/events/${eventId.value}/rooms`)
  rooms.value = Array.isArray(roomsData) ? roomsData : (roomsData?.rooms ?? [])

  // Plan-ID holen
  const { data: planData } = await axios.get(`/plans/event/${eventId.value}`)
  if (!planData?.id) {
    console.warn('Kein Plan für Event gefunden')
    return
  }

  // --- Aktivitäten (room-types) laden ---
  const { data: roomTypeGroups } = await axios.get(`/room-types/${planData.id}`)
  typeGroups.value = roomTypeGroups
  roomTypes.value = roomTypeGroups.flatMap(group =>
    group.room_types.map(rt => ({
      id: rt.type_id,
      key: `activity-${rt.type_id}`,   // 👈 HIER NEU
      name: rt.type_name,
      first_program: rt.first_program,
      type: 'activity',
      group: { id: group.id, name: group.name }
    }))
  )

  // --- Teams laden über neue API ---
  try {
    const [exploreResponse, challengeResponse] = await Promise.all([
      axios.get(`/events/${eventId.value}/teams`, { params: { program: 'explore' } }),
      axios.get(`/events/${eventId.value}/teams`, { params: { program: 'challenge' } })
    ])

    exploreTeams.value = exploreResponse.data.map(t => ({
      id: t.id,                                  // ✅ echte DB-ID
      key: `team-${t.id}`,
      number: t.team_number_hot,                 // ✅ Anzeige über HOT-Nummer
      name: t.name ?? 'Unbenannt',
      type: 'team',
      first_program: 2,
      group: { id: 'explore', name: 'Explore' }
    }))

    challengeTeams.value = challengeResponse.data.map(t => ({
      id: t.id,
      key: `team-${t.id}`,
      number: t.team_number_hot,
      name: t.name ?? 'Unbenannt',
      type: 'team',
      first_program: 3,
      group: { id: 'challenge', name: 'Challenge' }
    }))
  } catch (err) {
    console.error('Fehler beim Laden der Teams:', err)
    exploreTeams.value = []
    challengeTeams.value = []
  }

  // --- Zusammenführen in gemeinsame Struktur ---
  assignables.value = [
    {
      id: 'activities',
      type: 'activity',
      groups: roomTypeGroups.map(g => ({
        id: g.id,
        name: g.name,
        items: g.room_types.map(rt => ({
          id: rt.type_id,
          key: `activity-${rt.type_id}`,   // ✅ gleiche Struktur wie bei Teams
          name: rt.type_name,
          first_program: rt.first_program,
          type: 'activity',
          group: { id: g.id, name: g.name }
        }))
      }))
    },
    {
      id: 'teams',
      type: 'team',
      groups: [
        { id: 'explore', name: 'Explore', items: exploreTeams.value },
        { id: 'challenge', name: 'Challenge', items: challengeTeams.value }
      ]
    }
  ]

  // --- Bestehende Zuordnungen übernehmen (typisierte Keys) ---
  const result = {}
  roomsData.rooms.forEach(room => {
    room.room_types.forEach(rt => { result[`activity-${rt.id}`] = room.id })
    if (room.extra_blocks && Array.isArray(room.extra_blocks)) {
      room.extra_blocks.forEach(eb => { result[`activity-${eb.id}`] = room.id })
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

// --- Gemeinsame Zuordnung Raum <-> Item ---
const assignItemToRoom = async (itemKey, roomId) => {
  const item = findItemById(itemKey)
  if (!item) return

  assignments.value[itemKey] = roomId
  if (item.type === 'activity') {
    await axios.put(`/rooms/assign-types`, {
      type_id: item.id,
      room_id: roomId,
      event: eventStore.selectedEvent?.id,
      extra_block: item?.group?.id === 999
    })
  } else {
    console.log(`Team ${item.name} zu Raum ${roomId} (lokal)`)
  }
}

// --- Item nach ID finden ---
const findItemById = (idOrKey) => {
  const str = String(idOrKey)
  const [prefix, num] = str.includes('-') ? str.split('-') : [null, str]
  const normalizedId = Number(num)
  const typeFilter = prefix === 'team' || prefix === 'activity' ? prefix : null

  for (const category of assignables.value) {
    if (typeFilter && category.type !== typeFilter) continue
    for (const group of category.groups) {
      const found = group.items.find(i => i.id === normalizedId)
      if (found) return found
    }
  }
  return null
}

// --- Unassign ---
const unassignItemFromRoom = async (itemKey) => {
  const item = findItemById(itemKey)
  if (!item) return

  assignments.value[itemKey] = null

  if (item.type === 'activity') {
    const isExtraBlock = item?.group?.id === 999
    await axios.put(`/rooms/assign-types`, {
      type_id: item.id,
      room_id: null,
      event: eventStore.selectedEvent?.id,
      extra_block: isExtraBlock
    })
  }

  if (item.type === 'team') {
    console.log(`Team ${item?.name} aus Raum entfernt (lokal)`)
  }
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
  if (!newRoomName.value.trim() && !newRoomNote.value.trim()) return

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
  const item = event.item.__draggable_context?.element
  if (item && item.id) {
    const key = `${item.type}-${item.id}`
    await assignItemToRoom(key, room.id)
  } else {
    console.warn('Ungültiges Item beim Drop:', item)
  }
  dragOverRoomId.value = null
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
  rooms.value = rooms.value.filter(r => r.id !== deletedRoomId)

  Object.keys(assignments.value).forEach(key => {
    if (assignments.value[key] === deletedRoomId) assignments.value[key] = null
  })

  showDeleteModal.value = false
  roomToDelete.value = null
}

const cancelDeleteRoom = () => {
  showDeleteModal.value = false
  roomToDelete.value = null
}

// --- Klick außerhalb Eingabefelds ---
const handleClickOutside = (event) => {
  if (newRoomCardRef.value && !newRoomCardRef.value.contains(event.target)) {
    if (newRoomName.value.trim() || newRoomNote.value.trim()) createRoom()
  }
}

onMounted(() => document.addEventListener('click', handleClickOutside))
onUnmounted(() => document.removeEventListener('click', handleClickOutside))

const activeTab = ref('activities')

// Hilfsfunktion für Template (typisierte IDs)
const getItemsInRoom = (roomId) => {
  const all = []
  for (const category of assignables.value) {
    for (const group of category.groups) {
      all.push(...group.items.filter(i => assignments.value[`${i.type}-${i.id}`] === roomId))
    }
  }
  return all
}

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
              <!-- Raumname -->
              <div class="mb-2">
                <input
                  v-model="room.name"
                  class="text-md font-semibold border-b border-gray-300 w-full focus:outline-none focus:border-blue-500"
                  @blur="updateRoom(room)"
                />
              </div>

              <!-- Navigationshinweis -->
              <div>
                <input
                  v-model="room.navigation_instruction"
                  class="text-sm border-b border-gray-300 w-full text-gray-700 focus:outline-none focus:border-blue-500"
                  placeholder="z. B. 2. Etage rechts"
                  @blur="updateRoom(room)"
                />
              </div>

              <!-- Gemeinsame Drop-Zone für Aktivitäten & Teams -->
              <div
                class="flex flex-wrap mt-2 gap-2 min-h-[40px] border rounded p-2 transition-colors"
                :class="{
                  'bg-blue-100': dragOverRoomId === room.id,
                  'bg-yellow-100': isDragging && dragOverRoomId !== room.id,
                  'bg-gray-50': !isDragging && dragOverRoomId !== room.id
                }"
              >
                <draggable
                  :list="getItemsInRoom(room.id)"
                  group="assignables"
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
                        backgroundColor: '#fff'
                      }"
                      class="text-xs px-2 py-1 rounded-full cursor-move flex items-center gap-1 font-medium"
                    >
                      <img
                        v-if="programLogoSrc(element.first_program)"
                        :src="programLogoSrc(element.first_program)"
                        :alt="programLogoAlt(element.first_program)"
                        class="w-3 h-3 flex-shrink-0"
                      />
                      <span>
                        {{ element.name }}
                        <template v-if="element.type === 'team'">({{ element.number }})</template>
                      </span>
                      <button
                        class="ml-1 text-sm text-gray-500 hover:text-black"
                        @click.stop="unassignItemFromRoom(element.key)"
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

        <!-- 🟩 Neuer Raum -->
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

    <!-- 🔵 Rechte Spalte: Aktivitäten & Teams -->
    <div>
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

      <!-- Dynamisch alle Gruppen aus der gemeinsamen Struktur -->
      <div v-for="category in assignables" :key="category.id" v-show="activeTab === category.id">
        <div
          v-for="group in category.groups"
          :key="group.id"
          class="mb-6 bg-gray-50 border rounded-lg p-4 shadow"
        >
          <div class="text-lg font-semibold text-black mb-3">
            {{ group.name }}
          </div>
          <draggable
            :list="group.items.filter(i => !assignments[`${i.type}-${i.id}`])"
            group="assignables"
            item-key="id"
            class="flex flex-wrap gap-2"
            @start="isDragging = true"
            @end="isDragging = false"
          >



            <template #item="{ element }">
              <span
                v-if="element.type === 'activity'"
                :style="{
                  border: '2px solid ' + getProgramColor(element),
                  backgroundColor: '#fff'
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
                  class="ml-1 text-sm text-gray-500 hover:text-black"
                  @click.stop="unassignItemFromRoom(element.key)"
                >
                  ✖
                </button>
              </span>

              <span
                v-else-if="element.type === 'team'"
                class="flex items-center border rounded-md text-xs bg-white shadow-sm cursor-move"
              >
                <span
                  class="w-1.5 h-full rounded-l-md"
                  :style="{ backgroundColor: getProgramColor(element) }"
                ></span>
                <span class="px-2 py-1 flex items-center gap-1">
                  <img
                    v-if="programLogoSrc(element.first_program)"
                    :src="programLogoSrc(element.first_program)"
                    :alt="programLogoAlt(element.first_program)"
                    class="w-3 h-3 flex-shrink-0"
                  />
                  {{ element.name }} ({{ element.number }})
                </span>
                <button
                  class="ml-1 text-sm text-gray-500 hover:text-black pr-1"
                  @click.stop="unassignItemFromRoom(element.key)"
                >
                  ✖
                </button>
              </span>
            </template>



          </draggable>
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
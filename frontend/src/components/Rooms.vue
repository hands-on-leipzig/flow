<script setup>
import { ref, onMounted, onUnmounted, computed, nextTick, watch } from 'vue'
import axios from 'axios'
import { useEventStore } from '@/stores/event'
import draggable from 'vuedraggable'
import { programLogoSrc, programLogoAlt } from '@/utils/images'
import LoaderFlow from "@/components/atoms/LoaderFlow.vue";
import LoaderText from "@/components/atoms/LoaderText.vue";
import ConfirmationModal from "@/components/molecules/ConfirmationModal.vue";

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
const isDraggingRoom = ref(false)
const previewedTypeId = ref(null)

// --- Farbzuweisung ---
const getProgramColor = (item) => {
  switch (item.first_program) {
    case 2: return '#10B981' // Grün (Explore)
    case 3: return '#EF4444' // Rot (Challenge)
    default: return '#9CA3AF' // Grau (Neutral)
  }
}

// --- Loading state ---
const loading = ref(true)

// --- Lifecycle ---
onMounted(async () => {
  loading.value = true
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
      axios.get(`/events/${eventId.value}/teams`, { params: { program: 'explore', sort: 'name' } }),
      axios.get(`/events/${eventId.value}/teams`, { params: { program: 'challenge', sort: 'name' } })
    ])

    exploreTeams.value = exploreResponse.data.map(t => ({
      id: t.id,
      key: `team-${t.id}`,
      number: t.team_number_hot,
      name: t.name ?? 'Unbenannt',
      type: 'team',
      first_program: 2,
      room: t.room ?? null,                 // 👈 WICHTIG
      group: { id: 'explore', name: 'Explore' }
    }))

    challengeTeams.value = challengeResponse.data.map(t => ({
      id: t.id,
      key: `team-${t.id}`,
      number: t.team_number_hot,
      name: t.name ?? 'Unbenannt',
      type: 'team',
      first_program: 3,
      room: t.room ?? null,                 // 👈 WICHTIG
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
          // Use item_type from backend to create unique keys (prevents collision between room_type.id=5 and extra_block.id=5)
          key: rt.item_type === 'extra_block' ? `activity-eb-${rt.type_id}` : `activity-rt-${rt.type_id}`,
          name: rt.type_name,
          first_program: rt.first_program,
          type: 'activity',
          group: { id: g.id, name: g.name },
          item_type: rt.item_type || 'room_type' // Store for reference
        }))
      }))
    },
    {
      id: 'teams',
      type: 'team',
      groups: [
        { id: 'explore', name: 'FLL Explore', items: exploreTeams.value },
        { id: 'challenge', name: 'FLL Challenge', items: challengeTeams.value }
      ]
    }
  ]


  // --- Bestehende Zuordnungen übernehmen (Activities + Teams, typisierte Keys) ---
  const result = {}

  // 1) Activities (RoomTypes + Extra Blocks)
  roomsData.rooms.forEach(room => {
    (room.room_types ?? []).forEach(rt => {
      // Use rt prefix for room types
      result[`activity-rt-${rt.id}`] = room.id
    })
    ;(room.extra_blocks ?? []).forEach(eb => {
      // Use eb prefix for extra blocks
      result[`activity-eb-${eb.id}`] = room.id
    })
  })

  // 2) Teams (Explore + Challenge) – nur wenn backend room mitliefert
  ;[...exploreTeams.value, ...challengeTeams.value].forEach(team => {
    if (team.room !== null && team.room !== undefined) {
      result[`team-${team.id}`] = team.room
    }
  })

  // 3) Zusammenführen
  assignments.value = result

  // Load saved bulk mode preferences for this event
  // This will also restore proxy assignments via nextTick callback
  loadBulkModePreferences()

  // (Optional zum Prüfen)
  // console.log('Assignments summary:', {
  //   activities: Object.keys(result).filter(k => k.startsWith('activity-')).length,
  //   teams: Object.keys(result).filter(k => k.startsWith('team-')).length
  // })
  
  loading.value = false
})

// --- Raum bearbeiten ---
const updateRoom = async (room) => {
  await axios.put(`/rooms/${room.id}`, {
    name: room.name,
    navigation_instruction: room.navigation_instruction,
    is_accessible: room.is_accessible
  })
}

// --- Accessibility toggle ---
const toggleAccessibility = async (room) => {
  room.is_accessible = !room.is_accessible
  await updateRoom(room)
}

// --- Gemeinsame Zuordnung Raum <-> Item ---
const assignItemToRoom = async (itemKey, roomId) => {
  // Handle proxy items
  if (itemKey === 'proxy-explore' || itemKey === 'proxy-challenge') {
    await handleProxyAssignment(itemKey, roomId)
    return
  }
  
  const item = findItemById(itemKey)
  if (!item) return

  // Lokale Zuordnung aktualisieren
  assignments.value[itemKey] = roomId

  if (item.type === 'activity') {
    await axios.put(`/rooms/assign-types`, {
      type_id: item.id,
      room_id: roomId,
      event: eventStore.selectedEvent?.id,
      extra_block: item?.item_type === 'extra_block' || item?.group?.id === 999
    })
  }

  if (item.type === 'team') {
    await axios.put(`/rooms/assign-teams`, {
      team_id: item.id,
      room_id: roomId,
      event: eventStore.selectedEvent?.id
    })
  }

  // ✅ Nach erfolgreicher Änderung Readiness global neu laden
  if (eventStore.selectedEvent?.id) {
    await eventStore.refreshReadiness(eventStore.selectedEvent.id)
  }

}

// --- Item nach ID finden ---
const findItemById = (idOrKey) => {
  const str = String(idOrKey)
  
  // Handle new key format: activity-rt-5, activity-eb-5, team-123, proxy-explore, etc.
  if (str.includes('-')) {
    const parts = str.split('-')
    
    // Handle proxy keys
    if (parts[0] === 'proxy') {
      return null // Proxy items don't need lookup
    }
    
    // Handle activity keys: activity-rt-5 or activity-eb-5
    if (parts[0] === 'activity' && (parts[1] === 'rt' || parts[1] === 'eb')) {
      const normalizedId = Number(parts[2])
      for (const category of assignables.value) {
        if (category.type !== 'activity') continue
        for (const group of category.groups) {
          const found = group.items.find(i => i.id === normalizedId)
          if (found) return found
        }
      }
      return null
    }
    
    // Handle team keys: team-123
    if (parts[0] === 'team') {
      const normalizedId = Number(parts[1])
      for (const category of assignables.value) {
        if (category.type !== 'team') continue
        for (const group of category.groups) {
          const found = group.items.find(i => i.id === normalizedId)
          if (found) return found
        }
      }
      return null
    }
    
    // Legacy format fallback: activity-5 or team-5 (for backwards compatibility)
    const normalizedId = Number(parts[1])
    const typeFilter = parts[0] === 'team' || parts[0] === 'activity' ? parts[0] : null
    for (const category of assignables.value) {
      if (typeFilter && category.type !== typeFilter) continue
      for (const group of category.groups) {
        const found = group.items.find(i => i.id === normalizedId)
        if (found) return found
      }
    }
  }
  
  // If no dashes, treat as plain ID and search all items
  const normalizedId = Number(str)
  for (const category of assignables.value) {
    for (const group of category.groups) {
      const found = group.items.find(i => i.id === normalizedId)
      if (found) return found
    }
  }
  return null
}

// --- Unassign ---
const unassignItemFromRoom = async (itemKey) => {
  // Handle proxy items
  if (itemKey === 'proxy-explore' || itemKey === 'proxy-challenge') {
    await handleProxyAssignment(itemKey, null)
    return
  }
  
  const item = findItemById(itemKey)
  if (!item) return

  // Lokale Zuordnung löschen
  assignments.value[itemKey] = null

  if (item.type === 'activity') {
    const isExtraBlock = item?.item_type === 'extra_block' || item?.group?.id === 999
    await axios.put(`/rooms/assign-types`, {
      type_id: item.id,
      room_id: null,
      event: eventStore.selectedEvent?.id,
      extra_block: isExtraBlock
    })
  }

  if (item.type === 'team') {
    await axios.put(`/rooms/assign-teams`, {
      team_id: item.id,
      room_id: null,
      event: eventStore.selectedEvent?.id
    })
  }

  // ✅ Nach erfolgreicher Änderung Readiness global neu laden
  if (eventStore.selectedEvent?.id) {
    await eventStore.refreshReadiness(eventStore.selectedEvent.id)
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
  if (item && item.key) {
    // Use the key directly since all items have a unique key property
    await assignItemToRoom(item.key, room.id)
  } else if (item && item.id && item.type) {
    // Fallback: construct key if not present
    const key = `${item.type}-${item.id}`
    await assignItemToRoom(key, room.id)
  } else {
    console.warn('Ungültiges Item beim Drop:', item)
  }
  dragOverRoomId.value = null
  isDragging.value = false
}

// --- Room reordering ---
const handleRoomReorder = async () => {
  try {
    const roomsWithSequence = rooms.value.map((room, index) => ({
      room_id: room.id,
      sequence: index + 1
    }))

    await axios.put('/rooms/update-sequence', {
      rooms: roomsWithSequence,
      event_id: eventId.value
    })
  } catch (error) {
    console.error('Error updating room sequence:', error)
    // Optionally reload rooms to restore original order
    const { data: roomsData } = await axios.get(`/events/${eventId.value}/rooms`)
    rooms.value = Array.isArray(roomsData) ? roomsData : (roomsData?.rooms ?? [])
  }
}

// --- Raum löschen ---
const roomToDelete = ref(null)

const askDeleteRoom = (room) => {
  roomToDelete.value = room
}

const confirmDeleteRoom = async () => {
  if (!roomToDelete.value) return
  const deletedRoomId = roomToDelete.value.id
  await axios.delete(`/rooms/${deletedRoomId}`)
  rooms.value = rooms.value.filter(r => r.id !== deletedRoomId)

  Object.keys(assignments.value).forEach(key => {
    if (assignments.value[key] === deletedRoomId) assignments.value[key] = null
  })

  roomToDelete.value = null
}

const cancelDeleteRoom = () => {
  roomToDelete.value = null
}

const deleteRoomMessage = computed(() => {
  if (!roomToDelete.value) return ''
  return `Raum "${roomToDelete.value.name || 'Unbekannt'}" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

// --- Klick außerhalb Eingabefelds ---
const handleClickOutside = (event) => {
  if (newRoomCardRef.value && !newRoomCardRef.value.contains(event.target)) {
    if (newRoomName.value.trim() || newRoomNote.value.trim()) createRoom()
  }
}

onMounted(() => document.addEventListener('click', handleClickOutside))
onUnmounted(() => document.removeEventListener('click', handleClickOutside))

const activeTab = ref('activities')

// --- Bulk Team Assignment Feature ---
const bulkModeExplore = ref(false)
const bulkModeChallenge = ref(false)

// Proxy keys for bulk assignment (constants for internal use)
const PROXY_EXPLORE_KEY = 'proxy-explore'
const PROXY_CHALLENGE_KEY = 'proxy-challenge'

// --- Persistence: localStorage with event scope ---
const getStorageKey = () => {
  if (!eventId.value) return null
  return `rooms-bulk-mode-${eventId.value}`
}

// Load saved bulk mode preferences for current event
const loadBulkModePreferences = () => {
  const key = getStorageKey()
  if (!key) return
  
  try {
    const saved = localStorage.getItem(key)
    if (saved) {
      const { explore, challenge } = JSON.parse(saved)
      bulkModeExplore.value = explore ?? false
      bulkModeChallenge.value = challenge ?? false
      
      // Restore proxy assignments if bulk mode is enabled and teams are assigned
      // We need to check after assignments are loaded, so we'll call restoreProxyAssignments separately
      nextTick(() => {
        restoreProxyAssignments()
      })
    }
  } catch (e) {
    console.warn('Failed to load bulk mode preferences', e)
  }
}

// Restore proxy assignments based on actual team assignments
const restoreProxyAssignments = () => {
  // Check Explore teams
  if (bulkModeExplore.value && exploreTeams.value.length > 0) {
    const teamsWithAssignments = exploreTeams.value
      .map(t => ({ id: t.id, room: assignments.value[`team-${t.id}`] }))
      .filter(t => t.room !== null && t.room !== undefined)
    
    if (teamsWithAssignments.length === exploreTeams.value.length) {
      // All teams assigned - check if they're in the same room
      const roomIds = [...new Set(teamsWithAssignments.map(t => t.room))]
      if (roomIds.length === 1) {
        // All teams in the same room - restore proxy assignment
        assignments.value[PROXY_EXPLORE_KEY] = roomIds[0]
      }
    }
  }
  
  // Check Challenge teams
  if (bulkModeChallenge.value && challengeTeams.value.length > 0) {
    const teamsWithAssignments = challengeTeams.value
      .map(t => ({ id: t.id, room: assignments.value[`team-${t.id}`] }))
      .filter(t => t.room !== null && t.room !== undefined)
    
    if (teamsWithAssignments.length === challengeTeams.value.length) {
      // All teams assigned - check if they're in the same room
      const roomIds = [...new Set(teamsWithAssignments.map(t => t.room))]
      if (roomIds.length === 1) {
        // All teams in the same room - restore proxy assignment
        assignments.value[PROXY_CHALLENGE_KEY] = roomIds[0]
      }
    }
  }
}

// Save bulk mode preferences when they change
watch([bulkModeExplore, bulkModeChallenge], ([explore, challenge]) => {
  const key = getStorageKey()
  if (!key) return
  
  try {
    localStorage.setItem(key, JSON.stringify({ explore, challenge }))
  } catch (e) {
    console.warn('Failed to save bulk mode preferences', e)
  }
})

// Reload preferences when event changes
watch(eventId, () => {
  loadBulkModePreferences()
})

// Find proxy assignment room ID (returns null if not assigned)
const getProxyRoomId = (proxyKey) => {
  return assignments.value[proxyKey] || null
}

// Get all teams for a program
const getTeamsForProgram = (program) => {
  if (program === 'explore') return exploreTeams.value
  if (program === 'challenge') return challengeTeams.value
  return []
}

// Checkbox toggle handler - unassign all teams when enabling bulk mode
const toggleBulkMode = async (program) => {
  const isExplore = program === 'explore'
  const currentMode = isExplore ? bulkModeExplore.value : bulkModeChallenge.value
  
  if (!currentMode) {
    // Enabling bulk mode: unassign all teams of this program
    const teams = getTeamsForProgram(program)
    for (const team of teams) {
      const key = `team-${team.id}`
      if (assignments.value[key]) {
        await unassignItemFromRoom(key)
      }
    }
    // Set bulk mode after unassigning
    if (isExplore) {
      bulkModeExplore.value = true
    } else {
      bulkModeChallenge.value = true
    }
  } else {
    // Disabling bulk mode: if proxy is assigned, keep assignments, otherwise clear
    const proxyKey = isExplore ? 'proxy-explore' : 'proxy-challenge'
    const proxyRoomId = getProxyRoomId(proxyKey)
    
    if (proxyRoomId) {
      // Proxy is assigned: all teams should appear individually in that room
      const teams = getTeamsForProgram(program)
      // First, assign all teams to backend
      for (const team of teams) {
        const key = `team-${team.id}`
        assignments.value[key] = proxyRoomId
        await axios.put(`/rooms/assign-teams`, {
          team_id: team.id,
          room_id: proxyRoomId,
          event: eventStore.selectedEvent?.id
        })
      }
      // Remove proxy assignment
      assignments.value[proxyKey] = null
    }
    
    if (isExplore) {
      bulkModeExplore.value = false
    } else {
      bulkModeChallenge.value = false
    }
  }
  
  // Refresh readiness after mode change
  if (eventStore.selectedEvent?.id) {
    await eventStore.refreshReadiness(eventStore.selectedEvent.id)
  }
}

// Bulk assign all teams of a program to a room
const bulkAssignTeams = async (program, roomId) => {
  const teams = getTeamsForProgram(program)
  
  // Assign all teams to the room
  for (const team of teams) {
    const key = `team-${team.id}`
    assignments.value[key] = roomId
    
    await axios.put(`/rooms/assign-teams`, {
      team_id: team.id,
      room_id: roomId,
      event: eventStore.selectedEvent?.id
    })
  }
  
  // Also set proxy assignment
  const proxyKey = program === 'explore' ? 'proxy-explore' : 'proxy-challenge'
  assignments.value[proxyKey] = roomId
}

// Bulk unassign all teams of a program
const bulkUnassignTeams = async (program) => {
  const teams = getTeamsForProgram(program)
  
  // Unassign all teams
  for (const team of teams) {
    const key = `team-${team.id}`
    if (assignments.value[key]) {
      assignments.value[key] = null
      await axios.put(`/rooms/assign-teams`, {
        team_id: team.id,
        room_id: null,
        event: eventStore.selectedEvent?.id
      })
    }
  }
  
  // Remove proxy assignment
  const proxyKey = program === 'explore' ? 'proxy-explore' : 'proxy-challenge'
  assignments.value[proxyKey] = null
}

// Handle proxy item assignment/unassignment
const handleProxyAssignment = async (proxyKey, roomId) => {
  const program = proxyKey === 'proxy-explore' ? 'explore' : 'challenge'
  
  if (roomId) {
    await bulkAssignTeams(program, roomId)
  } else {
    await bulkUnassignTeams(program)
  }
  
  // Refresh readiness
  if (eventStore.selectedEvent?.id) {
    await eventStore.refreshReadiness(eventStore.selectedEvent.id)
  }
}

// Hilfsfunktion für Template (typisierte IDs)
const getItemsInRoom = (roomId) => {
  const all = []
  
  // Handle regular items
  for (const category of assignables.value) {
    for (const group of category.groups) {
      if (category.type === 'team') {
        // For teams: check bulk mode and show proxy or individual teams
        const isExplore = group.id === 'explore'
        const isChallenge = group.id === 'challenge'
        const bulkMode = isExplore ? bulkModeExplore.value : (isChallenge ? bulkModeChallenge.value : false)
        
        if (bulkMode) {
          // Bulk mode: check if proxy is assigned to this room
          const proxyKey = isExplore ? 'proxy-explore' : 'proxy-challenge'
          if (assignments.value[proxyKey] === roomId) {
            all.push({
              key: proxyKey,
              type: 'team-proxy',
              name: isExplore ? 'Alle FLL Explore Teams' : 'Alle FLL Challenge Teams',
              first_program: isExplore ? 2 : 3,
              program: isExplore ? 'explore' : 'challenge'
            })
          }
        } else {
          // Individual mode: show individual teams assigned to this room
          all.push(...group.items.filter(i => assignments.value[i.key] === roomId))
        }
      } else {
        // Activities: use the item's key property (which has rt/eb prefix)
        all.push(...group.items.filter(i => assignments.value[i.key] === roomId))
      }
    }
  }
  return all
}




// --- Data Readiness: direkt aus Store ---

// Reaktive Referenz auf den Store-Status
const readinessStatus = computed(() => eventStore.readiness)

// --- Beim Start einmal initial laden ---
onMounted(async () => {
  if (eventStore.selectedEvent?.id) {
    await eventStore.refreshReadiness(eventStore.selectedEvent.id)
  }
})

// --- Watcher für Änderungen am Store (z. B. aus anderen Seiten) ---
watch(
  () => eventStore.readiness,
  (newVal) => {
    if (newVal) console.debug('Readiness aktualisiert:', newVal)
  },
  { deep: true }
)

// --- Helper für Warnungen ---
const hasWarning = (tab) => {
  const details = readinessStatus.value?.room_mapping_details || {}
  if (tab === 'activities') return details.activities_ok === false
  if (tab === 'teams') return details.teams_ok === false
  return false
}

</script>

<template>
  <div v-if="loading" class="flex items-center justify-center h-full flex-col text-gray-600 min-h-[400px]">
    <LoaderFlow/>
    <LoaderText/>
  </div>
  <div v-else class="grid grid-cols-4 gap-6 p-6">
    <!-- 🟢 Räume: Erste 3 Spalten -->
    <div class="col-span-3">
      <h2 class="text-xl font-bold mb-4">Räume</h2>
      <div class="grid grid-cols-3 gap-4">
        <draggable
          v-model="rooms"
          group="rooms"
          item-key="id"
          @start="isDraggingRoom = true"
          @end="isDraggingRoom = false; handleRoomReorder()"
          class="contents"
        >
          <template #item="{ element: room }">
            <div
              :key="room.id"
              class="p-4 mb-2 border rounded bg-white shadow cursor-move hover:shadow-md transition-shadow"
              :class="{
                'opacity-50': isDraggingRoom,
                'shadow-lg': isDraggingRoom
              }"
            >
              <!-- Line 1: Drag handle, Room name, Delete icon -->
              <div class="flex items-center gap-2 mb-2">
                <div class="text-gray-400 cursor-move select-none">⋮⋮</div>
                <input
                  v-model="room.name"
                  class="text-md font-semibold border-b border-gray-300 flex-1 focus:outline-none focus:border-blue-500"
                  @blur="updateRoom(room)"
                />
                <button
                  @click="askDeleteRoom(room)"
                  class="text-red-600 text-lg"
                  title="Raum löschen"
                >
                  🗑️
                </button>
              </div>

              <!-- Line 2: Navigation instruction full width with accessibility icon at end -->
              <div class="mb-2 flex items-center gap-2">
                <input
                  v-model="room.navigation_instruction"
                  class="text-sm border-b border-gray-300 flex-1 text-gray-700 focus:outline-none focus:border-blue-500"
                  placeholder="z. B. 2. Etage rechts"
                  @blur="updateRoom(room)"
                />
                <div 
                  class="cursor-pointer"
                  :title="room.is_accessible ? 'Barrierefrei' : 'Nicht barrierefrei'"
                  @click="toggleAccessibility(room)"
                >
                  <img 
                    :src="room.is_accessible ? '/flow/accessible_yes.png' : '/flow/accessible_no.png'"
                    :alt="room.is_accessible ? 'Barrierefrei' : 'Nicht barrierefrei'"
                    class="w-6 h-6"
                  />
                </div>
              </div>

              <!-- Line 3: Drop area full width with reduced padding -->
              <div
                class="flex flex-wrap gap-1 min-h-[40px] border rounded p-1 transition-colors"
                :class="{
                  'bg-blue-100': dragOverRoomId === room.id,
                  'bg-yellow-100': isDragging && dragOverRoomId !== room.id,
                  'bg-gray-50': !isDragging && dragOverRoomId !== room.id
                }"
              >
                  <draggable
                    :list="getItemsInRoom(room.id)"
                    group="assignables"
                    item-key="key"
                    @add="event => handleDrop(event, room)"
                    @start="isDragging = true"
                    @end="isDragging = false"
                    class="flex flex-wrap gap-1 w-full"
                  >

                  
                    <template #item="{ element }">
                      <div class="flex items-center">
                        <!-- Activity -->
                        <span
                          v-if="element.type === 'activity'"
                          :style="{ border: '2px solid ' + getProgramColor(element), backgroundColor: '#fff' }"
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

                        <!-- Team Proxy -->
                        <span
                          v-else-if="element.type === 'team-proxy'"
                          class="flex items-center border rounded-md text-xs bg-white shadow-sm cursor-move"
                        >
                          <span
                            class="w-1.5 self-stretch rounded-l-md"
                            :style="{ backgroundColor: getProgramColor(element) }"
                          ></span>
                          <span class="px-2 py-1 flex items-center gap-1">
                            <img
                              v-if="programLogoSrc(element.first_program)"
                              :src="programLogoSrc(element.first_program)"
                              :alt="programLogoAlt(element.first_program)"
                              class="w-3 h-3 flex-shrink-0"
                            />
                            {{ element.name }}
                          </span>
                          <button
                            class="ml-1 text-sm text-gray-500 hover:text-black pr-1"
                            @click.stop="unassignItemFromRoom(element.key)"
                          >
                            ✖
                          </button>
                        </span>

                        <!-- Team -->
                        <span
                          v-else
                          class="flex items-center border rounded-md text-xs bg-white shadow-sm cursor-move"
                        >
                          <span
                              class="w-1.5 self-stretch rounded-l-md"
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
                      </div>
                    </template>

                  </draggable>
                </div>
            </div>
          </template>
        </draggable>

        <!-- 🟩 Neuer Raum (always visible, outside draggable) -->
        <div
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
        </div>
      </div>
    </div>

    <!-- 🔵 Rechte Spalte: Aktivitäten & Teams -->
    <div class="col-span-1">
      <div class="flex mb-4 border-b text-xl font-bold relative">
        <button
          class="px-4 py-2 relative"
          :class="activeTab === 'activities' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-600'"
          @click="activeTab = 'activities'"
        >
          Aktivitäten
          <div
            v-if="hasWarning('activities')"
            class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"
            title="Noch nicht alle Aktivitäten zugeordnet"
          ></div>
        </button>

        <button
          class="px-4 py-2 ml-4 relative"
          :class="activeTab === 'teams' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-600'"
          @click="activeTab = 'teams'"
        >
          Teams
          <div
            v-if="hasWarning('teams')"
            class="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"
            title="Noch nicht alle Teams zugeordnet"
          ></div>
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
          
          <!-- Bulk mode checkbox for teams -->
          <div v-if="category.type === 'team'" class="mb-2">
            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
              <input
                type="checkbox"
                :checked="(group.id === 'explore' ? bulkModeExplore : bulkModeChallenge)"
                @change="toggleBulkMode(group.id)"
                class="cursor-pointer"
              />
              <span>Alle Teams zusammen</span>
            </label>
          </div>
          
          <draggable
            :list="category.type === 'team' && (group.id === 'explore' ? bulkModeExplore : group.id === 'challenge' ? bulkModeChallenge : false)
              ? (() => {
                  const proxyKey = group.id === 'explore' ? 'proxy-explore' : 'proxy-challenge'
                  return [{
                    key: proxyKey,
                    type: 'team-proxy',
                    name: group.id === 'explore' ? 'Alle FLL Explore Teams' : 'Alle FLL Challenge Teams',
                    first_program: group.id === 'explore' ? 2 : 3,
                    program: group.id
                  }].filter(p => !assignments[p.key])
                })()
              : group.items.filter(i => !assignments[i.key])"
            group="assignables"
            item-key="key"
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
              </span>

              <span
                v-else-if="element.type === 'team-proxy'"
                class="flex items-center border rounded-md text-xs bg-white shadow-sm cursor-move"
              >
                <span
                  class="w-1.5 self-stretch rounded-l-md"
                  :style="{ backgroundColor: getProgramColor(element) }"
                ></span>
                <span class="px-2 py-1 flex items-center gap-1">
                  <img
                    v-if="programLogoSrc(element.first_program)"
                    :src="programLogoSrc(element.first_program)"
                    :alt="programLogoAlt(element.first_program)"
                    class="w-3 h-3 flex-shrink-0"
                  />
                  {{ element.name }}
                </span>
              </span>

              <span
                v-else-if="element.type === 'team'"
                class="flex items-center border rounded-md text-xs bg-white shadow-sm cursor-move"
              >
              <span
                class="w-1.5 self-stretch rounded-l-md"
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
              </span>
            </template>



          </draggable>
        </div>
      </div>
    </div>
  </div>

  <!-- 🔴 Lösch-Modal -->
  <ConfirmationModal
    :show="!!roomToDelete"
    title="Raum löschen"
    :message="deleteRoomMessage"
    type="danger"
    confirm-text="Löschen"
    cancel-text="Abbrechen"
    @confirm="confirmDeleteRoom"
    @cancel="cancelDeleteRoom"
  />
</template>
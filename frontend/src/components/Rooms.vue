<script setup>
import {ref, onMounted, onUnmounted, computed, nextTick} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import draggable from 'vuedraggable'

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const rooms = ref([])
const roomTypes = ref([])
const typeGroups = ref([])
const assignments = ref({})
const scheduleParameters = ref({})
const extraBlocks = ref([])

const dragOverRoomId = ref(null)
const isDragging = ref(false)
const previewedTypeId = ref(null)

const getProgramColor = (type) => {
  // Check if this room type is associated with an extra block
  const associatedExtraBlock = extraBlocks.value.find(block => {
    return block.insert_point && 
           block.insert_point.room_type && 
           block.insert_point.room_type.id === type.id
  })
  
  if (associatedExtraBlock) {
    // Color based on extra block program
    if (associatedExtraBlock.first_program === 2 || associatedExtraBlock.first_program === 0) {
      return '#10B981' // Green for Explore (or both programs)
    } else if (associatedExtraBlock.first_program === 3) {
      return '#EF4444' // Red for Challenge only
    }
  }
  
  // Fallback to original color logic
  return type?.group?.program?.color || '#888888'
}

// Get current jury group counts from schedule parameters
const challengeJuryGroups = computed(() => {
  return Number(scheduleParameters.value['j_lanes'] || 0)
})

const exploreJuryGroupsAM = computed(() => {
  return Number(scheduleParameters.value['e1_lanes'] || 0)
})

const exploreJuryGroupsPM = computed(() => {
  return Number(scheduleParameters.value['e2_lanes'] || 0)
})

// Get program modes to determine if programs are enabled
const challengeMode = computed(() => {
  return Number(scheduleParameters.value['c_mode'] || 0)
})

const exploreMode = computed(() => {
  return Number(scheduleParameters.value['e_mode'] || 0)
})

// Check if an extra block is enabled by room type ID
const isExtraBlockEnabled = computed(() => {
  return (roomTypeId) => {
    // Check if any extra block has an insert point with this room type
    const enabled = extraBlocks.value.some(block => {
      return block.insert_point && 
             block.insert_point.room_type && 
             block.insert_point.room_type.id === roomTypeId
    })
    
    console.log(`Checking extra block for room type ID ${roomTypeId}:`, {
      enabled,
      availableBlocks: extraBlocks.value.map(b => ({
        name: b.name,
        insertPoint: b.insert_point?.room_type?.id
      })),
      matchingBlocks: extraBlocks.value.filter(block => 
        block.insert_point && 
        block.insert_point.room_type && 
        block.insert_point.room_type.id === roomTypeId
      )
    })
    return enabled
  }
})

// Filter room types based on jury group configuration
const filteredRoomTypes = computed(() => {
  console.log('All room types:', roomTypes.value.map(t => ({ name: t.name, group: t.group?.name })))
  console.log('Available extra blocks:', extraBlocks.value.map(b => b.name))
  
  return roomTypes.value.filter(type => {
    const groupName = type.group?.name?.toLowerCase() || ''
    const typeName = type.name?.toLowerCase() || ''
    
    // Debug logging
    console.log('Filtering room type:', {
      name: type.name,
      groupName: type.group?.name,
      challengeJuryGroups: challengeJuryGroups.value,
      exploreJuryGroupsAM: exploreJuryGroupsAM.value,
      exploreJuryGroupsPM: exploreJuryGroupsPM.value
    })
    
    // For jurybewertung (Challenge jury groups)
    if (groupName.includes('jurybewertung') || groupName.includes('jury') || typeName.includes('jury')) {
      // Hide if Challenge mode is disabled
      if (challengeMode.value === 0) {
        console.log(`Challenge room ${type.name}: hidden (c_mode=0)`)
        return false
      }
      
      const juryGroupNumber = extractJuryGroupNumber(type.name)
      const shouldShow = juryGroupNumber <= challengeJuryGroups.value
      console.log(`Challenge room ${type.name}: group ${juryGroupNumber} <= ${challengeJuryGroups.value} = ${shouldShow}`)
      return shouldShow
    }
    
    // For begutachtung (Explore jury groups)
    if (groupName.includes('begutachtung') || groupName.includes('explore') || typeName.includes('begutachtung')) {
      // Hide if Explore mode is disabled
      if (exploreMode.value === 0) {
        console.log(`Explore room ${type.name}: hidden (e_mode=0)`)
        return false
      }
      
      const juryGroupNumber = extractJuryGroupNumber(type.name)
      const maxExploreGroups = Math.max(exploreJuryGroupsAM.value, exploreJuryGroupsPM.value)
      const shouldShow = juryGroupNumber <= maxExploreGroups
      console.log(`Explore room ${type.name}: group ${juryGroupNumber} <= ${maxExploreGroups} = ${shouldShow}`)
      return shouldShow
    }
    
    // For extra block room types, only show if the corresponding extra block is enabled
    if (groupName.includes('zusatz') || groupName.includes('extra') || groupName.includes('block') || 
        typeName.includes('zusatz') || typeName.includes('extra') || typeName.includes('block')) {
      // If no extra blocks are loaded yet, show all extra block room types as fallback
      if (extraBlocks.value.length === 0) {
        console.log(`Extra block room ${type.name}: showing (no extra blocks loaded yet)`)
        return true
      }
      
      // Check if this room type is associated with an extra block and its program mode
      const associatedExtraBlock = extraBlocks.value.find(block => {
        return block.insert_point && 
               block.insert_point.room_type && 
               block.insert_point.room_type.id === type.id
      })
      
      if (associatedExtraBlock) {
        // Hide if the extra block's program is disabled
        if (associatedExtraBlock.first_program === 3 && challengeMode.value === 0) {
          console.log(`Extra block room ${type.name}: hidden (Challenge extra block, c_mode=0)`)
          return false
        }
        if (associatedExtraBlock.first_program === 2 && exploreMode.value === 0) {
          console.log(`Extra block room ${type.name}: hidden (Explore extra block, e_mode=0)`)
          return false
        }
        if (associatedExtraBlock.first_program === 0 && challengeMode.value === 0 && exploreMode.value === 0) {
          console.log(`Extra block room ${type.name}: hidden (Both programs extra block, both modes=0)`)
          return false
        }
      }
      
      const shouldShow = isExtraBlockEnabled.value(type.id)
      console.log(`Extra block room ${type.name} (ID: ${type.id}, group: ${groupName}): enabled = ${shouldShow}`)
      return shouldShow
    }
    
    // For other room types, show all
    console.log(`Other room ${type.name}: showing`)
    return true
  })
})

// Extract jury group number from room type name (e.g., "Jurygruppe 1" -> 1)
const extractJuryGroupNumber = (name) => {
  const match = name.match(/(\d+)/)
  return match ? parseInt(match[1]) : 0
}

onMounted(async () => {
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  
  // Fetch rooms and room types
  const {data} = await axios.get(`/events/${eventId.value}/rooms`)
  rooms.value = data.rooms
  roomTypes.value = data.roomTypes
  typeGroups.value = data.groups

  // Fetch schedule parameters to get jury group configuration
  try {
    // Get the plan for this event
    const {data: planData} = await axios.get(`/plans/event/${eventId.value}`)
    
    if (planData && planData.id) {
      const {data: paramsData} = await axios.get(`/plans/${planData.id}/parameters`)
      scheduleParameters.value = paramsData.reduce((acc, param) => {
        if (param.name) {
          acc[param.name] = param.value
        }
        return acc
      }, {})
      
      // Fetch extra blocks for this plan (with room types for filtering)
      const {data: extraBlocksData} = await axios.get(`/plans/${planData.id}/extra-blocks-with-room-types`)
      extraBlocks.value = extraBlocksData
      console.log('Fetched extra blocks:', extraBlocksData)
      console.log('Extra blocks with insert points:', extraBlocksData.map(b => ({
        name: b.name,
        insert_point: b.insert_point,
        room_type: b.insert_point?.room_type
      })))
    }
  } catch (error) {
    console.warn('Could not fetch schedule parameters or extra blocks:', error)
  }

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

// Removed accordion functionality - all groups are always visible

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
                    :list="filteredRoomTypes.filter(t => assignments[t.id] === room.id)"
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

    <!-- Assignment panel -->
    <div>
      <h2 class="text-xl font-bold mb-4">Raumzuordnung</h2>
      <div
          v-for="group in typeGroups"
          :key="group.id"
          class="mb-6 bg-gray-50 border rounded-lg p-4 shadow"
      >
        <div class="text-lg font-semibold text-black mb-3">
          {{ group.name }}
        </div>

        <draggable
            :list="filteredRoomTypes.filter(t => t.group?.id === group.id && !assignments[t.id])"
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

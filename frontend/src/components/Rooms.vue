<script setup>
import {ref, onMounted, computed, nextTick, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import {usePlanCacheStore} from '@/stores/planCache'
import draggable from 'vuedraggable'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {eventPrograms, programDisplayName, programMatchesSlug, programSlug, programNameForId} from '@/utils/eventPrograms'
import {getProgramTheme} from '@/utils/programTheme'
import LoaderFlow from "@/components/atoms/LoaderFlow.vue";
import LoaderText from "@/components/atoms/LoaderText.vue";
import IconDangerButton from "@/components/atoms/IconDangerButton.vue";
import ConfirmationModal from "@/components/molecules/ConfirmationModal.vue";
import ItemCard from "@/components/molecules/ItemCard.vue";
import ItemComposer from "@/components/molecules/ItemComposer.vue";

defineOptions({name: 'Rooms'})

// --- Stores & Refs ---
const eventStore = useEventStore()
const planCache = usePlanCacheStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => eventStore.selectedEvent?.id)
const rooms = ref([])
const assignments = ref({})

// --- Gemeinsame Struktur für Activities + Teams ---
const assignables = ref([]) // ← gemeinsame Ebene 1 (type = 'activity' | 'team')

// --- Hilfslisten ---
const roomTypes = ref([])
const typeGroups = ref([])
const hasTwoExploreGroups = ref(false)

// People data from DRAHT API
const peopleData = ref({})

const dragOverRoomId = ref(null)
const isDragging = ref(false)
const isDraggingRoom = ref(false)
const previewedTypeId = ref(null)

// --- Farbzuweisung ---
const itemProgramName = (item) => {
  if (item?.program_name) return item.program_name
  return programNameForId(event.value, item?.first_program)
}

const itemProgramRef = (item) => ({
  first_program: item?.first_program,
  name: itemProgramName(item),
})

const showItemProgramLogo = (item) => !!(item?.first_program || itemProgramName(item))

const getProgramColor = (item) => {
  const programs = eventPrograms(event.value)
  const row = programs.find((p) =>
      Number(p.first_program) === Number(item?.first_program)
      || programMatchesSlug(p.name, item?.program_name)
  )
  const hex = row?.color_hex
  if (hex) return hex.startsWith('#') ? hex : `#${hex}`
  const theme = getProgramTheme(itemProgramName(item) || String(item?.first_program || ''))
  if (theme.key !== 'shared') return theme.accent
  return '#9CA3AF'
}

// --- Format program name with italic FIRST ---
// Handles both normalized names (FIRST LEGO League) and DB names (FLL Explore/Challenge/Future 8+)
const formatProgramName = (name) => {
  if (!name) return ''

  // First, expand FLL to FIRST LEGO League if present
  let normalized = name
      .replace(/^FLL Explore$/i, 'FIRST LEGO League Explore')
      .replace(/^FLL Challenge$/i, 'FIRST LEGO League Challenge')
      .replace(/^FLL Future 8\+$/i, 'FIRST LEGO League Future 8+')
      .replace(/FLL /g, 'FIRST LEGO League ')

  // Then apply italic styling to FIRST
  return normalized.replace(/FIRST/g, '<span class="italic">FIRST</span>')
}

// Get people count for a team (players + coaches)
const getPeopleCount = (team) => {
  if (!team || !team.number) return null
  const teamNumber = String(team.number)
  if (!peopleData.value[teamNumber]) {
    return null
  }
  const teamData = peopleData.value[teamNumber]
  return (teamData.num_players || 0) + (teamData.num_coaches || 0)
}

// --- Loading state ---
const loading = ref(true)

// --- Lifecycle ---
onMounted(async () => {
  loading.value = true
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()

  // Räume laden
  const {data: roomsData} = await axios.get(`/events/${eventId.value}/rooms`)
  rooms.value = Array.isArray(roomsData) ? roomsData : (roomsData?.rooms ?? [])

  // Plan-ID holen
  const planData = await planCache.getPlan(eventId.value)
  if (!planData?.id) {
    if (import.meta.env.DEV) {
      console.debug('Kein Plan für Event gefunden')
    }
    loading.value = false
    return
  }

  // --- Aktivitäten (room-types) laden ---
  const {data: roomTypeGroups} = await axios.get(`/room-types/${planData.id}`)
  typeGroups.value = roomTypeGroups
  roomTypes.value = roomTypeGroups.flatMap(group =>
      group.room_types.map(rt => ({
        id: rt.type_id,
        key: `activity-${rt.type_id}`,
        name: rt.type_name,
        first_program: rt.first_program,
        program_name: programNameForId(event.value, rt.first_program),
        type: 'activity',
        group: {id: group.id, name: group.name}
      }))
  )

  // --- Teams laden über neue API ---
  const mapTeam = (t, program, groupId, groupName) => ({
    id: t.id,
    key: `team-${t.id}`,
    number: t.team_number_hot,
    name: t.name ?? 'Unbenannt',
    type: 'team',
    first_program: Number(program.first_program),
    program_name: program.name,
    room: t.room ?? null,
    team_number_plan: t.team_number_plan,
    group: {id: groupId, name: groupName}
  })

  const teamsFromResponse = (data) => {
    if (Array.isArray(data)) return {teams: data, metadata: {}}
    return {teams: data?.teams || [], metadata: data?.metadata || {}}
  }

  const attached = eventPrograms(event.value)
  const toFetch = attached
  let teamGroups = []

  try {
    const results = await Promise.all(toFetch.map(async (program) => {
      try {
        const res = await axios.get(`/events/${eventId.value}/teams`, {
          params: {program: program.first_program || program.name, sort: 'name'}
        })
        return {program, ...teamsFromResponse(res.data)}
      } catch (err) {
        if (import.meta.env.DEV) {
          console.error('Fehler beim Laden der Teams:', err)
        }
        return {program, teams: [], metadata: {}}
      }
    }))

    hasTwoExploreGroups.value = false

    const teamsByProgram = new Map()
    for (const {program, teams, metadata} of results) {
      teamsByProgram.set(Number(program.first_program), {program, teams, metadata})
    }

    for (const program of toFetch) {
      const id = Number(program.first_program)
      const loaded = teamsByProgram.get(id) || {program, teams: [], metadata: {}}
      const label = programDisplayName(program)
      const slug = programSlug(program.name)

      if (programMatchesSlug(program.name, 'explore')) {
        const eMode = loaded.metadata.e_mode || 0
        const e1Teams = loaded.metadata.e1_teams || 0
        hasTwoExploreGroups.value = (eMode === 8 || eMode === 5) && e1Teams > 0
        if (hasTwoExploreGroups.value) {
          teamGroups.push(
              {
                id: 'explore-morning',
                name: 'Explore Vormittag',
                first_program: id,
                program_name: program.name,
                items: loaded.teams
                    .filter(t => (t.team_number_plan || 0) <= e1Teams)
                    .map(t => mapTeam(t, program, 'explore-morning', 'Explore Vormittag')),
              },
              {
                id: 'explore-afternoon',
                name: 'Explore Nachmittag',
                first_program: id,
                program_name: program.name,
                items: loaded.teams
                    .filter(t => (t.team_number_plan || 0) > e1Teams)
                    .map(t => mapTeam(t, program, 'explore-afternoon', 'Explore Nachmittag')),
              },
          )
        } else {
          teamGroups.push({
            id: slug,
            name: label,
            first_program: id,
            program_name: program.name,
            items: loaded.teams.map(t => mapTeam(t, program, slug, label)),
          })
        }
      } else {
        teamGroups.push({
          id: slug,
          name: label,
          first_program: id,
          program_name: program.name,
          items: loaded.teams.map(t => mapTeam(t, program, slug, label)),
        })
      }
    }
  } catch (err) {
    if (import.meta.env.DEV) {
      console.error('Fehler beim Laden der Teams:', err)
    }
    teamGroups = []
  }

  assignables.value = [
    {
      id: 'activities',
      type: 'activity',
      groups: roomTypeGroups.map(g => ({
        id: g.id,
        name: g.name,
        items: g.room_types.map(rt => ({
          id: rt.type_id,
          key: rt.item_type === 'extra_block' ? `activity-eb-${rt.type_id}` : `activity-rt-${rt.type_id}`,
          name: rt.type_name,
          first_program: rt.first_program,
          program_name: programNameForId(event.value, rt.first_program),
          type: 'activity',
          group: {id: g.id, name: g.name},
          item_type: rt.item_type || 'room_type'
        }))
      }))
    },
    {
      id: 'teams',
      type: 'team',
      groups: teamGroups
    }
  ]

  const result = {}

  roomsData.rooms.forEach(room => {
    (room.room_types ?? []).forEach(rt => {
      result[`activity-rt-${rt.id}`] = room.id
    })
    ;(room.extra_blocks ?? []).forEach(eb => {
      result[`activity-eb-${eb.id}`] = room.id
    })
  })

  teamGroups.flatMap(g => g.items).forEach(team => {
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

  const fetchPeopleData = async () => {
    const promises = []
    for (const program of eventPrograms(event.value)) {
      if (!program.draht_id) continue
      promises.push(
          axios.get(`/draht/people/${program.draht_id}`)
              .then(res => {
                if (res.data) {
                  const {total_players, total_coaches, ...teamsData} = res.data
                  Object.assign(peopleData.value, teamsData)
                }
              })
              .catch(err => {
                if (import.meta.env.DEV) {
                  console.error('Failed to fetch people data', err)
                }
              })
      )
    }
    await Promise.all(promises)
  }

  await fetchPeopleData()

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
  if (String(itemKey).startsWith('proxy-')) {
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
  if (String(itemKey).startsWith('proxy-')) {
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
const isSaving = ref(false)
const isCreatingRoom = ref(false)
const mobileComposerRef = ref(null)
const desktopComposerRef = ref(null)

const focusNewRoomComposer = () => {
  const desktop = typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches
  const composer = desktop ? desktopComposerRef.value : mobileComposerRef.value
  composer?.focusTitle()
}

const createRoom = async () => {
  if (isCreatingRoom.value) return
  if (!newRoomName.value.trim()) return

  isCreatingRoom.value = true
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
    await nextTick()
    focusNewRoomComposer()
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
    if (import.meta.env.DEV) {
      console.debug('Ungültiges Item beim Drop:', item)
    }
  }
  dragOverRoomId.value = null
  isDragging.value = false
}

const onRoomDropzoneLeave = (event, roomId) => {
  const next = event.relatedTarget
  if (next && event.currentTarget.contains(next)) return
  if (dragOverRoomId.value === roomId) {
    dragOverRoomId.value = null
  }
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
    if (import.meta.env.DEV) {
      console.error('Error updating room sequence:', error)
    }
    // Optionally reload rooms to restore original order
    const {data: roomsData} = await axios.get(`/events/${eventId.value}/rooms`)
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
  const name = roomToDelete.value.name || 'Unbekannt'
  return `„${name}“ wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

const activeTab = ref('activities')

// --- Mobile tap-to-assign ---
const showAssignModal = ref(false)
const selectedAssignable = ref(null)

const bulkModeByGroup = ref({})

const isBulkModeEnabled = (groupId) => !!bulkModeByGroup.value[groupId]

const proxyKeyForGroup = (groupId) => `proxy-${groupId}`

const groupIdFromProxyKey = (proxyKey) => String(proxyKey || '').replace(/^proxy-/, '')

const findTeamGroup = (groupId) => {
  const teamsCat = assignables.value.find(c => c.id === 'teams')
  return teamsCat?.groups.find(g => g.id === groupId) || null
}

const buildProxyItem = (groupId) => {
  const group = findTeamGroup(groupId)
  if (!group) return null
  return {
    key: proxyKeyForGroup(groupId),
    type: 'team-proxy',
    name: `Alle ${group.name} Teams`,
    first_program: group.first_program,
    program_name: group.program_name,
    program: groupId,
  }
}

const getUnassignedItems = (category, group) => {
  if (category.type === 'team' && isBulkModeEnabled(group.id)) {
    const proxy = buildProxyItem(group.id)
    return proxy && !assignments.value[proxy.key] ? [proxy] : []
  }
  return group.items.filter(i => !assignments.value[i.key])
}

const openAssignModal = (item) => {
  selectedAssignable.value = item
  showAssignModal.value = true
}

const closeAssignModal = () => {
  selectedAssignable.value = null
  showAssignModal.value = false
}

const assignSelectedToRoom = async (roomId) => {
  if (!selectedAssignable.value?.key) return
  await assignItemToRoom(selectedAssignable.value.key, roomId)
  closeAssignModal()
}

// --- Bulk Team Assignment Feature ---
const getStorageKey = () => {
  if (!eventId.value) return null
  return `rooms-bulk-mode-${eventId.value}`
}

const loadBulkModePreferences = () => {
  const key = getStorageKey()
  if (!key) return

  try {
    const saved = localStorage.getItem(key)
    if (saved) {
      const prefs = JSON.parse(saved)
      const groups = {...(prefs.groups || {})}
      if (prefs.explore) groups.explore = prefs.explore
      if (prefs.exploreMorning) groups['explore-morning'] = prefs.exploreMorning
      if (prefs.exploreAfternoon) groups['explore-afternoon'] = prefs.exploreAfternoon
      if (prefs.challenge) groups.challenge = prefs.challenge
      bulkModeByGroup.value = groups
      nextTick(() => {
        restoreProxyAssignments()
      })
    }
  } catch (e) {
    if (import.meta.env.DEV) {
      console.debug('Failed to load bulk mode preferences', e)
    }
  }
}

const restoreProxyAssignments = () => {
  const teamsCat = assignables.value.find(c => c.id === 'teams')
  for (const group of teamsCat?.groups || []) {
    if (!isBulkModeEnabled(group.id) || !group.items.length) continue
    const teamsWithAssignments = group.items
        .map(t => ({id: t.id, room: assignments.value[`team-${t.id}`]}))
        .filter(t => t.room !== null && t.room !== undefined)
    if (teamsWithAssignments.length !== group.items.length) continue
    const roomIds = [...new Set(teamsWithAssignments.map(t => t.room))]
    if (roomIds.length === 1) {
      assignments.value[proxyKeyForGroup(group.id)] = roomIds[0]
    }
  }
}

watch(bulkModeByGroup, (groups) => {
  const key = getStorageKey()
  if (!key) return
  try {
    localStorage.setItem(key, JSON.stringify({groups}))
  } catch (e) {
    if (import.meta.env.DEV) {
      console.debug('Failed to save bulk mode preferences', e)
    }
  }
}, {deep: true})

watch(eventId, () => {
  loadBulkModePreferences()
})

const getProxyRoomId = (proxyKey) => {
  return assignments.value[proxyKey] || null
}

const getTeamsForProgram = (programOrGroupId) => {
  return findTeamGroup(programOrGroupId)?.items || []
}

const setBulkMode = (groupId, value) => {
  bulkModeByGroup.value = {...bulkModeByGroup.value, [groupId]: value}
}

const toggleBulkMode = async (groupId) => {
  const proxyKey = proxyKeyForGroup(groupId)
  const currentMode = isBulkModeEnabled(groupId)

  if (!currentMode) {
    const teams = getTeamsForProgram(groupId)
    for (const team of teams) {
      const key = `team-${team.id}`
      if (assignments.value[key]) {
        await unassignItemFromRoom(key)
      }
    }
    setBulkMode(groupId, true)
  } else {
    const proxyRoomId = getProxyRoomId(proxyKey)
    if (proxyRoomId) {
      const teams = getTeamsForProgram(groupId)
      for (const team of teams) {
        const key = `team-${team.id}`
        assignments.value[key] = proxyRoomId
        await axios.put(`/rooms/assign-teams`, {
          team_id: team.id,
          room_id: proxyRoomId,
          event: eventStore.selectedEvent?.id
        })
      }
      assignments.value[proxyKey] = null
    }
    setBulkMode(groupId, false)
  }

  if (eventStore.selectedEvent?.id) {
    await eventStore.refreshReadiness(eventStore.selectedEvent.id)
  }
}

const bulkAssignTeams = async (groupId, roomId) => {
  const teams = getTeamsForProgram(groupId)
  for (const team of teams) {
    const key = `team-${team.id}`
    assignments.value[key] = roomId
    await axios.put(`/rooms/assign-teams`, {
      team_id: team.id,
      room_id: roomId,
      event: eventStore.selectedEvent?.id
    })
  }
  assignments.value[proxyKeyForGroup(groupId)] = roomId
}

const bulkUnassignTeams = async (groupId) => {
  const teams = getTeamsForProgram(groupId)
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
  assignments.value[proxyKeyForGroup(groupId)] = null
}

const handleProxyAssignment = async (proxyKey, roomId) => {
  const groupId = groupIdFromProxyKey(proxyKey)
  if (!groupId) return
  if (roomId) {
    await bulkAssignTeams(groupId, roomId)
  } else {
    await bulkUnassignTeams(groupId)
  }
  if (eventStore.selectedEvent?.id) {
    await eventStore.refreshReadiness(eventStore.selectedEvent.id)
  }
}

const getItemsInRoom = (roomId) => {
  const all = []
  for (const category of assignables.value) {
    for (const group of category.groups) {
      if (category.type === 'team') {
        if (isBulkModeEnabled(group.id)) {
          const proxy = buildProxyItem(group.id)
          if (proxy && assignments.value[proxy.key] === roomId) {
            all.push(proxy)
          }
        } else {
          all.push(...group.items.filter(i => assignments.value[i.key] === roomId))
        }
      } else {
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
    {deep: true}
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
  <div>
    <div v-if="loading" class="flex items-center justify-start h-full flex-col text-[var(--color-text-muted)] min-h-[400px]">
      <LoaderFlow/>
      <LoaderText/>
    </div>
    <div v-else class="grid grid-cols-1 lg:grid-cols-4 gap-4 lg:gap-5">
      <!-- Räume: Erste 3 Spalten -->
      <div class="lg:col-span-3 order-2 lg:order-1">
        <h2 class="glass-card__heading !text-lg md:!text-xl !mb-3 md:!mb-4">Räume</h2>
        <!-- Mobile: tap-first room list (no drag/drop) -->
        <div class="md:hidden space-y-3">
          <ItemCard
              v-for="room in rooms"
              :key="`mobile-room-${room.id}`"
          >
            <template #title>
              <input
                  v-model="room.name"
                  class="item-card__title glass-input glass-input--sm liquid-surface-control"
                  @blur="updateRoom(room)"
              />
            </template>
            <template #trailing>
              <IconDangerButton label="Raum löschen" @click="askDeleteRoom(room)"/>
            </template>

            <div class="flex items-center gap-2">
              <input
                  v-model="room.navigation_instruction"
                  class="glass-input glass-input--sm liquid-surface-control flex-1 min-w-0 !text-xs text-[var(--color-text-muted)]"
                  placeholder="z. B. 2. Etage rechts"
                  @blur="updateRoom(room)"
              />
              <div
                  :title="room.is_accessible ? 'Barrierefrei' : 'Nicht barrierefrei'"
                  class="shrink-0 cursor-pointer"
                  @click="toggleAccessibility(room)"
              >
                <img
                    :alt="room.is_accessible ? 'Barrierefrei' : 'Nicht barrierefrei'"
                    :src="room.is_accessible ? '/flow/accessible_yes.png' : '/flow/accessible_no.png'"
                    class="w-6 h-6"
                />
              </div>
            </div>

            <div class="glass-dropzone">
              <div
                  v-if="getItemsInRoom(room.id).length === 0"
                  class="glass-dropzone__empty"
              >
                <i class="bi bi-box-arrow-in-down glass-dropzone__empty-icon"></i>
                <span class="glass-dropzone__empty-text">Noch nichts zugewiesen</span>
              </div>
              <div v-else class="glass-dropzone__list">
                <div v-for="element in getItemsInRoom(room.id)" :key="`mobile-assigned-${element.key}`" class="flex items-center">
                  <span
                      v-if="element.type === 'activity'"
                      :style="{ borderColor: getProgramColor(element) }"
                      class="glass-program-pill text-[11px]"
                  >
                    <ProgramLogo v-if="showItemProgramLogo(element)" :program="itemProgramRef(element)" size="xs" />
                    {{ element.name }}
                    <button class="ml-0.5 text-sm text-[var(--color-text-subtle)] hover:text-[var(--color-text)]" @click.stop="unassignItemFromRoom(element.key)">✖</button>
                  </span>
                  <span
                      v-else
                      class="glass-row-item text-[11px]"
                  >
                    <span :style="{ backgroundColor: getProgramColor(element) }" class="w-1.5 self-stretch rounded-l-md"></span>
                    <span class="px-2 py-1 flex items-center gap-1">
                      <ProgramLogo v-if="showItemProgramLogo(element)" :program="itemProgramRef(element)" size="xs" />
                      {{ element.number ? `${element.number} | ` : '' }}{{ element.name }}
                    </span>
                    <button class="ml-1 text-sm text-[var(--color-text-subtle)] hover:text-[var(--color-text)] pr-1" @click.stop="unassignItemFromRoom(element.key)">✖</button>
                  </span>
                </div>
              </div>
            </div>
          </ItemCard>

          <ItemComposer
              ref="mobileComposerRef"
              v-model:title="newRoomName"
              :disabled="isSaving"
              title-placeholder="Neuer Raum z. B. A2.03"
              empty-hint="Bitte eintragen, wie der Raum im Gebäude heißt, nicht was darin passiert."
              @commit="createRoom"
          >
            <transition name="fade">
              <div v-if="newRoomName.trim().length > 0">
                <input
                    v-model="newRoomNote"
                    :disabled="isSaving"
                    class="glass-input glass-input--sm liquid-surface-control w-full !text-xs text-[var(--color-text-muted)]"
                    placeholder="Navigationshinweis"
                />
              </div>
            </transition>
          </ItemComposer>
        </div>

        <!-- Desktop: drag/drop rooms -->
        <div class="hidden md:grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 md:gap-4">
          <draggable
              v-model="rooms"
              class="contents"
              group="rooms"
              item-key="id"
              @end="isDraggingRoom = false; handleRoomReorder()"
              @start="isDraggingRoom = true"
          >
            <template #item="{ element: room }">
              <ItemCard
                  :key="room.id"
                  interactive
                  class="cursor-move"
                  :class="{ 'opacity-55 scale-[1.01]': isDraggingRoom }"
              >
                <template #leading>
                  <div class="text-[var(--color-text-subtle)] cursor-move select-none leading-none px-0.5" aria-hidden="true">⋮⋮</div>
                </template>
                <template #title>
                  <input
                      v-model="room.name"
                      class="item-card__title glass-input glass-input--sm liquid-surface-control"
                      @blur="updateRoom(room)"
                  />
                </template>
                <template #trailing>
                  <IconDangerButton label="Raum löschen" @click.stop="askDeleteRoom(room)"/>
                </template>

                <!-- Line 2: Navigation instruction full width with accessibility icon at end -->
                <div class="flex items-center gap-2">
                  <input
                      v-model="room.navigation_instruction"
                      class="glass-input glass-input--sm liquid-surface-control flex-1 min-w-0 !text-xs md:!text-sm text-[var(--color-text-muted)]"
                      placeholder="z. B. 2. Etage rechts"
                      @blur="updateRoom(room)"
                  />
                  <div
                      :title="room.is_accessible ? 'Barrierefrei' : 'Nicht barrierefrei'"
                      class="shrink-0 cursor-pointer"
                      @click="toggleAccessibility(room)"
                  >
                    <img
                        :alt="room.is_accessible ? 'Barrierefrei' : 'Nicht barrierefrei'"
                        :src="room.is_accessible ? '/flow/accessible_yes.png' : '/flow/accessible_no.png'"
                        class="w-6 h-6"
                    />
                  </div>
                </div>

                <!-- Line 3: Drop area -->
                <div
                    class="glass-dropzone"
                    :class="{
                      'glass-dropzone--dragging': isDragging,
                      'glass-dropzone--active': dragOverRoomId === room.id,
                    }"
                    @dragenter.prevent="dragOverRoomId = room.id"
                    @dragover.prevent="dragOverRoomId = room.id"
                    @dragleave="onRoomDropzoneLeave($event, room.id)"
                >
                  <div
                      v-if="getItemsInRoom(room.id).length === 0"
                      class="glass-dropzone__empty"
                  >
                    <i class="bi bi-box-arrow-in-down glass-dropzone__empty-icon"></i>
                    <span class="glass-dropzone__empty-text">
                      {{ isDragging ? 'Hier ablegen' : 'Aktivitäten oder Teams hierher ziehen' }}
                    </span>
                  </div>
                  <draggable
                      :list="getItemsInRoom(room.id)"
                      class="glass-dropzone__list"
                      group="assignables"
                      item-key="key"
                      @add="event => handleDrop(event, room)"
                      @end="isDragging = false; dragOverRoomId = null"
                      @start="isDragging = true"
                  >


                    <template #item="{ element }">
                      <div class="flex items-center">
                        <!-- Activity -->
                        <span
                            v-if="element.type === 'activity'"
                            :style="{ borderColor: getProgramColor(element) }"
                            class="glass-program-pill glass-program-pill--interactive text-[11px] md:text-xs"
                        >
                          <ProgramLogo
                              v-if="showItemProgramLogo(element)"
                              :program="itemProgramRef(element)"
                              size="xs"
                          />
                          {{ element.name }}
                          <button
                              class="ml-0.5 text-sm text-[var(--color-text-subtle)] hover:text-[var(--color-text)]"
                              @click.stop="unassignItemFromRoom(element.key)"
                          >
                            ✖
                          </button>
                        </span>

                        <!-- Team Proxy -->
                        <span
                            v-else-if="element.type === 'team-proxy'"
                            class="glass-row-item glass-row-item--interactive text-[11px] md:text-xs cursor-move"
                        >
                          <span
                              :style="{ backgroundColor: getProgramColor(element) }"
                              class="w-1.5 self-stretch rounded-l-md"
                          ></span>
                          <span class="px-2 py-1 flex items-center gap-1">
                            <ProgramLogo
                                v-if="showItemProgramLogo(element)"
                                :program="itemProgramRef(element)"
                                size="xs"
                            />
                            {{ element.name }}
                          </span>
                          <button
                              class="ml-1 text-sm text-[var(--color-text-subtle)] hover:text-[var(--color-text)] pr-1"
                              @click.stop="unassignItemFromRoom(element.key)"
                          >
                            ✖
                          </button>
                        </span>

                        <!-- Team -->
                        <span
                            v-else
                            class="glass-row-item glass-row-item--interactive text-[11px] md:text-xs cursor-move"
                        >
                          <span
                              :style="{ backgroundColor: getProgramColor(element) }"
                              class="w-1.5 self-stretch rounded-l-md"
                          ></span>
                          <span class="px-2 py-1 flex items-center gap-1.5">
                            <ProgramLogo
                                v-if="showItemProgramLogo(element)"
                                :program="itemProgramRef(element)"
                                size="xs"
                            />
                            <span class="text-[var(--color-text-muted)]">{{ element.number || '–' }} | {{ element.name }}</span>
                            <span v-if="getPeopleCount(element) !== null" class="text-[var(--color-text-muted)] space-x-1">
                              <span> | {{ getPeopleCount(element) }}</span>
                              <i class="bi bi-person-fill"></i>
                            </span>
                          </span>
                          <button
                              class="ml-1 text-sm text-[var(--color-text-subtle)] hover:text-[var(--color-text)] pr-1"
                              @click.stop="unassignItemFromRoom(element.key)"
                          >
                            ✖
                          </button>
                        </span>
                      </div>
                    </template>

                  </draggable>
                </div>
              </ItemCard>
            </template>
          </draggable>

          <!-- Neuer Raum (always visible, outside draggable) -->
          <ItemComposer
              ref="desktopComposerRef"
              v-model:title="newRoomName"
              :disabled="isSaving"
              title-placeholder="Neuer Raum z. B. A2.03"
              empty-hint="Bitte eintragen, wie der Raum im Gebäude heißt, nicht was darin passiert."
              @commit="createRoom"
          >
            <transition name="fade">
              <div v-if="newRoomName.trim().length > 0">
                <input
                    v-model="newRoomNote"
                    :disabled="isSaving"
                    class="glass-input glass-input--sm liquid-surface-control w-full !text-xs md:!text-sm text-[var(--color-text-muted)]"
                    placeholder="Navigationshinweis"
                />
                <p v-if="!newRoomNote.trim()" class="item-card__hint">
                  Falls der Raum schwer zu finden ist, hier bitte einen Hinweis eintragen.
                </p>
              </div>
            </transition>
          </ItemComposer>
        </div>
      </div>

      <!-- Rechte Spalte: Aktivitäten & Teams -->
      <div class="lg:col-span-1 order-1 lg:order-2">
        <div class="glass-card liquid-surface-inner !p-3 md:!p-4">
        <div class="glass-tabs !mb-3 md:!mb-4">
          <button
              type="button"
              :class="['glass-tab relative', activeTab === 'activities' ? 'glass-tab--active' : '']"
              @click="activeTab = 'activities'"
          >
            Aktivitäten
            <span
                v-if="hasWarning('activities')"
                class="absolute top-1.5 right-1 w-2 h-2 bg-red-500 rounded-full"
                title="Noch nicht alle Aktivitäten zugeordnet"
            ></span>
          </button>

          <button
              type="button"
              :class="['glass-tab relative', activeTab === 'teams' ? 'glass-tab--active' : '']"
              @click="activeTab = 'teams'"
          >
            Teams
            <span
                v-if="hasWarning('teams')"
                class="absolute top-1.5 right-1 w-2 h-2 bg-red-500 rounded-full"
                title="Noch nicht alle Teams zugeordnet"
            ></span>
          </button>
        </div>

        <!-- Dynamisch alle Gruppen aus der gemeinsamen Struktur -->
        <div v-for="category in assignables" v-show="activeTab === category.id" :key="category.id">
          <template
              v-for="group in category.groups"
              :key="group.id"
          >
            <div
                class="mb-3 md:mb-4 liquid-surface-inner rounded-[var(--radius)] p-3"
            >
              <div class="glass-card__heading !mb-2 md:!mb-3 !text-sm md:!text-base flex items-center gap-2">
                <ProgramLogo
                    v-if="category.type === 'team' && showItemProgramLogo(group)"
                    :program="itemProgramRef(group)"
                    size="base"
                />
                <span v-html="formatProgramName(group.name)"></span>
              </div>

              <!-- Bulk mode checkbox for teams -->
              <div v-if="category.type === 'team'" class="mb-2">
                <label class="flex items-center gap-2 text-xs md:text-sm text-[var(--color-text-muted)] cursor-pointer">
                  <input
                      :checked="isBulkModeEnabled(group.id)"
                      class="cursor-pointer accent-[var(--color-accent)]"
                      type="checkbox"
                      @change="toggleBulkMode(group.id)"
                  />
                  <span>Alle Teams zusammen</span>
                </label>
              </div>

              <!-- Mobile: tap-to-assign chips -->
              <div class="md:hidden flex flex-wrap gap-1.5">
                <template v-for="element in getUnassignedItems(category, group)" :key="`mobile-unassigned-${element.key}`">
                  <button
                      v-if="element.type === 'activity'"
                      type="button"
                      :style="{ borderColor: getProgramColor(element) }"
                      class="glass-program-pill text-[11px]"
                      @click="openAssignModal(element)"
                  >
                    <ProgramLogo
                        v-if="showItemProgramLogo(element)"
                        :program="itemProgramRef(element)"
                        size="xs"
                    />
                    {{ element.name }}
                  </button>
                  <button
                      v-else
                      type="button"
                      class="glass-row-item text-[11px]"
                      @click="openAssignModal(element)"
                  >
                    <span :style="{ backgroundColor: getProgramColor(element) }" class="w-1.5 self-stretch rounded-l-md"></span>
                    <span class="px-2 py-1 flex items-center gap-1.5">
                      <ProgramLogo
                          v-if="showItemProgramLogo(element)"
                          :program="itemProgramRef(element)"
                          size="xs"
                      />
                      <span class="text-[var(--color-text-muted)]">{{ element.number ? `${element.number} | ` : '' }}{{ element.name }}</span>
                    </span>
                  </button>
                </template>
              </div>

              <!-- Desktop: drag/drop chips -->
              <draggable
                  class="hidden md:flex flex-wrap gap-1.5 md:gap-2"
                  :list="getUnassignedItems(category, group)"
                  group="assignables"
                  item-key="key"
                  @end="isDragging = false"
                  @start="isDragging = true"
              >


                <template #item="{ element }">
                  <span
                      v-if="element.type === 'activity'"
                      :style="{ borderColor: getProgramColor(element) }"
                      class="glass-program-pill glass-program-pill--interactive text-[11px] md:text-xs"
                  >
                    <ProgramLogo
                        v-if="showItemProgramLogo(element)"
                        :program="itemProgramRef(element)"
                        size="xs"
                    />
                    {{ element.name }}
                  </span>

                  <span
                      v-else-if="element.type === 'team-proxy'"
                      class="glass-row-item glass-row-item--interactive text-[11px] md:text-xs cursor-move"
                  >
                    <span
                        :style="{ backgroundColor: getProgramColor(element) }"
                        class="w-1.5 self-stretch rounded-l-md"
                    ></span>
                    <span class="px-2 py-1 flex items-center gap-1">
                      <ProgramLogo
                          v-if="showItemProgramLogo(element)"
                          :program="itemProgramRef(element)"
                          size="xs"
                      />
                      {{ element.name }}
                    </span>
                  </span>

                  <span
                      v-else-if="element.type === 'team'"
                      class="glass-row-item glass-row-item--interactive text-[11px] md:text-xs cursor-move"
                  >
                    <span
                        :style="{ backgroundColor: getProgramColor(element) }"
                        class="w-1.5 self-stretch rounded-l-md"
                    ></span>
                    <span class="px-2 py-1 flex items-center gap-1.5">
                      <ProgramLogo
                          v-if="showItemProgramLogo(element)"
                          :program="itemProgramRef(element)"
                          size="xs"
                      />
                      <span class="text-[var(--color-text-muted)]">{{ element.number || '–' }} | {{ element.name }}</span>
                      <span v-if="getPeopleCount(element) !== null" class="text-[var(--color-text-muted)] space-x-1">
                        <span> | {{ getPeopleCount(element) }}</span>
                        <i class="bi bi-person-fill"></i>
                      </span>
                    </span>
                  </span>
                </template>


              </draggable>
            </div>
          </template>
        </div>
        </div>
      </div>
    </div>

    <ConfirmationModal
        :message="deleteRoomMessage"
        :show="!!roomToDelete"
        cancel-text="Abbrechen"
        confirm-text="Löschen"
        title="Raum löschen"
        type="danger"
        @cancel="cancelDeleteRoom"
        @confirm="confirmDeleteRoom"
    />

    <!-- Mobile tap-to-assign modal -->
    <div
        v-if="showAssignModal && selectedAssignable"
        class="glass-scrim fixed inset-0 z-50 flex items-end md:hidden"
        @click="closeAssignModal"
    >
      <div
          class="w-full max-h-[70vh] overflow-y-auto rounded-t-[var(--radius-xl)] border border-[var(--liquid-border)] bg-[var(--liquid-popover-fill)] backdrop-blur-[var(--liquid-popover-blur)] p-4 shadow-[var(--shadow-lg)]"
          @click.stop
      >
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold text-[var(--color-text)]">Zu Raum zuweisen</h3>
          <button
              type="button"
              class="text-[var(--color-text-subtle)] hover:text-[var(--color-text)]"
              @click="closeAssignModal"
          >
            ✕
          </button>
        </div>
        <div class="text-xs text-[var(--color-text-muted)] mb-3 truncate">
          {{ selectedAssignable.name }}
        </div>
        <div class="space-y-2">
          <button
              v-for="room in rooms"
              :key="`assign-room-${room.id}`"
              type="button"
              class="w-full text-left px-3 py-2.5 liquid-surface-inner rounded-[var(--radius)] hover:bg-[var(--color-bg-hover)] transition-colors"
              @click="assignSelectedToRoom(room.id)"
          >
            <div class="font-medium text-sm text-[var(--color-text)]">{{ room.name || 'Unbenannter Raum' }}</div>
            <div v-if="room.navigation_instruction" class="text-xs text-[var(--color-text-subtle)]">{{ room.navigation_instruction }}</div>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
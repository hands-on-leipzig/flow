<script setup>
import draggable from 'vuedraggable'
import {computed, toRef, ref, watch, onMounted, nextTick} from "vue";
import axios from "axios";
import {useEventStore} from "@/stores/event";
import IconDraggable from "@/components/icons/IconDraggable.vue";
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import SavingToast from "@/components/atoms/SavingToast.vue"

const props = defineProps({
  program: {type: String, required: true}, // 'explore' or 'challenge'
  remoteTeams: {type: Array, default: () => []},
})

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const localTeams = ref([])
const teamList = ref([])
const teamsDiffer = ref(false)
const showDiffModal = ref(false)
// No background color needed - using subtle grey instead

const savingToast = ref(null)

const ignoredTeamNumbers = ref(new Set())

// People data from DRAHT API
const peopleData = ref({})
const expandedTeams = ref(new Set())
const totalPlayers = ref(0)
const totalCoaches = ref(0)

// Plan parameter values for display
const planParams = ref({
  c_teams: 0,
  e_teams: 0,
  e1_teams: 0,
  e_mode: 0
})

watch(() => props.teams, (newVal) => {
  teamList.value = [...newVal]
})

const onSort = async () => {
  // Update team_number_plan immediately based on new positions for instant border color refresh
  teamList.value = teamList.value.map((team, index) => ({
    ...team,
    team_number_plan: index + 1
  }))

  const payload = teamList.value.map((team, index) => ({
    team_id: team.id,
    order: index + 1
  }))

  savingToast?.value?.show()

  try {
    await axios.post(`/events/${event.value?.id}/teams/update-order`, {
      program: props.program,
      order: payload
    })
    // Refresh discrepancy status after team reordering
    await eventStore.updateTeamDiscrepancyStatus()

    // Reload teams to sync with backend (backend may have additional logic for team_number_plan)
    const dbRes = await axios.get(`/events/${event.value?.id}/teams?program=${props.program}&sort=plan_order`)
    const teamsArray = Array.isArray(dbRes.data) ? dbRes.data : (dbRes.data.teams || [])
    teamList.value = teamsArray.map(team => ({
      ...team,
      noshow: team.noshow === 1 || team.noshow === true || team.noshow === '1'
    }))
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error('Order update failed', e)
    }
  }
}

const updateTeamName = async (team) => {
  savingToast?.value?.show()
  try {
    await axios.put(`/events/${event.value?.id}/teams`, {
      id: team.id,
      number: team.number,
      name: team.name,
    })
    // Refresh discrepancy status after team update
    await eventStore.updateTeamDiscrepancyStatus()
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error(`Failed to update team name for ${team.id}`, e)
    }
  }
}

const updateTeamNoshow = async (team) => {
  savingToast?.value?.show()
  try {
    await axios.put(`/events/${event.value?.id}/teams`, {
      id: team.id,
      noshow: team.noshow ? 1 : 0,
    })
    // Refresh discrepancy status after team update
    await eventStore.updateTeamDiscrepancyStatus()
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error(`Failed to update team noshow for ${team.id}`, e)
    }
  }
}

const mergedTeams = computed(() => {
  const result = []
  const processedLocalIds = new Set()
  const processedDrahtIds = new Set()

  // Normalize team numbers for comparison (handle null, undefined, strings, 0)
  const normalizeTeamNumber = (num) => {
    if (num == null || num === '' || num === 0) return null
    const normalized = Number(num)
    return isNaN(normalized) || normalized === 0 ? null : normalized
  }

  // Step 1: Match teams by team_number_hot (when both have valid numbers)
  const localMapByNumber = new Map()
  const drahtMapByNumber = new Map()

  localTeams.value.forEach(t => {
    const num = normalizeTeamNumber(t.team_number_hot)
    if (num != null) {
      localMapByNumber.set(num, t)
    }
  })

  props.remoteTeams.forEach(t => {
    const num = normalizeTeamNumber(t.number)
    if (num != null) {
      drahtMapByNumber.set(num, t)
    }
  })

  // Collect all valid team numbers
  const allNumbers = new Set()
  localTeams.value.forEach(t => {
    const num = normalizeTeamNumber(t.team_number_hot)
    if (num != null) allNumbers.add(num)
  })
  props.remoteTeams.forEach(t => {
    const num = normalizeTeamNumber(t.number)
    if (num != null) allNumbers.add(num)
  })

  // Match by number
  allNumbers.forEach(number => {
    const local = localMapByNumber.get(number)
    const draht = drahtMapByNumber.get(number)

    let status = 'match'
    if (ignoredTeamNumbers.value.has(number)) {
      status = 'ignored'
    } else if (local && draht) {
      status = local.name !== draht.name ? 'conflict' : 'match'
    } else if (draht && !local) {
      status = 'new'
    } else if (local && !draht) {
      status = 'missing'
    }

    if (local) processedLocalIds.add(local.id)
    if (draht) processedDrahtIds.add(draht.id)

    result.push({number, local, draht, status})
  })

  // Step 2: Match teams without team_number_hot by name
  const localWithoutNumber = localTeams.value.filter(t => {
    const num = normalizeTeamNumber(t.team_number_hot)
    return num == null && !processedLocalIds.has(t.id)
  })

  const drahtWithoutNumber = props.remoteTeams.filter(t => {
    const num = normalizeTeamNumber(t.number)
    return num == null && !processedDrahtIds.has(t.id)
  })

  // Match by name for teams without numbers
  drahtWithoutNumber.forEach(draht => {
    const matchingLocal = localWithoutNumber.find(local =>
        local.name === draht.name && !processedLocalIds.has(local.id)
    )

    if (matchingLocal) {
      processedLocalIds.add(matchingLocal.id)
      processedDrahtIds.add(draht.id)
      result.push({
        number: null,
        local: matchingLocal,
        draht: draht,
        status: matchingLocal.name !== draht.name ? 'conflict' : 'match'
      })
    } else {
      processedDrahtIds.add(draht.id)
      result.push({
        number: null,
        local: null,
        draht: draht,
        status: 'new'
      })
    }
  })

  // Add any remaining local teams without numbers or matches
  localWithoutNumber.forEach(local => {
    if (!processedLocalIds.has(local.id)) {
      processedLocalIds.add(local.id)
      result.push({
        number: null,
        local: local,
        draht: null,
        status: 'missing'
      })
    }
  })

  return result
})

const statusLabels = {
  match: '✔ Identisch',
  conflict: '⚠ Unterschied',
  new: '➕ Nur angemeldet',
  missing: '❌ Nur in FLOW'
}

const applyDrahtTeam = async (team) => {
  if (!team.draht) {
    if (import.meta.env.DEV) {
      console.error('Cannot apply team: draht data is missing', team)
    }
    return
  }

  // Validate that team number exists (required field)
  // In Teams.vue, we map DRAHT's 'ref' field to 'number' field
  // Note: ref can be 0, which is a valid team number, so we check for null/undefined only
  const teamNumberHot = team.draht.number ?? team.number ?? null
  if (teamNumberHot == null) {
    alert('Fehler: Team-Nummer ist erforderlich. Das Team in DRAHT hat keine gültige "ref" (Team-Nummer).')
    return
  }

  try {
    const response = await axios.put(`/events/${event.value?.id}/teams`, {
      id: team.local?.id, // null for new teams (triggers create)
      team_number_hot: teamNumberHot,
      name: team.draht.name,
      event: event.value.id,
      first_program: props.program,
      location: team.draht.location || null,
      organization: team.draht.organization || null,
    })

    // Refresh teams from server to get the updated/created team with correct ID
    const dbRes = await axios.get(`/events/${event.value?.id}/teams?program=${props.program}&sort=plan_order`)
    // Handle both array format and object format (for Explore teams with metadata)
    const teamsArray = Array.isArray(dbRes.data) ? dbRes.data : (dbRes.data.teams || [])
    // Normalize noshow values to boolean (handle null, 0, 1, true, false)
    localTeams.value = teamsArray.map(team => ({
      ...team,
      noshow: team.noshow === 1 || team.noshow === true || team.noshow === '1'
    }))
    teamList.value = [...localTeams.value]

    // Refresh discrepancy status
    await eventStore.updateTeamDiscrepancyStatus()

    team.status = 'match'

    const hasRemainingDiffs = mergedTeams.value.some(t => t.status !== 'match' && t.status !== 'ignored')
    if (!hasRemainingDiffs) {
      showDiffModal.value = false
    }
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error(`Fehler beim Übernehmen von Team ${team.number || team.draht.name}`, e)
    }
    alert('Fehler beim Übernehmen des Teams: ' + (e.response?.data?.message || e.message))
  }
}

const deleteTeam = async (team) => {
  if (!team.local?.id) {
    if (import.meta.env.DEV) {
      console.error('Cannot delete team: team ID is missing', team)
    }
    return
  }

  if (!confirm(`Möchtest du das Team "${team.local.name}" wirklich löschen?`)) {
    return
  }

  try {
    savingToast?.value?.show()
    await axios.delete(`/teams/${team.local.id}`)

    // Refresh teams from server
    const dbRes = await axios.get(`/events/${event.value?.id}/teams?program=${props.program}&sort=plan_order`)
    // Handle both array format and object format (for Explore teams with metadata)
    const teamsArray = Array.isArray(dbRes.data) ? dbRes.data : (dbRes.data.teams || [])
    // Normalize noshow values to boolean (handle null, 0, 1, true, false)
    localTeams.value = teamsArray.map(team => ({
      ...team,
      noshow: team.noshow === 1 || team.noshow === true || team.noshow === '1'
    }))
    teamList.value = [...localTeams.value]

    // Refresh discrepancy status
    await eventStore.updateTeamDiscrepancyStatus()

    const hasRemainingDiffs = mergedTeams.value.some(t => t.status !== 'match' && t.status !== 'ignored')
    if (!hasRemainingDiffs) {
      showDiffModal.value = false
    }
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error(`Fehler beim Löschen von Team ${team.local.name}`, e)
    }
    alert('Fehler beim Löschen des Teams: ' + (e.response?.data?.message || e.message))
  } finally {
    savingToast?.value?.hide()
  }
}

const ignoreDiff = (team) => {
  // Mark as resolved but not updated
  ignoredTeamNumbers.value.add(team.number)

  const hasRemainingDiffs = mergedTeams.value.some(t => t.status !== 'match' && t.status !== 'ignored')
  if (!hasRemainingDiffs) {
    showDiffModal.value = false
  }
}

const showSyncPrompt = computed(() =>
    mergedTeams.value.some(t => t.status !== 'match' && t.status !== 'ignored')
)

// Computed: Get plan capacity for current program
const planCapacity = computed(() => {
  return props.program === 'explore' ? planParams.value.e_teams : planParams.value.c_teams
})

// Computed: Get enrolled count for current program
const enrolledCount = computed(() => {
  return props.program === 'explore'
      ? (event.value?.drahtTeamsExplore || 0)
      : (event.value?.drahtTeamsChallenge || 0)
})

// Computed: Get placeholder rows if plan > enrolled
const placeholderRows = computed(() => {
  const capacity = planCapacity.value
  const enrolled = enrolledCount.value
  const currentTeams = teamList.value.length

  // If plan has more teams than enrolled, add empty rows to fill up to plan capacity
  if (capacity > enrolled) {
    const count = Math.max(0, capacity - currentTeams)
    return Array(count).fill(null).map((_, idx) => ({
      id: `empty-${currentTeams + idx}`,
      index: currentTeams + idx + 1 // 1-based index for display
    }))
  }
  return []
})

// Computed: Check if any teams are beyond capacity
const teamsBeyondCapacity = computed(() => {
  const capacity = planCapacity.value
  const currentTeams = teamList.value.length
  return currentTeams > capacity
})

// Computed: Check if we have 2x Explore groups (e_mode = 5 DECOUPLED_BOTH or 8 HYBRID_BOTH)
const hasTwoExploreGroups = computed(() => {
  return props.program === 'explore' && (planParams.value.e_mode === 5 || planParams.value.e_mode === 8)
})

// Function: Determine if a team belongs to morning or afternoon group
const getTeamGroup = (team) => {
  if (!hasTwoExploreGroups.value || planParams.value.e1_teams <= 0) {
    return null
  }
  const teamNumberPlan = team?.team_number_plan || 0
  return teamNumberPlan <= planParams.value.e1_teams ? 'morning' : 'afternoon'
}

// Function: Get border style for a team based on its group
const getTeamBorderStyle = (team) => {
  const group = getTeamGroup(team)
  if (group === 'morning') {
    return 'border-left-color: #1e40af;'
  } else if (group === 'afternoon') {
    return 'border-left-color: #93c5fd;'
  }
  return ''
}

// Computed: Find the index where afternoon section starts (for divider label)
const afternoonStartIndex = computed(() => {
  if (!hasTwoExploreGroups.value || planParams.value.e1_teams <= 0) {
    return -1
  }
  const e1Teams = planParams.value.e1_teams
  for (let i = 0; i < teamList.value.length; i++) {
    if ((teamList.value[i].team_number_plan || 0) > e1Teams) {
      return i
    }
  }
  return -1
})

// Get DRAHT team number for a team (try team_number_hot first, then remoteTeams)
const getDrahtTeamNumber = (team) => {
  // First try team_number_hot
  if (team.team_number_hot) {
    return String(team.team_number_hot)
  }
  // If not found, try to find in remoteTeams by matching name or id
  const remoteTeam = props.remoteTeams.find(rt =>
      rt.id === team.id ||
      (rt.name === team.name && rt.number)
  )
  if (remoteTeam && remoteTeam.number) {
    return String(remoteTeam.number)
  }
  return null
}

// Get people count for a team (players + coaches)
const getPeopleCount = (team) => {
  const teamNumber = getDrahtTeamNumber(team)
  if (!teamNumber || !peopleData.value[teamNumber]) {
    return null
  }
  const teamData = peopleData.value[teamNumber]
  return (teamData.num_players || 0) + (teamData.num_coaches || 0)
}

// Get team people data
const getTeamPeopleData = (team) => {
  const teamNumber = getDrahtTeamNumber(team)
  if (!teamNumber || !peopleData.value[teamNumber]) {
    return null
  }
  return peopleData.value[teamNumber]
}

// Toggle team expansion
const toggleTeamExpansion = (team) => {
  const teamNumber = getDrahtTeamNumber(team)
  if (!teamNumber) return

  if (expandedTeams.value.has(teamNumber)) {
    expandedTeams.value.delete(teamNumber)
  } else {
    expandedTeams.value.add(teamNumber)
  }
}

// Check if team is expanded
const isTeamExpanded = (team) => {
  const teamNumber = getDrahtTeamNumber(team)
  return teamNumber && expandedTeams.value.has(teamNumber)
}

// Format birthday timestamp to date string
const formatBirthday = (timestamp) => {
  if (!timestamp || timestamp === false) return 'N/A'
  const date = new Date(timestamp * 1000)
  return date.toLocaleDateString('de-DE')
}

// Copy to clipboard function
const copyToClipboard = async (text, type) => {
  if (!text) return

  try {
    await navigator.clipboard.writeText(text)
    // Show temporary feedback
    const toast = document.createElement('div')
    toast.className = 'fixed top-4 right-4 bg-green-600 text-white px-4 py-2 rounded shadow-lg z-50'
    toast.textContent = `${type} kopiert!`
    document.body.appendChild(toast)
    setTimeout(() => {
      toast.remove()
    }, 2000)
  } catch (err) {
    console.error('Failed to copy to clipboard:', err)
    // Fallback for older browsers
    const textArea = document.createElement('textarea')
    textArea.value = text
    textArea.style.position = 'fixed'
    textArea.style.opacity = '0'
    document.body.appendChild(textArea)
    textArea.select()
    try {
      document.execCommand('copy')
      const toast = document.createElement('div')
      toast.className = 'fixed top-4 right-4 bg-green-600 text-white px-4 py-2 rounded shadow-lg z-50'
      toast.textContent = `${type} kopiert!`
      document.body.appendChild(toast)
      setTimeout(() => {
        toast.remove()
      }, 2000)
    } catch (e) {
      console.error('Fallback copy failed:', e)
    }
    document.body.removeChild(textArea)
  }
}

// Download functions
const downloadJSON = () => {
  const dataStr = JSON.stringify(peopleData.value, null, 2)
  const dataBlob = new Blob([dataStr], {type: 'application/json'})
  const url = URL.createObjectURL(dataBlob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${props.program}_teams_people.json`
  link.click()
  URL.revokeObjectURL(url)
}

const downloadCSV = () => {
  const rows = []
  rows.push(['Team Number', 'Team Name', 'Type', 'Name', 'First Name', 'Gender', 'Birthday', 'Email', 'Phone'])

  Object.entries(peopleData.value).forEach(([teamNumber, teamData]) => {
    // Add players
    if (teamData.players && Array.isArray(teamData.players)) {
      teamData.players.forEach(player => {
        rows.push([
          teamNumber,
          teamData.name || '',
          'Player',
          player.name || '',
          player.firstname || '',
          player.gender || '',
          formatBirthday(player.birthday),
          '',
          ''
        ])
      })
    }
    // Add coaches
    if (teamData.coaches && Array.isArray(teamData.coaches)) {
      teamData.coaches.forEach(coach => {
        if (typeof coach === 'object' && coach !== null) {
          rows.push([
            teamNumber,
            teamData.name || '',
            'Coach',
            coach.name || '',
            '',
            '',
            '',
            coach.email || '',
            coach.phone || ''
          ])
        } else {
          // Handle string coaches
          rows.push([
            teamNumber,
            teamData.name || '',
            'Coach',
            coach || '',
            '',
            '',
            '',
            '',
            ''
          ])
        }
      })
    }
  })

  const csvContent = rows.map(row =>
      row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')
  ).join('\n')

  const dataBlob = new Blob([csvContent], {type: 'text/csv;charset=utf-8;'})
  const url = URL.createObjectURL(dataBlob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${props.program}_teams_people.csv`
  link.click()
  URL.revokeObjectURL(url)
}

const downloadXML = () => {
  let xml = '<?xml version="1.0" encoding="UTF-8"?>\n<teams>\n'

  Object.entries(peopleData.value).forEach(([teamNumber, teamData]) => {
    xml += `  <team number="${teamNumber}" name="${escapeXml(teamData.name || '')}">\n`
    // Add players
    if (teamData.players && Array.isArray(teamData.players)) {
      teamData.players.forEach(player => {
        xml += `    <player>\n`
        xml += `      <name>${escapeXml(player.name || '')}</name>\n`
        xml += `      <firstname>${escapeXml(player.firstname || '')}</firstname>\n`
        xml += `      <gender>${escapeXml(player.gender || '')}</gender>\n`
        xml += `      <birthday>${formatBirthday(player.birthday)}</birthday>\n`
        xml += `    </player>\n`
      })
    }
    // Add coaches
    if (teamData.coaches && Array.isArray(teamData.coaches)) {
      teamData.coaches.forEach(coach => {
        xml += `    <coach>\n`
        if (typeof coach === 'object' && coach !== null) {
          xml += `      <name>${escapeXml(coach.name || '')}</name>\n`
          xml += `      <email>${escapeXml(coach.email || '')}</email>\n`
          xml += `      <phone>${escapeXml(coach.phone || '')}</phone>\n`
        } else {
          xml += `      <name>${escapeXml(coach || '')}</name>\n`
        }
        xml += `    </coach>\n`
      })
    }
    xml += `  </team>\n`
  })

  xml += '</teams>'

  const dataBlob = new Blob([xml], {type: 'application/xml;charset=utf-8;'})
  const url = URL.createObjectURL(dataBlob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${props.program}_teams_people.xml`
  link.click()
  URL.revokeObjectURL(url)
}

const escapeXml = (str) => {
  if (!str) return ''
  return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&apos;')
}

onMounted(async () => {
  try {
    // Fetch plan parameters
    try {
      const planRes = await axios.get(`/plans/public/${event.value?.id}`)
      const planId = planRes.data?.id
      if (planId) {
        const paramsRes = await axios.get(`/plans/${planId}/parameters`)
        const params = Array.isArray(paramsRes.data) ? paramsRes.data : []
        planParams.value = {
          c_teams: Number(params.find(p => p.name === 'c_teams')?.value || 0),
          e_teams: Number(params.find(p => p.name === 'e_teams')?.value || 0),
          e1_teams: Number(params.find(p => p.name === 'e1_teams')?.value || 0),
          e_mode: Number(params.find(p => p.name === 'e_mode')?.value || 0)
        }
      }
    } catch (paramErr) {
      if (import.meta.env.DEV) {
        console.debug('Failed to fetch plan parameters', paramErr)
      }
    }

    const dbRes = await axios.get(`/events/${event.value?.id}/teams?program=${props.program}&sort=plan_order`)
    // Handle both array format and object format (for Explore teams with metadata)
    const teamsArray = Array.isArray(dbRes.data) ? dbRes.data : (dbRes.data.teams || [])
    // Normalize noshow values to boolean (handle null, 0, 1, true, false)
    localTeams.value = teamsArray.map(team => ({
      ...team,
      noshow: team.noshow === 1 || team.noshow === true || team.noshow === '1'
    }))
    teamList.value = [...localTeams.value]

    // Teams loaded successfully

    teamList.value = [...localTeams.value]
    teamsDiffer.value = JSON.stringify(localTeams.value) !== JSON.stringify(props.remoteTeams)

    // Fetch people data from DRAHT API
    const drahtEventId = props.program === 'explore'
        ? event.value?.event_explore
        : event.value?.event_challenge

    if (drahtEventId) {
      try {
        const peopleRes = await axios.get(`/draht/people/${drahtEventId}`)
        if (peopleRes.data) {
          // Store totals before removing them
          totalPlayers.value = peopleRes.data.total_players || 0
          totalCoaches.value = peopleRes.data.total_coaches || 0
          // Remove 'total_players' and 'total_coaches' from the data
          const {total_players, total_coaches, ...teamsData} = peopleRes.data
          peopleData.value = teamsData
        }
      } catch (peopleErr) {
        if (import.meta.env.DEV) {
          console.error('Failed to fetch people data', peopleErr)
        }
      }
    }
  } catch (err) {
    if (import.meta.env.DEV) {
      console.error('Failed to fetch teams', err)
    }
  }
})
</script>

<template>
  <SavingToast ref="savingToast" message="Änderungen werden gespeichert..."/>

  <div class="overflow-y-auto max-h-[80vh] lg:max-h-none mx-4">
    <div class="p-4 border rounded shadow">
      <div class="flex items-center gap-2 mb-2">
        <img
            :alt="programLogoAlt(program)"
            :src="programLogoSrc(program)"
            class="w-10 h-10 flex-shrink-0"
        />
        <div>
          <h3 class="text-lg font-semibold capitalize">
            <span class="italic">FIRST</span> LEGO League {{ program }}
          </h3>
          <div class="text-sm text-gray-500 flex items-center gap-3">
            <span>
              <span :class="planCapacity !== enrolledCount ? 'bg-yellow-100 px-1 rounded text-red-800' : ''">Plan für: {{
                  program === 'explore' ? planParams.e_teams : planParams.c_teams
                }}</span>, <span
                :class="planCapacity !== enrolledCount ? 'bg-yellow-100 px-1 rounded text-red-800' : ''">Angemeldet: {{
                program === 'explore' ? event?.drahtTeamsExplore || 0 : event?.drahtTeamsChallenge || 0
              }}</span>, Kapazität: {{
                program === 'explore' ? event?.drahtCapacityExplore || 0 : event?.drahtCapacityChallenge || 0
              }}
            </span>
            <!-- Color code indicators for 2x Explore -->
            <template v-if="hasTwoExploreGroups">
              <span class="flex items-center gap-1">
                <span class="w-6 h-4 rounded" style="background-color: #1e40af;"></span>
                <span style="color: #1e40af;">Vormittag</span>
              </span>
              <span class="flex items-center gap-1">
                <span class="w-6 h-4 rounded" style="background-color: #93c5fd;"></span>
                <span style="color: #93c5fd;">Nachmittag</span>
              </span>
            </template>
          </div>
        </div>
      </div>
      <div v-if="showSyncPrompt" class="mb-2 p-2 bg-yellow-100 border border-yellow-300 text-red-800 rounded">
        Die Daten in FLOW weichen von denen der Anmeldung ab.
        <button class="text-sm text-red-700" @click="showDiffModal = !showDiffModal">
          Unterschiede anzeigen
          ({{ mergedTeams.filter(t => !['match', 'ignored'].includes(t.status)).length }})
        </button>
      </div>
      <draggable
          v-model="teamList"
          animation="150"
          chosen-class="drag-chosen"
          drag-class="drag-dragging"
          ghost-class="drag-ghost"
          handle=".drag-handle"
          item-key="id"
          @end="onSort"
      >
        <template #item="{element: team, index}">
          <div>
            <li
                :class="[
                  'rounded px-3 py-2 mb-1 flex justify-between items-center gap-2 transition-opacity cursor-pointer',
                  (teamsBeyondCapacity && index >= planCapacity) 
                    ? 'bg-yellow-100 text-red-800' 
                    : 'bg-gray-50',
                  team.noshow ? 'opacity-50' : 'opacity-100',
                  (teamsBeyondCapacity && index >= planCapacity)
                    ? 'border border-yellow-300'
                    : '',
                  // Only apply colored border if team is NOT beyond capacity
                  !(teamsBeyondCapacity && index >= planCapacity) && hasTwoExploreGroups && getTeamGroup(team) === 'morning' 
                    ? 'border-l-[6px]' 
                    : (!(teamsBeyondCapacity && index >= planCapacity) && hasTwoExploreGroups && getTeamGroup(team) === 'afternoon' 
                        ? 'border-l-[6px]' 
                        : '')
                ]"
                :style="(teamsBeyondCapacity && index >= planCapacity) ? '' : getTeamBorderStyle(team)"
                @click="toggleTeamExpansion(team)"
            >
              <!-- Drag-Handle -->
              <span class="drag-handle cursor-move text-gray-500" @click.stop><IconDraggable/></span>

              <!-- Neue Positionsspalte (Txx) - empty if beyond capacity -->
              <span v-if="!teamsBeyondCapacity || index < planCapacity"
                    :class="(teamsBeyondCapacity && index >= planCapacity) ? 'text-red-800' : 'text-black'"
                    class="w-8 text-right text-sm">T{{
                  String(index + 1).padStart(2, '0')
                }}</span>
              <span v-else class="w-8 text-right text-sm text-red-800">–</span>

              <!-- Teamnummer (grau) -->
              <span :class="(teamsBeyondCapacity && index >= planCapacity) ? 'text-red-800' : 'text-gray-500'"
                    class="text-sm w-12">{{
                  team.team_number_hot || '–'
                }}</span>

              <!-- No-Show Checkbox (hidden for teams beyond capacity) -->
              <label v-if="!(teamsBeyondCapacity && index >= planCapacity)"
                     class="flex items-center gap-1 text-sm text-gray-600 cursor-pointer" @click.stop>
                <input
                    v-model="team.noshow"
                    class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                    type="checkbox"
                    @change="updateTeamNoshow(team)"
                />
                <span class="text-xs">No-Show</span>
              </label>
              <span v-else class="w-16"></span>

              <!-- Eingabefeld -->
              <input
                  v-model="team.name"
                  :class="[
                    'editable-input flex-1 text-sm px-2 py-1 border border-transparent rounded hover:border-gray-300 focus:border-blue-500 focus:outline-none transition-colors cursor-pointer',
                    (teamsBeyondCapacity && index >= planCapacity) ? 'text-red-800' : ''
                  ]"
                  placeholder="Click to edit team name"
                  @blur="updateTeamName(team)"
                  @click.stop
              />

              <!-- People count -->
              <span v-if="getPeopleCount(team) !== null" class="text-sm text-gray-600 space-x-2">
                {{ getPeopleCount(team) }} <i class="fa-solid fa-person"></i>
              </span>
              <span v-else class="text-sm text-gray-400">–</span>

              <!-- Expand/Collapse icon -->
              <span class="text-gray-500 text-sm">
                {{ isTeamExpanded(team) ? '▼' : '▶' }}
              </span>
            </li>
            <!-- Expanded players and coaches list -->
            <div v-if="isTeamExpanded(team) && getTeamPeopleData(team)" class="ml-8 mb-2 bg-gray-100 rounded p-3">
              <!-- Players section -->
              <div v-if="getTeamPeopleData(team).players && getTeamPeopleData(team).players.length > 0" class="mb-3">
                <div class="text-xs font-semibold text-gray-600 mb-1">Mitglieder
                  ({{ getTeamPeopleData(team).num_players || 0 }}):
                </div>
                <div class="space-y-1">
                  <div
                      v-for="(player, playerIndex) in getTeamPeopleData(team).players"
                      :key="playerIndex"
                      class="text-sm text-gray-700"
                  >
                    <span v-if="player.name || player.firstname">
                      {{ player.firstname || '' }} {{ player.name || '' }}
                      <span class="text-gray-500">({{ player.gender || 'N/A' }}, {{
                          formatBirthday(player.birthday)
                        }})</span>
                    </span>
                    <span v-else class="text-gray-400 italic">Unbekanntes Mitglied</span>
                  </div>
                </div>
              </div>
              <div v-else class="text-sm text-gray-400 italic mb-3">Keine Mitglieder gefunden</div>

              <!-- Coaches section -->
              <div v-if="getTeamPeopleData(team).coaches && getTeamPeopleData(team).coaches.length > 0">
                <div class="text-xs font-semibold text-gray-600 mb-1">Coaches
                  ({{ getTeamPeopleData(team).num_coaches || 0 }}):
                </div>
                <div class="space-y-1">
                  <div
                      v-for="(coach, coachIndex) in getTeamPeopleData(team).coaches"
                      :key="coachIndex"
                      class="text-sm text-gray-700"
                  >
                    <template v-if="typeof coach === 'object' && coach !== null">
                      <div class="flex flex-col">
                        <span class="font-medium">{{ coach.name || 'Unbekannt' }}</span>
                        <div v-if="coach.email || coach.phone"
                             class="text-xs text-gray-500 ml-2 flex flex-wrap items-center gap-2">
                          <span v-if="coach.email" class="flex items-center gap-1">
                            {{ coach.email }}
                            <button
                                @click.stop="copyToClipboard(coach.email, 'E-Mail')"
                                class="text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded p-0.5 transition-colors"
                                title="E-Mail kopieren"
                            >
                              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                              </svg>
                            </button>
                          </span>
                          <span v-if="coach.phone" class="flex items-center gap-1">
                            {{ coach.phone }}
                            <button
                                @click.stop="copyToClipboard(coach.phone, 'Telefon')"
                                class="text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded p-0.5 transition-colors"
                                title="Telefonnummer kopieren"
                            >
                              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                              </svg>
                            </button>
                          </span>
                        </div>
                      </div>
                    </template>
                    <template v-else>
                      <span>{{ coach || 'Unbekannt' }}</span>
                    </template>
                  </div>
                </div>
              </div>
              <div v-else class="text-sm text-gray-400 italic">Keine Coaches gefunden</div>
            </div>
          </div>
        </template>
      </draggable>

      <!-- Placeholder rows for plan > enrolled -->
      <template v-for="placeholder in placeholderRows" :key="placeholder.id">
        <li
            class="bg-yellow-100 border border-yellow-300 text-red-800 rounded px-3 py-2 mb-1 flex justify-between items-center gap-2"
        >
          <!-- Empty space for drag handle -->
          <span class="w-6"></span>

          <!-- Empty Txx column (no Txx shown as per requirements) -->
          <span class="w-8"></span>

          <!-- Empty team number -->
          <span class="text-sm w-12 text-red-800">–</span>

          <!-- Placeholder text -->
          <span class="flex-1 text-sm text-red-800 italic">Fehlendes Team</span>

          <!-- Empty space for checkbox -->
          <span class="w-16"></span>
        </li>
      </template>

      <!-- Note about no-show teams -->
      <div class="mt-4 text-xs text-gray-600 italic">
        "No-show" Teams bleiben im Plan, werden aber in allen Ausgaben "durchgestrichen" dargestellt.
      </div>

      <!-- Totals and Download buttons -->
      <div v-if="Object.keys(peopleData).length > 0" class="mt-4 pt-4 border-t border-gray-300">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-4">
            <div class="text-sm">
              <span class="font-semibold">Gesamt:</span>
              <span class="ml-2">{{ totalPlayers }} {{ totalPlayers === 1 ? 'Mitglied' : 'Mitglieder' }}</span>
              <span class="ml-2">+</span>
              <span class="ml-2">{{ totalCoaches }} {{ totalCoaches === 1 ? 'Coach' : 'Coaches' }}</span>
              <span class="ml-2">=</span>
              <span class="ml-2 font-semibold">{{ totalCoaches + totalPlayers }} Personen</span>
            </div>
          </div>
          <div class="flex gap-2">
            <button
                class="px-3 py-1 text-sm bg-blue-600 text-white rounded hover:bg-blue-700"
                @click="downloadJSON"
            >
              Download JSON
            </button>
            <button
                class="px-3 py-1 text-sm bg-green-600 text-white rounded hover:bg-green-700"
                @click="downloadCSV"
            >
              Download CSV
            </button>
            <button
                class="px-3 py-1 text-sm bg-purple-600 text-white rounded hover:bg-purple-700"
                @click="downloadXML"
            >
              Download XML
            </button>
          </div>
        </div>
      </div>
    </div>
    <div
        v-if="showDiffModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50"
    >
      <div class="bg-white w-full max-w-4xl max-h-[80vh] overflow-y-auto rounded-lg shadow-lg p-6 relative">
        <h2 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Abweichungen zwischen FLOW und der Anmeldung</h2>
        <button
            class="absolute top-3 right-3 text-gray-500 hover:text-black"
            @click="showDiffModal = false"
        >
          &times;
        </button>

        <div class="space-y-4">
          <div
              v-for="team in mergedTeams.filter(t => t.status !== 'match' && t.status !== 'ignored')"
              :key="team.number"
              :class="{
      'border-yellow-400': team.status === 'conflict',
      'border-green-500': team.status === 'new',
      'border-red-500': team.status === 'missing'
    }"
              class="rounded-md p-4 border-l-4 bg-gray-50"
          >
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm font-semibold text-gray-700">
                Team-Nr: {{ team.number ?? (team.draht?.number ?? 'Keine Nummer') }}
              </span>
              <span
                  :class="{
          'text-yellow-700': team.status === 'conflict',
          'text-green-700': team.status === 'new',
          'text-red-700': team.status === 'missing'
        }"
                  class="text-xs font-medium uppercase"
              >
        {{ statusLabels[team.status] }}
      </span>
            </div>

            <div class="grid grid-cols-2 gap-4 text-sm text-black">
              <div>
                <div class="text-gray-500">FLOW:</div>
                <div>{{ team.local?.name || '–' }}</div>
              </div>
              <div>
                <div class="text-gray-500">Anmeldung:</div>
                <div>{{ team.draht?.name || '–' }}</div>
              </div>
            </div>

            <div v-if="!team.draht?.number && team.draht" class="mt-2 text-xs text-yellow-700 bg-yellow-50 p-2 rounded">
              ⚠️ Dieses Team hat keine Team-Nummer in DRAHT und kann nicht importiert werden.
            </div>

            <div class="flex justify-end gap-2 mt-4">
              <button
                  v-if="team.status === 'missing'"
                  class="px-3 py-1 text-sm rounded bg-red-600 text-white hover:bg-red-700"
                  @click="deleteTeam(team)"
              >
                Löschen
              </button>
              <button
                  v-else
                  :class="{
                    'bg-blue-600 text-white hover:bg-blue-700': team.draht?.number || team.number,
                    'bg-gray-300 text-gray-500 cursor-not-allowed': !team.draht?.number && !team.number
                  }"
                  :disabled="!team.draht?.number && !team.number"
                  class="px-3 py-1 text-sm rounded"
                  @click="applyDrahtTeam(team)"
              >
                {{
                  (!team.draht?.number && !team.number) ? 'Keine Team-Nummer' : (team.status === 'new' ? 'Hinzufügen' : 'Übernehmen')
                }}
              </button>
              <button
                  class="px-3 py-1 text-sm rounded bg-gray-300 hover:bg-gray-400"
                  @click="ignoreDiff(team)"
              >
                Ignorieren
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<style scoped>
.drag-ghost {
  opacity: 0.4;
  transform: scale(0.98);
}

.drag-chosen {
  background-color: #fde68a; /* yellow-200 */
  box-shadow: 0 0 0 2px #facc15; /* yellow-400 */
}

.drag-dragging {
  cursor: grabbing;
}
</style>

<style scoped>
.editable-input {
  border: 1px solid transparent;
  background-color: transparent;
  transition: all 0.2s ease;
  position: relative;
}

.editable-input:hover {
  background: rgba(255, 255, 255, 0.8);
  border-color: #d1d5db;
  cursor: text;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.editable-input:focus {
  background: white;
  border-color: #3b82f6;
  box-shadow: 0 0 0 1px #3b82f6, 0 2px 4px rgba(0, 0, 0, 0.1);
  outline: none;
}

.editable-input::placeholder {
  color: #9ca3af;
  font-style: italic;
}
</style>
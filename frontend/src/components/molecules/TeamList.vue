<script setup>
import draggable from 'vuedraggable'
import {computed, toRef, ref, watch, onMounted, nextTick} from "vue";
import axios from "axios";
import {useEventStore} from "@/stores/event";
import IconDraggable from "@/components/icons/IconDraggable.vue";
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import {getProgramTheme} from '@/utils/programTheme'
import SavingToast from "@/components/atoms/SavingToast.vue"
import {showGlassToast} from '@/composables/useGlassToast'
import {drahtIdFor, programMatchesSlug} from '@/utils/eventPrograms'


const props = defineProps({
  program: {type: String, required: true},
  remoteTeams: {type: Array, default: () => []},
  /** DRAHT venue capacity for this program (optional). */
  remoteCapacity: {type: Number, default: 0},
  /** List left (2/3) + export/tools right (1/3) */
  split: {type: Boolean, default: false},
})

const isExplore = computed(() => programMatchesSlug(props.program, 'explore'))
const isChallenge = computed(() => programMatchesSlug(props.program, 'challenge'))

const programTheme = computed(() => getProgramTheme(props.program))
const programLabel = computed(() => programTheme.value.shortName)

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
  // Keep arrays per number so duplicate FLOW teams are not overwritten.
  const localMapByNumber = new Map()
  const drahtMapByNumber = new Map()

  localTeams.value.forEach(t => {
    const num = normalizeTeamNumber(t.team_number_hot)
    if (num != null) {
      if (!localMapByNumber.has(num)) localMapByNumber.set(num, [])
      localMapByNumber.get(num).push(t)
    }
  })

  props.remoteTeams.forEach(t => {
    const num = normalizeTeamNumber(t.number)
    if (num != null) {
      if (!drahtMapByNumber.has(num)) drahtMapByNumber.set(num, [])
      drahtMapByNumber.get(num).push(t)
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

  // Match by number with cardinality support (1:1, 1:n, n:1)
  allNumbers.forEach(number => {
    const locals = localMapByNumber.get(number) || []
    const drahts = drahtMapByNumber.get(number) || []
    const maxLen = Math.max(locals.length, drahts.length)

    for (let i = 0; i < maxLen; i++) {
      const local = locals[i] || null
      const draht = drahts[i] || null

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

      if (local?.id != null) processedLocalIds.add(local.id)
      if (draht?.id != null) processedDrahtIds.add(draht.id)

      result.push({number, local, draht, status})
    }
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
    showGlassToast('Fehler: Team-Nummer ist erforderlich. Das Team in DRAHT hat keine gültige "ref" (Team-Nummer).', 'error')
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
    showGlassToast('Fehler beim Übernehmen des Teams: ' + (e.response?.data?.message || e.message), 'error')
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
    showGlassToast('Fehler beim Löschen des Teams: ' + (e.response?.data?.message || e.message), 'error')
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

const diffCount = computed(() =>
    mergedTeams.value.filter(t => !['match', 'ignored'].includes(t.status)).length
)

// Computed: Get plan capacity for current program
const planCapacity = computed(() => {
  if (isExplore.value) return planParams.value.e_teams
  if (isChallenge.value) return planParams.value.c_teams
  return props.remoteTeams.length
})

// Computed: Get enrolled count for current program
const enrolledCount = computed(() => props.remoteTeams.length)

const venueCapacity = computed(() => Number(props.remoteCapacity || 0))

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
  return isExplore.value && (planParams.value.e_mode === 5 || planParams.value.e_mode === 8)
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

/** Coach display names for the team row */
const getCoachNames = (team) => {
  const data = getTeamPeopleData(team)
  if (!data?.coaches?.length) return []
  return data.coaches
      .map((coach) => {
        if (typeof coach === 'string') return coach.trim() || null
        if (!coach || typeof coach !== 'object') return null
        const name = [coach.firstname, coach.name].filter(Boolean).join(' ').trim()
        return name || null
      })
      .filter(Boolean)
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
  let xml = '<?xml version="1.0" encoding="UTF-8"?>\n<teams offset="0">\n'

  // Create a map of team_number_hot to team objects for quick lookup
  const teamMap = new Map()
  teamList.value.forEach(team => {
    if (team.team_number_hot) {
      teamMap.set(String(team.team_number_hot), team)
    }
  })

  const programName = programLabel.value

  Object.entries(peopleData.value).forEach(([teamNumber, teamData]) => {
    // Find matching team object to get organization and location
    const teamObj = teamMap.get(teamNumber)
    const organization = teamObj?.organization || teamData.organization || ''
    const location = teamObj?.location || teamData.location || ''
    const teamName = teamData.name || teamObj?.name || ''

    xml += `\t<team>\n`
    xml += `\t\t<nummer>${escapeXml(teamNumber)}</nummer>\n`
    xml += `\t\t<name>${escapeXml(teamName)}</name>\n`
    xml += `\t\t<programm>${escapeXml(programName)}</programm>\n`
    xml += `\t\t<institution>\n`
    xml += `\t\t\t<name>${escapeXml(organization)}</name>\n`
    xml += `\t\t\t<ort>${escapeXml(location)}</ort>\n`
    xml += `\t\t</institution>\n`
    xml += `\t\t<mitglieder>\n`

    // Add players as teammitglied
    if (teamData.players && Array.isArray(teamData.players)) {
      teamData.players.forEach(player => {
        if (player.name || player.firstname) {
          xml += `\t\t\t<mitglied typ="teammitglied">\n`
          xml += `\t\t\t\t<vorname>${escapeXml(player.firstname || '')}</vorname>\n`
          xml += `\t\t\t\t<nachname>${escapeXml(player.name || '')}</nachname>\n`
          xml += `\t\t\t</mitglied>\n`
        }
      })
    }

    // Add coaches - first one is "coach", rest are "co-coach"
    if (teamData.coaches && Array.isArray(teamData.coaches)) {
      teamData.coaches.forEach((coach, index) => {
        const coachType = index === 0 ? 'coach' : 'co-coach'
        xml += `\t\t\t<mitglied typ="${coachType}">\n`

        if (typeof coach === 'object' && coach !== null) {
          // Split name into firstname and lastname if possible
          const fullName = coach.name || ''
          const nameParts = fullName.trim().split(/\s+/)
          const vorname = nameParts.length > 1 ? nameParts.slice(0, -1).join(' ') : ''
          const nachname = nameParts.length > 0 ? nameParts[nameParts.length - 1] : fullName

          xml += `\t\t\t\t<vorname>${escapeXml(vorname)}</vorname>\n`
          xml += `\t\t\t\t<nachname>${escapeXml(nachname)}</nachname>\n`
          xml += `\t\t\t\t<email>${escapeXml(coach.email || '')}</email>\n`
          xml += `\t\t\t\t<telefon>${escapeXml(coach.phone || '')}</telefon>\n`
        } else {
          // String coach - try to split name
          const fullName = String(coach || '')
          const nameParts = fullName.trim().split(/\s+/)
          const vorname = nameParts.length > 1 ? nameParts.slice(0, -1).join(' ') : ''
          const nachname = nameParts.length > 0 ? nameParts[nameParts.length - 1] : fullName

          xml += `\t\t\t\t<vorname>${escapeXml(vorname)}</vorname>\n`
          xml += `\t\t\t\t<nachname>${escapeXml(nachname)}</nachname>\n`
          xml += `\t\t\t\t<email></email>\n`
          xml += `\t\t\t\t<telefon></telefon>\n`
        }
        xml += `\t\t\t</mitglied>\n`
      })
    }

    xml += `\t\t</mitglieder>\n`
    xml += `\t</team>\n`
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
    const drahtEventId = drahtIdFor(event.value, props.program)

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

  <div
      class="team-list"
      :class="{ 'team-list--split': split }"
  >
    <div class="team-list__main glass-card liquid-surface-inner">
      <div class="flex items-start sm:items-center gap-2 mb-2">
        <img
            :alt="programLogoAlt(programTheme.logoKey || program)"
            :src="programLogoSrc(programTheme.logoKey || program)"
            class="w-10 h-10 flex-shrink-0"
        />
        <div>
          <h3 class="text-lg font-semibold">
            <span class="italic">FIRST</span> LEGO League {{ programLabel }}
          </h3>
          <div class="text-sm text-[var(--color-text-subtle)] flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
            <span>
              <span :class="planCapacity !== enrolledCount ? 'bg-amber-50 px-1.5 py-0.5 rounded-md text-amber-950 font-medium' : ''">Plan für: {{
                  planCapacity
                }}</span>, <span
                :class="planCapacity !== enrolledCount ? 'bg-amber-50 px-1.5 py-0.5 rounded-md text-amber-950 font-medium' : ''">Angemeldet: {{
                enrolledCount
              }}</span>, Kapazität: {{ venueCapacity }}
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
      <div v-if="showSyncPrompt" class="mb-3 p-3 bg-amber-50 border border-amber-200 text-amber-950 rounded-xl flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 text-sm font-medium">
        Die Daten in FLOW weichen von denen der Anmeldung ab.
        <button
          class="px-3 py-1.5 text-sm font-medium rounded-lg bg-blue-600 text-white hover:bg-blue-700 shrink-0"
          @click="showDiffModal = !showDiffModal"
        >
          {{ diffCount }} {{ diffCount === 1 ? 'Änderung' : 'Änderungen' }} übernehmen
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
                  'rounded-xl px-3 py-2 md:py-2.5 mb-1.5 flex flex-wrap md:flex-nowrap justify-between items-center gap-2 transition-opacity cursor-pointer border',
                  (teamsBeyondCapacity && index >= planCapacity)
                    ? 'bg-amber-50 text-amber-950 border-amber-200'
                    : 'bg-white/90 text-[var(--color-text)] border-[var(--color-border)]',
                  team.noshow ? 'opacity-55' : 'opacity-100',
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
              <span class="drag-handle cursor-move text-[var(--color-text-muted)] self-center" @click.stop><IconDraggable/></span>

              <!-- Mobile: zweizeilig, linksbündig -->
              <div class="flex-1 min-w-0 md:hidden grid grid-cols-[3rem_minmax(0,1fr)] gap-x-2 gap-y-0 items-start">
                <div class="flex flex-col leading-4">
                  <span
                      v-if="!teamsBeyondCapacity || index < planCapacity"
                      :class="(teamsBeyondCapacity && index >= planCapacity) ? 'text-amber-950' : 'text-[var(--color-text)]'"
                      class="text-sm font-semibold tabular-nums"
                  >
                    T{{ String(index + 1).padStart(2, '0') }}
                  </span>
                  <span v-else class="text-sm font-semibold text-amber-950">–</span>
                  <span
                      :class="(teamsBeyondCapacity && index >= planCapacity) ? 'text-amber-900' : 'text-[var(--color-text-muted)]'"
                      class="text-sm tabular-nums"
                  >
                    {{ team.team_number_hot ? String(team.team_number_hot).padStart(4, '0') : '0000' }}
                  </span>
                </div>

                <div class="min-w-0">
                  <div
                      :class="(teamsBeyondCapacity && index >= planCapacity) ? 'text-amber-950' : 'text-[var(--color-text)]'"
                      class="text-sm font-medium truncate leading-tight"
                  >
                    {{ team.name }}
                  </div>
                  <div
                      v-if="getCoachNames(team).length"
                      class="inline-flex items-center gap-1 text-xs text-[var(--color-text-muted)] truncate leading-tight mt-0.5 min-w-0"
                      :title="getCoachNames(team).join(', ')"
                  >
                    <i class="bi bi-person-badge shrink-0" aria-hidden="true"/>
                    <span class="truncate">{{ getCoachNames(team).join(', ') }}</span>
                  </div>
                </div>

                <div class="col-start-2 flex items-center justify-between gap-2 -mt-1">
                  <label
                      v-if="!(teamsBeyondCapacity && index >= planCapacity)"
                      class="flex items-center gap-1 text-xs text-[var(--color-text-muted)] cursor-pointer"
                      @click.stop
                  >
                    <input
                        v-model="team.noshow"
                        class="w-3.5 h-3.5 text-blue-600 border-[var(--color-border)] rounded focus:ring-blue-500"
                        type="checkbox"
                        @change="updateTeamNoshow(team)"
                    />
                    <span class="text-xs">No-show</span>
                  </label>
                  <span v-else class="text-xs text-amber-800">No-show</span>
                  <span class="ml-auto flex items-center gap-2">
                    <span
                        v-if="getPeopleCount(team) !== null"
                        class="inline-flex items-center gap-0.5 text-xs tabular-nums text-[var(--color-text-muted)]"
                        :title="`${getPeopleCount(team)} Personen`"
                    >
                      {{ getPeopleCount(team) }}
                      <i class="bi bi-person-fill text-[0.85em]" aria-hidden="true"></i>
                    </span>
                    <span v-else class="text-xs text-[var(--color-text-subtle)]">–</span>
                    <span class="text-[var(--color-text-muted)] text-sm">
                      {{ isTeamExpanded(team) ? '▼' : '▶' }}
                    </span>
                  </span>
                </div>
              </div>

              <!-- Desktop: einzeilig -->
              <div class="hidden md:flex flex-1 min-w-0 items-center gap-3">
                <span
                    v-if="!teamsBeyondCapacity || index < planCapacity"
                    :class="(teamsBeyondCapacity && index >= planCapacity) ? 'text-amber-950' : 'text-[var(--color-text)]'"
                    class="w-8 text-right text-sm font-semibold tabular-nums shrink-0"
                >
                  T{{ String(index + 1).padStart(2, '0') }}
                </span>
                <span v-else class="w-8 text-right text-sm font-semibold text-amber-950 shrink-0">–</span>
                <span
                    :class="(teamsBeyondCapacity && index >= planCapacity) ? 'text-amber-900' : 'text-[var(--color-text-muted)]'"
                    class="text-sm w-12 tabular-nums font-medium shrink-0"
                >
                  {{ team.team_number_hot || '–' }}
                </span>
                <span
                    :class="(teamsBeyondCapacity && index >= planCapacity) ? 'text-amber-950' : 'text-[var(--color-text)]'"
                    class="min-w-0 basis-[30%] max-w-[14rem] text-sm font-medium truncate"
                >
                  {{ team.name }}
                </span>
                <span
                    class="min-w-0 flex-1 inline-flex items-center gap-1.5 text-sm text-[var(--color-text-muted)]"
                    :title="getCoachNames(team).join(', ') || undefined"
                >
                  <template v-if="getCoachNames(team).length">
                    <i class="bi bi-person-badge shrink-0 opacity-80" aria-hidden="true"/>
                    <span class="truncate">{{ getCoachNames(team).join(', ') }}</span>
                  </template>
                  <span v-else class="text-[var(--color-text-subtle)]">–</span>
                </span>
                <span
                    v-if="getPeopleCount(team) !== null"
                    class="inline-flex items-center gap-1 text-sm tabular-nums text-[var(--color-text-muted)] shrink-0"
                    :title="`${getPeopleCount(team)} Personen`"
                >
                  {{ getPeopleCount(team) }}
                  <i class="bi bi-person-fill" aria-hidden="true"></i>
                </span>
                <span v-else class="text-sm text-[var(--color-text-subtle)] shrink-0">–</span>
                <label
                    v-if="!(teamsBeyondCapacity && index >= planCapacity)"
                    class="flex items-center gap-1 text-sm text-[var(--color-text-muted)] cursor-pointer shrink-0"
                    @click.stop
                >
                  <input
                      v-model="team.noshow"
                      class="w-4 h-4 text-blue-600 border-[var(--color-border)] rounded focus:ring-blue-500"
                      type="checkbox"
                      @change="updateTeamNoshow(team)"
                  />
                  <span class="text-xs">No-show</span>
                </label>
                <span v-else class="text-xs text-amber-800 shrink-0">No-show</span>
                <span class="text-[var(--color-text-muted)] text-sm shrink-0">
                  {{ isTeamExpanded(team) ? '▼' : '▶' }}
                </span>
              </div>
              <!-- Eingabefeld -->
              <!--<input
                  v-model="team.name"
                  :class="[
                    'editable-input flex-1 text-sm px-2 py-1 border border-transparent rounded hover:border-[var(--color-border)] focus:border-blue-500 focus:outline-none transition-colors cursor-pointer',
                    (teamsBeyondCapacity && index >= planCapacity) ? 'text-red-800' : ''
                  ]"
                  placeholder="Click to edit team name"
                  @blur="updateTeamName(team)"
                  @click.stop
              />
              -->

            </li>
            <!-- Expanded players and coaches list -->
            <div v-if="isTeamExpanded(team) && getTeamPeopleData(team)" class="ml-8 mb-2 bg-[var(--color-bg-muted)] rounded p-3">
              <!-- Players section -->
              <div v-if="getTeamPeopleData(team).players && getTeamPeopleData(team).players.length > 0" class="mb-3">
                <div class="text-xs font-semibold text-[var(--color-text-muted)] mb-1">Mitglieder
                  ({{ getTeamPeopleData(team).num_players || 0 }}):
                </div>
                <div class="space-y-1">
                  <div
                      v-for="(player, playerIndex) in getTeamPeopleData(team).players"
                      :key="playerIndex"
                      class="text-sm text-[var(--color-text-muted)]"
                  >
                    <span v-if="player.name || player.firstname">
                      {{ player.firstname || '' }} {{ player.name || '' }}
                      <span class="text-[var(--color-text-subtle)]">({{ player.gender || 'N/A' }}, {{
                          formatBirthday(player.birthday)
                        }})</span>
                    </span>
                    <span v-else class="text-[var(--color-text-subtle)] italic">Unbekanntes Mitglied</span>
                  </div>
                </div>
              </div>
              <div v-else class="text-sm text-[var(--color-text-subtle)] italic mb-3">Keine Mitglieder gefunden</div>

              <!-- Coaches section -->
              <div v-if="getTeamPeopleData(team).coaches && getTeamPeopleData(team).coaches.length > 0">
                <div class="text-xs font-semibold text-[var(--color-text-muted)] mb-1">Coaches
                  ({{ getTeamPeopleData(team).num_coaches || 0 }}):
                </div>
                <div class="space-y-1">
                  <div
                      v-for="(coach, coachIndex) in getTeamPeopleData(team).coaches"
                      :key="coachIndex"
                      class="text-sm text-[var(--color-text-muted)]"
                  >
                    <template v-if="typeof coach === 'object' && coach !== null">
                      <div class="flex flex-col">
                        <span class="font-medium">{{ coach.firstname || 'Unbekannt' }} {{
                            coach.name || 'Unbekannt'
                          }}</span>
                        <div v-if="coach.email || coach.phone"
                             class="text-xs text-[var(--color-text-subtle)] ml-2 flex flex-wrap items-center gap-2">
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
              <div v-else class="text-sm text-[var(--color-text-subtle)] italic">Keine Coaches gefunden</div>
            </div>
          </div>
        </template>
      </draggable>

      <!-- Placeholder rows for plan > enrolled -->
      <template v-for="placeholder in placeholderRows" :key="placeholder.id">
        <li
            class="bg-amber-50 border border-amber-200 text-amber-950 rounded-xl px-3 py-2.5 mb-1.5 flex flex-wrap md:flex-nowrap justify-between items-center gap-2"
        >
          <!-- Empty space for drag handle -->
          <span class="w-6"></span>

          <!-- Empty Txx column (no Txx shown as per requirements) -->
          <span class="w-8"></span>

          <!-- Empty team number -->
          <span class="text-sm w-12 text-amber-900 tabular-nums">–</span>

          <!-- Placeholder text -->
          <span class="w-full md:w-auto md:flex-1 text-sm font-medium text-amber-950 order-last md:order-none">Fehlendes Team</span>

          <!-- Empty space for checkbox -->
          <span class="w-16"></span>
        </li>
      </template>

      <!-- Stacked layout: note + exports under the list -->
      <template v-if="!split">
        <div class="mt-4 text-xs text-[var(--color-text-muted)] italic">
          "No-show" Teams bleiben im Plan, werden aber in allen Ausgaben "durchgestrichen" dargestellt.
        </div>

        <div v-if="Object.keys(peopleData).length > 0" class="mt-4 pt-4 border-t border-[var(--color-border)]">
          <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-3">
            <div class="text-sm">
              <span class="font-semibold">Gesamt:</span>
              <span class="ml-2">{{ totalPlayers }} {{ totalPlayers === 1 ? 'Mitglied' : 'Mitglieder' }}</span>
              <span class="ml-2">+</span>
              <span class="ml-2">{{ totalCoaches }} {{ totalCoaches === 1 ? 'Coach' : 'Coaches' }}</span>
              <span class="ml-2">=</span>
              <span class="ml-2 font-semibold">{{ totalCoaches + totalPlayers }} Personen</span>
            </div>
            <div class="flex flex-wrap gap-2">
              <button type="button" class="px-3 py-1 text-sm bg-blue-600 text-white rounded hover:bg-blue-700" @click="downloadJSON">
                Download JSON
              </button>
              <button type="button" class="px-3 py-1 text-sm bg-green-600 text-white rounded hover:bg-green-700" @click="downloadCSV">
                Download CSV
              </button>
              <button type="button" class="px-3 py-1 text-sm bg-purple-600 text-white rounded hover:bg-purple-700" @click="downloadXML">
                Download XML
              </button>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- Split layout: tools column -->
    <aside v-if="split" class="team-list__aside glass-card liquid-surface-inner">
      <h2 class="text-sm font-semibold tracking-wide uppercase text-[var(--color-text-muted)] mb-3">
        Export & Funktionen
      </h2>

      <div class="space-y-4">
        <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-bg-muted)]/35 px-3 py-3">
          <div class="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mb-1.5">Personen</div>
          <div v-if="Object.keys(peopleData).length > 0" class="text-sm space-y-1">
            <div class="flex justify-between gap-2">
              <span>Mitglieder</span>
              <span class="font-semibold tabular-nums">{{ totalPlayers }}</span>
            </div>
            <div class="flex justify-between gap-2">
              <span>Coaches</span>
              <span class="font-semibold tabular-nums">{{ totalCoaches }}</span>
            </div>
            <div class="flex justify-between gap-2 pt-1 border-t border-[var(--color-border)] mt-1">
              <span>Gesamt</span>
              <span class="font-semibold tabular-nums">{{ totalCoaches + totalPlayers }}</span>
            </div>
          </div>
          <p v-else class="text-sm text-[var(--color-text-muted)]">
            Noch keine Personen-Daten geladen.
          </p>
        </div>

        <div>
          <div class="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mb-2">Export</div>
          <div class="flex flex-col gap-2">
            <button
                type="button"
                class="w-full px-3 py-2 text-sm font-medium rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50"
                :disabled="Object.keys(peopleData).length === 0"
                @click="downloadJSON"
            >
              Download JSON
            </button>
            <button
                type="button"
                class="w-full px-3 py-2 text-sm font-medium rounded-lg bg-green-600 text-white hover:bg-green-700 disabled:opacity-50"
                :disabled="Object.keys(peopleData).length === 0"
                @click="downloadCSV"
            >
              Download CSV
            </button>
            <button
                type="button"
                class="w-full px-3 py-2 text-sm font-medium rounded-lg bg-purple-600 text-white hover:bg-purple-700 disabled:opacity-50"
                :disabled="Object.keys(peopleData).length === 0"
                @click="downloadXML"
            >
              Download XML
            </button>
          </div>
        </div>

        <div class="text-xs text-[var(--color-text-muted)] italic leading-relaxed">
          "No-show" Teams bleiben im Plan, werden aber in allen Ausgaben "durchgestrichen" dargestellt.
        </div>
      </div>
    </aside>

    <div
        v-if="showDiffModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50"
    >
      <div class="glass-modal glass-modal-lg max-w-4xl max-h-[80vh] overflow-y-auto p-6 relative w-full">
        <h2 class="text-lg font-bold text-[var(--color-text)] mb-4 border-b pb-2">Abweichungen zwischen FLOW und der Anmeldung</h2>
        <button
            class="absolute top-3 right-3 text-[var(--color-text-subtle)] hover:text-black"
            @click="showDiffModal = false"
        >
          &times;
        </button>

        <div class="space-y-4">
          <div
              v-for="team in mergedTeams.filter(t => t.status !== 'match' && t.status !== 'ignored')"
              :key="`${team.number ?? 'no-number'}-${team.local?.id ?? 'no-local'}-${team.draht?.id ?? team.draht?.name ?? 'no-draht'}`"
              :class="{
      'border-yellow-400': team.status === 'conflict',
      'border-green-500': team.status === 'new',
      'border-red-500': team.status === 'missing'
    }"
              class="rounded-md p-4 border-l-4 bg-[var(--color-bg-muted)]"
          >
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm font-semibold text-[var(--color-text-muted)]">
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
                <div class="text-[var(--color-text-subtle)]">FLOW:</div>
                <div>{{ team.local?.name || '–' }}</div>
              </div>
              <div>
                <div class="text-[var(--color-text-subtle)]">Anmeldung:</div>
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
                    'bg-gray-300 text-[var(--color-text-subtle)] cursor-not-allowed': !team.draht?.number && !team.number
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
.team-list {
  min-height: 0;
}

.team-list--split {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
  align-items: start;
  height: 100%;
}

@media (min-width: 960px) {
  .team-list--split {
    grid-template-columns: minmax(0, 2fr) minmax(16rem, 1fr);
  }

  .team-list--split .team-list__main {
    max-height: calc(100dvh - 8rem);
    overflow-y: auto;
  }

  .team-list--split .team-list__aside {
    position: sticky;
    top: 0.25rem;
  }
}

.team-list__main,
.team-list__aside {
  min-width: 0;
}

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
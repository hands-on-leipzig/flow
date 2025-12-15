<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import axios from 'axios'

import { formatDateOnly, formatDateTime } from '@/utils/dateTimeFormat'
import { programLogoSrc, programLogoAlt } from '@/utils/images'  

import { useRouter } from 'vue-router'
import { useEventStore } from '@/stores/event'
import StatisticsExpertParametersModal from './statistics/StatisticsExpertParametersModal.vue'
import StatisticsGeneratorChartModal from './statistics/StatisticsGeneratorChartModal.vue'
import StatisticsAccessChartModal from './statistics/StatisticsAccessChartModal.vue'
import StatisticsDeleteModal from './statistics/StatisticsDeleteModal.vue'
import StatisticsExtraBlocksModal from './statistics/StatisticsExtraBlocksModal.vue'
import ConfirmationModal from './ConfirmationModal.vue'

type FlattenedRow = {
  partner_id: number | null
  partner_name: string | null
  contact_email: string | null
  event_id: number | null
  event_name: string | null
  event_date: string | null
  event_link: string | null
  event_explore: number | null
  event_challenge: number | null
  event_teams_explore: number
  event_teams_challenge: number
  draht_issue?: boolean
  plan_id: number | null
  plan_name: string | null
  plan_created: string | null
  plan_last_change: string | null
  generator_stats: number | null
  expert_param_changes?: { input: number; expert: number }
  extra_blocks?: { free: number; inserted: number }
  publication_level?: number | null
  publication_date?: string | null
  publication_last_change?: string | null
  access_count?: number
  has_warning?: boolean
}

const data = ref<any>(null)
const totals = ref<any>(null)
const accessStats = ref<Map<number, number>>(new Map())
const loading = ref(true)
const error = ref<string | null>(null)
const selectedSeasonKey = ref<string | null>(null)

// DRAHT check state
const drahtCheckState = ref({
  isRunning: false,
  checked: 0,
  total: 0,
  problems: 0,
  completed: false
})
const drahtIssues = ref<Map<number, boolean>>(new Map())
const contactEmails = ref<Record<number, string>>({})
const planWarnings = ref<Map<number, boolean>>(new Map()) // plan_id => has_warning

const router = useRouter()
const eventStore = useEventStore()

async function selectEvent(eventId, regionalPartnerId) {
  await axios.post('/user/select-event', {
    event: eventId,
    regional_partner: regionalPartnerId
  })
  await eventStore.fetchSelectedEvent()
  router.push('/event')
}

onMounted(async () => {
  try {
    const [plansRes, totalsRes, accessRes] = await Promise.all([
      axios.get('/stats/plans'),
      axios.get('/stats/totals'),
      axios.get('/stats/one-link-access').catch(() => ({ data: { accesses: [] } })),
    ])
    data.value = plansRes.data
    totals.value = totalsRes.data
    
    // Build access stats map
    if (accessRes.data?.accesses) {
      const map = new Map<number, number>()
      for (const access of accessRes.data.accesses) {
        map.set(access.event_id, access.total_count)
      }
      accessStats.value = map
    }

    if (data.value?.seasons?.length > 0) {
      // Default: preselect the most recent season
      const last = data.value.seasons[data.value.seasons.length - 1]
      selectedSeasonKey.value = `${last.season_year}-${last.season_name}`
    }
    
    // Don't start DRAHT checks automatically - user must click button
  } catch (e) {
    error.value = 'Fehler beim Laden der Statistiken.'
    console.error(e)
  } finally {
    loading.value = false
  }
})

// Watch for season changes - reset state but don't auto-start
watch(selectedSeasonKey, () => {
  if (data.value && selectedSeasonKey.value) {
    // Stop any running checks
    drahtCheckState.value.isRunning = false
    drahtIssues.value.clear()
    contactEmails.value = {}
    drahtCheckState.value = {
      isRunning: false,
      checked: 0,
      total: 0,
      problems: 0,
      completed: false
    }
  }
})

// Cleanup on unmount - stop any running checks
onUnmounted(() => {
  drahtCheckState.value.isRunning = false
})

async function startDrahtChecks() {
  // Get all events with DRAHT IDs from current season
  const season = data.value?.seasons.find(
    s => `${s.season_year}-${s.season_name}` === selectedSeasonKey.value
  )
  if (!season) return

  const eventsToCheck: number[] = []
  for (const partner of season.partners) {
    for (const event of partner.events || []) {
      if (event.event_id && (event.event_explore || event.event_challenge)) {
        eventsToCheck.push(event.event_id)
      }
    }
  }

  if (eventsToCheck.length === 0) {
    drahtCheckState.value.completed = true
    return
  }

  drahtCheckState.value = {
    isRunning: true,
    checked: 0,
    total: eventsToCheck.length,
    problems: 0,
    completed: false
  }

  // Check events one by one - only proceed if still running
  for (const eventId of eventsToCheck) {
    // Stop if user left the screen or manually stopped
    if (!drahtCheckState.value.isRunning) {
      break
    }
    
    try {
      const response = await axios.get(`/stats/draht-check/${eventId}`)
      const hasIssue = response.data.has_issue === true
      const contactEmail = response.data.contact_email && response.data.contact_email.trim() ? response.data.contact_email.trim() : null
      const planWarningsData = response.data.plan_warnings || {}
      
      if (hasIssue) {
        drahtIssues.value.set(eventId, true)
        drahtCheckState.value.problems++
      } else {
        drahtIssues.value.set(eventId, false)
      }
      
      // Store contact email if available
      if (contactEmail) {
        contactEmails.value[eventId] = contactEmail
      }
      
      // Store plan warnings
      for (const [planId, hasWarning] of Object.entries(planWarningsData)) {
        planWarnings.value.set(Number(planId), hasWarning === true)
      }
    } catch (e) {
      // On error, mark as having issue
      drahtIssues.value.set(eventId, true)
      drahtCheckState.value.problems++
      console.error(`DRAHT check failed for event ${eventId}:`, e)
    }
    
    drahtCheckState.value.checked++
    
    // Only proceed to next event if still running
    if (!drahtCheckState.value.isRunning) {
      break
    }
    
    // Small delay to avoid overwhelming the server
    await new Promise(resolve => setTimeout(resolve, 100))
  }

  // Only mark as completed if we finished all checks (not stopped)
  if (drahtCheckState.value.isRunning) {
    drahtCheckState.value.isRunning = false
    drahtCheckState.value.completed = true
  }
}

function startDrahtCheck() {
  // Reset state and start checking
  drahtIssues.value.clear()
  contactEmails.value = {}
  planWarnings.value.clear()
  drahtCheckState.value = {
    isRunning: true,
    checked: 0,
    total: 0,
    problems: 0,
    completed: false
  }
  startDrahtChecks()
}

// Map for quick access to totals per "year-name"
const totalsByKey = computed(() => {
  const map = new Map()
  if (!totals.value?.seasons) return map
  for (const s of totals.value.seasons) {
    map.set(`${s.season_year}-${s.season_name}`, s.totals ?? null)
  }
  return map
})

// Replaces the previous seasonTotals definition
const seasonTotals = computed(() => {
  const ZERO = {
    rp_total: 0,
    rp_with_events: 0,
    events_total: 0,
    events_past: 0,
    events_future: 0,
    events_with_plan: 0,
    events_with_plan_past: 0,
    events_with_plan_future: 0,
    events_with_plan_with_generator_past: 0,
    events_with_plan_with_generator_future: 0,
    plans_total: 0,
    activity_groups_total: 0,
    activities_total: 0,
  }
  if (!totals.value?.seasons || !selectedSeasonKey.value) return ZERO
  const s = totals.value.seasons.find(
    t => `${t.season_year}-${t.season_name}` === selectedSeasonKey.value
  )
  if (!s) return ZERO
  return {
    rp_total: s.rp?.total ?? 0,
    rp_with_events: s.rp?.with_events ?? 0,
    events_total: s.events?.total ?? 0,
    events_past: s.events?.past ?? 0,
    events_future: s.events?.future ?? 0,
    events_with_plan: s.events?.with_plan ?? 0,
    events_with_plan_past: s.events?.with_plan_past ?? 0,
    events_with_plan_future: s.events?.with_plan_future ?? 0,
    events_with_plan_with_generator_past: s.events?.with_plan_with_generator_past ?? 0,
    events_with_plan_with_generator_future: s.events?.with_plan_with_generator_future ?? 0,
    plans_total: s.plans?.total ?? 0,
    activity_groups_total: s.activity_groups?.total ?? 0,
    activities_total: s.activities?.total ?? 0,
  }
})

const orphans = computed(() => ({
  events: totals.value?.global_orphans?.events?.orphans ?? 0,
  plans: totals.value?.global_orphans?.plans?.orphans ?? 0,
  ags: totals.value?.global_orphans?.activity_groups?.orphans ?? 0,
  acts: totals.value?.global_orphans?.activities?.orphans ?? 0,
}))

type CleanupTarget = 'events' | 'plans' | 'activity-groups' | 'activities'
type ModalMode = 'plan-delete' | 'cleanup' | 'non-default-parameters' | 'timeline' | 'access-chart' | 'extra-blocks'

const cleanupMeta: Record<
  CleanupTarget,
  { title: string; description: string; confirmLabel: string; orphanKey: 'events' | 'plans' | 'ags' | 'acts' }
> = {
  events: {
    title: 'Events bereinigen?',
    description: 'Alle Events ohne gültigen Regionalpartner werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'events',
  },
  plans: {
    title: 'Pläne bereinigen?',
    description: 'Alle Pläne ohne gültiges Event werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'plans',
  },
  'activity-groups': {
    title: 'Activity Groups bereinigen?',
    description: 'Alle Activity Groups ohne gültigen Plan werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'ags',
  },
  activities: {
    title: 'Activities bereinigen?',
    description: 'Alle Activities ohne gültige Activity Group werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'acts',
  },
}

const modalState = ref<{
  visible: boolean
  mode: ModalMode | null
  planId: number | null
  planName: string | null
  eventId: number | null
  cleanupType: CleanupTarget | null
}>({
  visible: false,
  mode: null,
  planId: null,
  planName: null,
  eventId: null,
  cleanupType: null,
})


const badgeClass = (n) =>
  n > 0
    ? 'bg-red-100 text-red-800 border border-red-300'
    : 'bg-gray-100 text-gray-700 border border-gray-300'

const publicationTotals = computed(() => ({
  total: totals.value?.publication_totals?.total ?? 0,
  level_1: totals.value?.publication_totals?.level_1 ?? 0,
  level_2: totals.value?.publication_totals?.level_2 ?? 0,
  level_3: totals.value?.publication_totals?.level_3 ?? 0,
  level_4: totals.value?.publication_totals?.level_4 ?? 0,
}))

const flattenedRows = computed<FlattenedRow[]>(() => {
  const season = data.value?.seasons.find(
    s => `${s.season_year}-${s.season_name}` === selectedSeasonKey.value
  )
  if (!season) return []

  const rows: FlattenedRow[] = []

  for (const partner of season.partners) {
      if (!partner.events || partner.events.length === 0) {
        rows.push({
          partner_id: partner.partner_id,
          partner_name: partner.partner_name,
          contact_email: null,
          event_id: null,
          event_name: null,
          event_date: null,
          event_link: null,
          event_explore: null,
          event_challenge: null,
          event_teams_explore: 0,
          event_teams_challenge: 0,
          draht_issue: false,
          plan_id: null,
          plan_name: null,
          plan_created: null,
          plan_last_change: null,
          generator_stats: null,
        })
        continue
      }

    for (const event of partner.events) {
      const teamsExplore = Number(event.teams_explore ?? 0)
      const teamsChallenge = Number(event.teams_challenge ?? 0)
      if (!event.plans || event.plans.length === 0) {
        rows.push({
          partner_id: partner.partner_id,
          partner_name: partner.partner_name,
          contact_email: contactEmails.value[event.event_id] ?? null,
          event_id: event.event_id,
          event_name: event.event_name,
          event_date: event.event_date,
          event_link: event.event_link ?? null,
          event_explore: event.event_explore,
          event_challenge: event.event_challenge,
          event_teams_explore: teamsExplore,
          event_teams_challenge: teamsChallenge,
          draht_issue: drahtIssues.value.get(event.event_id) ?? false,
          plan_id: null,
          plan_name: null,
          plan_created: null,
          plan_last_change: null,
          generator_stats: null,
          access_count: accessStats.value.get(event.event_id) ?? undefined,
        })
        continue
      }

      for (const plan of event.plans) {
        rows.push({
          partner_id: partner.partner_id,
          partner_name: partner.partner_name,
          contact_email: contactEmails.value[event.event_id] ?? null,
          event_id: event.event_id,
          event_name: event.event_name,
          event_date: event.event_date,
          event_link: event.event_link ?? null,
          event_explore: event.event_explore,
          event_challenge: event.event_challenge,
          event_teams_explore: teamsExplore,
          event_teams_challenge: teamsChallenge,
          draht_issue: drahtIssues.value.get(event.event_id) ?? false,
          plan_id: plan.plan_id,
          plan_name: plan.plan_name,
          plan_created: plan.plan_created,
          plan_last_change: plan.plan_last_change,
          generator_stats: plan.generator_stats ?? null,
          expert_param_changes: plan.expert_param_changes ?? { input: 0, expert: 0 },
          extra_blocks: plan.extra_blocks ?? { free: 0, inserted: 0 },
          publication_level: plan.publication_level ?? null,
          publication_date: plan.publication_date ?? null,
          publication_last_change: plan.publication_last_change ?? null,
          access_count: accessStats.value.get(event.event_id) ?? undefined,
          has_warning: planWarnings.value.get(plan.plan_id) ?? false,
        })
      }
    }
  }

  return rows
})

function shouldShowPartner(index) {
  if (index === 0) return true
  return flattenedRows.value[index].partner_id !== flattenedRows.value[index - 1].partner_id
}

function shouldShowEvent(index) {
  if (index === 0) return true
  const current = flattenedRows.value[index]
  const previous = flattenedRows.value[index - 1]
  return (
    current.partner_id !== previous.partner_id ||
    current.event_id !== previous.event_id
  )
}

function getEventName(eventId: number | null): string {
  if (!eventId) return ''
  const row = flattenedRows.value.find(r => r.event_id === eventId)
  return row?.event_name || ''
}

const getPlanCount = (eventId) => {
  return flattenedRows.value.filter(r => r.event_id === eventId && r.plan_id !== null).length
}

function openPreview(planId) {
  window.open(`/preview/${planId}`, '_blank', 'noopener')
}

function formatNumber(num) {
  if (num === null || num === undefined) return '0'
  return Number(num).toLocaleString('de-DE')
}

function getHoursSince(timestamp: string | null): number | null {
  if (!timestamp) return null
  const date = new Date(timestamp)
  if (isNaN(date.getTime())) return null
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  return Math.floor(diffMs / (1000 * 60 * 60))
}

function getEventDateClass(eventDate: string | null): string {
  if (!eventDate) return ''
  
  try {
    const eventDateObj = new Date(eventDate)
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    eventDateObj.setHours(0, 0, 0, 0)
    
    const diffTime = eventDateObj.getTime() - today.getTime()
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24))
    
    // If event date is within 14 days from today (0 to 14 days)
    if (diffDays >= 0 && diffDays <= 14) {
      return 'bg-orange-200'
    }
  } catch (e) {
    // Invalid date, return empty string
  }
  
  return ''
}

function getLastChangeClass(timestamp: string | null): string {
  const hours = getHoursSince(timestamp)
  if (hours === null) return ''
  
  if (hours <= 24) {
    return 'bg-blue-600 text-white' // Darkest blue - last 24 hours
  } else if (hours <= 72) {
    return 'bg-blue-400 text-white' // Medium blue - last 72 hours
  } else if (hours <= 168) {
    return 'bg-blue-200 text-gray-800' // Lightest blue - last 7 days
  }
  return '' // No highlight for older changes
}


function openPlanDelete(planId: number) {
  // Find plan name from flattened rows
  const row = flattenedRows.value.find(r => r.plan_id === planId)
  modalState.value = {
    visible: true,
    mode: 'plan-delete',
    planId,
    planName: row?.plan_name || null,
    eventId: null,
    cleanupType: null,
  }
}

function askCleanup(target: CleanupTarget) {
  const meta = cleanupMeta[target]
  const count = (orphans.value as Record<string, number>)[meta.orphanKey] ?? 0
  if (count === 0) return

  modalState.value = {
    visible: true,
    mode: 'cleanup',
    planId: null,
    planName: null,
    eventId: null,
    cleanupType: target,
  }
}

function openNonDefaultParameters(planId: number) {
  modalState.value = {
    visible: true,
    mode: 'non-default-parameters',
    planId,
    planName: null,
    eventId: null,
    cleanupType: null,
  }
}

function openTimeline(planId: number) {
  modalState.value = {
    visible: true,
    mode: 'timeline',
    planId,
    planName: null,
    eventId: null,
    cleanupType: null,
  }
}

function openAccessChart(eventId: number) {
  modalState.value = {
    visible: true,
    mode: 'access-chart',
    planId: null,
    planName: null,
    eventId,
    cleanupType: null,
  }
}

function openExtraBlocks(planId: number) {
  modalState.value = {
    visible: true,
    mode: 'extra-blocks',
    planId,
    planName: null,
    eventId: null,
    cleanupType: null,
  }
}

const timelineModalInfo = computed(() => {
  if (!modalState.value.planId) return null
  const row = flattenedRows.value.find(r => r.plan_id === modalState.value.planId)
  if (!row) return null
  return {
    event_name: row.event_name,
    event_id: row.event_id,
    plan_id: modalState.value.planId,
  }
})

function closeModal() {
  modalState.value = {
    visible: false,
    mode: null,
    planId: null,
    planName: null,
    eventId: null,
    cleanupType: null,
  }
}

const deletePlanMessage = computed(() => {
  if (!modalState.value.planId) return ''
  const planName = modalState.value.planName || modalState.value.planId || 'Unbekannt'
  return `Plan "${planName}" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

async function reloadStats() {
  const [plansRes, totalsRes] = await Promise.all([
    axios.get('/stats/plans'),
    axios.get('/stats/totals'),
  ])
  data.value = plansRes.data
  totals.value = totalsRes.data
  
  // Reset DRAHT check state (don't auto-start)
  drahtIssues.value.clear()
  contactEmails.value = {}
  drahtCheckState.value = {
    isRunning: false,
    checked: 0,
    total: 0,
    problems: 0,
    completed: false
  }
}

async function confirmModal() {
  if (!modalState.value.mode) return

  try {
    if (modalState.value.mode === 'plan-delete' && modalState.value.planId) {
      await axios.delete(`/plans/${modalState.value.planId}`)
    } else if (modalState.value.mode === 'cleanup' && modalState.value.cleanupType) {
      await axios.delete(`/stats/orphans/${modalState.value.cleanupType}/cleanup`)
    } else {
      return
    }
    await reloadStats()
  } catch (e) {
    if (modalState.value.mode === 'plan-delete') {
      console.error('Fehler beim Löschen des Plans:', e)
    } else {
      console.error('Fehler bei der Orphan-Bereinigung:', e)
    }
  } finally {
    closeModal()
  }
}

function exportToCSV() {
  if (!flattenedRows.value || flattenedRows.value.length === 0) {
    alert('Keine Daten zum Exportieren verfügbar.')
    return
  }

  // Define CSV headers
  const headers = [
    'RP ID',
    'Partner',
    'Contact Email',
    'Event ID',
    'Event Name',
    'Datum',
    'Event Link',
    'Event Explore',
    'Event Challenge',
    'Teams Explore',
    'Teams Challenge',
    'DRAHT Issue',
    'Plan ID',
    'Plan Name',
    'Plan Created',
    'Plan Last Change',
    'Generator Stats',
    'Expert Parameter Changes (Input)',
    'Expert Parameter Changes (Expert)',
    'Extra Blocks (Free)',
    'Extra Blocks (Inserted)',
    'Publication Level',
    'Publication Date',
    'Publication Last Change',
    'Access Count'
  ]

  // Convert rows to CSV format
  const csvRows = [
    headers.join(','),
    ...flattenedRows.value.map(row => {
      const escapeCSV = (value: any) => {
        if (value === null || value === undefined) return ''
        const str = String(value)
        // Escape quotes and wrap in quotes if contains comma, quote, or newline
        if (str.includes(',') || str.includes('"') || str.includes('\n')) {
          return `"${str.replace(/"/g, '""')}"`
        }
        return str
      }

      return [
        escapeCSV(row.partner_id),
        escapeCSV(row.partner_name),
        escapeCSV(row.contact_email ?? ''),
        escapeCSV(row.event_id),
        escapeCSV(row.event_name),
        escapeCSV(row.event_date ? formatDateOnly(row.event_date) : ''),
        escapeCSV(row.event_link),
        escapeCSV(row.event_explore),
        escapeCSV(row.event_challenge),
        escapeCSV(row.event_teams_explore),
        escapeCSV(row.event_teams_challenge),
        escapeCSV(row.draht_issue ? 'Yes' : 'No'),
        escapeCSV(row.plan_id),
        escapeCSV(row.plan_name),
        escapeCSV(row.plan_created ? formatDateTime(row.plan_created) : ''),
        escapeCSV(row.plan_last_change ? formatDateTime(row.plan_last_change) : ''),
        escapeCSV(row.generator_stats),
        escapeCSV(row.expert_param_changes?.input ?? 0),
        escapeCSV(row.expert_param_changes?.expert ?? 0),
        escapeCSV(row.extra_blocks?.free ?? 0),
        escapeCSV(row.extra_blocks?.inserted ?? 0),
        escapeCSV(row.publication_level ?? ''),
        escapeCSV(row.publication_date ? formatDateTime(row.publication_date) : ''),
        escapeCSV(row.publication_last_change ? formatDateTime(row.publication_last_change) : ''),
        escapeCSV(row.access_count ?? '')
      ].join(',')
    })
  ]

  // Create CSV content
  const csvContent = csvRows.join('\n')

  // Create blob and download
  const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' }) // BOM for Excel UTF-8 support
  const link = document.createElement('a')
  const url = URL.createObjectURL(blob)
  link.setAttribute('href', url)
  
  // Generate filename with current date in yymmdd format
  const now = new Date()
  const year = now.getFullYear().toString().slice(-2)
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  const dateStr = `${year}${month}${day}`
  link.setAttribute('download', `${dateStr} FLOW Statistics.csv`)
  
  link.style.visibility = 'hidden'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}


</script>

<template>
  <div>
    <div v-if="loading" class="text-gray-500">Lade Daten …</div>
    <div v-else-if="error" class="text-red-500">{{ error }}</div>
    <div v-else>
      <!-- Global orphans -->
      <div class="mb-2 flex flex-wrap items-center gap-2">
        <button
          type="button"
          :disabled="orphans.events === 0"
          :class="[
            'px-3 py-1 rounded-full text-sm font-semibold transition',
            badgeClass(orphans.events),
            orphans.events > 0 ? 'cursor-pointer hover:ring-2 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500' : 'opacity-70 cursor-not-allowed',
          ]"
          @click="askCleanup('events')"
        >
          Events (ohne/ungültiger RP): {{ orphans.events }}
        </button>
        <button
          type="button"
          :disabled="orphans.plans === 0"
          :class="[
            'px-3 py-1 rounded-full text-sm font-semibold transition',
            badgeClass(orphans.plans),
            orphans.plans > 0 ? 'cursor-pointer hover:ring-2 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500' : 'opacity-70 cursor-not-allowed',
          ]"
          @click="askCleanup('plans')"
        >
          Pläne (ohne/ungültiges Event): {{ orphans.plans }}
        </button>
        <button
          type="button"
          :disabled="orphans.ags === 0"
          :class="[
            'px-3 py-1 rounded-full text-sm font-semibold transition',
            badgeClass(orphans.ags),
            orphans.ags > 0 ? 'cursor-pointer hover:ring-2 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500' : 'opacity-70 cursor-not-allowed',
          ]"
          @click="askCleanup('activity-groups')"
        >
          ActGroups (ohne/ungültiger Plan): {{ orphans.ags }}
        </button>
        <button
          type="button"
          :disabled="orphans.acts === 0"
          :class="[
            'px-3 py-1 rounded-full text-sm font-semibold transition',
            badgeClass(orphans.acts),
            orphans.acts > 0 ? 'cursor-pointer hover:ring-2 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500' : 'opacity-70 cursor-not-allowed',
          ]"
          @click="askCleanup('activities')"
        >
          Activities (ohne/ungültiger ActGroup): {{ orphans.acts }}
        </button>
      </div>
        <!-- Season filter -->
        <div class="mb-3">
          <div class="flex flex-wrap gap-2">
            <label
              v-for="season in data.seasons"
              :key="`${season.season_year}-${season.season_name}`"
              class="cursor-pointer"
            >
              <input
                type="radio"
                :value="`${season.season_year}-${season.season_name}`"
                v-model="selectedSeasonKey"
                class="mr-1"
              />
              {{ season.season_year }} – {{ season.season_name }}
            </label>
          </div>
        </div>

        <!-- Season totals (5 boxes) -->
        <div class="mb-3 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-2">
          <!-- Box 1: regional partners -->
          <div class="bg-white border rounded shadow-sm p-2 space-y-0.5">
            <div class="flex justify-between text-gray-700">
              <span>Regionalpartner</span>
              <span class="font-semibold">{{ seasonTotals.rp_total }}</span>
            </div>
            <div class="flex justify-between text-gray-700">
              <span>mit Event</span>
              <span class="font-semibold">{{ seasonTotals.rp_with_events }}</span>
            </div>
          </div>

          <!-- Box 2: past events -->
          <div class="bg-white border rounded shadow-sm p-2 space-y-0.5">
            <div class="flex justify-between text-gray-700">
              <span>Events: Vergangenheit</span>
              <span class="font-semibold">{{ seasonTotals.events_past }}</span>
            </div>
            <div class="flex justify-between text-gray-700">
              <span>mit generiertem Plan</span>
              <span class="font-semibold">{{ seasonTotals.events_with_plan_with_generator_past }}</span>
            </div>
          </div>

          <!-- Box 3: future events -->
          <div class="bg-white border rounded shadow-sm p-2 space-y-0.5">
            <div class="flex justify-between text-gray-700">
              <span>Events: Zukunft</span>
              <span class="font-semibold">{{ seasonTotals.events_future }}</span>
            </div>
            <div class="flex justify-between text-gray-700">
              <span>mit generiertem Plan</span>
              <span class="font-semibold">{{ seasonTotals.events_with_plan_with_generator_future }}</span>
            </div>
          </div>

        <!-- Box 4: plans & activities -->
        <div class="bg-white border rounded shadow-sm p-2 space-y-0.5">
          <div class="flex justify-between text-gray-700">
            <span>Pläne</span>
            <span class="font-semibold">{{ formatNumber(seasonTotals.plans_total) }}</span>
          </div>
          <div class="flex justify-between text-gray-700">
            <span>ActGroups | Activities</span>
            <span class="font-semibold">
              {{ formatNumber(seasonTotals.activity_groups_total) }} | {{ formatNumber(seasonTotals.activities_total) }}
            </span>
          </div>
        </div>

        <!-- Box 5: Publications -->
        <div class="bg-white border rounded shadow-sm p-2 space-y-0.5">
          <div class="flex justify-between text-gray-700">
            <span>Veröffentlichte Pläne</span>
            <span class="font-semibold">{{ formatNumber(publicationTotals.total) }}</span>
          </div>
          <div class="flex justify-between text-gray-700">
            <span>Level 1 | 2 | 3 | 4</span>
            <span class="font-semibold">
              {{ formatNumber(publicationTotals.level_1) }} | {{ formatNumber(publicationTotals.level_2) }} | {{ formatNumber(publicationTotals.level_3) }} | {{ formatNumber(publicationTotals.level_4) }}
            </span>
          </div>
        </div>
      </div>

      <!-- DRAHT Check Banner -->
      <div v-if="drahtCheckState.isRunning || drahtCheckState.completed" class="mb-2 p-2 rounded border" :class="drahtCheckState.completed && drahtCheckState.problems > 0 ? 'bg-red-50 border-red-300' : drahtCheckState.completed ? 'bg-green-50 border-green-300' : 'bg-blue-50 border-blue-300'">
        <div class="flex justify-between items-center">
          <div class="text-sm font-medium" :class="drahtCheckState.completed && drahtCheckState.problems > 0 ? 'text-red-800' : drahtCheckState.completed ? 'text-green-800' : 'text-blue-800'">
            <template v-if="drahtCheckState.isRunning">
              DRAHT-Daten werden geladen. {{ drahtCheckState.checked }} von {{ drahtCheckState.total }} getestet. {{ drahtCheckState.problems }} Probleme.
            </template>
            <template v-else-if="drahtCheckState.completed">
              DRAHT-Daten geladen: {{ drahtCheckState.problems }} {{ drahtCheckState.problems === 1 ? 'Problem' : 'Probleme' }}.
            </template>
          </div>
          <button
            v-if="!drahtCheckState.isRunning"
            @click="startDrahtCheck"
            class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm font-medium"
          >
            DRAHT-Daten holen
          </button>
        </div>
      </div>
      
      <!-- DRAHT Check Button (when not running and not completed) -->
      <div v-if="!drahtCheckState.isRunning && !drahtCheckState.completed" class="mb-2 p-2 rounded border bg-blue-50 border-blue-300">
        <button
          @click="startDrahtCheck"
          class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm font-medium"
        >
          DRAHT-Daten holen
        </button>
      </div>

      <!-- Table -->
      <div class="border border-gray-300 bg-white rounded shadow-sm overflow-hidden">
        <div class="flex justify-between items-center p-2 bg-gray-50 border-b">
          <div class="text-xs text-gray-600">
            <span class="mr-4">🔴 = Problem mit DRAHT Daten</span>
            <span class="mr-4">⬜️ = Kein Plan</span>
            <span class="mr-4">✅ = Genau ein Plan</span>
            <span>⚠️ = Mehrere Pläne</span>
          </div>
          <button
            @click="exportToCSV"
            class="px-3 py-1.5 bg-blue-600 text-white rounded hover:bg-blue-700 text-xs font-medium"
          >
            Export als CSV
          </button>
        </div>
        <div class="max-h-[60vh] overflow-y-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-100 text-left sticky top-0 z-10">
              <tr>
                <th class="px-3 py-2">RP</th>
                <th class="px-3 py-2">Partner</th>
                <th class="px-3 py-2">Event</th>
                <th class="px-3 py-2">Name, Datum, Anmeldungen</th>
                <th class="px-3 py-2">Plan</th>
                <th class="px-3 py-2">Letzte Änderung</th>
                <th class="px-3 py-2">Generie-<br>rungen</th>
                <th class="px-3 py-2">Veränderte Parameter</th>
                <th class="px-3 py-2">Extra-Blöcke</th>
                <th class="px-3 py-2">Veröffentl.-Level / -Link</th>
                <th class="px-3 py-2">Letzte Änderung</th>
                <th class="px-3 py-2">Zugriffe</th>
              </tr>
            </thead>
            <tbody>
        <tr
            v-for="(row, index) in flattenedRows"
          :key="`${row.partner_id}-${row.event_id}-${row.plan_id}`"
          class="border-t border-gray-200 hover:bg-gray-50"
        >
          <!-- RP ID -->
          <td class="px-3 py-2 text-gray-400">
            <template v-if="shouldShowPartner(index)">
              {{ row.partner_id }}
            </template>
            <template v-else>
              &nbsp;
            </template>
          </td>

          <!-- RP name -->
          <td class="px-3 py-2">
            <template v-if="shouldShowPartner(index)">
              <span class="flex items-center gap-1">
                {{ row.partner_name }}
                <a
                  v-if="row.contact_email"
                  :href="`mailto:${row.contact_email}?subject=FLOW`"
                  class="text-blue-600 hover:text-blue-800"
                  title="E-Mail senden"
                >
                  ✉️
                </a>
              </span>
            </template>
            <template v-else>
              &nbsp;
            </template>
          </td>

          <!-- Event ID -->
          <td class="px-3 py-2 text-gray-400">
            <template v-if="shouldShowEvent(index)">
              {{ row.event_id }}
            </template>
            <template v-else>
              &nbsp;
            </template>
          </td>

          <!-- Event name + date -->
          <td class="px-3 py-2" :class="getEventDateClass(row.event_date)">
            <template v-if="shouldShowEvent(index)">
              <span class="mr-2">
                <template v-if="row.draht_issue">
                  <!-- 🔴 DRAHT issue (critical) -->
                  🔴
                </template>
                <template v-else-if="row.plan_id === null">
                  <!-- ⬜️  No plan -->
                  ⬜️ 
                </template>
                <template v-else-if="getPlanCount(row.event_id) === 1">
                  <!-- ✅ Exactly one plan -->
                  ✅
                </template>
                <template v-else>
                  <!-- ⚠️ Multiple plans -->
                  ⚠️
                </template>
              </span>
              <!-- Clickable name -->
              <a
                href="#"
                class="text-blue-600 hover:underline cursor-pointer"
                @click.prevent="selectEvent(row.event_id, row.partner_id)"
              >
                {{ row.event_name }}
              </a>

              <span class="text-gray-500"> ({{ formatDateOnly(row.event_date) }})</span>
              <span
                v-if="row.event_explore || row.event_challenge"
                class="inline-flex items-center space-x-2 ml-2"
              >
                <span
                  v-if="row.event_explore"
                  class="inline-flex items-center space-x-1"
                >
                  <img
                    :src="programLogoSrc('E')"
                    :alt="programLogoAlt('E')"
                    class="w-5 h-5 inline-block"
                  />
                  <span class="text-xs text-gray-600">
                    {{ row.event_teams_explore ?? 0 }}
                  </span>
                </span>
                <span
                  v-if="row.event_challenge"
                  class="inline-flex items-center space-x-1"
                >
                  <img
                    :src="programLogoSrc('C')"
                    :alt="programLogoAlt('C')"
                    class="w-5 h-5 inline-block"
                  />
                  <span class="text-xs text-gray-600">
                    {{ row.event_teams_challenge ?? 0 }}
                  </span>
                </span>
              </span>
            </template>
            <template v-else>
              &nbsp;
            </template>
          </td>

          <!-- Plan ID + buttons -->
          <td class="px-3 py-2 text-gray-400">
            <div class="flex flex-col items-start">
              <div class="flex items-center gap-1">
                <span>{{ row.plan_id }}</span>
                <div
                  v-if="row.has_warning"
                  class="w-2 h-2 bg-red-500 rounded-full"
                  title="Achtung: Es gibt offene Punkte in diesem Bereich"
                ></div>
              </div>
              <div v-if="row.plan_id" class="flex gap-2 mt-1">
                <!-- Preview -->
                <button
                  class="text-blue-600 hover:text-blue-800"
                  title="Vorschau öffnen"
                  @click="openPreview(row.plan_id)"
                >
                  🧾
                </button>
                <!-- Delete -->
                <button
                  class="text-red-600 hover:text-red-800"
                  title="Plan löschen"
                  @click="openPlanDelete(row.plan_id)"
                >
                  🗑️
                </button>
              </div>
            </div>
          </td>

          <!-- Plan last change -->
          <td class="px-3 py-2" :class="getLastChangeClass(row.plan_last_change)">
            {{ formatDateTime(row.plan_last_change) }}
          </td>
  
          <!-- Generator stats -->
          <td class="px-3 py-2 text-right">
            <template v-if="row.plan_id && row.generator_stats !== null">
              <div class="flex flex-col items-end">
                <span>{{ row.generator_stats }}</span>
                <button
                  v-if="row.plan_id"
                  class="text-blue-600 hover:text-blue-800 mt-1"
                  title="Timeline anzeigen"
                  @click="openTimeline(row.plan_id)"
                >
                  📈
                </button>
              </div>
            </template>
            <template v-else>
              –
            </template>
          </td>     

          <!-- Changed parameter changes -->
          <td class="px-3 py-2">
            <template v-if="row.plan_id">
              <div class="flex flex-col items-center">
                <span v-if="row.expert_param_changes">
                  {{ row.expert_param_changes.input }} + {{ row.expert_param_changes.expert }}
                </span>
                <span v-else>0 + 0</span>
                <template v-if="row.expert_param_changes && (row.expert_param_changes.input > 0 || row.expert_param_changes.expert > 0)">
                  <a
                    href="#"
                    class="text-blue-600 hover:text-blue-800 hover:underline cursor-pointer mt-1"
                    @click.prevent="openNonDefaultParameters(row.plan_id)"
                    title="Veränderte Parameter anzeigen"
                  >
                    🔍
                  </a>
                </template>
              </div>
            </template>
            <template v-else>–</template>
          </td>

          <!-- Extra blocks -->
          <td class="px-3 py-2">
            <template v-if="row.plan_id">
              <div class="flex flex-col items-center">
                <span v-if="row.extra_blocks">
                  {{ row.extra_blocks.free }} + {{ row.extra_blocks.inserted }}
                </span>
                <span v-else>0 + 0</span>
                <template v-if="row.extra_blocks && (row.extra_blocks.free > 0 || row.extra_blocks.inserted > 0)">
                  <a
                    href="#"
                    class="text-blue-600 hover:text-blue-800 hover:underline cursor-pointer mt-1"
                    @click.prevent="openExtraBlocks(row.plan_id)"
                    title="Extra-Blöcke anzeigen"
                  >
                    🔍
                  </a>
                </template>
              </div>
            </template>
            <template v-else>–</template>
          </td>

          <!-- Publication level -->
          <td class="px-3 py-2">
            <div class="flex flex-col items-start">
              <span class="flex">
                <span
                  v-for="n in 4"
                  :key="n"
                  class="w-3 h-3 rounded-full mx-0.5"
                  :class="n <= (row.publication_level ?? 0)
                    ? 'bg-blue-600'
                    : 'bg-gray-300'"
                ></span>
              </span>
              <template v-if="(row.publication_level ?? 0) >= 1">
                <template v-if="row.event_link">
                  <a
                    :href="row.event_link"
                    class="text-blue-600 hover:text-blue-800 hover:underline cursor-pointer mt-1"
                    target="_blank"
                    rel="noopener noreferrer"
                    title="Event-Link öffnen"
                  >
                    🔗
                  </a>
                </template>
                <template v-else>
                  <span class="text-yellow-600 mt-1 text-xs" title="Kein Link verfügbar">
                    ⚠️ fehlt
                  </span>
                </template>
              </template>
            </div>
          </td>

          <!-- Publication last change -->
          <td class="px-3 py-2" :class="getLastChangeClass(row.publication_last_change ?? null)">
            <template v-if="row.plan_id && row.publication_last_change">
              {{ formatDateTime(row.publication_last_change) }}
            </template>
            <template v-else>–</template>
          </td>

          <!-- Access count -->
          <td class="px-3 py-2">
            <template v-if="row.event_id && row.access_count !== null && row.access_count !== undefined">
              <div class="flex flex-col items-end">
                <span>{{ row.access_count }}</span>
                <button
                  v-if="row.event_id"
                  class="text-blue-600 hover:text-blue-800 mt-1"
                  title="Zugriffe anzeigen"
                  @click="openAccessChart(row.event_id)"
                >
                  📈
                </button>
              </div>
            </template>
            <template v-else>–</template>
          </td>

        </tr>

            </tbody>
          </table>
        </div>
      </div>

      <div v-if="flattenedRows.length === 0" class="mt-4 text-gray-500 italic">
        Keine Pläne in dieser Saison.
      </div>
    </div>
  </div>


  <!-- Modals -->
  <teleport to="body">
    <div v-if="modalState.visible" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <!-- Expert Parameters Modal -->
      <StatisticsExpertParametersModal
        v-if="modalState.mode === 'non-default-parameters' && modalState.planId"
        :plan-id="modalState.planId"
        @close="closeModal"
      />
      
      <!-- Generator Chart Modal -->
      <StatisticsGeneratorChartModal
        v-if="modalState.mode === 'timeline' && modalState.planId"
        :plan-id="modalState.planId"
        :timeline-modal-info="timelineModalInfo"
        @close="closeModal"
      />
      
      <!-- Access Chart Modal -->
      <StatisticsAccessChartModal
        v-if="modalState.mode === 'access-chart' && modalState.eventId"
        :event-id="modalState.eventId"
        :event-name="getEventName(modalState.eventId)"
        @close="closeModal"
      />
      
      <!-- Extra Blocks Modal -->
      <StatisticsExtraBlocksModal
        v-if="modalState.mode === 'extra-blocks' && modalState.planId"
        :plan-id="modalState.planId"
        @close="closeModal"
      />
      
      <!-- Plan Delete Modal -->
      <ConfirmationModal
        v-if="modalState.mode === 'plan-delete'"
        :show="modalState.visible"
        title="Plan löschen"
        :message="deletePlanMessage"
        type="danger"
        confirm-text="Löschen"
        cancel-text="Abbrechen"
        @confirm="confirmModal"
        @cancel="closeModal"
      />
      
      <!-- Cleanup Modal -->
      <StatisticsDeleteModal
        v-if="modalState.mode === 'cleanup' && modalState.cleanupType !== null"
        :mode="modalState.mode"
        :plan-id="modalState.planId"
        :cleanup-type="modalState.cleanupType"
        @confirm="confirmModal"
        @cancel="closeModal"
      />
    </div>
  </teleport>



</template>
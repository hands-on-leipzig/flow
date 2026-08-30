<script setup lang="ts">

import { formatTimeOnly, formatDateTime } from '@/utils/dateTimeFormat'

import {ref, watch, onMounted, computed} from 'vue'
import QPlanDetails from '@/components/atoms/QPlanDetails.vue'
import axios from 'axios'
import { useRoute } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import { useAdminInlineVisibility } from '@/composables/useAdminInlineVisibility'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import { getProgramTheme } from '@/utils/programTheme'

const FIRST_PROGRAM = {
  CHALLENGE: 3,
  FUTURE_8: 8,
} as const

type Match = {
  match_id: number
  match_no: number
  table_1: number | null
  table_1_team: number | null
  table_2: number | null
  table_2_team: number | null
}

type RobotGameRound = {
  round: number
  name: string
  matches: Match[]
}

type TeamSummary = {
  team: number
  different_tables: number
  different_opponents: number
}

type RobotGameData = {
  has_challenge?: boolean
  has_match_plan?: boolean
  programs?: number[]
  first_program?: number | null
  rounds: RobotGameRound[]
  team_summary: TeamSummary[]
}

const route = useRoute()
const { initializeUserRoles } = useAuth()
const { showAdminInline } = useAdminInlineVisibility()
const {
  selectedPlanId,
  planLocked,
  isGenerating,
  regeneratePlan,
} = useScheduleWorkspace()

// Ensure roles are initialized
onMounted(() => {
  initializeUserRoles()
})

const props = withDefaults(defineProps<{
  planId?: number
  initialView?: 'overview' | 'roles' | 'teams' | 'robot-game' | 'activities'
  reload?: number
  /** Hide Plan-ID and similar meta (used in pop-out). */
  hideMeta?: boolean
}>(), {
  initialView: 'overview',
  hideMeta: false,
})

const effectivePlanId = computed(() => {
  return props.planId ?? Number(route.params.planId)
})

/** Schedule shell only: workspace plan must match; skip when locked or already generating. */
const canRegeneratePlan = computed(() => {
  const planId = effectivePlanId.value
  if (!planId || !selectedPlanId.value) return false
  if (Number(selectedPlanId.value) !== Number(planId)) return false
  if (planLocked.value || isGenerating.value) return false
  return true
})

async function onRegeneratePlan() {
  if (!canRegeneratePlan.value) return
  await regeneratePlan()
}

const view = ref<'overview' | 'roles' | 'teams' | 'robot-game' | 'quality' | 'activities'>(props.initialView as any)

const loading = ref(false)
const error = ref<string | null>(null)

// Activities-Datenstruktur (roh von /plans/activities/{id})
type ActivityRow = {
  activity_id: number
  start_time: string
  end_time: string
  program: string|null
  activity_name: string
  lane: number|null
  team: number|null
  table_1_team: number|null
  table_2_team: number|null
  table_1: number|null
  table_2: number|null
  slot_team?: number | null
  room_type_name: string
}
type ActivityGroup = {
  activity_group_id: number|null
  activity_group_name?: string
  explore_group?: number|null
  activities: ActivityRow[]
}
const activities = ref<ActivityGroup[]>([])

// Match-Plan data
const robotGameData = ref<RobotGameData | null>(null)
/** Challenge-shaped programs on this plan (3 and/or 8). */
const matchPlanPrograms = ref<number[]>([])
/** Selected program for Match-Plan / Plan-Qualität. */
const selectedFirstProgram = ref<number | null>(null)

const hasMatchPlan = computed(() => matchPlanPrograms.value.length > 0)
const dualMatchPlan = computed(() => matchPlanPrograms.value.length > 1)

// Event overview HTML
const overviewHtml = ref<string>('')
/** Rollen / Teams grid HTML (Überblick-style). */
const rolesHtml = ref<string>('')
const teamsHtml = ref<string>('')
type PreviewProgramFilter = { id: number; label: string }
const rolesPrograms = ref<PreviewProgramFilter[]>([])
const teamsPrograms = ref<PreviewProgramFilter[]>([])
/** Program id → visible (default true). */
const rolesProgramOn = ref<Record<number, boolean>>({})
const teamsProgramOn = ref<Record<number, boolean>>({})

const rolesHiddenProgramIds = computed(() =>
  rolesPrograms.value
    .filter((p) => rolesProgramOn.value[p.id] === false)
    .map((p) => p.id)
)

const teamsHiddenProgramIds = computed(() =>
  teamsPrograms.value
    .filter((p) => teamsProgramOn.value[p.id] === false)
    .map((p) => p.id)
)

function mapPreviewPrograms(raw: unknown): PreviewProgramFilter[] {
  if (!Array.isArray(raw)) return []
  return raw.map((p: { id: number; label: string }) => ({
    id: Number(p.id),
    label: String(p.label ?? ''),
  }))
}

function syncProgramFilters(
  targetPrograms: typeof rolesPrograms,
  targetOn: typeof rolesProgramOn,
  programs: PreviewProgramFilter[]
) {
  targetPrograms.value = programs
  const next: Record<number, boolean> = {}
  for (const p of programs) {
    next[p.id] = targetOn.value[p.id] !== false
  }
  targetOn.value = next
}

function toggleRolesProgram(programId: number) {
  rolesProgramOn.value = {
    ...rolesProgramOn.value,
    [programId]: rolesProgramOn.value[programId] === false,
  }
}

function toggleTeamsProgram(programId: number) {
  teamsProgramOn.value = {
    ...teamsProgramOn.value,
    [programId]: teamsProgramOn.value[programId] === false,
  }
}

function clearGridHtml() {
  rolesHtml.value = ''
  teamsHtml.value = ''
}

function programThemeKey(programId: number): 'challenge' | 'future8' {
  return programId === FIRST_PROGRAM.FUTURE_8 ? 'future8' : 'challenge'
}

function themeForProgram(programId: number) {
  return getProgramTheme(programThemeKey(programId))
}

async function loadMatchPlanMeta() {
  if (!effectivePlanId.value) return
  try {
    const { data } = await axios.get(`/plans/preview/${effectivePlanId.value}/robot-game`)
    const programs = Array.isArray(data?.programs) ? data.programs.map((id: number) => Number(id)) : []
    matchPlanPrograms.value = programs
    if (programs.length === 1) {
      selectedFirstProgram.value = programs[0]
    } else if (programs.length > 1) {
      const lead = data?.first_program != null ? Number(data.first_program) : programs[0]
      if (selectedFirstProgram.value == null || !programs.includes(selectedFirstProgram.value)) {
        selectedFirstProgram.value = lead
      }
    } else {
      selectedFirstProgram.value = null
    }
  } catch (e) {
    console.error('[Preview] Failed to load match-plan programs:', e)
    matchPlanPrograms.value = []
    selectedFirstProgram.value = null
  }
}

async function load() {
  if (!effectivePlanId.value) return
  loading.value = true
  error.value = null

  try {
    if (view.value === 'overview') {
      const { data } = await axios.get(`/plans/preview/${effectivePlanId.value}/overview`)
      overviewHtml.value = data.html
      clearGridHtml()
      activities.value = []
      robotGameData.value = null
    } else if (view.value === 'roles') {
      const { data } = await axios.get(`/plans/preview/${effectivePlanId.value}/roles-grid`)
      rolesHtml.value = data.html ?? ''
      teamsHtml.value = ''
      syncProgramFilters(rolesPrograms, rolesProgramOn, mapPreviewPrograms(data?.programs))
      overviewHtml.value = ''
      activities.value = []
      robotGameData.value = null
    } else if (view.value === 'teams') {
      const { data } = await axios.get(`/plans/preview/${effectivePlanId.value}/teams-grid`)
      teamsHtml.value = data.html ?? ''
      rolesHtml.value = ''
      syncProgramFilters(teamsPrograms, teamsProgramOn, mapPreviewPrograms(data?.programs))
      overviewHtml.value = ''
      activities.value = []
      robotGameData.value = null
    } else if (view.value === 'robot-game') {
      const params: Record<string, number> = {}
      if (selectedFirstProgram.value != null) {
        params.first_program = selectedFirstProgram.value
      }
      const { data } = await axios.get(`/plans/preview/${effectivePlanId.value}/robot-game`, { params })
      robotGameData.value = data
      if (Array.isArray(data?.programs)) {
        matchPlanPrograms.value = data.programs.map((id: number) => Number(id))
      }
      if (data?.first_program != null) {
        selectedFirstProgram.value = Number(data.first_program)
      }
      activities.value = []
      overviewHtml.value = ''
      clearGridHtml()
    } else if (view.value === 'quality') {
      activities.value = []
      overviewHtml.value = ''
      clearGridHtml()
    } else if (view.value === 'activities') {
      const { data } = await axios.get(`/plans/preview/${effectivePlanId.value}/activities`)
      activities.value = Array.isArray(data?.groups) ? data.groups : []
      robotGameData.value = null
      overviewHtml.value = ''
      clearGridHtml()
    }
  } catch (e: any) {
    console.error('[Preview] load() error:', e)
    error.value = e?.message || 'Fehler beim Laden'
    activities.value = []
    robotGameData.value = null
    overviewHtml.value = ''
    clearGridHtml()
  } finally {
    loading.value = false
  }
}

watch(() => effectivePlanId.value, async () => {
  await loadMatchPlanMeta()
  load()
})
watch(view, () => load())
watch(() => props.reload, async () => {
  await loadMatchPlanMeta()
  load()
})
watch(selectedFirstProgram, (program, prev) => {
  if (program === prev) return
  if (view.value === 'robot-game' || view.value === 'quality') {
    load()
  }
})
watch(showAdminInline, (visible) => {
  if (!visible && (view.value === 'activities' || view.value === 'quality')) {
    setView('overview')
  }
})

onMounted(async () => {
  await loadMatchPlanMeta()
  load()
})

function setView(v: 'overview' | 'roles' | 'teams' | 'robot-game' | 'quality' | 'activities') {
  if (view.value !== v) view.value = v
}

function openMatchPlan(programId?: number) {
  if (programId != null) {
    selectedFirstProgram.value = programId
  } else if (matchPlanPrograms.value.length === 1) {
    selectedFirstProgram.value = matchPlanPrograms.value[0]
  }
  setView('robot-game')
}

function openQuality(programId?: number) {
  if (programId != null) {
    selectedFirstProgram.value = programId
  } else if (matchPlanPrograms.value.length === 1) {
    selectedFirstProgram.value = matchPlanPrograms.value[0]
  }
  setView('quality')
}

// Helper functions for Match-Plan view
function hasTable34(round: RobotGameRound): boolean {
  // Check if any match in this round uses table 3 or 4
  // Table 3/4 only exist when r_tables = 4
  return round.matches.some(m => m.table_1 === 3 || m.table_1 === 4 || m.table_2 === 3 || m.table_2 === 4)
}

function formatTeam(teamNum: number | null): string {
  // Format team display
  // Empty: no team (shouldn't happen in this context, but handle it)
  // '–': Team 0 (volunteer/BYE)
  // Number: Regular team
  if (teamNum === null) return ''
  if (teamNum === 0) return '–'
  return String(teamNum)
}

function matchPlanProgramLabel(programId: number): string {
  return `FIRST LEGO League ${themeForProgram(programId).shortName}`
}

function selectMatchPlanProgram(programId: number) {
  if (selectedFirstProgram.value === programId) return
  selectedFirstProgram.value = programId
}

// Check if any activity group has explore_group filled
const hasExploreGroups = computed(() => {
  return activities.value.some(group => group.explore_group !== null && group.explore_group !== undefined)
})

// Format explore group display
function formatExploreGroup(exploreGroup: number | null | undefined): string {
  if (exploreGroup === null || exploreGroup === undefined) return ''
  if (exploreGroup === 1) return 'Gruppe 1'
  if (exploreGroup === 2) return 'Gruppe 2'
  return ''
}
</script>

<template>
  <div class="flex flex-col gap-3 h-full min-h-0">
    <div class="glass-panel-header !mb-0 shrink-0">
      <div class="flex flex-wrap items-center gap-2 min-w-0 flex-1">
        <div class="glass-segment">
          <button
            type="button"
            class="glass-segment__btn"
            :class="{'glass-segment__btn--active': view === 'overview'}"
            @click="setView('overview')"
          >Überblick</button>
          <button
            type="button"
            class="glass-segment__btn"
            :class="{'glass-segment__btn--active': view === 'roles'}"
            @click="setView('roles')"
          >Rollen</button>
          <button
            type="button"
            class="glass-segment__btn"
            :class="{'glass-segment__btn--active': view === 'teams'}"
            @click="setView('teams')"
          >Teams</button>
          <button
            v-if="hasMatchPlan"
            type="button"
            class="glass-segment__btn"
            :class="{'glass-segment__btn--active': view === 'robot-game'}"
            @click="openMatchPlan()"
          >Match-Plan</button>
        </div>

        <div v-if="showAdminInline" class="glass-segment">
          <span
            class="glass-segment__btn pointer-events-none opacity-80"
            title="Admin"
            aria-hidden="true"
          >
            <i class="bi bi-shield-lock" aria-hidden="true"/>
          </span>
        </div>

        <div v-if="showAdminInline" class="glass-segment">
          <button
            type="button"
            class="glass-segment__btn"
            :class="{'glass-segment__btn--active': view === 'activities'}"
            @click="setView('activities')"
          >Aktivitäten</button>
          <button
            v-if="hasMatchPlan"
            type="button"
            class="glass-segment__btn"
            :class="{'glass-segment__btn--active': view === 'quality'}"
            @click="openQuality()"
          >Plan-Qualität</button>
          <button
            type="button"
            class="glass-segment__btn"
            :disabled="!canRegeneratePlan"
            title="Plan sofort neu generieren (ohne ausstehende Parameter-Änderungen)"
            @click="onRegeneratePlan"
          >Neu generieren</button>
        </div>
      </div>

      <p v-if="!hideMeta" class="glass-settings-hint !not-italic shrink-0 m-0">
        Plan ID: {{ effectivePlanId }}
      </p>
    </div>

    <p
      v-if="view === 'roles'"
      class="glass-settings-hint !mt-0 !mb-0"
    >
      Freie Blöcke werden hier nicht angezeigt, weil sie vom generierten Ablauf unabhängig sind.
    </p>
    <p
      v-else-if="view === 'teams'"
      class="glass-settings-hint !mt-0 !mb-0"
    >
      Zusätzliche Blöcke werden hier nicht angezeigt, weil sie für alle Teams gleich sind.
    </p>

    <!-- Program filters (glass-choice), above content -->
    <div
      v-if="view === 'roles' && !loading && rolesHtml && rolesPrograms.length > 1"
      class="glass-settings-row shrink-0"
    >
      <button
        v-for="p in rolesPrograms"
        :key="p.id"
        type="button"
        class="glass-choice preview-program-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
        :class="{ 'glass-choice--active': rolesProgramOn[p.id] !== false }"
        :aria-pressed="rolesProgramOn[p.id] !== false"
        @click="toggleRolesProgram(p.id)"
      >
        <ProgramLogo :program="p.id" size="sm" decorative class="preview-program-choice__logo" />
        <span>{{ p.label }}</span>
      </button>
    </div>

    <div
      v-else-if="view === 'teams' && !loading && teamsHtml && teamsPrograms.length > 1"
      class="glass-settings-row shrink-0"
    >
      <button
        v-for="p in teamsPrograms"
        :key="p.id"
        type="button"
        class="glass-choice preview-program-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
        :class="{ 'glass-choice--active': teamsProgramOn[p.id] !== false }"
        :aria-pressed="teamsProgramOn[p.id] !== false"
        @click="toggleTeamsProgram(p.id)"
      >
        <ProgramLogo :program="p.id" size="sm" decorative class="preview-program-choice__logo" />
        <span>{{ p.label }}</span>
      </button>
    </div>

    <div
      v-else-if="(view === 'robot-game' || view === 'quality') && dualMatchPlan"
      class="glass-settings-row shrink-0"
    >
      <button
        v-for="programId in matchPlanPrograms"
        :key="`program-filter-${programId}`"
        type="button"
        class="glass-choice preview-program-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
        :class="{ 'glass-choice--active': selectedFirstProgram === programId }"
        :aria-pressed="selectedFirstProgram === programId"
        @click="selectMatchPlanProgram(programId)"
      >
        <ProgramLogo
          v-if="themeForProgram(programId).catalogName"
          :program="programId"
          size="sm"
          decorative
          class="preview-program-choice__logo"
        />
        <span>{{ matchPlanProgramLabel(programId) }}</span>
      </button>
    </div>

    <div v-if="error" class="glass-chip liquid-surface-inner !px-3 !py-2 text-sm text-red-700 shrink-0">
      {{ error }}
    </div>

    <!-- ANSICHT: Rollen (new grid) -->
    <div v-if="view === 'roles'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-[var(--color-border)] bg-white p-4">
      <div v-if="loading" class="px-3 py-8 text-left text-[var(--color-text-subtle)]">Wird geladen …</div>
      <template v-else>
        <div v-if="!rolesHtml" class="px-3 py-6 text-center text-[var(--color-text-subtle)]">
          Keine Rollen-Daten gefunden.
        </div>
        <div
          v-else
          class="roles-grid-host min-h-0"
          :data-hide-programs="rolesHiddenProgramIds.join(' ')"
          v-html="rolesHtml"
        ></div>
      </template>
    </div>

    <!-- ANSICHT: Teams (new grid) -->
    <div v-else-if="view === 'teams'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-[var(--color-border)] bg-white p-4">
      <div v-if="loading" class="px-3 py-8 text-left text-[var(--color-text-subtle)]">Wird geladen …</div>
      <template v-else>
        <div v-if="!teamsHtml" class="px-3 py-6 text-center text-[var(--color-text-subtle)]">
          Keine Team-Daten gefunden.
        </div>
        <div
          v-else
          class="roles-grid-host min-h-0"
          :data-hide-programs="teamsHiddenProgramIds.join(' ')"
          v-html="teamsHtml"
        ></div>
      </template>
    </div>

    <!-- ANSICHT: Überblick -->
    <div v-else-if="view === 'overview'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-[var(--color-border)] bg-white p-4">
      <div v-if="loading" class="px-3 py-8 text-left text-[var(--color-text-subtle)]">Wird geladen …</div>
      
      <template v-else>
        <div v-if="!overviewHtml" class="px-3 py-6 text-center text-[var(--color-text-subtle)]">
          Keine Übersichtsdaten gefunden.
        </div>
        
        <div v-else v-html="overviewHtml" class="event-overview-container"></div>
      </template>
    </div>

    <!-- ANSICHT: Match-Plan -->
    <div v-else-if="view === 'robot-game'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-[var(--color-border)] bg-white p-4">
      <div v-if="loading" class="px-3 py-8 text-left text-[var(--color-text-subtle)]">Wird geladen …</div>

      <template v-else>
        <div v-if="!robotGameData || !robotGameData.rounds || robotGameData.rounds.length === 0" class="px-3 py-6 text-center text-[var(--color-text-subtle)]">
          Keine Match-Plan Daten gefunden.
        </div>

        <div v-else class="flex flex-col gap-6">
          <!-- Match plan by rounds -->
          <div class="flex flex-row gap-4">
            <div
              v-for="round in robotGameData.rounds"
              :key="round.round"
              class="min-w-max"
            >
              <div class="text-sm font-semibold text-[var(--color-text-muted)] mb-2">
                {{ round.name }}
              </div>
              <table class="table-auto text-sm border-collapse border border-[var(--color-border)]">
                <thead class="bg-[var(--color-bg-muted)]">
                  <tr>
                    <th class="px-2 py-1 border border-[var(--color-border)] text-center font-normal">Tisch 1</th>
                    <th class="px-2 py-1 border border-[var(--color-border)] text-center font-normal">Tisch 2</th>
                    <th v-if="hasTable34(round)" class="px-2 py-1 border border-[var(--color-border)] text-center font-normal">Tisch 3</th>
                    <th v-if="hasTable34(round)" class="px-2 py-1 border border-[var(--color-border)] text-center font-normal">Tisch 4</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="match in round.matches"
                    :key="match.match_id"
                    class="border-t"
                  >
                    <td class="text-center px-2 py-1">
                      <span v-if="match.table_1 === 1">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 1">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                    <td class="text-center px-2 py-1">
                      <span v-if="match.table_1 === 2">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 2">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                    <td v-if="hasTable34(round)" class="text-center px-2 py-1">
                      <span v-if="match.table_1 === 3">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 3">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                    <td v-if="hasTable34(round)" class="text-center px-2 py-1">
                      <span v-if="match.table_1 === 4">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 4">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Team summary table -->
          <div v-if="robotGameData.team_summary && robotGameData.team_summary.length > 0" class="mt-4">
            <div class="text-sm font-semibold text-[var(--color-text-muted)] mb-2">Übersicht über die Verteilung</div>
            <table class="table-auto text-sm border-collapse border border-[var(--color-border)]">
              <thead class="bg-[var(--color-bg-muted)]">
                <tr>
                  <th class="px-3 py-2 border border-[var(--color-border)] text-left font-normal">Team</th>
                  <th class="px-3 py-2 border border-[var(--color-border)] text-center font-normal">Verschiedene Tische</th>
                  <th class="px-3 py-2 border border-[var(--color-border)] text-center font-normal">Verschiedene Teams</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="summary in robotGameData.team_summary"
                  :key="summary.team"
                  class="border-t"
                >
                  <td class="px-3 py-2 border border-[var(--color-border)]">{{ summary.team }}</td>
                  <td class="px-3 py-2 border border-[var(--color-border)] text-center">{{ summary.different_tables }}</td>
                  <td class="px-3 py-2 border border-[var(--color-border)] text-center">{{ summary.different_opponents }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    <!-- ANSICHT: Plan-Qualität (QPlanDetails) -->
    <div v-else-if="view === 'quality'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-[var(--color-border)] bg-white p-4">
      <QPlanDetails
        v-if="effectivePlanId"
        :plan-id="Number(effectivePlanId)"
        :first-program="selectedFirstProgram ?? undefined"
      />
    </div>

    <!-- ANSICHT: Power-User „Aktivitäten" -->
    <div v-else-if="view === 'activities'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-[var(--color-border)] bg-white p-3">
      <div v-if="loading" class="px-3 py-8 text-left text-[var(--color-text-subtle)]">Wird geladen …</div>

      <template v-else>
        <div v-if="activities.length === 0" class="px-3 py-6 text-center text-[var(--color-text-subtle)]">
          Keine Aktivitäten gefunden.
        </div>

        <div v-for="group in activities" :key="String(group.activity_group_id)" class="mb-6">
          <div class="font-semibold text-sm mb-2">
            Activity Group ID: {{ group.activity_group_id ?? '–' }} - {{ group.activity_group_name ?? 'Unknown Group' }}
          </div>

          <div class="overflow-x-auto border rounded">
            <table class="min-w-full text-xs">
              <thead class="bg-[var(--color-bg-muted)]">
                <tr>
                  <th class="px-2 py-1 text-left">Activity ID</th>
                  <th class="px-2 py-1 text-left">Start</th>
                  <th class="px-2 py-1 text-left">Ende</th>
                  <th class="px-2 py-1 text-left">FIRST Program</th>
                  <th class="px-2 py-1 text-left">Activity Name</th>
                  <th class="px-2 py-1 text-left">Lane</th>
                  <th class="px-2 py-1 text-left">Team</th>
                  <th class="px-2 py-1 text-left">Table 1 Team</th>
                  <th class="px-2 py-1 text-left">Table 2 Team</th>
                  <th class="px-2 py-1 text-left">Table 1</th>
                  <th class="px-2 py-1 text-left">Table 2</th>
                  <th class="px-2 py-1 text-left">Slot Team</th>
                  <th class="px-2 py-1 text-left">Room Type</th>
                  <th v-if="hasExploreGroups" class="px-2 py-1 text-left">Gruppe</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in group.activities" :key="a.activity_id" class="border-t">
                  <td class="px-2 py-1 text-[var(--color-text-subtle)]">{{ a.activity_id }}</td>
                  <td class="px-2 py-1">{{ formatDateTime(a.start_time, true) }}</td>
                  <td class="px-2 py-1">{{ formatTimeOnly(a.end_time, true) }}</td>
                  <td class="px-2 py-1">{{ a.program || '' }}</td>
                  <td class="px-2 py-1">{{ a.activity_name }}</td>
                  <td class="px-2 py-1">{{ a.lane ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.team ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.table_1_team ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.table_2_team ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.table_1 ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.table_2 ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.slot_team ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.room_type_name || '' }}</td>
                  <td v-if="hasExploreGroups" class="px-2 py-1">{{ formatExploreGroup(group.explore_group) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
/* alle Spalten gleich breit */
table {
  table-layout: fixed;
}

/* kein fett im Header */
th {
  font-weight: 400;
}

/* Inhalte dürfen Zeilenumbrüche enthalten */
td {
  white-space: pre-line;
}

/* Zeitspalte genau wie Zellen (kein Bold) */

/* Event overview container */
.event-overview-container {
  width: 100%;
  overflow-x: auto;
}

.event-overview-container .event-overview {
  min-width: 100%;
}

.event-overview-container .day-header {
  margin-bottom: 15px;
}

.event-overview-container .overview-table {
  font-size: 11px;
}

.event-overview-container .overview-table th,
.event-overview-container .overview-table td {
  padding: 6px 4px;
  height: 24px; /* Consistent row height for HTML preview */
  vertical-align: top; /* Align content to top of cell */
}

.event-overview-container .overview-table td {
  overflow: hidden; /* Hide overflow text to maintain consistent height */
  text-overflow: ellipsis; /* Show ellipsis for truncated text */
  line-height: 1.1; /* Minimize vertical gap between activity name and time */
}

.event-overview-container .header-logo {
  height: 18px;
}

/* Hide program columns when filter toggles are off */
.roles-grid-host[data-hide-programs~='2'] :deep([data-program-id='2']),
.roles-grid-host[data-hide-programs~='3'] :deep([data-program-id='3']),
.roles-grid-host[data-hide-programs~='8'] :deep([data-program-id='8']) {
  display: none !important;
}

.preview-program-choice {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  cursor: pointer;
}

.preview-program-choice__logo {
  width: 1.25rem;
  height: 1.25rem;
  object-fit: contain;
  flex-shrink: 0;
}
</style>
<script setup lang="ts">
import {computed, onActivated, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {
  drahtIdFor,
  eventPrograms,
  programMatchesSlug,
  programSlug,
  type EventProgramRef,
} from '@/utils/eventPrograms'
import {formatPlanTeamNo} from '@/utils/planTeamLabel'
import {formatJuryCell} from '@/utils/teamJury'
import {
  describeCoachConflict,
  findCoachConflict,
  type CoachConflict,
  type ProgramSlotSchedule,
  type TeamPlacement,
} from '@/utils/coachConflicts'

type TeamRef = {
  key: string
  name: string
  number: string
  program: EventProgramRef
}

type CoachEntry = {
  name: string
  teams: TeamRef[]
}

type PlanTeam = {
  team_number_hot?: number | string | null
  team_number_plan?: number | null
  jury_lane?: number | null
}

type PlanSlot = {plan: number | null, lane: number | null}

const props = defineProps<{
  /** Reihenfolge: only coaches with a team in this program, each team with its plan slot. */
  program?: string
  /** Current order of that program's teams while sorting. */
  liveTeams?: PlanTeam[]
}>()

const emit = defineEmits<{
  /** Focus-program teams whose coach has a clash, by team number. */
  conflicts: [byTeamNumber: Record<string, {status: 'red' | 'yellow'; title: string}>]
}>()

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const programs = computed(() => eventPrograms(event.value))

const loading = ref(false)
const entries = ref<CoachEntry[]>([])
const savedSlots = ref<Record<string, Record<string, PlanSlot>>>({})
const schedules = ref(new Map<number, ProgramSlotSchedule>())

const focusMode = computed(() => !!props.program)

function isFocusProgram(prog: EventProgramRef): boolean {
  return !!props.program && programMatchesSlug(prog.name, props.program)
}

function slotMap(teams: PlanTeam[], byIndex: boolean): Record<string, PlanSlot> {
  const map: Record<string, PlanSlot> = {}
  teams.forEach((team, index) => {
    const number = team.team_number_hot == null ? '' : String(Number(team.team_number_hot))
    if (!number) return
    map[number] = {
      plan: byIndex ? index + 1 : (team.team_number_plan ?? null),
      lane: team.jury_lane ?? null,
    }
  })
  return map
}

const liveSlots = computed(() => slotMap(props.liveTeams ?? [], true))

function slotFor(team: TeamRef): PlanSlot | null {
  const number = String(Number(team.number))
  if (isFocusProgram(team.program) && props.liveTeams?.length) {
    return liveSlots.value[number] ?? null
  }
  return savedSlots.value[programSlug(team.program.name)]?.[number] ?? null
}

function slotLabel(team: TeamRef): string {
  const slot = slotFor(team)
  if (!slot?.plan) return '–'
  const lane = formatJuryCell(String(team.program.name ?? ''), slot.lane)
  return lane === '–' ? formatPlanTeamNo(slot.plan) : `${formatPlanTeamNo(slot.plan)} · ${lane}`
}

function placement(team: TeamRef): TeamPlacement {
  return {programId: Number(team.program.first_program ?? 0), slot: slotFor(team)?.plan ?? null, name: team.name}
}

/** Clashes between any two teams of a coach, worst first. */
function conflictsFor(entry: CoachEntry): CoachConflict[] {
  const found: CoachConflict[] = []
  for (let i = 0; i < entry.teams.length; i++) {
    for (let j = i + 1; j < entry.teams.length; j++) {
      const conflict = findCoachConflict(placement(entry.teams[i]), placement(entry.teams[j]), schedules.value)
      if (conflict) found.push(conflict)
    }
  }
  return found.sort((a, b) => (a.status === b.status ? a.gapMinutes - b.gapMinutes : a.status === 'red' ? -1 : 1))
}

const statusRank = {red: 0, yellow: 1, none: 2}

const visibleEntries = computed(() => {
  if (!focusMode.value) return []
  return entries.value
    .filter((entry) => entry.teams.some((team) => isFocusProgram(team.program)))
    .map((entry) => ({...entry, conflicts: conflictsFor(entry)}))
    .sort((a, b) => statusRank[a.conflicts[0]?.status ?? 'none'] - statusRank[b.conflicts[0]?.status ?? 'none'])
})

watch(visibleEntries, (list) => {
  if (!focusMode.value) return
  const byTeam: Record<string, {status: 'red' | 'yellow'; title: string}> = {}
  for (const entry of list) {
    const worst = entry.conflicts[0]
    if (!worst) continue
    for (const team of entry.teams) {
      if (!isFocusProgram(team.program)) continue
      const number = String(Number(team.number))
      if (byTeam[number]?.status === 'red') continue
      byTeam[number] = {status: worst.status, title: `${entry.name}: ${describeCoachConflict(worst)}`}
    }
  }
  emit('conflicts', byTeam)
})

async function loadSchedules() {
  try {
    const {data} = await axios.get(`/events/${event.value?.id}/teams/slot-schedule`)
    const list: ProgramSlotSchedule[] = Array.isArray(data?.programs) ? data.programs : []
    schedules.value = new Map(list.map((row) => [Number(row.first_program), row]))
  } catch {
    schedules.value = new Map()
  }
}

async function loadSavedSlots() {
  if (!event.value?.id || !focusMode.value) {
    savedSlots.value = {}
    schedules.value = new Map()
    return
  }
  void loadSchedules()
  const next: Record<string, Record<string, PlanSlot>> = {}
  await Promise.all(programs.value.map(async (prog) => {
    const slug = programSlug(prog.name)
    try {
      const {data} = await axios.get(`/events/${event.value?.id}/teams`, {
        params: {program: slug, sort: 'plan_order'},
      })
      next[slug] = slotMap(Array.isArray(data) ? data : (data?.teams ?? []), false)
    } catch {
      next[slug] = {}
    }
  }))
  savedSlots.value = next
}

function coachIdentity(coach: unknown): {key: string; name: string} | null {
  if (typeof coach === 'string') {
    const name = coach.trim()
    if (!name) return null
    return {key: `name:${name.toLowerCase()}`, name}
  }

  if (!coach || typeof coach !== 'object') return null

  const row = coach as {firstname?: string; name?: string; email?: string}
  const email = String(row.email || '').trim().toLowerCase()
  const name = [row.firstname, row.name].filter(Boolean).join(' ').trim()
  const display = name || email

  if (!display) return null
  if (email) return {key: `email:${email}`, name: display}

  return {key: `name:${name.toLowerCase()}`, name: display}
}

function teamRef(prog: EventProgramRef, teamData: Record<string, unknown>, teamKey: string): TeamRef {
  const name = teamLabel(teamData, teamKey)
  return {
    key: `${programSlug(prog.name)}:${name.toLowerCase()}`,
    name,
    number: String(teamKey).trim(),
    program: prog,
  }
}

function programSequence(prog: EventProgramRef): number {
  return prog.sequence ?? Number.POSITIVE_INFINITY
}

function sortTeams(a: TeamRef, b: TeamRef): number {
  const seq = programSequence(a.program) - programSequence(b.program)
  if (seq !== 0) return seq
  return a.name.localeCompare(b.name, 'de')
}

function teamLabel(teamData: Record<string, unknown>, teamKey: string): string {
  const fromData = String(teamData.name || '').trim()
  if (fromData) return fromData
  return String(teamKey).trim() || 'Unbekanntes Team'
}

async function loadMultiTeamCoaches() {
  if (!event.value?.id) {
    entries.value = []
    return
  }

  loading.value = true
  try {
    const byCoach = new Map<string, {name: string; teams: Map<string, TeamRef>}>()

    for (const prog of programs.value) {
      const drahtId = drahtIdFor(event.value, programSlug(prog.name))
      if (!drahtId) continue

      try {
        const {data} = await axios.get(`/draht/people/${drahtId}`)
        const {total_players, total_coaches, ...teams} = data ?? {}

        for (const [teamKey, rawTeam] of Object.entries(teams)) {
          if (!rawTeam || typeof rawTeam !== 'object') continue
          const teamData = rawTeam as Record<string, unknown>
          const ref = teamRef(prog, teamData, teamKey)

          for (const coach of (teamData.coaches as unknown[]) ?? []) {
            const identity = coachIdentity(coach)
            if (!identity) continue

            const existing = byCoach.get(identity.key)
            if (existing) {
              existing.teams.set(ref.key, ref)
            } else {
              byCoach.set(identity.key, {
                name: identity.name,
                teams: new Map([[ref.key, ref]]),
              })
            }
          }
        }
      } catch {
        // skip programme on failure
      }
    }

    entries.value = [...byCoach.values()]
      .filter((row) => row.teams.size > 1)
      .map((row) => ({
        name: row.name,
        teams: [...row.teams.values()].sort(sortTeams),
      }))
      .sort((a, b) => a.name.localeCompare(b.name, 'de'))
  } finally {
    loading.value = false
  }
}

const showTile = computed(() => !loading.value && (focusMode.value || entries.value.length > 0))

let activatedBefore = false

onMounted(() => {
  void loadMultiTeamCoaches()
  void loadSavedSlots()
})

// Kept alive per program: another program's order may have changed meanwhile.
onActivated(() => {
  if (activatedBefore) void loadSavedSlots()
  activatedBefore = true
})

watch(() => event.value?.id, () => {
  void loadMultiTeamCoaches()
  void loadSavedSlots()
})

// One Reihenfolge page for all programs: the previous program's order may have changed.
watch(() => props.program, (next, prev) => {
  if (next && prev && next !== prev) void loadSavedSlots()
})
</script>

<template>
  <div
      v-if="showTile"
      class="glass-card liquid-surface-inner vol-sidebar-tile teams-multi-team-coaches"
  >
    <h2 class="vol-sidebar-heading">Coach:innen mit mehr als einem Team</h2>

    <p v-if="focusMode && !visibleEntries.length" class="vol-sidebar-muted">
      Kein Team in diesem Programm teilt sich Coach:innen mit einem anderen Team.
    </p>

    <ul v-else-if="focusMode" class="teams-multi-team-coaches__list">
      <li
          v-for="entry in visibleEntries"
          :key="entry.name"
          class="teams-multi-team-coaches__item"
      >
        <span class="teams-multi-team-coaches__coach">
          <span
              v-if="entry.conflicts.length"
              class="teams-multi-team-coaches__status"
              :class="`teams-multi-team-coaches__status--${entry.conflicts[0].status}`"
              aria-hidden="true"
          />
          {{ entry.name }}
        </span>
        <p
            v-for="(conflict, index) in entry.conflicts"
            :key="index"
            class="teams-multi-team-coaches__conflict"
            :class="`teams-multi-team-coaches__conflict--${conflict.status}`"
        >
          {{ describeCoachConflict(conflict) }}
        </p>
        <ul class="teams-multi-team-coaches__slots">
          <li
              v-for="team in entry.teams"
              :key="team.key"
              class="teams-multi-team-coaches__slot"
              :class="{'teams-multi-team-coaches__slot--focus': isFocusProgram(team.program)}"
          >
            <ProgramLogo :program="team.program" size="chip" decorative/>
            <span class="teams-multi-team-coaches__slot-no">{{ slotLabel(team) }}</span>
            <span class="teams-multi-team-coaches__slot-name">{{ team.name }}</span>
          </li>
        </ul>
      </li>
    </ul>

    <div v-else class="teams-multi-team-coaches__scroll">
      <ul class="teams-multi-team-coaches__list">
        <li
            v-for="entry in entries"
            :key="entry.name"
            class="teams-multi-team-coaches__item"
        >
          <span class="teams-multi-team-coaches__coach">{{ entry.name }}</span>:
          <span class="teams-multi-team-coaches__teams">
            <span
                v-for="(team, index) in entry.teams"
                :key="team.key"
                class="teams-multi-team-coaches__team"
            >
              <ProgramLogo :program="team.program" size="chip" decorative/>
              <span>{{ team.name }}</span><span v-if="index < entry.teams.length - 1">, </span>
            </span>
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.teams-multi-team-coaches__list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.teams-multi-team-coaches__item {
  font-size: 0.8125rem;
  line-height: 1.45;
  color: var(--color-text);
}

.teams-multi-team-coaches__item + .teams-multi-team-coaches__item {
  margin-top: 0.35rem;
}

.teams-multi-team-coaches__coach {
  font-weight: 600;
}

.teams-multi-team-coaches__teams {
  display: inline;
}

.teams-multi-team-coaches__team {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  vertical-align: middle;
}

.teams-multi-team-coaches__status {
  display: inline-block;
  width: 0.5rem;
  height: 0.5rem;
  margin-right: 0.3rem;
  border-radius: 999px;
  vertical-align: middle;
}

.teams-multi-team-coaches__status--red {
  background: #ef4444;
}

.teams-multi-team-coaches__status--yellow {
  background: #eab308;
}

.teams-multi-team-coaches__conflict {
  margin: 0.15rem 0 0;
  font-size: 0.75rem;
  line-height: 1.35;
}

.teams-multi-team-coaches__conflict--red {
  color: #b91c1c;
}

.teams-multi-team-coaches__conflict--yellow {
  color: #a16207;
}

.teams-multi-team-coaches__slots {
  margin: 0.15rem 0 0;
  padding: 0;
  list-style: none;
}

.teams-multi-team-coaches__slot {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
  color: var(--color-text-muted);
}

.teams-multi-team-coaches__slot--focus {
  color: var(--color-text);
}

.teams-multi-team-coaches__slot-no {
  flex: 0 0 auto;
  min-width: 4.25rem;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

.teams-multi-team-coaches__slot-name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>

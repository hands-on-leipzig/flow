<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {drahtIdFor, eventPrograms, programSlug, type EventProgramRef} from '@/utils/eventPrograms'

type TeamRef = {
  key: string
  name: string
  program: EventProgramRef
}

type CoachEntry = {
  name: string
  teams: TeamRef[]
}

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const programs = computed(() => eventPrograms(event.value))

const loading = ref(false)
const entries = ref<CoachEntry[]>([])

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

const showTile = computed(() => !loading.value && entries.value.length > 0)

onMounted(() => {
  void loadMultiTeamCoaches()
})

watch(() => event.value?.id, () => {
  void loadMultiTeamCoaches()
})
</script>

<template>
  <div v-if="showTile" class="teams-multi-team-coaches">
    <h3 class="teams-multi-team-coaches__title">
      Coach mit mehr als einem Team
    </h3>

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
</template>

<style scoped>
.teams-multi-team-coaches {
  border-radius: var(--radius-lg);
  border: 1px solid var(--liquid-border);
  background: var(--liquid-tile-bg);
  padding: 0.85rem 1rem;
  box-shadow:
    0 6px 14px rgba(15, 23, 42, 0.06),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.teams-multi-team-coaches__title {
  margin: 0 0 0.5rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

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
</style>

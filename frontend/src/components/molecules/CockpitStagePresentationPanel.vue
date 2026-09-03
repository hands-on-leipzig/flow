<script setup lang="ts">
import {onMounted, onUnmounted, ref} from 'vue'
import type {AxiosInstance} from 'axios'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import {programLogoAlt, programLogoSrc} from '@/utils/images'

defineOptions({name: 'CockpitStagePresentationPanel'})

type TeamOption = { id: number, name: string }
type Slot = { slot: number, team: number | null, team_name: string | null }
type ProgramSection = {
  program: string
  program_label: string
  logo_stem: string | null
  presentations: number
  locked: boolean
  teams: TeamOption[]
  slots: Slot[]
}

const REFRESH_MS = 20_000

const props = defineProps<{
  slug: string
  http: AxiosInstance
}>()

const programs = ref<ProgramSection[]>([])
const hasPlan = ref(true)
const loading = ref(false)
const busy = ref('')
const error = ref('')
const unlockTarget = ref<ProgramSection | null>(null)

/**
 * Options for one slot: everything still available, minus the teams picked in
 * the other slots. A team that was picked and later marked no-show is no
 * longer offered, so it is added back for its own slot to stay visible.
 */
function optionsFor(section: ProgramSection, slot: Slot): TeamOption[] {
  const takenElsewhere = new Set(
    section.slots.filter((s) => s.slot !== slot.slot && s.team !== null).map((s) => s.team as number),
  )
  const options = section.teams.filter((team) => !takenElsewhere.has(team.id))

  if (slot.team !== null && !options.some((team) => team.id === slot.team)) {
    options.unshift({id: slot.team, name: slot.team_name || `Team ${slot.team}`})
  }

  return options
}

async function loadState(silent = false) {
  if (!silent) {
    loading.value = true
    error.value = ''
  }
  try {
    const {data} = await props.http.get(`/cockpit/${props.slug}/stage-presentations/bootstrap`)
    apply(data)
  } catch (e: any) {
    if (!silent) error.value = e?.response?.data?.error || 'Auswahl konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

function apply(data: any) {
  hasPlan.value = data?.has_plan !== false
  programs.value = Array.isArray(data?.programs) ? data.programs : []
}

/** Persist on every change so Moderator and Stage Crew see entries at once. */
async function saveSelection(section: ProgramSection) {
  busy.value = section.program
  error.value = ''
  try {
    const {data} = await props.http.put(`/cockpit/${props.slug}/stage-presentations/selection`, {
      program: section.program,
      teams: section.slots.map((slot) => slot.team),
    })
    apply(data)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Speichern fehlgeschlagen.'
    await loadState(true)
  } finally {
    busy.value = ''
  }
}

async function setLock(section: ProgramSection, locked: boolean) {
  unlockTarget.value = null
  busy.value = section.program
  error.value = ''
  try {
    const {data} = await props.http.put(`/cockpit/${props.slug}/stage-presentations/lock`, {
      program: section.program,
      locked,
    })
    apply(data)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Sperren fehlgeschlagen.'
  } finally {
    busy.value = ''
  }
}

function onSlotChange(section: ProgramSection, slot: Slot, value: string) {
  slot.team = value === '' ? null : Number(value)
  slot.team_name = section.teams.find((team) => team.id === slot.team)?.name ?? null
  saveSelection(section)
}

let refreshTimer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  loadState()
  refreshTimer = setInterval(() => {
    if (!busy.value && !unlockTarget.value) loadState(true)
  }, REFRESH_MS)
})

onUnmounted(() => {
  if (refreshTimer) clearInterval(refreshTimer)
})
</script>

<template>
  <div class="cp-stage">
    <p v-if="error" class="glass-alert-error !mb-0">{{ error }}</p>

    <p v-if="loading" class="cp-stage__hint">Lade…</p>
    <p v-else-if="!hasPlan" class="cp-stage__hint">Für diese Veranstaltung gibt es keinen Zeitplan.</p>
    <p v-else-if="!programs.length" class="cp-stage__hint">Keine Präsentationen geplant.</p>

    <section v-for="section in programs" :key="section.program" class="cp-stage__program">
      <header class="cp-stage__head">
        <img
            v-if="section.logo_stem"
            class="cp-stage__logo"
            :src="programLogoSrc({logo_stem: section.logo_stem})"
            :alt="programLogoAlt({logo_stem: section.logo_stem})"
        >
        <h2 class="cp-stage__title">{{ section.program_label }}</h2>
      </header>

      <ol class="cp-stage__slots">
        <li v-for="slot in section.slots" :key="slot.slot" class="cp-stage__slot liquid-surface-inner">
          <span class="cp-stage__slot-no">{{ slot.slot }}.</span>

          <select
              v-if="!section.locked"
              class="glass-input cp-stage__select"
              :value="slot.team === null ? '' : String(slot.team)"
              :disabled="busy === section.program"
              :aria-label="`Team für Präsentation ${slot.slot}`"
              @change="onSlotChange(section, slot, ($event.target as HTMLSelectElement).value)"
          >
            <option value="">— kein Team —</option>
            <option v-for="team in optionsFor(section, slot)" :key="team.id" :value="String(team.id)">
              {{ team.name }}
            </option>
          </select>

          <span v-else class="cp-stage__locked-team">{{ slot.team_name || '— kein Team —' }}</span>
        </li>
      </ol>

      <button
          v-if="!section.locked"
          type="button"
          class="glass-btn-accent cp-stage__lock"
          :disabled="busy === section.program"
          @click="setLock(section, true)"
      >
        <i class="bi bi-lock-fill" aria-hidden="true"/>
        Sperren
      </button>

      <button
          v-else
          type="button"
          class="glass-btn-secondary cp-stage__lock"
          :disabled="busy === section.program"
          @click="unlockTarget = section"
      >
        <i class="bi bi-unlock-fill" aria-hidden="true"/>
        Entsperren
      </button>
    </section>

    <ConfirmationModal
        :show="!!unlockTarget"
        type="warning"
        title="Sperre wirklich aufheben?"
        message="Die Auswahl kann dann wieder geändert werden."
        confirm-text="Ja"
        cancel-text="Nein"
        @confirm="unlockTarget && setLock(unlockTarget, false)"
        @cancel="unlockTarget = null"
    />
  </div>
</template>

<style scoped>
.cp-stage {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.cp-stage__hint {
  margin: 0;
  font-size: 0.9rem;
  color: var(--color-text-muted);
}

.cp-stage__program {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.cp-stage__head {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.cp-stage__logo {
  height: 2.25rem;
  width: auto;
  flex-shrink: 0;
}

.cp-stage__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 750;
  line-height: 1.25;
  color: var(--color-text);
}

.cp-stage__slots {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.cp-stage__slot {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.6rem 0.75rem;
  border-radius: var(--radius);
}

.cp-stage__slot-no {
  flex-shrink: 0;
  min-width: 1.25rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: var(--color-text-muted);
}

.cp-stage__select {
  flex: 1;
  min-width: 0;
  min-height: 2.75rem;
}

.cp-stage__locked-team {
  flex: 1;
  min-width: 0;
  font-weight: 600;
  color: var(--color-text);
  overflow-wrap: anywhere;
}

.cp-stage__lock {
  width: 100%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
}
</style>

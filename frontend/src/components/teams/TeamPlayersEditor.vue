<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import Spinner from '@/components/atoms/Spinner.vue'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'
import type {TeamDataRow} from '@/utils/teamDataCompletion'
import {
  formatReferenceDate,
  PLAYER_GENDER_OPTIONS,
  playerAgeOn,
  playerAgeOutOfRange,
  type PlayerAgeRule,
  type TeamPlayer,
} from '@/utils/teamPlayers'

const props = defineProps<{
  eventId?: number | null
  teamId: number
}>()

const emit = defineEmits<{
  saved: [team: TeamDataRow]
}>()

type PlayersResponse = {
  players: TeamPlayer[]
  max_players: number | null
  player_age_rule: PlayerAgeRule | null
  team?: TeamDataRow
}

const players = ref<TeamPlayer[]>([])
const rule = ref<PlayerAgeRule | null>(null)
const maxPlayers = ref<number | null>(null)
const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const dirty = ref(false)
const editingIndex = ref<number | null>(null)

const today = new Date().toISOString().slice(0, 10)

const atMax = computed(() => maxPlayers.value !== null && players.value.length >= maxPlayers.value)

const ruleHint = computed(() => {
  if (!rule.value) return ''
  return `Erlaubt sind ${rule.value.minAge} bis ${rule.value.maxAge} Jahre am ${formatReferenceDate(rule.value)}.`
})

const outOfRangeCount = computed(() =>
  players.value.filter((player) => playerAgeOutOfRange(player.birthday, rule.value) !== null).length,
)

function genderLabel(gender: string) {
  return PLAYER_GENDER_OPTIONS.find((option) => option.value === gender)?.label ?? '—'
}

function formatDate(value: string | null) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '')
  if (!match) return '—'
  return `${match[3]}.${match[2]}.${match[1]}`
}

function displayName(value: string) {
  const trimmed = value.trim()
  return trimmed === '' ? '—' : trimmed
}

function applyResponse(data: PlayersResponse) {
  players.value = (data.players ?? []).map((player) => ({
    firstname: player.firstname ?? '',
    name: player.name ?? '',
    gender: player.gender ?? '',
    birthday: player.birthday ?? null,
  }))
  rule.value = data.player_age_rule ?? null
  maxPlayers.value = data.max_players ?? null
  dirty.value = false
  editingIndex.value = null
}

async function load() {
  if (!props.eventId || !props.teamId) return
  loading.value = true
  loadError.value = ''
  players.value = []
  try {
    const {data} = await axios.get<PlayersResponse>(`/events/${props.eventId}/teams/${props.teamId}/players`)
    applyResponse(data)
  } catch (e: unknown) {
    loadError.value = apiError(e, 'Teammitglieder konnten nicht geladen werden.')
  } finally {
    loading.value = false
  }
}

function ageLabel(player: TeamPlayer): string {
  if (!rule.value) return ''
  const age = playerAgeOn(player.birthday, rule.value.referenceDate)
  return age === null ? '—' : String(age)
}

function isOutOfRange(player: TeamPlayer): boolean {
  return playerAgeOutOfRange(player.birthday, rule.value) !== null
}

function update(index: number, field: keyof TeamPlayer, value: string) {
  const next = {...players.value[index]}
  if (field === 'birthday') next.birthday = value === '' ? null : value
  else next[field] = value
  players.value[index] = next
  dirty.value = true
}

function addPlayer() {
  if (atMax.value) return
  players.value.push({firstname: '', name: '', gender: '', birthday: null})
  editingIndex.value = players.value.length - 1
  dirty.value = true
}

function removePlayer(index: number) {
  players.value.splice(index, 1)
  if (editingIndex.value === index) editingIndex.value = null
  else if (editingIndex.value !== null && editingIndex.value > index) editingIndex.value -= 1
  dirty.value = true
}

async function save() {
  if (!props.eventId || !props.teamId || saving.value) return
  saving.value = true
  try {
    const {data} = await axios.put<PlayersResponse>(`/events/${props.eventId}/teams/${props.teamId}/players`, {
      players: players.value.map((player) => ({
        firstname: player.firstname.trim(),
        name: player.name.trim(),
        gender: player.gender,
        birthday: player.birthday,
      })),
    })
    applyResponse(data)
    if (data.team) emit('saved', data.team)
    showGlassToast('Teammitglieder gespeichert', 'success')
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    saving.value = false
  }
}

watch(
  () => [props.teamId, props.eventId] as const,
  ([teamId]) => {
    if (teamId) void load()
  },
  {immediate: true},
)
</script>

<template>
  <div class="team-players-editor">
    <p v-if="loading" class="vol-muted inline-flex items-center gap-2">
      <Spinner size="sm"/>
      <span>Laden…</span>
    </p>
    <p v-else-if="loadError" class="team-players-editor__error" role="alert">{{ loadError }}</p>
    <template v-else>
      <p v-if="ruleHint" class="team-players-editor__hint">{{ ruleHint }}</p>
      <p v-if="outOfRangeCount" class="team-players-editor__warning" role="status">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"/>
        {{ outOfRangeCount === 1 ? '1 Teammitglied liegt' : `${outOfRangeCount} Teammitglieder liegen` }}
        außerhalb der Altersgrenze.
      </p>
      <p v-if="!players.length" class="vol-muted">Keine Teammitglieder eingetragen.</p>
      <ul v-else class="team-players-editor__list">
        <li
            v-for="(player, index) in players"
            :key="index"
            class="team-players-editor__row"
            :class="{'team-players-editor__row--warning': isOutOfRange(player)}"
        >
          <template v-if="editingIndex === index">
            <input
                type="text"
                class="glass-input glass-input--sm"
                aria-label="Vorname"
                :value="player.firstname"
                :disabled="saving"
                @input="update(index, 'firstname', ($event.target as HTMLInputElement).value)"
            >
            <input
                type="text"
                class="glass-input glass-input--sm"
                aria-label="Nachname"
                :value="player.name"
                :disabled="saving"
                @input="update(index, 'name', ($event.target as HTMLInputElement).value)"
            >
            <select
                class="select-input vol-detail-select"
                aria-label="Geschlecht"
                :value="player.gender"
                :disabled="saving"
                @change="update(index, 'gender', ($event.target as HTMLSelectElement).value)"
            >
              <option v-for="option in PLAYER_GENDER_OPTIONS" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <input
                type="date"
                class="glass-input glass-input--sm"
                aria-label="Geburtsdatum"
                :max="today"
                :value="player.birthday ?? ''"
                :disabled="saving"
                @change="update(index, 'birthday', ($event.target as HTMLInputElement).value)"
            >
          </template>
          <template v-else>
            <span class="team-players-editor__name">{{ displayName(player.firstname) }} {{ displayName(player.name) }}</span>
            <span class="team-players-editor__meta">{{ genderLabel(player.gender) }}</span>
            <span class="team-players-editor__meta">{{ formatDate(player.birthday) }}</span>
            <span
                v-if="rule"
                class="team-players-editor__meta"
                :title="isOutOfRange(player) ? `Außerhalb ${rule.minAge}–${rule.maxAge} Jahre` : undefined"
            >
              <i v-if="isOutOfRange(player)" class="bi bi-exclamation-triangle" aria-hidden="true"/>
              {{ ageLabel(player) }}
            </span>
          </template>
          <span class="team-players-editor__actions">
            <button
                v-if="editingIndex === index"
                type="button"
                class="glass-btn-icon"
                aria-label="Bearbeitung schließen"
                title="Bearbeitung schließen"
                :disabled="saving"
                @click="editingIndex = null"
            >
              <i class="bi bi-check-lg" aria-hidden="true"/>
            </button>
            <button
                v-else
                type="button"
                class="glass-btn-icon"
                aria-label="Teammitglied bearbeiten"
                title="Bearbeiten"
                :disabled="saving"
                @click="editingIndex = index"
            >
              <i class="bi bi-pencil" aria-hidden="true"/>
            </button>
            <button
                type="button"
                class="glass-btn-icon"
                aria-label="Teammitglied entfernen"
                title="Entfernen"
                :disabled="saving"
                @click="removePlayer(index)"
            >
              <i class="bi bi-trash" aria-hidden="true"/>
            </button>
          </span>
        </li>
      </ul>
      <div class="team-players-editor__footer">
        <button
            type="button"
            class="glass-btn-secondary"
            :disabled="saving || atMax"
            @click="addPlayer"
        >
          <i class="bi bi-plus-lg" aria-hidden="true"/>
          Teammitglied hinzufügen
        </button>
        <span v-if="maxPlayers !== null" class="vol-muted">{{ players.length }} / {{ maxPlayers }}</span>
        <button
            type="button"
            class="glass-btn-accent"
            :disabled="saving || !dirty"
            @click="save"
        >
          {{ saving ? 'Speichern…' : 'Speichern' }}
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.team-players-editor__hint,
.team-players-editor__warning,
.team-players-editor__error {
  margin: 0 0 0.6rem;
  font-size: 0.85rem;
}

.team-players-editor__warning {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--color-warning, #b45309);
}

.team-players-editor__error {
  color: var(--color-danger, #c0392b);
}

.team-players-editor__hint {
  color: var(--color-text-muted);
}

.team-players-editor__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.team-players-editor__row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.team-players-editor__row--warning .team-players-editor__meta {
  color: var(--color-warning, #b45309);
}

.team-players-editor__name {
  min-width: 10rem;
  font-weight: 600;
}

.team-players-editor__meta {
  color: var(--color-text-muted);
  font-variant-numeric: tabular-nums;
}

.team-players-editor__row input,
.team-players-editor__row select {
  min-width: 0;
}

.team-players-editor__row input[type="text"] {
  width: 8.5rem;
}

.team-players-editor__actions {
  display: inline-flex;
  margin-left: auto;
}

.team-players-editor__footer {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 0.75rem;
}
</style>

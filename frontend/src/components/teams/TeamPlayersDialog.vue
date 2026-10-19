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
  team: TeamDataRow | null
}>()

const emit = defineEmits<{
  close: []
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

const titleId = 'team-players-dialog-title'
const today = new Date().toISOString().slice(0, 10)

const atMax = computed(() => maxPlayers.value !== null && players.value.length >= maxPlayers.value)

const ruleHint = computed(() => {
  if (!rule.value) return ''
  return `Erlaubt sind ${rule.value.minAge} bis ${rule.value.maxAge} Jahre am ${formatReferenceDate(rule.value)}.`
})

const outOfRangeCount = computed(() =>
  players.value.filter((p) => playerAgeOutOfRange(p.birthday, rule.value) !== null).length,
)

function applyResponse(data: PlayersResponse) {
  players.value = (data.players ?? []).map((p) => ({
    firstname: p.firstname ?? '',
    name: p.name ?? '',
    gender: p.gender ?? '',
    birthday: p.birthday ?? null,
  }))
  rule.value = data.player_age_rule ?? null
  maxPlayers.value = data.max_players ?? null
  dirty.value = false
}

async function load() {
  const team = props.team
  if (!team || !props.eventId) return
  loading.value = true
  loadError.value = ''
  players.value = []
  try {
    const {data} = await axios.get<PlayersResponse>(`/events/${props.eventId}/teams/${team.id}/players`)
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
  if (field === 'birthday') {
    next.birthday = value === '' ? null : value
  } else {
    next[field] = value
  }
  players.value[index] = next
  dirty.value = true
}

function addPlayer() {
  if (atMax.value) return
  players.value.push({firstname: '', name: '', gender: '', birthday: null})
  dirty.value = true
}

function removePlayer(index: number) {
  players.value.splice(index, 1)
  dirty.value = true
}

async function save() {
  const team = props.team
  if (!team || !props.eventId || saving.value) return
  saving.value = true
  try {
    const {data} = await axios.put<PlayersResponse>(`/events/${props.eventId}/teams/${team.id}/players`, {
      players: players.value.map((p) => ({
        firstname: p.firstname.trim(),
        name: p.name.trim(),
        gender: p.gender,
        birthday: p.birthday,
      })),
    })
    applyResponse(data)
    if (data.team) emit('saved', data.team)
    emit('close')
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    saving.value = false
  }
}

function onDialogKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && !saving.value) {
    event.preventDefault()
    emit('close')
  }
}

watch(
  () => [props.team?.id, props.eventId] as const,
  ([teamId]) => {
    if (teamId) void load()
  },
  {immediate: true},
)
</script>

<template>
  <Teleport to="body">
    <div
        v-if="team"
        class="glass-scrim fixed inset-0 z-[100] flex items-center justify-center p-4"
        @click="!saving && emit('close')"
    >
      <div
          class="glass-modal vol-columns-dialog team-players-dialog"
          role="dialog"
          :aria-labelledby="titleId"
          aria-modal="true"
          @click.stop
          @keydown="onDialogKeydown"
      >
        <header class="vol-columns-dialog__header">
          <h2 :id="titleId" class="vol-columns-dialog__title">
            Teammitglieder · {{ team.name }}
            <span v-if="team.team_number_hot != null" class="team-players-dialog__nr">#{{ team.team_number_hot }}</span>
          </h2>
          <p class="vol-columns-dialog__hint">
            Änderungen werden direkt in der Anmeldung (DRAHT) gespeichert und sind für den Coach in JOIN sichtbar.
            <template v-if="ruleHint"> {{ ruleHint }}</template>
          </p>
        </header>

        <div class="vol-columns-dialog__body">
          <p v-if="loading" class="vol-muted inline-flex items-center gap-2">
            <Spinner size="sm"/>
            <span>Laden…</span>
          </p>
          <p v-else-if="loadError" class="team-players-dialog__error" role="alert">{{ loadError }}</p>
          <template v-else>
            <p v-if="outOfRangeCount" class="team-players-dialog__warning" role="status">
              <i class="bi bi-exclamation-triangle" aria-hidden="true"/>
              {{ outOfRangeCount === 1 ? '1 Teammitglied liegt' : `${outOfRangeCount} Teammitglieder liegen` }}
              außerhalb der Altersgrenze.
            </p>
            <p v-if="!players.length" class="vol-muted">Keine Teammitglieder eingetragen.</p>
            <table v-else class="team-players-dialog__table">
              <thead>
                <tr>
                  <th scope="col">Vorname</th>
                  <th scope="col">Nachname</th>
                  <th scope="col">Geschlecht</th>
                  <th scope="col">Geburtsdatum</th>
                  <th v-if="rule" scope="col" class="team-players-dialog__age">Alter</th>
                  <th scope="col"><span class="sr-only">Entfernen</span></th>
                </tr>
              </thead>
              <tbody>
                <tr
                    v-for="(player, index) in players"
                    :key="index"
                    :class="{'team-players-dialog__row--warning': isOutOfRange(player)}"
                >
                  <td>
                    <input
                        type="text"
                        class="glass-input glass-input--sm"
                        aria-label="Vorname"
                        :value="player.firstname"
                        :disabled="saving"
                        @input="update(index, 'firstname', ($event.target as HTMLInputElement).value)"
                    >
                  </td>
                  <td>
                    <input
                        type="text"
                        class="glass-input glass-input--sm"
                        aria-label="Nachname"
                        :value="player.name"
                        :disabled="saving"
                        @input="update(index, 'name', ($event.target as HTMLInputElement).value)"
                    >
                  </td>
                  <td>
                    <select
                        class="select-input vol-detail-select vol-detail-select--full"
                        aria-label="Geschlecht"
                        :value="player.gender"
                        :disabled="saving"
                        @change="update(index, 'gender', ($event.target as HTMLSelectElement).value)"
                    >
                      <option v-for="option in PLAYER_GENDER_OPTIONS" :key="option.value" :value="option.value">
                        {{ option.label }}
                      </option>
                    </select>
                  </td>
                  <td>
                    <input
                        type="date"
                        class="glass-input glass-input--sm"
                        aria-label="Geburtsdatum"
                        :max="today"
                        :value="player.birthday ?? ''"
                        :disabled="saving"
                        @change="update(index, 'birthday', ($event.target as HTMLInputElement).value)"
                    >
                  </td>
                  <td
                      v-if="rule"
                      class="team-players-dialog__age"
                      :title="isOutOfRange(player) ? `Außerhalb ${rule.minAge}–${rule.maxAge} Jahre` : undefined"
                  >
                    <i v-if="isOutOfRange(player)" class="bi bi-exclamation-triangle" aria-hidden="true"/>
                    {{ ageLabel(player) }}
                  </td>
                  <td>
                    <button
                        type="button"
                        class="glass-btn-icon"
                        aria-label="Teammitglied entfernen"
                        :disabled="saving"
                        @click="removePlayer(index)"
                    >
                      <i class="bi bi-trash" aria-hidden="true"/>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
            <div class="team-players-dialog__add">
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
            </div>
          </template>
        </div>

        <footer class="vol-columns-dialog__footer">
          <button type="button" class="glass-btn-secondary" :disabled="saving" @click="emit('close')">
            Abbrechen
          </button>
          <button
              type="button"
              class="glass-btn-accent"
              :disabled="saving || loading || !!loadError || !dirty"
              @click="save"
          >
            {{ saving ? 'Speichern…' : 'Speichern' }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style>
@import '@/assets/columns-dialog.css';

.team-players-dialog {
  width: min(100%, 52rem);
}

.team-players-dialog__nr {
  margin-left: 0.35rem;
  font-weight: 500;
  color: var(--color-text-muted);
  font-variant-numeric: tabular-nums;
}

.team-players-dialog__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}

.team-players-dialog__table th {
  padding: 0 0.35rem 0.35rem;
  text-align: left;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

.team-players-dialog__table td {
  padding: 0.25rem 0.35rem;
  vertical-align: middle;
}

.team-players-dialog__table input {
  width: 100%;
}

.team-players-dialog__age {
  width: 4.5rem;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.team-players-dialog__row--warning .team-players-dialog__age {
  color: var(--color-warning, #b45309);
  font-weight: 600;
}

.team-players-dialog__warning {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0 0 0.75rem;
  font-size: 0.85rem;
  color: var(--color-warning, #b45309);
}

.team-players-dialog__error {
  margin: 0;
  font-size: 0.85rem;
  color: var(--color-danger, #c0392b);
}

.team-players-dialog__add {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 0.75rem;
}
</style>

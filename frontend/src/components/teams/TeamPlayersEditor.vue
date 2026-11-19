<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import Spinner from '@/components/atoms/Spinner.vue'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'
import type {TeamDataColumn, TeamDataRow} from '@/utils/teamDataCompletion'
import {
  PLAYER_GENDER_OPTIONS,
  PLAYER_MEAL_OPTIONS,
  PLAYER_PHOTO_OPTIONS,
  playerAgeOn,
  playerAgeOutOfRange,
  type PlayerAgeRule,
  type TeamPlayer,
} from '@/utils/teamPlayers'

const props = defineProps<{
  eventId?: number | null
  teamId: number
  /** When set, rows join the team-data table and share its columns. */
  columns?: TeamDataColumn[]
}>()

const emit = defineEmits<{
  saved: [team: TeamDataRow]
}>()

type TeamCoach = {
  role: 'coach' | 'co_coach'
  firstname: string
  name: string
  email: string
  phone: string
}

type PlayersResponse = {
  players: TeamPlayer[]
  coaches?: TeamCoach[]
  max_players: number | null
  player_age_rule: PlayerAgeRule | null
  team?: TeamDataRow
}

const players = ref<TeamPlayer[]>([])
const coaches = ref<TeamCoach[]>([])
const rule = ref<PlayerAgeRule | null>(null)
const maxPlayers = ref<number | null>(null)
const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const dirty = ref(false)

const today = new Date().toISOString().slice(0, 10)

const atMax = computed(() => maxPlayers.value !== null && players.value.length >= maxPlayers.value)
const aligned = computed(() => props.columns != null)
const span = computed(() => 12 + (props.columns?.length ?? 0))

const outOfRangeCount = computed(() =>
  players.value.filter((player) => playerAgeOutOfRange(player.birthday, rule.value) !== null).length,
)

function applyResponse(data: PlayersResponse) {
  players.value = (data.players ?? []).map((player) => ({
    firstname: player.firstname ?? '',
    name: player.name ?? '',
    gender: player.gender ?? '',
    birthday: player.birthday ?? null,
    photo: player.photo === 'yes' || player.photo === 'no' ? player.photo : '',
    meal: player.meal === 'standard' || player.meal === 'vegetarisch' || player.meal === 'vegan' || player.meal === 'keine' ? player.meal : '',
  }))
  coaches.value = (data.coaches ?? []).map((coach) => ({
    role: coach.role === 'co_coach' ? 'co_coach' : 'coach',
    firstname: coach.firstname ?? '',
    name: coach.name ?? '',
    email: coach.email ?? '',
    phone: coach.phone ?? '',
  }))
  rule.value = data.player_age_rule ?? null
  maxPlayers.value = data.max_players ?? null
  dirty.value = false
}

function coachName(coach: TeamCoach) {
  return [coach.firstname, coach.name].filter((part) => part.trim() !== '').join(' ')
}

function coachRole(coach: TeamCoach) {
  return coach.role === 'co_coach' ? 'Co-Coach' : 'Coach'
}

async function load() {
  if (!props.eventId || !props.teamId) return
  loading.value = true
  loadError.value = ''
  players.value = []
  coaches.value = []
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
  else if (field === 'photo') next.photo = value === 'yes' || value === 'no' ? value : ''
  else if (field === 'meal') next.meal = value === 'standard' || value === 'vegetarisch' || value === 'vegan' || value === 'keine' ? value : ''
  else next[field] = value
  players.value[index] = next
  dirty.value = true
}

function addPlayer() {
  if (atMax.value) return
  players.value.push({firstname: '', name: '', gender: '', birthday: null, photo: '', meal: ''})
  dirty.value = true
}

function removePlayer(index: number) {
  players.value.splice(index, 1)
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
        photo: player.photo,
        meal: player.meal,
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
  <tbody v-if="aligned" class="team-players-editor team-players-editor--aligned">
    <tr v-if="loading" class="team-players-editor__note">
      <td :colspan="span">
        <span class="vol-muted inline-flex items-center gap-2">
          <Spinner size="sm"/>
          <span>Laden…</span>
        </span>
      </td>
    </tr>
    <tr v-else-if="loadError" class="team-players-editor__note">
      <td :colspan="span" class="team-players-editor__error" role="alert">{{ loadError }}</td>
    </tr>
    <template v-else>
      <tr v-if="coaches.length" class="team-players-editor__coaches">
        <td :colspan="span">
          <ul class="team-players-editor__people">
            <li v-for="(coach, index) in coaches" :key="index" class="team-players-editor__person">
              <span class="team-players-editor__role">{{ coachRole(coach) }}</span>
              <span class="team-players-editor__person-name">{{ coachName(coach) }}</span>
              <a v-if="coach.email" :href="`mailto:${coach.email}`">{{ coach.email }}</a>
              <span v-if="coach.phone">{{ coach.phone }}</span>
            </li>
          </ul>
        </td>
      </tr>
      <tr v-if="outOfRangeCount" class="team-players-editor__note">
        <td :colspan="span">
          <span class="team-players-editor__warning" role="status">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"/>
            {{ outOfRangeCount === 1 ? '1 Teammitglied liegt' : `${outOfRangeCount} Teammitglieder liegen` }}
            außerhalb der Altersgrenze.
          </span>
        </td>
      </tr>
      <tr v-if="players.length" class="team-players-editor__subhead">
        <td class="vol-table__sticky vol-table__sticky--expand"></td>
        <td class="vol-table__sticky vol-table__sticky--program"></td>
        <td class="vol-table__sticky vol-table__sticky--nr"></td>
        <td class="vol-table__sticky vol-table__sticky--ablauf"></td>
        <td class="vol-table__sticky vol-table__sticky--name">Vorname</td>
        <td>Nachname</td>
        <td>Geschlecht</td>
        <td>Geburtsdatum</td>
        <td>{{ rule ? 'Alter' : '' }}</td>
        <td></td>
        <td></td>
        <td v-for="column in columns" :key="column.key">
          {{ column.kind === 'photo' || column.kind === 'meal' ? column.label : '' }}
        </td>
        <td></td>
      </tr>
      <tr v-if="!players.length" class="team-players-editor__note">
        <td :colspan="span" class="vol-muted">Keine Teammitglieder eingetragen.</td>
      </tr>
      <tr
          v-for="(player, index) in players"
          :key="index"
          class="team-players-editor__row"
          :class="{'team-players-editor__row--warning': isOutOfRange(player)}"
      >
        <td class="vol-table__sticky vol-table__sticky--expand"></td>
        <td class="vol-table__sticky vol-table__sticky--program"></td>
        <td class="vol-table__sticky vol-table__sticky--nr"></td>
        <td class="vol-table__sticky vol-table__sticky--ablauf"></td>
        <td class="vol-table__sticky vol-table__sticky--name">
          <input
              type="text"
              class="glass-input glass-input--sm team-data-control"
              aria-label="Vorname"
              :value="player.firstname"
              :disabled="saving"
              @input="update(index, 'firstname', ($event.target as HTMLInputElement).value)"
          >
        </td>
        <td>
          <input
              type="text"
              class="glass-input glass-input--sm team-data-control"
              aria-label="Nachname"
              :value="player.name"
              :disabled="saving"
              @input="update(index, 'name', ($event.target as HTMLInputElement).value)"
          >
        </td>
        <td>
          <select
              class="select-input team-data-control"
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
              class="glass-input glass-input--sm team-data-control"
              aria-label="Geburtsdatum"
              :max="today"
              :value="player.birthday ?? ''"
              :disabled="saving"
              @change="update(index, 'birthday', ($event.target as HTMLInputElement).value)"
          >
        </td>
        <td class="team-players-editor__age">
          <template v-if="rule">
            <i v-if="isOutOfRange(player)" class="bi bi-exclamation-triangle" aria-hidden="true"/>
            {{ ageLabel(player) }}
          </template>
        </td>
        <td></td>
        <td></td>
        <td v-for="column in columns" :key="`${index}-${column.key}`">
          <select
              v-if="column.kind === 'photo'"
              class="select-input team-data-control"
              aria-label="Fotoerlaubnis"
              :value="player.photo"
              :disabled="saving"
              @change="update(index, 'photo', ($event.target as HTMLSelectElement).value)"
          >
            <option v-for="option in PLAYER_PHOTO_OPTIONS" :key="'photo-' + option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
          <select
              v-else-if="column.kind === 'meal'"
              class="select-input team-data-control"
              aria-label="Essen"
              :value="player.meal"
              :disabled="saving"
              @change="update(index, 'meal', ($event.target as HTMLSelectElement).value)"
          >
            <option v-for="option in PLAYER_MEAL_OPTIONS" :key="'meal-' + option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </td>
        <td>
          <button
              type="button"
              class="glass-btn-icon team-data-icon no-touch-min"
              aria-label="Teammitglied entfernen"
              title="Entfernen"
              :disabled="saving"
              @click="removePlayer(index)"
          >
            <i class="bi bi-trash" aria-hidden="true"/>
          </button>
        </td>
      </tr>
      <tr class="team-players-editor__footer">
        <td :colspan="span">
          <div class="team-players-editor__actions">
            <button
                type="button"
                class="glass-btn-secondary glass-btn--sm no-touch-min"
                :disabled="saving || atMax"
                @click="addPlayer"
            >
              <i class="bi bi-plus-lg" aria-hidden="true"/>
              Teammitglied hinzufügen
            </button>
            <span v-if="maxPlayers !== null" class="vol-muted">{{ players.length }} / {{ maxPlayers }}</span>
            <button
                type="button"
                class="glass-btn-accent glass-btn--sm no-touch-min"
                :disabled="saving || !dirty"
                @click="save"
            >
              {{ saving ? 'Speichern…' : 'Speichern' }}
            </button>
          </div>
        </td>
      </tr>
    </template>
  </tbody>
  <div v-else class="team-players-editor">
    <p v-if="loading" class="vol-muted inline-flex items-center gap-2">
      <Spinner size="sm"/>
      <span>Laden…</span>
    </p>
    <p v-else-if="loadError" class="team-players-editor__error" role="alert">{{ loadError }}</p>
    <template v-else>
      <p v-if="outOfRangeCount" class="team-players-editor__warning" role="status">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"/>
        {{ outOfRangeCount === 1 ? '1 Teammitglied liegt' : `${outOfRangeCount} Teammitglieder liegen` }}
        außerhalb der Altersgrenze.
      </p>
      <p v-if="!players.length" class="vol-muted">Keine Teammitglieder eingetragen.</p>
      <table v-else class="team-players-editor__table">
        <thead>
          <tr>
            <th scope="col">Vorname</th>
            <th scope="col">Nachname</th>
            <th scope="col">Geschlecht</th>
            <th scope="col">Geburtsdatum</th>
            <th scope="col">Fotoerlaubnis</th>
            <th scope="col">Essen</th>
            <th v-if="rule" scope="col">Alter</th>
            <th scope="col"><span class="sr-only">Entfernen</span></th>
          </tr>
        </thead>
        <tbody>
          <tr
              v-for="(player, index) in players"
              :key="index"
              :class="{'team-players-editor__row--warning': isOutOfRange(player)}"
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
            <td>
              <select
                  class="select-input vol-detail-select"
                  aria-label="Fotoerlaubnis"
                  :value="player.photo"
                  :disabled="saving"
                  @change="update(index, 'photo', ($event.target as HTMLSelectElement).value)"
              >
                <option v-for="option in PLAYER_PHOTO_OPTIONS" :key="'photo-' + option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
            </td>
            <td>
              <select
                  class="select-input vol-detail-select"
                  aria-label="Essen"
                  :value="player.meal"
                  :disabled="saving"
                  @change="update(index, 'meal', ($event.target as HTMLSelectElement).value)"
              >
                <option v-for="option in PLAYER_MEAL_OPTIONS" :key="'meal-' + option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
            </td>
            <td v-if="rule" class="team-players-editor__age">
              <i v-if="isOutOfRange(player)" class="bi bi-exclamation-triangle" aria-hidden="true"/>
              {{ ageLabel(player) }}
            </td>
            <td>
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
            </td>
          </tr>
        </tbody>
      </table>
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

.team-players-editor__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}

.team-players-editor__table th {
  text-align: left;
  font-size: 0.75rem;
  font-weight: 650;
  color: var(--color-text-muted);
  padding: 0 0.35rem 0.35rem;
  white-space: nowrap;
}

.team-players-editor__table td {
  padding: 0.2rem 0.35rem 0.45rem 0;
  vertical-align: middle;
}

.team-players-editor__table input[type="text"] {
  width: 8rem;
}

.team-players-editor__table select,
.team-players-editor__table input[type="date"] {
  width: 100%;
  min-width: 6.5rem;
}

.team-players-editor__row--warning .team-players-editor__age {
  color: var(--color-warning, #b45309);
}

.team-players-editor__age {
  font-variant-numeric: tabular-nums;
  color: var(--color-text-muted);
  white-space: nowrap;
}

div.team-players-editor__footer {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 0.75rem;
}

.team-players-editor--aligned .team-players-editor__hint,
.team-players-editor--aligned .team-players-editor__warning,
.team-players-editor--aligned .team-players-editor__error {
  margin: 0;
  font-size: 0.75rem;
}

.team-players-editor--aligned .team-players-editor__warning {
  display: inline-flex;
  margin-left: 0;
}

.team-players-editor--aligned .team-players-editor__subhead td {
  font-size: 0.68rem;
  font-weight: 650;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: var(--color-text-muted);
  padding-top: 0.05rem;
  padding-bottom: 0.05rem;
}

.team-players-editor__actions {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  padding: 0.15rem 0 0.2rem;
}

.team-players-editor__people {
  display: flex;
  flex-direction: column;
  gap: 0.12rem;
  margin: 0;
  padding: 0.1rem 0 0.28rem;
  list-style: none;
}

.team-players-editor__person {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.2rem 0.7rem;
  font-size: 0.8125rem;
}

.team-players-editor__role {
  min-width: 5.25rem;
  font-size: 0.68rem;
  font-weight: 650;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: var(--color-text-muted);
}

.team-players-editor__person-name {
  font-weight: 600;
}

.team-players-editor__person a {
  color: var(--color-text-muted);
  text-decoration: none;
}

.team-players-editor__person a:hover {
  text-decoration: underline;
}

tr.team-players-editor__footer td {
  border-bottom: none;
}
</style>

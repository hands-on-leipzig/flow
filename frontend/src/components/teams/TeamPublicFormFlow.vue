<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {sumCountMap} from '@/utils/teamDataCompletion'
import {
  photoConsentStatusClass,
  photoConsentStatusForTeam,
} from '@/utils/photoConsentStatus'
import {isOtpStubAccepted} from '@/utils/otpStub'
import PublicFormOtpNotice from '@/components/molecules/PublicFormOtpNotice.vue'

type FormColumn = {
  key: string
  label: string
  kind?: string
  type?: string
  editor?: string
  field_key?: string
  options?: Array<{value: string; label: string}>
  boolean_keys?: string[]
}

type MealOption = {value: string; label: string}

type TeamSummary = {
  id: number
  name: string
  team_number_hot: number | null
  organization: string | null
  first_program: number | null
  program_label: string
  people_count: number | null
}

type FormPayload = {
  team: TeamSummary
  columns: FormColumn[]
  photo_consent: Record<string, number>
  meals: Record<string, number>
  custom: Record<string, string | number | boolean | null>
  meal_options: MealOption[]
  touched: {photo: boolean; meal: boolean; custom: Record<string, boolean>}
}

const props = defineProps<{
  step: 'email' | 'otp' | 'pick-team' | 'data' | 'done'
  email: string
  slug: string
  event?: Record<string, unknown> | null
}>()

const emit = defineEmits<{
  'update:email': [value: string]
  'update:step': [value: 'email' | 'otp' | 'pick-team' | 'data' | 'done']
  cancel: []
}>()

const otpCode = ref('')
const otpError = ref('')
const lookupLoading = ref(false)
const lookupError = ref('')
const teams = ref<TeamSummary[]>([])
const formPayload = ref<FormPayload | null>(null)
const selectedTeamId = ref<number | null>(null)
const saving = ref(false)
const saveError = ref('')

const photoDraft = ref<Record<string, number>>({unknown: 0, yes: 0, no: 0})
const mealsDraft = ref<Record<string, number>>({})
const customDraft = ref<Record<string, string | number | boolean | null>>({})

const emailModel = computed({
  get: () => props.email,
  set: (value: string) => emit('update:email', value),
})

const peopleCount = computed(() => formPayload.value?.team.people_count ?? null)

const hasPhotoColumn = computed(() =>
  (formPayload.value?.columns ?? []).some((c) => c.kind === 'photo' || c.key === 'photo_consent'),
)

const hasMealColumn = computed(() =>
  (formPayload.value?.columns ?? []).some((c) => c.kind === 'meal' || c.editor === 'meal_counts'),
)

const photoStatus = computed(() =>
  photoConsentStatusForTeam(photoDraft.value, peopleCount.value),
)

const mealSum = computed(() => sumCountMap(mealsDraft.value))

const mealMismatch = computed(() => {
  if (!hasMealColumn.value || peopleCount.value === null) return false
  return mealSum.value !== peopleCount.value
})

const saveDisabled = computed(() => saving.value || mealMismatch.value)

function proceedFromEmail() {
  const trimmed = props.email.trim()
  if (!trimmed) return
  emit('update:email', trimmed)
  otpError.value = ''
  otpCode.value = ''
  emit('update:step', 'otp')
}

async function verifyOtp() {
  if (!isOtpStubAccepted(otpCode.value)) {
    otpError.value = 'Ungültiger Code. Bitte erneut versuchen.'
    return
  }
  otpError.value = ''
  await loadLookup()
}

function applyForm(data: FormPayload) {
  formPayload.value = data
  selectedTeamId.value = data.team.id
  photoDraft.value = {
    unknown: Number(data.photo_consent?.unknown ?? 0),
    yes: Number(data.photo_consent?.yes ?? 0),
    no: Number(data.photo_consent?.no ?? 0),
  }
  mealsDraft.value = {...(data.meals ?? {})}
  customDraft.value = {...(data.custom ?? {})}
}

async function loadLookup() {
  if (!props.slug || !props.email.trim()) return
  lookupLoading.value = true
  lookupError.value = ''
  teams.value = []
  formPayload.value = null
  try {
    const {data} = await axios.get(`/public-team-form/${props.slug}/lookup`, {
      params: {email: props.email.trim()},
    })
    teams.value = data.teams ?? []
    if (data.form) {
      applyForm(data.form)
      emit('update:step', 'data')
      return
    }
    if (teams.value.length > 1) {
      emit('update:step', 'pick-team')
      return
    }
    lookupError.value = 'Kein Team für diese E-Mail gefunden.'
  } catch (error: unknown) {
    const message = axios.isAxiosError(error)
        ? (error.response?.data?.error as string | undefined)
          || (error.response?.data?.message as string | undefined)
        : undefined
    lookupError.value = message || 'Diese E-Mail ist keinem Team als Coach zugeordnet.'
    emit('update:step', 'data')
  } finally {
    lookupLoading.value = false
  }
}

async function selectTeam(teamId: number) {
  if (!props.slug || !props.email.trim()) return
  lookupLoading.value = true
  lookupError.value = ''
  try {
    const {data} = await axios.get(`/public-team-form/${props.slug}/team/${teamId}`, {
      params: {email: props.email.trim()},
    })
    applyForm(data.form)
    emit('update:step', 'data')
  } catch {
    lookupError.value = 'Team konnte nicht geladen werden.'
  } finally {
    lookupLoading.value = false
  }
}

function setMealCount(key: string, raw: string) {
  const n = Number.parseInt(raw, 10)
  mealsDraft.value = {...mealsDraft.value, [key]: Number.isFinite(n) && n >= 0 ? n : 0}
}

function customValue(fieldKey: string) {
  return customDraft.value[fieldKey] ?? null
}

function setCustomValue(fieldKey: string, value: string | number | boolean | null) {
  customDraft.value[fieldKey] = value
}

async function submitForm() {
  if (!props.slug || !props.email.trim() || !selectedTeamId.value || saveDisabled.value) return
  saving.value = true
  saveError.value = ''
  const customPayload: Record<string, string | number | boolean | null> = {}
  for (const column of formPayload.value?.columns ?? []) {
    if (column.kind === 'custom' && column.field_key) {
      customPayload[column.field_key] = customDraft.value[column.field_key] ?? null
    }
  }
  const body: Record<string, unknown> = {
    email: props.email.trim(),
    team: selectedTeamId.value,
    custom: customPayload,
  }
  if (hasMealColumn.value) {
    body.meals = {...mealsDraft.value}
  }
  try {
    await axios.post(`/public-team-form/${props.slug}/save`, body)
    emit('update:step', 'done')
  } catch (error: unknown) {
    const message = axios.isAxiosError(error)
        ? (error.response?.data?.error as string | undefined)
        : undefined
    saveError.value = message || 'Speichern fehlgeschlagen. Bitte erneut versuchen.'
  } finally {
    saving.value = false
  }
}

watch(
  () => props.step,
  (step) => {
    if (step === 'done') {
      window.scrollTo({top: 0, behavior: 'smooth'})
    }
  },
)
</script>

<template>
  <section class="glass-card liquid-surface-inner pe-section vol-public-form">
    <header v-if="step !== 'done'" class="vol-public-form__head">
      <h2 class="glass-card__title">Dateneingabe für Coaches</h2>
    </header>

    <div v-if="step === 'email'" class="vol-public-form__step">
      <label class="vol-public-form__label" for="team-form-email">E-Mail</label>
      <input
          id="team-form-email"
          v-model="emailModel"
          type="email"
          class="glass-input"
          autocomplete="email"
          placeholder="name@beispiel.de"
      >
      <div class="vol-public-form__actions vol-public-form__actions--inline">
        <button type="button" class="glass-btn-accent" @click="proceedFromEmail">
          Weiter
        </button>
        <button type="button" class="glass-btn-secondary" @click="emit('cancel')">
          Abbrechen
        </button>
      </div>
    </div>

    <div v-else-if="step === 'otp'" class="vol-public-form__step">
      <p class="vol-public-form__info">
        Wenn diese E-Mail für diese Veranstaltung als Coach registriert ist, erhalten Sie einen Code per E-Mail. Bitte geben Sie den Code ein.
      </p>
      <PublicFormOtpNotice />
      <label class="vol-public-form__label" for="team-form-otp">Code</label>
      <input
          id="team-form-otp"
          v-model="otpCode"
          type="text"
          inputmode="numeric"
          maxlength="6"
          class="glass-input vol-public-form__otp"
          autocomplete="one-time-code"
          placeholder="000000"
      >
      <p v-if="otpError" class="vol-public-form__error">{{ otpError }}</p>
      <p v-if="lookupLoading" class="pe-muted">Laden…</p>
      <div class="vol-public-form__actions vol-public-form__actions--otp">
        <button type="button" class="glass-btn-accent" :disabled="lookupLoading" @click="verifyOtp">
          Bestätigen
        </button>
        <button type="button" class="glass-btn-secondary" @click="emit('cancel')">
          Abbrechen
        </button>
      </div>
    </div>

    <div v-else-if="step === 'pick-team'" class="vol-public-form__step">
      <p class="vol-public-form__info">Mehrere Teams gefunden. Bitte wählen Sie eines aus.</p>
      <p v-if="lookupLoading" class="pe-muted">Laden…</p>
      <ul class="team-public-form__picker">
        <li v-for="team in teams" :key="team.id">
          <button
              type="button"
              class="glass-btn-secondary team-public-form__pick"
              :disabled="lookupLoading"
              @click="selectTeam(team.id)"
          >
            <span class="team-public-form__pick-name">{{ team.name }}</span>
            <span v-if="team.team_number_hot != null" class="team-public-form__pick-nr">
              Nr. {{ team.team_number_hot }}
            </span>
          </button>
        </li>
      </ul>
      <div class="vol-public-form__actions vol-public-form__actions--inline">
        <button type="button" class="glass-btn-secondary" @click="emit('cancel')">
          Abbrechen
        </button>
      </div>
    </div>

    <div v-else-if="step === 'done'" class="vol-public-form__step">
      <p class="vol-public-form__thanks">
        Danke für die Informationen. Du kannst wiederkommen, wenn du noch etwas ändern möchtest.
        Einige Tage vor der Veranstaltung wird das Formular aber gesperrt.
      </p>
      <div class="vol-public-form__actions vol-public-form__actions--inline">
        <button type="button" class="glass-btn-accent" @click="emit('cancel')">
          Schließen
        </button>
      </div>
    </div>

    <div v-else class="vol-public-form__step">
      <p v-if="lookupLoading" class="pe-muted">Laden…</p>
      <template v-else-if="lookupError">
        <p class="vol-public-form__error">{{ lookupError }}</p>
        <div class="vol-public-form__actions vol-public-form__actions--inline">
          <button type="button" class="glass-btn-secondary" @click="emit('cancel')">
            Abbrechen
          </button>
        </div>
      </template>

      <template v-else-if="formPayload">
        <div class="vol-public-form__fields">
          <header class="team-public-form__meta">
            <div class="team-public-form__identity">
              <ProgramLogo
                  v-if="formPayload.team.first_program"
                  :event="event"
                  :program="formPayload.team.first_program"
                  size="sm"
                  class="team-public-form__logo"
              />
              <p class="team-public-form__title">
                <span class="team-public-form__name">{{ formPayload.team.name }}</span>
                <span
                    v-if="formPayload.team.team_number_hot != null"
                    class="team-public-form__hot"
                >({{ formPayload.team.team_number_hot }})</span>
                <span class="team-public-form__org">{{ formPayload.team.organization || '—' }}</span>
              </p>
            </div>
            <p class="team-public-form__people">
              Gemeldete Personen: {{ peopleCount ?? '—' }}
            </p>
          </header>

          <div
              v-if="hasPhotoColumn"
              class="photo-consent-banner"
              :class="photoConsentStatusClass(photoStatus.status)"
              role="status"
          >
            <i class="bi bi-camera photo-consent-banner__icon" aria-hidden="true"/>
            <p class="photo-consent-banner__text">{{ photoStatus.selfServiceMessage }}</p>
          </div>

          <div
              v-if="hasMealColumn"
              class="vol-public-form__row"
              :class="{'team-public-form__mismatch': mealMismatch}"
          >
            <span class="vol-public-form__label">Essen</span>
            <div class="team-public-form__counts">
              <label
                  v-for="option in formPayload.meal_options"
                  :key="option.value"
                  class="team-public-form__count"
              >
                <span>{{ option.label }}</span>
                <input
                    type="number"
                    min="0"
                    class="glass-input"
                    :value="mealsDraft[option.value] ?? 0"
                    @input="setMealCount(option.value, ($event.target as HTMLInputElement).value)"
                >
              </label>
              <p v-if="mealMismatch" class="vol-public-form__error">
                Summe {{ mealSum }} ≠ Personen {{ peopleCount }}
              </p>
            </div>
          </div>

          <template v-for="field in formPayload.columns" :key="field.key">
            <div v-if="field.kind === 'custom' && field.type === 'text'" class="vol-public-form__row">
              <label class="vol-public-form__label" :for="`team-form-custom-${field.field_key}`">{{ field.label }}</label>
              <input
                  :id="`team-form-custom-${field.field_key}`"
                  :value="(customValue(field.field_key ?? '') as string) ?? ''"
                  type="text"
                  class="glass-input"
                  @input="setCustomValue(field.field_key ?? '', ($event.target as HTMLInputElement).value || null)"
              >
            </div>

            <div v-else-if="field.kind === 'custom' && field.type === 'number'" class="vol-public-form__row">
              <label class="vol-public-form__label" :for="`team-form-custom-${field.field_key}`">{{ field.label }}</label>
              <input
                  :id="`team-form-custom-${field.field_key}`"
                  :value="customValue(field.field_key ?? '') ?? ''"
                  type="number"
                  class="glass-input"
                  @input="setCustomValue(field.field_key ?? '', ($event.target as HTMLInputElement).value || null)"
              >
            </div>

            <div v-else-if="field.kind === 'custom' && field.type === 'select'" class="vol-public-form__row">
              <label class="vol-public-form__label" :for="`team-form-custom-${field.field_key}`">{{ field.label }}</label>
              <select
                  :id="`team-form-custom-${field.field_key}`"
                  class="glass-input select-input"
                  :value="(customValue(field.field_key ?? '') as string) ?? ''"
                  @change="setCustomValue(field.field_key ?? '', ($event.target as HTMLSelectElement).value || null)"
              >
                <option value="">?</option>
                <option v-for="option in field.options ?? []" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
            </div>

            <div v-else-if="field.kind === 'custom' && field.type === 'boolean'" class="vol-public-form__row">
              <span class="vol-public-form__label">{{ field.label }}</span>
              <div class="glass-segment vol-tristate" role="group" :aria-label="field.label">
                <button
                    type="button"
                    class="glass-segment__btn"
                    :class="{'glass-segment__btn--active': customValue(field.field_key ?? '') === null}"
                    @click="setCustomValue(field.field_key ?? '', null)"
                >
                  ?
                </button>
                <button
                    type="button"
                    class="glass-segment__btn"
                    :class="{'glass-segment__btn--active': customValue(field.field_key ?? '') === true}"
                    @click="setCustomValue(field.field_key ?? '', true)"
                >
                  Ja
                </button>
                <button
                    type="button"
                    class="glass-segment__btn"
                    :class="{'glass-segment__btn--active': customValue(field.field_key ?? '') === false}"
                    @click="setCustomValue(field.field_key ?? '', false)"
                >
                  Nein
                </button>
              </div>
            </div>
          </template>
        </div>

        <footer class="vol-public-form__actions">
          <button type="button" class="glass-btn-secondary" :disabled="saving" @click="emit('cancel')">
            Abbrechen
          </button>
          <button type="button" class="glass-btn-accent" :disabled="saveDisabled" @click="submitForm">
            {{ saving ? 'Speichern…' : 'Speichern' }}
          </button>
        </footer>
        <p v-if="saveError" class="vol-public-form__error">{{ saveError }}</p>
      </template>
    </div>
  </section>
</template>

<style scoped>
.vol-public-form__head {
  margin-bottom: 1rem;
}

.vol-public-form__head .glass-card__title {
  margin: 0;
}

.vol-public-form__step {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.vol-public-form__fields {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.vol-public-form__row {
  display: grid;
  grid-template-columns: minmax(7rem, 11rem) 1fr;
  gap: 0.65rem 1rem;
  align-items: start;
}

.vol-public-form__label {
  font-size: 0.875rem;
  font-weight: 600;
  padding-top: 0.45rem;
}

.vol-public-form__info {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  line-height: 1.45;
}

.vol-public-form__thanks {
  margin: 0;
  font-size: 1rem;
  line-height: 1.55;
}

.vol-public-form__otp {
  max-width: 10rem;
  letter-spacing: 0.15em;
}

.vol-public-form__error {
  margin: 0;
  color: var(--color-danger, #c0392b);
  font-size: 0.875rem;
}

.vol-public-form__readonly {
  padding-top: 0.45rem;
  font-weight: 600;
}

.vol-public-form__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 1.25rem;
  padding-top: 1rem;
  border-top: 1px solid var(--liquid-border-soft);
}

.vol-public-form__actions--otp,
.vol-public-form__actions--inline {
  margin-top: 0;
  padding-top: 0;
  border-top: none;
}

.team-public-form__picker {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.team-public-form__meta {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  margin-bottom: 0.35rem;
}

.team-public-form__identity {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
}

.team-public-form__logo {
  flex-shrink: 0;
}

.team-public-form__title {
  margin: 0;
  min-width: 0;
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.35rem 0.5rem;
  font-size: 1rem;
  line-height: 1.35;
}

.team-public-form__name {
  font-weight: 700;
}

.team-public-form__hot {
  color: var(--color-text-muted);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.team-public-form__org {
  color: var(--color-text-muted);
  font-size: 0.9rem;
  overflow-wrap: anywhere;
}

.team-public-form__people {
  margin: 0;
  font-size: 0.9rem;
  font-weight: 600;
  line-height: 1.35;
}

.team-public-form__pick {
  width: 100%;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  text-align: left;
}

.team-public-form__pick-name {
  font-weight: 600;
}

.team-public-form__pick-nr {
  color: var(--color-text-muted);
  font-size: 0.875rem;
}

.team-public-form__counts {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.team-public-form__count {
  display: grid;
  grid-template-columns: 6rem 1fr;
  gap: 0.5rem;
  align-items: center;
  font-size: 0.875rem;
}

.team-public-form__mismatch {
  outline: 1px solid var(--color-danger, #c0392b);
  outline-offset: 4px;
  border-radius: 0.35rem;
}

@media (max-width: 640px) {
  .vol-public-form__row {
    grid-template-columns: 1fr;
  }

  .vol-public-form__label {
    padding-top: 0;
  }
}
</style>

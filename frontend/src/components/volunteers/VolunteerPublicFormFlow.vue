<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import {T_SHIRT_CUTS, T_SHIRT_SIZES} from '@/volunteers/rosterConstants'
import {defaultRosterDetail, type RosterDetail} from '@/volunteers/rosterTypes'
import {
  photoConsentStatusClass,
  photoConsentStatusForVolunteer,
} from '@/utils/photoConsentStatus'

type FormField = {
  key: string
  label: string
  kind?: string
  type?: string
  editor?: string
  field_key?: string
  options?: Array<{value: string; label: string}>
}

type MealOption = {value: string; label: string}

type LookupPayload = {
  person: {first_name: string; last_name: string; mobile: string | null}
  detail: RosterDetail
  custom: Record<string, string | number | boolean | null>
  meal_options: MealOption[]
  fields: FormField[]
}

const props = defineProps<{
  step: 'email' | 'otp' | 'data' | 'done'
  email: string
  slug: string
}>()

const emit = defineEmits<{
  'update:email': [value: string]
  'update:step': [value: 'email' | 'otp' | 'data' | 'done']
  cancel: []
}>()

const otpCode = ref('')
const otpError = ref('')
const lookupLoading = ref(false)
const lookupError = ref('')
const lookupPayload = ref<LookupPayload | null>(null)
const saving = ref(false)
const saveError = ref('')

const personDraft = ref({first_name: '', last_name: '', mobile: '', organization: ''})
const detailDraft = ref<RosterDetail>(defaultRosterDetail())
const customDraft = ref<Record<string, string | number | boolean | null>>({})

const emailModel = computed({
  get: () => props.email,
  set: (value: string) => emit('update:email', value),
})

const photoStatus = computed(() => photoConsentStatusForVolunteer(detailDraft.value.photo_consent))

function proceedFromEmail() {
  const trimmed = props.email.trim()
  if (!trimmed) return
  emit('update:email', trimmed)
  otpError.value = ''
  otpCode.value = ''
  emit('update:step', 'otp')
}

function verifyOtp() {
  const code = otpCode.value.trim()
  if (code === '007008') {
    otpError.value = ''
    emit('update:step', 'data')
    return
  }
  otpError.value = 'Ungültiger Code. Bitte erneut versuchen.'
}

async function loadLookup() {
  if (!props.slug || !props.email.trim()) return
  lookupLoading.value = true
  lookupError.value = ''
  lookupPayload.value = null
  try {
    const {data} = await axios.get(`/public-volunteer-form/${props.slug}/lookup`, {
      params: {email: props.email.trim()},
    })
    lookupPayload.value = data
    personDraft.value = {
      first_name: data.person.first_name ?? '',
      last_name: data.person.last_name ?? '',
      mobile: data.person.mobile ?? '',
      organization: data.person.organization ?? '',
    }
    detailDraft.value = {...defaultRosterDetail(), ...data.detail}
    customDraft.value = {...data.custom}
  } catch {
    lookupError.value = 'Diese E-Mail ist nicht auf der Helfer:innenliste dieser Veranstaltung.'
  } finally {
    lookupLoading.value = false
  }
}

function customValue(fieldKey: string) {
  return customDraft.value[fieldKey] ?? null
}

function setCustomValue(fieldKey: string, value: string | number | boolean | null) {
  customDraft.value[fieldKey] = value
}

function setCustomBoolean(fieldKey: string, value: boolean | null) {
  setCustomValue(fieldKey, value)
}

async function submitForm() {
  if (!props.slug || !props.email.trim() || saving.value) return
  saving.value = true
  saveError.value = ''
  const {photo_consent: _photoConsent, ...detailPayload} = detailDraft.value
  const customPayload: Record<string, string | number | boolean | null> = {}
  for (const field of lookupPayload.value?.fields ?? []) {
    if (field.kind === 'custom' && field.field_key) {
      customPayload[field.field_key] = customDraft.value[field.field_key] ?? null
    }
  }
  try {
    await axios.post(`/public-volunteer-form/${props.slug}/save`, {
      email: props.email.trim(),
      person: personDraft.value,
      detail: detailPayload,
      custom: customPayload,
    })
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
    if (step === 'data') {
      void loadLookup()
      return
    }
    if (step === 'done') {
      window.scrollTo({top: 0, behavior: 'smooth'})
    }
  },
  {immediate: true},
)
</script>

<template>
  <section class="glass-card liquid-surface-inner pe-section vol-public-form">
    <header v-if="step !== 'done'" class="vol-public-form__head">
      <h2 class="glass-card__title">Dateneingabe für Helfer:innen</h2>
    </header>

    <div v-if="step === 'email'" class="vol-public-form__step">
      <label class="vol-public-form__label" for="vol-form-email">E-Mail</label>
      <input
          id="vol-form-email"
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
        Wenn diese E-Mail für diese Veranstaltung als Helfer:in registriert ist, erhalten Sie einen Code per E-Mail. Bitte geben Sie den Code ein.
      </p>
      <label class="vol-public-form__label" for="vol-form-otp">Code</label>
      <input
          id="vol-form-otp"
          v-model="otpCode"
          type="text"
          inputmode="numeric"
          maxlength="6"
          class="glass-input vol-public-form__otp"
          autocomplete="one-time-code"
          placeholder="000000"
      >
      <p v-if="otpError" class="vol-public-form__error">{{ otpError }}</p>
      <div class="vol-public-form__actions vol-public-form__actions--otp">
        <button type="button" class="glass-btn-accent" @click="verifyOtp">
          Bestätigen
        </button>
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

      <template v-else-if="lookupPayload">
        <div class="vol-public-form__fields">
          <div class="vol-public-form__row">
            <label class="vol-public-form__label" for="vol-form-first">Vorname</label>
            <input id="vol-form-first" v-model="personDraft.first_name" type="text" class="glass-input">
          </div>
          <div class="vol-public-form__row">
            <label class="vol-public-form__label" for="vol-form-last">Name</label>
            <input id="vol-form-last" v-model="personDraft.last_name" type="text" class="glass-input">
          </div>
          <div class="vol-public-form__row">
            <label class="vol-public-form__label" for="vol-form-mobile">Mobil</label>
            <input id="vol-form-mobile" v-model="personDraft.mobile" type="tel" class="glass-input">
          </div>
          <div class="vol-public-form__row">
            <label class="vol-public-form__label" for="vol-form-org">Organisation</label>
            <input id="vol-form-org" v-model="personDraft.organization" type="text" class="glass-input">
          </div>

          <template v-for="field in lookupPayload.fields" :key="field.key">
            <div
                v-if="field.key === 'photo_consent'"
                class="photo-consent-banner"
                :class="photoConsentStatusClass(photoStatus.status)"
                role="status"
            >
              <i class="bi bi-camera photo-consent-banner__icon" aria-hidden="true"/>
              <p class="photo-consent-banner__text">{{ photoStatus.selfServiceMessage }}</p>
            </div>

            <template v-else-if="field.editor === 't_shirt'">
              <div class="vol-public-form__row">
                <label class="vol-public-form__label" for="vol-form-shirt-cut">T-Shirt Schnitt</label>
                <select id="vol-form-shirt-cut" v-model="detailDraft.t_shirt_cut" class="glass-input select-input">
                  <option :value="null">?</option>
                  <option v-for="cut in T_SHIRT_CUTS" :key="cut.value" :value="cut.value">{{ cut.label }}</option>
                </select>
              </div>
              <div class="vol-public-form__row">
                <label class="vol-public-form__label" for="vol-form-shirt-size">T-Shirt Größe</label>
                <select id="vol-form-shirt-size" v-model="detailDraft.t_shirt_size" class="glass-input select-input">
                  <option :value="null">?</option>
                  <option v-for="size in T_SHIRT_SIZES" :key="size" :value="size">{{ size }}</option>
                </select>
              </div>
            </template>

            <div v-else-if="field.editor === 'meal'" class="vol-public-form__row">
              <label class="vol-public-form__label" :for="`vol-form-meal-${field.key}`">Essen</label>
              <select :id="`vol-form-meal-${field.key}`" v-model="detailDraft.meal" class="glass-input select-input">
                <option :value="null">?</option>
                <option v-for="meal in lookupPayload.meal_options" :key="meal.value" :value="meal.value">
                  {{ meal.label }}
                </option>
              </select>
            </div>

            <div v-else-if="field.kind === 'custom' && field.type === 'text'" class="vol-public-form__row">
              <label class="vol-public-form__label" :for="`vol-form-custom-${field.field_key}`">{{ field.label }}</label>
              <input
                  :id="`vol-form-custom-${field.field_key}`"
                  :value="(customValue(field.field_key ?? '') as string) ?? ''"
                  type="text"
                  class="glass-input"
                  @input="setCustomValue(field.field_key ?? '', ($event.target as HTMLInputElement).value || null)"
              >
            </div>

            <div v-else-if="field.kind === 'custom' && field.type === 'number'" class="vol-public-form__row">
              <label class="vol-public-form__label" :for="`vol-form-custom-${field.field_key}`">{{ field.label }}</label>
              <input
                  :id="`vol-form-custom-${field.field_key}`"
                  :value="customValue(field.field_key ?? '') ?? ''"
                  type="number"
                  class="glass-input"
                  @input="setCustomValue(field.field_key ?? '', ($event.target as HTMLInputElement).value || null)"
              >
            </div>

            <div v-else-if="field.kind === 'custom' && field.type === 'select'" class="vol-public-form__row">
              <label class="vol-public-form__label" :for="`vol-form-custom-${field.field_key}`">{{ field.label }}</label>
              <select
                  :id="`vol-form-custom-${field.field_key}`"
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
                    @click="setCustomBoolean(field.field_key ?? '', null)"
                >
                  ?
                </button>
                <button
                    type="button"
                    class="glass-segment__btn"
                    :class="{'glass-segment__btn--active': customValue(field.field_key ?? '') === true}"
                    @click="setCustomBoolean(field.field_key ?? '', true)"
                >
                  Ja
                </button>
                <button
                    type="button"
                    class="glass-segment__btn"
                    :class="{'glass-segment__btn--active': customValue(field.field_key ?? '') === false}"
                    @click="setCustomBoolean(field.field_key ?? '', false)"
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
          <button type="button" class="glass-btn-accent" :disabled="saving" @click="submitForm">
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

.vol-public-form__reminder {
  grid-column: 2;
  margin: 0;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.vol-public-form__textarea {
  min-height: 4.5rem;
  resize: vertical;
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

@media (max-width: 640px) {
  .vol-public-form__row {
    grid-template-columns: 1fr;
  }

  .vol-public-form__label {
    padding-top: 0;
  }

  .vol-public-form__reminder {
    grid-column: 1;
  }
}
</style>

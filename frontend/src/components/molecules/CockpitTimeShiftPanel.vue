<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import type {AxiosInstance} from 'axios'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'

defineOptions({name: 'CockpitTimeShiftPanel'})

const MIN_MINUTES = 5
const MAX_MINUTES = 60
const STEP_MINUTES = 5

const props = defineProps<{
  slug: string
  http: AxiosInstance
}>()

const minutes = ref(MIN_MINUTES)
const endOfDayTime = ref<string | null>(null)
const locked = ref(false)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const result = ref('')
const showConfirm = ref(false)

const canDecrease = computed(() => minutes.value > MIN_MINUTES)
const canIncrease = computed(() => minutes.value < MAX_MINUTES)
const confirmTitle = computed(() => `Wirklich alles um ${minutes.value} Minuten verschieben?`)

function decrease() {
  if (canDecrease.value) minutes.value -= STEP_MINUTES
}

function increase() {
  if (canIncrease.value) minutes.value += STEP_MINUTES
}

async function loadState() {
  loading.value = true
  error.value = ''
  try {
    const {data} = await props.http.get(`/cockpit/${props.slug}/timeshift/bootstrap`)
    endOfDayTime.value = data.end_of_day_time ?? null
    locked.value = !!data.locked
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Zeiten konnten nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

async function applyShift() {
  showConfirm.value = false
  busy.value = true
  error.value = ''
  result.value = ''
  try {
    const {data} = await props.http.post(`/cockpit/${props.slug}/timeshift/shift`, {
      minutes: minutes.value,
    })
    endOfDayTime.value = data.end_of_day_time ?? null
    result.value = `${data.shifted_count} Aktivitäten um ${minutes.value} Minuten verschoben.`
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Verschieben fehlgeschlagen.'
  } finally {
    busy.value = false
  }
}

onMounted(loadState)
</script>

<template>
  <div class="cp-shift">
    <p class="glass-alert-warning !mb-0">Die Verschiebung kann hier nicht rückgängig gemacht werden!</p>
    <p class="cp-shift__note">Im Notfall kann der ganze Plan in FLOW neu generiert werden.</p>

    <div class="cp-shift__stepper liquid-surface-inner">
      <button
          type="button"
          class="cp-shift__step"
          :disabled="!canDecrease"
          aria-label="Weniger Minuten"
          @click="decrease"
      >
        <i class="bi bi-dash" aria-hidden="true"/>
      </button>
      <div class="cp-shift__value">
        <span class="cp-shift__number">{{ minutes }}</span>
        <span class="cp-shift__unit">Minuten</span>
      </div>
      <button
          type="button"
          class="cp-shift__step"
          :disabled="!canIncrease"
          aria-label="Mehr Minuten"
          @click="increase"
      >
        <i class="bi bi-plus" aria-hidden="true"/>
      </button>
    </div>

    <p class="cp-shift__end">
      Ende der Veranstaltung:
      <strong>{{ loading ? '…' : (endOfDayTime || '—') }}</strong>
    </p>

    <p v-if="locked" class="cp-shift__note">Der Plan ist gesperrt und kann nicht verschoben werden.</p>
    <p v-if="error" class="glass-alert-error !mb-0">{{ error }}</p>
    <p v-if="result" class="cp-shift__result">{{ result }}</p>

    <button
        type="button"
        class="glass-btn-accent cp-shift__submit"
        :disabled="busy || loading || locked"
        @click="showConfirm = true"
    >
      {{ busy ? 'Verschiebe…' : 'Verschieben' }}
    </button>

    <ConfirmationModal
        :show="showConfirm"
        type="danger"
        :title="confirmTitle"
        message="Die Verschiebung kann hier nicht rückgängig gemacht werden."
        confirm-text="Ja"
        cancel-text="Nein"
        :disable-confirm-button="busy"
        @confirm="applyShift"
        @cancel="showConfirm = false"
    />
  </div>
</template>

<style scoped>
.cp-shift {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.cp-shift__note {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.45;
  color: var(--color-text-muted);
}

.cp-shift__stepper {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 0.85rem;
  border-radius: var(--radius);
}

.cp-shift__step {
  width: 3.25rem;
  height: 3.25rem;
  flex-shrink: 0;
  border-radius: var(--radius);
  border: 1px solid var(--liquid-border);
  background: var(--liquid-tile-bg);
  color: var(--color-accent, var(--color-text));
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  cursor: pointer;
}

.cp-shift__step:active:not(:disabled) {
  opacity: 0.85;
}

.cp-shift__step:disabled {
  opacity: 0.4;
  cursor: not-allowed;
  color: var(--color-text-muted);
}

.cp-shift__value {
  display: flex;
  flex-direction: column;
  align-items: center;
  line-height: 1.15;
}

.cp-shift__number {
  font-size: 1.9rem;
  font-weight: 750;
  font-variant-numeric: tabular-nums;
  color: var(--color-text);
}

.cp-shift__unit {
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.cp-shift__end {
  margin: 0;
  font-size: 1rem;
  color: var(--color-text);
}

.cp-shift__result {
  margin: 0;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--color-text);
}

.cp-shift__submit {
  width: 100%;
}
</style>

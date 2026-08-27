<script setup lang="ts">
import {computed} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import {programLogoAlt, programLogoSrc} from '@/utils/images'
import {programDisplayName} from '@/utils/eventPrograms'

const props = defineProps<{
  parameters: any[]
}>()

const emit = defineEmits<{
  (e: 'update-param', param: {name: string; value: any}): void
}>()

const paramMapByName = computed<Record<string, any>>(
    () => Object.fromEntries(props.parameters.map((p: any) => [p.name, p]))
)

function asBool(value: unknown): boolean {
  return value === 1 || value === true || value === '1'
}

function updateByName(name: string, value: boolean) {
  emit('update-param', {name, value})
}

/** false = shared room, true = separate rooms */
const separateRooms = computed({
  get: () => asBool(paramMapByName.value['f8_separate_rooms']?.value),
  set: (val: boolean) => updateByName('f8_separate_rooms', val),
})

const roomsMode = computed<'shared' | 'separate'>({
  get: () => (separateRooms.value ? 'separate' : 'shared'),
  set: (val) => {
    separateRooms.value = val === 'separate'
  },
})

/** true = full round then flip (Policy A), false = within round (Policy B) */
const perRound = computed({
  get: () => {
    const raw = paramMapByName.value['f8_per_round']?.value
    if (raw === undefined || raw === null || raw === '') return true
    return asBool(raw)
  },
  set: (val: boolean) => updateByName('f8_per_round', val),
})

const switchMode = computed<'per_round' | 'within_round'>({
  get: () => (perRound.value ? 'per_round' : 'within_round'),
  set: (val) => {
    perRound.value = val === 'per_round'
  },
})

/** false = Challenge first, true = Future first */
const futureFirst = computed({
  get: () => asBool(paramMapByName.value['f8_future_first']?.value),
  set: (val: boolean) => updateByName('f8_future_first', val),
})

const firstMatch = computed<'challenge' | 'future8'>({
  get: () => (futureFirst.value ? 'future8' : 'challenge'),
  set: (val) => {
    futureFirst.value = val === 'future8'
  },
})

const challengeLabel = computed(() => programDisplayName('CHALLENGE') || 'Challenge')
const futureLabel = computed(() => programDisplayName('FUTURE_8') || 'Future 8+')

const switchOptions = [
  {
    value: 'per_round' as const,
    label: 'Nach einer kompletten Runde',
    hint: 'Platzhalter: Erst spielt ein Programm seine volle Runde, danach wechselt das andere. (Text folgt.)',
  },
  {
    value: 'within_round' as const,
    label: 'Innerhalb einer Runde',
    hint: 'Platzhalter: Innerhalb derselben Runde wird zwischen den Programmen gewechselt. (Text folgt.)',
  },
]
</script>

<template>
  <section class="integration-tile glass-card liquid-surface-inner">
    <header class="integration-tile__header">
      <div class="integration-tile__logos">
        <img
            :src="programLogoSrc('CHALLENGE')"
            :alt="programLogoAlt('CHALLENGE')"
            class="integration-tile__logo"
        >
        <img
            :src="programLogoSrc('FUTURE_8')"
            :alt="programLogoAlt('FUTURE_8')"
            class="integration-tile__logo"
        >
      </div>
      <h2 class="glass-card__title !mb-0">
        {{ challengeLabel }} und {{ futureLabel }}
      </h2>
    </header>

    <div class="integration-tile__body glass-settings-block">
      <div class="flex flex-col gap-1.5">
        <span class="glass-settings-label">Wo finden die Matches statt?</span>
        <RadioGroup v-model="roomsMode" class="flex gap-1.5 flex-wrap">
          <RadioGroupOption
              v-for="opt in [
                {value: 'shared', label: 'Zusammen in einem Raum'},
                {value: 'separate', label: 'In getrennten Räumen'},
              ]"
              :key="'rooms_' + opt.value"
              v-slot="{ checked }"
              :value="opt.value"
              as="template"
          >
            <button
                type="button"
                class="glass-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
                :class="checked ? 'glass-choice--active' : ''"
                @click="roomsMode = opt.value"
            >
              {{ opt.label }}
            </button>
          </RadioGroupOption>
        </RadioGroup>
      </div>

      <div v-if="roomsMode === 'shared'" class="flex flex-col gap-1.5">
        <span class="glass-settings-label">Wann wird zwischen den Programmen gewechselt?</span>
        <RadioGroup v-model="switchMode" class="flex flex-col gap-2">
          <RadioGroupOption
              v-for="opt in switchOptions"
              :key="'switch_' + opt.value"
              v-slot="{ checked }"
              :value="opt.value"
              as="template"
          >
            <button
                type="button"
                class="integration-choice"
                :class="checked ? 'integration-choice--active' : ''"
                @click="switchMode = opt.value"
            >
              <span class="glass-choice whitespace-nowrap shrink-0" :class="checked ? 'glass-choice--active' : ''">
                {{ opt.label }}
              </span>
              <span class="integration-choice__hint">{{ opt.hint }}</span>
            </button>
          </RadioGroupOption>
        </RadioGroup>
      </div>

      <div class="flex flex-col gap-1.5">
        <span class="glass-settings-label">Wer hat das erste Match des Tages?</span>
        <RadioGroup v-model="firstMatch" class="flex gap-1.5 flex-wrap">
          <RadioGroupOption
              v-for="opt in [
                {value: 'challenge', label: challengeLabel, logo: 'CHALLENGE'},
                {value: 'future8', label: futureLabel, logo: 'FUTURE_8'},
              ]"
              :key="'first_' + opt.value"
              v-slot="{ checked }"
              :value="opt.value"
              as="template"
          >
            <button
                type="button"
                class="glass-choice inline-flex items-center gap-1.5 whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
                :class="checked ? 'glass-choice--active' : ''"
                @click="firstMatch = opt.value"
            >
              <img
                  :src="programLogoSrc(opt.logo)"
                  :alt="programLogoAlt(opt.logo)"
                  class="w-5 h-5 object-contain"
              >
              {{ opt.label }}
            </button>
          </RadioGroupOption>
        </RadioGroup>
      </div>
    </div>
  </section>
</template>

<style scoped>
.integration-tile {
  padding: 0.7rem 1.1rem 0.85rem;
}

.integration-tile__header {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0 0 0.45rem;
  margin-bottom: 0;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
}

.integration-tile__logos {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-shrink: 0;
}

.integration-tile__logo {
  width: 2.25rem;
  height: 2.25rem;
  object-fit: contain;
}

.integration-tile__body {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 0.65rem 0 0.1rem;
}

.integration-choice {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.35rem;
  width: 100%;
  margin: 0;
  padding: 0.55rem 0.65rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 28%, transparent);
  border-radius: var(--radius);
  background: color-mix(in srgb, var(--color-bg) 70%, transparent);
  text-align: left;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}

.integration-choice:hover {
  border-color: color-mix(in srgb, var(--color-border-strong) 55%, transparent);
}

.integration-choice--active {
  border-color: color-mix(in srgb, var(--color-accent) 45%, var(--color-border));
  background: color-mix(in srgb, var(--color-accent) 8%, #fff);
}

.integration-choice__hint {
  font-size: 0.8rem;
  line-height: 1.35;
  color: var(--color-text-muted);
}

@media (min-width: 768px) {
  .integration-tile {
    padding: 0.8rem 1.25rem 0.95rem;
  }
}
</style>

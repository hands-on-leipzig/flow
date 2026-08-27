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

const roomsParam = computed(() => paramMapByName.value['g_separate_rooms'])
const switchParam = computed(() => paramMapByName.value['g_per_round'])
const firstParam = computed(() => paramMapByName.value['g_future_first'])

/** false = shared room, true = separate rooms */
const separateRooms = computed({
  get: () => asBool(roomsParam.value?.value),
  set: (val: boolean) => updateByName('g_separate_rooms', val),
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
    const raw = switchParam.value?.value
    if (raw === undefined || raw === null || raw === '') return true
    return asBool(raw)
  },
  set: (val: boolean) => updateByName('g_per_round', val),
})

const switchMode = computed<'per_round' | 'within_round'>({
  get: () => (perRound.value ? 'per_round' : 'within_round'),
  set: (val) => {
    perRound.value = val === 'per_round'
  },
})

/** false = Challenge first, true = Future first */
const futureFirst = computed({
  get: () => asBool(firstParam.value?.value),
  set: (val: boolean) => updateByName('g_future_first', val),
})

const firstMatch = computed<'challenge' | 'future8'>({
  get: () => (futureFirst.value ? 'future8' : 'challenge'),
  set: (val) => {
    futureFirst.value = val === 'future8'
  },
})

const challengeLabel = computed(() => programDisplayName('CHALLENGE') || 'Challenge')
const futureLabel = computed(() => programDisplayName('FUTURE_8') || 'Future 8+')
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
      <!-- Rooms (outer): shared choice → nested switch → separate choice -->
      <div v-if="roomsParam" class="flex flex-col gap-1.5">
        <span class="glass-settings-label">{{ roomsParam.ui_label }}</span>
        <p v-if="roomsParam.ui_description" class="integration-desc">
          {{ roomsParam.ui_description }}
        </p>
        <RadioGroup v-model="roomsMode" class="flex flex-col gap-2">
          <RadioGroupOption
              v-slot="{ checked }"
              value="shared"
              as="template"
          >
            <button
                type="button"
                class="glass-choice self-start whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
                :class="checked ? 'glass-choice--active' : ''"
                @click="roomsMode = 'shared'"
            >
              Zusammen in einem Raum
            </button>
          </RadioGroupOption>

          <div
              v-if="roomsMode === 'shared' && switchParam"
              class="integration-nested"
          >
            <span class="glass-settings-label">{{ switchParam.ui_label }}</span>
            <p v-if="switchParam.ui_description" class="integration-desc">
              {{ switchParam.ui_description }}
            </p>
            <RadioGroup v-model="switchMode" class="flex gap-1.5 flex-wrap">
              <RadioGroupOption
                  v-for="opt in [
                    {value: 'per_round', label: 'Nach einer kompletten Runde'},
                    {value: 'within_round', label: 'Innerhalb einer Runde'},
                  ]"
                  :key="'switch_' + opt.value"
                  v-slot="{ checked }"
                  :value="opt.value"
                  as="template"
              >
                <button
                    type="button"
                    class="glass-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
                    :class="checked ? 'glass-choice--active' : ''"
                    @click="switchMode = opt.value"
                >
                  {{ opt.label }}
                </button>
              </RadioGroupOption>
            </RadioGroup>
          </div>

          <RadioGroupOption
              v-slot="{ checked }"
              value="separate"
              as="template"
          >
            <button
                type="button"
                class="glass-choice self-start whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
                :class="checked ? 'glass-choice--active' : ''"
                @click="roomsMode = 'separate'"
            >
              In getrennten Räumen
            </button>
          </RadioGroupOption>
        </RadioGroup>
      </div>

      <!-- First match -->
      <div v-if="firstParam" class="flex flex-col gap-1.5">
        <span class="glass-settings-label">{{ firstParam.ui_label }}</span>
        <p v-if="firstParam.ui_description" class="integration-desc">
          {{ firstParam.ui_description }}
        </p>
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

.integration-desc {
  margin: 0;
  font-size: 0.8rem;
  line-height: 1.35;
  color: var(--color-text-muted);
}

.integration-nested {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-top: 0.35rem;
  margin-left: 0.35rem;
  padding: 0.7rem 0.75rem 0.75rem;
  border-left: 3px solid color-mix(in srgb, var(--color-accent) 45%, transparent);
  border-radius: 0 var(--radius) var(--radius) 0;
  background: color-mix(in srgb, var(--color-bg-muted) 35%, transparent);
}

@media (min-width: 768px) {
  .integration-tile {
    padding: 0.8rem 1.25rem 0.95rem;
  }
}
</style>

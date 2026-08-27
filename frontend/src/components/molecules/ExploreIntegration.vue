<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import {programLogoAlt, programLogoSrc} from '@/utils/images'
import {eventPrograms, programDisplayName, programId} from '@/utils/eventPrograms'
import {useEventStore} from '@/stores/event'

const EXPLORE_ID = 2

/** Mirrors backend App\Enums\ExploreMode. */
const ExploreMode = {
  NONE: 0,
  INTEGRATED_MORNING: 1,
  INTEGRATED_AFTERNOON: 2,
  DECOUPLED_MORNING: 3,
  DECOUPLED_AFTERNOON: 4,
  DECOUPLED_BOTH: 5,
  HYBRID_BOTH: 8,
} as const

const props = defineProps<{
  parameters: any[]
}>()

const emit = defineEmits<{
  (e: 'update-param', param: {name: string; value: any}): void
}>()

const eventStore = useEventStore()

const paramMapByName = computed<Record<string, any>>(
    () => Object.fromEntries(props.parameters.map((p: any) => [p.name, p]))
)

function updateByName(name: string, value: any) {
  emit('update-param', {name, value})
}

const eTeams = computed(() => Number(paramMapByName.value['e_teams']?.value || 0))
const e1Teams = computed(() => Number(paramMapByName.value['e1_teams']?.value || 0))
const e2Teams = computed(() => Number(paramMapByName.value['e2_teams']?.value || 0))

const hasOtherPrograms = computed(() =>
    eventPrograms(eventStore.selectedEvent).some((program) => programId(program) !== EXPLORE_ID)
)

function connectionFromMode(mode: number): 'integrated' | 'independent' {
  if (
      mode === ExploreMode.DECOUPLED_MORNING
      || mode === ExploreMode.DECOUPLED_AFTERNOON
      || mode === ExploreMode.DECOUPLED_BOTH
  ) {
    return 'independent'
  }
  return 'integrated'
}

const connection = ref<'integrated' | 'independent' | null>(null)

const connectionProxy = computed<'integrated' | 'independent'>({
  get: () => connection.value
      ?? connectionFromMode(Number(paramMapByName.value['e_mode']?.value || 0)),
  set: (val) => {
    connection.value = val
  },
})

function computeEMode(): number {
  const e1 = e1Teams.value
  const e2 = e2Teams.value
  if (eTeams.value <= 0 || (e1 <= 0 && e2 <= 0)) return ExploreMode.NONE

  const both = e1 > 0 && e2 > 0
  const morning = e1 > 0 && e2 <= 0
  const integrated = hasOtherPrograms.value && connectionProxy.value === 'integrated'

  if (integrated) {
    if (both) return ExploreMode.HYBRID_BOTH
    if (morning) return ExploreMode.INTEGRATED_MORNING
    return ExploreMode.INTEGRATED_AFTERNOON
  }

  if (both) return ExploreMode.DECOUPLED_BOTH
  if (morning) return ExploreMode.DECOUPLED_MORNING
  return ExploreMode.DECOUPLED_AFTERNOON
}

watch(
    [eTeams, e1Teams, e2Teams, hasOtherPrograms, connectionProxy],
    () => {
      if (!paramMapByName.value['e_mode']) return
      const next = computeEMode()
      if (next !== Number(paramMapByName.value['e_mode']?.value || 0)) {
        updateByName('e_mode', next)
      }
    },
    {immediate: true}
)

const exploreLabel = computed(() => programDisplayName('EXPLORE') || 'Explore')
</script>

<template>
  <section class="integration-tile glass-card liquid-surface-inner">
    <header class="integration-tile__header">
      <div class="integration-tile__logos">
        <img
            :src="programLogoSrc('EXPLORE')"
            :alt="programLogoAlt('EXPLORE')"
            class="integration-tile__logo"
        >
      </div>
      <h2 class="glass-card__title !mb-0">
        {{ exploreLabel }} und andere Programme
      </h2>
    </header>

    <div class="integration-tile__body glass-settings-block">
      <div class="flex flex-col gap-1.5">
        <span class="glass-settings-label">Verbindung mit anderen Programmen</span>
        <RadioGroup v-model="connectionProxy" class="flex gap-1.5 flex-wrap">
          <RadioGroupOption
              v-for="opt in [
                {value: 'integrated', label: 'Integriert'},
                {value: 'independent', label: 'unabhängig'},
              ]"
              :key="'explore_connection_' + opt.value"
              v-slot="{ checked }"
              :value="opt.value"
              as="template"
          >
            <button
                type="button"
                class="glass-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
                :class="checked ? 'glass-choice--active' : ''"
                @click="connectionProxy = opt.value"
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

@media (min-width: 768px) {
  .integration-tile {
    padding: 0.8rem 1.25rem 0.95rem;
  }
}
</style>

<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import ParameterField from '@/components/molecules/ParameterField.vue'
import {programLogoAlt, programLogoSrc} from '@/utils/images'
import {eventPrograms} from '@/utils/eventPrograms'
import {useEventStore} from '@/stores/event'

const props = defineProps<{
  parameters: any[]
  visibilityMap: Record<string, boolean>
  disabledMap: Record<string, boolean>
  showExplore?: boolean
  showChallenge?: boolean
}>()

const emit = defineEmits<{
  (e: 'update-param', param: any): void
}>()

type Prefix = 'g' | 'e1' | 'e2' | 'c'
type TimeKey = 'start_opening' | 'duration_opening' | 'duration_awards'
type Logo = { src: string; alt: string }

const eventStore = useEventStore()
const visibilityMatrix = ref<Record<string, any>>({})
const overrideEMode = ref<number | null>(null)
const overrideCMode = ref<number | null>(null)

const byName = computed<Record<string, any>>(
    () => Object.fromEntries(props.parameters.map((p: any) => [p.name, p]))
)

const eMode = computed({
  get: () => overrideEMode.value ?? Number(byName.value['e_mode']?.value ?? 0),
  set: (value) => {
    overrideEMode.value = Number(value)
  },
})

const cMode = computed({
  get: () => overrideCMode.value ?? Number(byName.value['c_mode']?.value ?? 0),
  set: (value) => {
    overrideCMode.value = Number(value)
  },
})

const currentVisibility = computed(() => {
  const key = `e${eMode.value}_c${cMode.value}`
  return visibilityMatrix.value[key]?.fields || {}
})

const columnLabels: Record<Prefix, string> = {
  g: 'Gemeinsam',
  e1: 'Explore Vormittag',
  e2: 'Explore Nachmittag',
  c: 'Challenge',
}

const columnIcons: Record<Exclude<Prefix, 'g'>, string> = {
  e1: programLogoSrc('EXPLORE'),
  e2: programLogoSrc('EXPLORE'),
  c: programLogoSrc('CHALLENGE'),
}

const gemeinsamLogos = computed<Logo[]>(() => {
  const programs = eventPrograms(eventStore.selectedEvent)
  const explore = programs.find((program) => String(program.name || '').toUpperCase() === 'EXPLORE')
  const others = programs.filter((program) => String(program.name || '').toUpperCase() !== 'EXPLORE')
  return [explore ?? {name: 'EXPLORE'}, ...others].map((program) => ({
    src: programLogoSrc(program),
    alt: programLogoAlt(program),
  }))
})

function logosFor(prefix: Prefix): Logo[] {
  if (prefix === 'g') return gemeinsamLogos.value
  return [{src: columnIcons[prefix], alt: columnLabels[prefix]}]
}

const allPrefixes: Prefix[] = ['g', 'e1', 'e2', 'c']

function getParam(name: string) {
  return byName.value[name] ?? null
}

function cellParam(prefix: Prefix, key: TimeKey) {
  return getParam(`${prefix}_${key}`)
}

function isFieldEditable(prefix: Prefix, key: TimeKey): boolean {
  if (prefix === 'c' && cMode.value === 0) return false
  if ((prefix === 'e1' || prefix === 'e2') && eMode.value === 0) return false

  const fieldName = `${prefix}_${key}`
  return currentVisibility.value[fieldName]?.editable || false
}

function showCell(prefix: Prefix, key: TimeKey): boolean {
  const param = cellParam(prefix, key)
  return !!(isFieldEditable(prefix, key) && param && props.visibilityMap[param.id])
}

function prefixesFor(key: TimeKey): Prefix[] {
  return allPrefixes.filter((prefix) => showCell(prefix, key))
}

const openingStartPrefixes = computed(() => prefixesFor('start_opening'))
const openingDurationPrefixes = computed(() => prefixesFor('duration_opening'))
const awardsDurationPrefixes = computed(() => prefixesFor('duration_awards'))

const showOpening = computed(() =>
    openingStartPrefixes.value.length > 0 || openingDurationPrefixes.value.length > 0
)
const showAwards = computed(() => awardsDurationPrefixes.value.length > 0)

function updateParam(p: any) {
  emit('update-param', p)
}

onMounted(async () => {
  try {
    const response = await axios.get('/parameters/visibility')
    visibilityMatrix.value = response.data.matrix
  } catch (error) {
    console.error('Failed to fetch visibility matrix:', error)
  }
})
</script>

<template>
  <div class="flex flex-col gap-[1.15rem]">
    <div class="glass-settings-row">
      <label class="inline-flex items-center gap-2">
        <span class="glass-settings-hint !not-italic">e_mode</span>
        <input
            v-model.number="eMode"
            class="glass-input glass-input--sm liquid-surface-control w-16 text-sm"
            min="0"
            type="number"
        >
      </label>
      <label class="inline-flex items-center gap-2">
        <span class="glass-settings-hint !not-italic">c_mode</span>
        <input
            v-model.number="cMode"
            class="glass-input glass-input--sm liquid-surface-control w-16 text-sm"
            min="0"
            type="number"
        >
      </label>
    </div>

    <section v-if="showOpening" class="times-card glass-card liquid-surface-inner">
      <h2 class="glass-card__title">Eröffnung</h2>
      <div class="glass-settings-block">
        <div v-if="openingStartPrefixes.length" class="flex flex-col gap-3">
          <h3 class="glass-settings-label">Start</h3>
          <div
              v-for="prefix in openingStartPrefixes"
              :key="`start_${prefix}`"
              class="glass-settings-row"
          >
            <div class="inline-flex items-center gap-2 min-w-[11rem]">
              <img
                  v-for="logo in logosFor(prefix)"
                  :key="logo.src"
                  :src="logo.src"
                  :alt="logo.alt"
                  class="w-8 h-8 flex-shrink-0 object-contain"
              >
              <span class="text-sm font-medium text-[var(--color-text-muted)]">{{ columnLabels[prefix] }}</span>
            </div>
            <ParameterField
                :disabled="disabledMap[cellParam(prefix, 'start_opening').id]"
                :horizontal="false"
                :with-label="true"
                :compact="true"
                :param="cellParam(prefix, 'start_opening')"
                @update="updateParam"
            />
          </div>
        </div>

        <div v-if="openingDurationPrefixes.length" class="flex flex-col gap-3">
          <h3 class="glass-settings-label">Dauer</h3>
          <div
              v-for="prefix in openingDurationPrefixes"
              :key="`duration_${prefix}`"
              class="glass-settings-row"
          >
            <div class="inline-flex items-center gap-2 min-w-[11rem]">
              <img
                  v-for="logo in logosFor(prefix)"
                  :key="logo.src"
                  :src="logo.src"
                  :alt="logo.alt"
                  class="w-8 h-8 flex-shrink-0 object-contain"
              >
              <span class="text-sm font-medium text-[var(--color-text-muted)]">{{ columnLabels[prefix] }}</span>
            </div>
            <ParameterField
                :disabled="disabledMap[cellParam(prefix, 'duration_opening').id]"
                :horizontal="false"
                :with-label="true"
                :compact="true"
                :param="cellParam(prefix, 'duration_opening')"
                @update="updateParam"
            />
          </div>
        </div>
      </div>
    </section>

    <section v-if="showAwards" class="times-card glass-card liquid-surface-inner">
      <h2 class="glass-card__title">Preisverleihung</h2>
      <div class="flex flex-col gap-3">
        <div
            v-for="prefix in awardsDurationPrefixes"
            :key="`awards_${prefix}`"
            class="glass-settings-row"
        >
          <div class="inline-flex items-center gap-2 min-w-[11rem]">
            <img
                v-for="logo in logosFor(prefix)"
                :key="logo.src"
                :src="logo.src"
                :alt="logo.alt"
                class="w-8 h-8 flex-shrink-0 object-contain"
            >
            <span class="text-sm font-medium text-[var(--color-text-muted)]">{{ columnLabels[prefix] }}</span>
          </div>
          <ParameterField
              :disabled="disabledMap[cellParam(prefix, 'duration_awards').id]"
              :horizontal="false"
              :with-label="true"
              :compact="true"
              :param="cellParam(prefix, 'duration_awards')"
              @update="updateParam"
          />
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.times-card {
  overflow: visible;
}
</style>

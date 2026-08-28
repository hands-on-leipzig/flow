<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import ParameterField from '@/components/molecules/ParameterField.vue'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {eventPrograms, programDisplayName, resolveProgramRef} from '@/utils/eventPrograms'
import {useEventStore} from '@/stores/event'
import type {EventProgramRef} from '@/utils/eventPrograms'

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

type Prefix = 'g' | 'e1' | 'e2' | 'c' | 'f8'
type TimeKey = 'start_opening' | 'duration_opening' | 'duration_awards'

const eventStore = useEventStore()
const visibilityMatrix = ref<Record<string, any>>({})

const byName = computed<Record<string, any>>(
    () => Object.fromEntries(props.parameters.map((p: any) => [p.name, p]))
)

const eMode = computed(() => Number(byName.value['e_mode']?.value ?? 0))
const cMode = computed(() => Number(byName.value['c_mode']?.value ?? 0))
const f8Mode = computed(() => Number(byName.value['f8_mode']?.value ?? 0))

const currentVisibility = computed(() => {
  const key = `e${eMode.value}_c${cMode.value}_f8${f8Mode.value}`
  const fields = visibilityMatrix.value[key]?.fields
  if (fields) return fields
  if (f8Mode.value === 0) {
    return visibilityMatrix.value[`e${eMode.value}_c${cMode.value}`]?.fields || {}
  }
  return {}
})

const columnLabels = computed<Record<Prefix, string>>(() => ({
  g: 'Gemeinsam',
  e1: 'Explore Vormittag',
  e2: 'Explore Nachmittag',
  c: programDisplayName('CHALLENGE') || 'Challenge',
  f8: programDisplayName('FUTURE_8') || 'Future 8+',
}))

const columnPrograms = computed<Record<Exclude<Prefix, 'g'>, EventProgramRef | null>>(() => {
  const event = eventStore.selectedEvent
  return {
    e1: resolveProgramRef(event, 'EXPLORE'),
    e2: resolveProgramRef(event, 'EXPLORE'),
    c: resolveProgramRef(event, 'CHALLENGE'),
    f8: resolveProgramRef(event, 'FUTURE_8'),
  }
})

const gemeinsamPrograms = computed(() => eventPrograms(eventStore.selectedEvent))

function programsFor(prefix: Prefix): EventProgramRef[] {
  if (prefix === 'g') return gemeinsamPrograms.value
  const program = columnPrograms.value[prefix]
  return program ? [program] : []
}

const allPrefixes: Prefix[] = ['g', 'e1', 'e2', 'c', 'f8']

function getParam(name: string) {
  return byName.value[name] ?? null
}

function cellParam(prefix: Prefix, key: TimeKey) {
  return getParam(`${prefix}_${key}`)
}

function isFieldEditable(prefix: Prefix, key: TimeKey): boolean {
  if (prefix === 'c' && cMode.value === 0) return false
  if (prefix === 'f8' && f8Mode.value === 0) return false
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

const openingPrefixes = computed(() =>
    allPrefixes.filter((prefix) => showCell(prefix, 'start_opening') || showCell(prefix, 'duration_opening'))
)
const awardsDurationPrefixes = computed(() => prefixesFor('duration_awards'))

const showOpening = computed(() => openingPrefixes.value.length > 0)
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
    <section v-if="showOpening" class="times-card glass-card liquid-surface-inner">
      <h2 class="glass-card__title">Eröffnung - Start und Dauer</h2>
      <div class="flex flex-col gap-3">
        <div
            v-for="prefix in openingPrefixes"
            :key="`opening_${prefix}`"
            class="glass-settings-row flex-nowrap"
        >
          <div class="inline-flex items-center gap-2 min-w-[11rem] shrink-0">
            <ProgramLogo
                v-for="program in programsFor(prefix)"
                :key="`${prefix}-${program.first_program ?? program.name}`"
                :program="program"
                size="md"
            />
            <span class="text-sm font-medium text-[var(--color-text-muted)]">{{ columnLabels[prefix] }}</span>
          </div>
          <ParameterField
              v-if="showCell(prefix, 'start_opening')"
              :disabled="disabledMap[cellParam(prefix, 'start_opening').id]"
              :horizontal="false"
              :with-label="false"
              :compact="true"
              :show-info="false"
              :param="cellParam(prefix, 'start_opening')"
              @update="updateParam"
          />
          <ParameterField
              v-if="showCell(prefix, 'duration_opening')"
              :disabled="disabledMap[cellParam(prefix, 'duration_opening').id]"
              :horizontal="false"
              :with-label="false"
              :compact="true"
              :param="cellParam(prefix, 'duration_opening')"
              @update="updateParam"
          />
        </div>
      </div>
    </section>

    <section v-if="showAwards" class="times-card glass-card liquid-surface-inner">
      <h2 class="glass-card__title">Preisverleihung - Dauer</h2>
      <div class="flex flex-col gap-3">
        <div
            v-for="prefix in awardsDurationPrefixes"
            :key="`awards_${prefix}`"
            class="glass-settings-row"
        >
          <div class="inline-flex items-center gap-2 min-w-[11rem]">
            <ProgramLogo
                v-for="program in programsFor(prefix)"
                :key="`${prefix}-${program.first_program ?? program.name}`"
                :program="program"
                size="md"
            />
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

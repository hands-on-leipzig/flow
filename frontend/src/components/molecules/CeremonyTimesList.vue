<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import ParameterField from '@/components/molecules/ParameterField.vue'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import type { Parameter } from '@/models/Parameter'

const props = defineProps<{
  planId: number | null
  parameters: Parameter[]
  disabledMap: Record<number, boolean>
  planLocked: boolean
  reloadToken: number
}>()

const emit = defineEmits<{
  (e: 'update-param', param: { name: string; value: unknown }): void
}>()

type CeremonyProgram = {
  id: number
  name: string
  display_name: string | null
  color_hex: string | null
}

type CeremonyRow = {
  activity_id: number
  code: string
  kind: 'opening' | 'awards'
  label: string
  explore_group: number | null
  start: string
  duration_min: number
  programs: CeremonyProgram[]
  start_editable: boolean
  start_parameter_id: number | null
  duration_parameter_id: number
  duration_value: number
}

const catalogIncomplete = ref(false)
const errorMessage = ref<string | null>(null)
const ceremonies = ref<CeremonyRow[]>([])
const loading = ref(false)

const paramById = computed<Record<number, Parameter>>(() => {
  const map: Record<number, Parameter> = {}
  for (const param of props.parameters) {
    map[param.id] = param
  }
  return map
})

function formatPlanTime(start: string): string {
  if (!start) return ''
  const match = start.match(/(\d{2}:\d{2})/)
  return match?.[1] ?? start
}

function durationParam(row: CeremonyRow): Parameter | null {
  return paramById.value[row.duration_parameter_id] ?? null
}

function startParam(row: CeremonyRow): Parameter | null {
  if (!row.start_parameter_id) return null
  return paramById.value[row.start_parameter_id] ?? null
}

function rowDisabled(param: Parameter | null): boolean {
  if (!param) return true
  return props.planLocked || !!props.disabledMap[param.id]
}

function showEditableStart(row: CeremonyRow): boolean {
  return row.kind === 'opening' && row.start_editable && startParam(row) !== null
}

function updateParam(param: Parameter) {
  emit('update-param', { name: param.name, value: param.value })
}

async function loadCeremonies() {
  if (!props.planId) {
    ceremonies.value = []
    catalogIncomplete.value = false
    errorMessage.value = null
    return
  }

  loading.value = true
  try {
    const response = await axios.get(`/plans/${props.planId}/ceremony-times`)
    catalogIncomplete.value = !!response.data.catalog_incomplete
    errorMessage.value = response.data.error ?? null
    ceremonies.value = Array.isArray(response.data.ceremonies) ? response.data.ceremonies : []
  } catch (error) {
    console.error('Failed to fetch ceremony times:', error)
    catalogIncomplete.value = true
    errorMessage.value = 'Zeiten können nicht geladen werden — die Zeremonie-Konfiguration (m_ceremonies) fehlt oder ist unvollständig.'
    ceremonies.value = []
  } finally {
    loading.value = false
  }
}

onMounted(loadCeremonies)

watch(
  () => [props.planId, props.reloadToken] as const,
  () => {
    loadCeremonies()
  },
)
</script>

<template>
  <div class="flex flex-col gap-[1.15rem]">
    <p
        v-if="catalogIncomplete"
        class="glass-alert-error shrink-0 flex items-start gap-2"
    >
      <i class="bi bi-exclamation-triangle mt-0.5 shrink-0" aria-hidden="true"/>
      <span>{{ errorMessage }}</span>
    </p>

    <p v-else-if="loading && ceremonies.length === 0" class="text-sm text-[var(--color-text-muted)]">
      Zeremonien werden geladen…
    </p>

    <section
        v-for="row in ceremonies"
        :key="row.activity_id"
        class="times-card glass-card liquid-surface-inner"
    >
      <div class="times-card__header">
        <ProgramLogo
            v-for="program in row.programs"
            :key="`${row.code}-${program.id}`"
            :program="program"
            size="md"
        />
        <span class="text-sm font-medium text-[var(--color-text-muted)]">{{ row.label }}</span>
      </div>

      <div class="times-card__controls">
        <ParameterField
            v-if="showEditableStart(row)"
            :disabled="rowDisabled(startParam(row))"
            :horizontal="false"
            :with-label="false"
            :compact="true"
            :show-info="false"
            :param="startParam(row)"
            class="times-card__start-field"
            @update="updateParam"
        />
        <div v-else class="times-card__start-readonly">
          <input
              type="time"
              class="ceremony-time-input glass-input glass-input--sm liquid-surface-control opacity-50 cursor-not-allowed"
              :value="formatPlanTime(row.start)"
              disabled
              aria-label="Startzeit"
          >
          <span class="times-card__default-slot" aria-hidden="true"/>
        </div>

        <ParameterField
            v-if="durationParam(row)"
            :disabled="rowDisabled(durationParam(row))"
            :horizontal="false"
            :with-label="false"
            :compact="true"
            :show-info="true"
            :param="durationParam(row)"
            class="times-card__duration-field"
            @update="updateParam"
        />
      </div>
    </section>
  </div>
</template>

<style scoped>
.times-card {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  overflow: visible;
}

.times-card__header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
}

.times-card__controls {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.65rem 0.9rem;
}

.times-card__start-readonly {
  display: flex;
  align-items: center;
  gap: 0.65rem;
}

.ceremony-time-input {
  width: 5.75rem;
  min-width: 0;
}

.times-card__controls :deep(.param-field__time) {
  width: 5.75rem;
}

/* Reserve space aligned with ParameterField default hints */
.times-card__default-slot {
  display: inline-block;
  min-width: 2.75rem;
  min-height: 1.25rem;
}

.times-card__controls :deep(.param-field) {
  min-width: 0;
}

.times-card__controls :deep(.glass-settings-row) {
  flex-wrap: nowrap;
  gap: 0.65rem;
}
</style>

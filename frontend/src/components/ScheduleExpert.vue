<script setup lang="ts">
import ParameterField from '@/components/molecules/ParameterField.vue'
import ProgramSection from '@/components/atoms/ProgramSection.vue'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'
import type { Parameter } from '@/models/Parameter'
import { programId, type EventProgramRef } from '@/utils/eventPrograms'

defineOptions({ name: 'ScheduleExpert' })

const {
  selectedEvent,
  attachedPrograms,
  visibilityMap,
  disabledMap,
  expertParamsByProgramId,
  finaleExpertParams,
  tableNames,
  handleParamUpdate,
  updateTableName,
} = useScheduleWorkspace()

function isChallenge(program: EventProgramRef): boolean {
  return String(program.name || '').toUpperCase() === 'CHALLENGE'
}

function expertParamsFor(program: EventProgramRef): Parameter[] {
  return (expertParamsByProgramId.value[programId(program)] || []).filter((param) => visibilityMap.value[param.id])
}

function visibleParams(params: Parameter[]): Parameter[] {
  return params.filter((param) => visibilityMap.value[param.id])
}
</script>

<template>
  <div class="schedule-expert flex flex-col pb-2">
    <ProgramSection
        v-for="program in attachedPrograms"
        :key="programId(program)"
        :program="program.name || 'shared'"
        collapsible
        default-collapsed
    >
      <ParameterField
          v-for="param in expertParamsFor(program)"
          :key="param.id"
          :param="param"
          :disabled="disabledMap[param.id]"
          :with-label="true"
          @update="(p: Parameter) => handleParamUpdate({ name: p.name, value: p.value })"
      />

      <div v-if="isChallenge(program)" class="flex flex-col gap-1.5 min-w-0">
        <span class="glass-settings-label">Bezeichnung der Robot-Game-Tische</span>
        <span class="glass-settings-hint">ersetzt nur die Nummer</span>
        <div class="grid grid-cols-1 min-[420px]:grid-cols-2 gap-x-4 gap-y-2">
          <div v-for="(name, i) in tableNames" :key="i" class="flex flex-col gap-1 min-w-0">
            <label class="glass-settings-hint !not-italic">Tisch {{ i + 1 }}</label>
            <input
                v-model="tableNames[i]"
                class="glass-input glass-input--sm liquid-surface-control w-full min-w-0 text-sm"
                :placeholder="`z.B. Alpha`"
                type="text"
                @blur="updateTableName"
            />
          </div>
        </div>
      </div>
    </ProgramSection>

    <ProgramSection
        v-if="selectedEvent?.level === 3"
        program="shared"
        short-name="Finale"
        title="Finale"
        subtitle="Parameter nur für Finalveranstaltungen"
        :show-logo="false"
        collapsible
        default-collapsed
    >
      <ParameterField
          v-for="param in visibleParams(finaleExpertParams)"
          :key="'ex_' + param.id"
          :param="param"
          :disabled="disabledMap[param.id]"
          :with-label="true"
          @update="(p: Parameter) => handleParamUpdate({ name: p.name, value: p.value })"
      />
    </ProgramSection>
  </div>
</template>

<style scoped>
.schedule-expert {
  gap: 1.15rem;
}
</style>

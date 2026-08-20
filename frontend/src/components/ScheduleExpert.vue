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
  finaleInputParams,
  finaleExpertParams,
  tableNames,
  handleParamUpdate,
  updateTableName,
} = useScheduleWorkspace()

function isChallenge(program: EventProgramRef): boolean {
  return String(program.name || '').toUpperCase() === 'CHALLENGE'
}

function expertParamsFor(program: EventProgramRef): Parameter[] {
  return expertParamsByProgramId.value[programId(program)] || []
}
</script>

<template>
  <div class="schedule-expert flex flex-col gap-4 pb-2">
    <ProgramSection
        v-for="program in attachedPrograms"
        :key="programId(program)"
        :program="program.name || 'shared'"
    >
      <template v-for="param in expertParamsFor(program)" :key="param.id">
        <ParameterField
            v-if="visibilityMap[param.id]"
            :param="param"
            :disabled="disabledMap[param.id]"
            :with-label="true"
            :horizontal="true"
            @update="(p: Parameter) => handleParamUpdate({ name: p.name, value: p.value })"
        />
      </template>

      <div v-if="isChallenge(program)" class="mt-3 md:mt-4 w-full max-w-lg">
        <div class="flex items-center mb-2 md:mb-3">
          <span class="text-sm md:text-base font-medium text-[var(--color-text)]">
            Bezeichnung der Robot-Game-Tische<br>(ersetzt nur die Nummer)
          </span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4">
          <div v-for="(name, i) in tableNames" :key="i">
            <label class="block text-xs text-[var(--color-text-muted)] mb-1">Tisch {{ i + 1 }}</label>
            <input
                v-model="tableNames[i]"
                class="w-full border px-3 py-1 rounded text-sm"
                :placeholder="`z.B. Alpha (leer lassen für >>Tisch ${i + 1}<<)`"
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
    >
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
        <div>
          <template v-for="param in finaleInputParams" :key="param.id">
            <ParameterField
                v-if="visibilityMap[param.id]"
                :param="param"
                :disabled="disabledMap[param.id]"
                :with-label="true"
                :horizontal="true"
                @update="(p: Parameter) => handleParamUpdate({ name: p.name, value: p.value })"
            />
          </template>
        </div>
        <div>
          <template v-for="param in finaleExpertParams" :key="param.id">
            <ParameterField
                v-if="visibilityMap[param.id]"
                :param="param"
                :disabled="disabledMap[param.id]"
                :with-label="true"
                :horizontal="true"
                @update="(p: Parameter) => handleParamUpdate({ name: p.name, value: p.value })"
            />
          </template>
        </div>
      </div>
    </ProgramSection>
  </div>
</template>

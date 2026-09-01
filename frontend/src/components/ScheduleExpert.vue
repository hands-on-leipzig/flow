<script setup lang="ts">
import ParameterField from '@/components/molecules/ParameterField.vue'
import ProgramSection from '@/components/atoms/ProgramSection.vue'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'
import type { Parameter } from '@/models/Parameter'
import { programId, type EventProgramRef } from '@/utils/eventPrograms'
import {
  FIRST_PROGRAM_CHALLENGE,
  FIRST_PROGRAM_FUTURE_8,
  TABLE_FIELD_MAX_LENGTH,
  supportsTableFieldLabels,
} from '@/utils/tableFieldLabels'

defineOptions({ name: 'ScheduleExpert' })

const {
  selectedEvent,
  attachedPrograms,
  visibilityMap,
  disabledMap,
  expertParamsByProgramId,
  finaleExpertParams,
  tableNamesByProgram,
  tableNameErrorsByProgram,
  tableFieldSectionTitle,
  tableFieldSlotLabel,
  handleParamUpdate,
  updateTableName,
} = useScheduleWorkspace()

function isChallenge(program: EventProgramRef): boolean {
  return String(program.name || '').toUpperCase() === 'CHALLENGE'
    || programId(program) === FIRST_PROGRAM_CHALLENGE
}

function isFuture8(program: EventProgramRef): boolean {
  return String(program.name || '').toUpperCase() === 'FUTURE_8'
    || programId(program) === FIRST_PROGRAM_FUTURE_8
}

function showsTableFieldRename(program: EventProgramRef): boolean {
  const id = programId(program)
  if (!supportsTableFieldLabels(id)) return false
  return isChallenge(program) || isFuture8(program)
}

function namesFor(program: EventProgramRef): string[] {
  return tableNamesByProgram.value[programId(program)] || []
}

function errorFor(program: EventProgramRef): string | null {
  return tableNameErrorsByProgram.value[programId(program)] ?? null
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

      <div
          v-if="showsTableFieldRename(program) && namesFor(program).length > 0"
          class="flex flex-col gap-1.5 min-w-0"
      >
        <span class="glass-settings-label">{{ tableFieldSectionTitle(programId(program)) }}</span>
        <span class="glass-settings-hint">frei wählbarer Name; leer = Standardbezeichnung</span>
        <div class="grid grid-cols-1 min-[420px]:grid-cols-2 gap-x-4 gap-y-2">
          <div
              v-for="(_name, i) in namesFor(program)"
              :key="programId(program) + '-' + i"
              class="flex flex-col gap-1 min-w-0"
          >
            <label class="glass-settings-hint !not-italic">{{ tableFieldSlotLabel(programId(program), i) }}</label>
            <input
                v-model="tableNamesByProgram[programId(program)][i]"
                class="glass-input glass-input--sm liquid-surface-control w-full min-w-0 text-sm"
                :placeholder="tableFieldSlotLabel(programId(program), i)"
                type="text"
                :maxlength="TABLE_FIELD_MAX_LENGTH"
                @blur="updateTableName(programId(program))"
            />
          </div>
        </div>
        <p
            v-if="errorFor(program)"
            class="text-sm text-[var(--color-danger, #b91c1c)]"
        >{{ errorFor(program) }}</p>
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

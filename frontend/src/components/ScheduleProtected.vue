<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import ParameterField from '@/components/molecules/ParameterField.vue'
import ProgramSection from '@/components/atoms/ProgramSection.vue'
import { useAuth } from '@/composables/useAuth'
import { useAdminInlineVisibility } from '@/composables/useAdminInlineVisibility'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'
import type { Parameter } from '@/models/Parameter'
import { programId, type EventProgramRef } from '@/utils/eventPrograms'
import {
  changedFromDefaultSuffix,
  countParametersChangedFromDefault,
} from '@/utils/parameterDefault'

defineOptions({ name: 'ScheduleProtected' })

const router = useRouter()
const { initializeUserRoles } = useAuth()
const { showAdminInline } = useAdminInlineVisibility()
const {
  selectedEvent,
  attachedPrograms,
  visibilityMap,
  disabledMap,
  protectedParamsByProgramId,
  finaleProtectedParams,
  handleParamUpdate,
} = useScheduleWorkspace()

function redirectIfHidden() {
  initializeUserRoles()
  if (!showAdminInline.value) {
    void router.replace('/plan/schedule')
  }
}

onMounted(redirectIfHidden)
watch(showAdminInline, redirectIfHidden)

function protectedParamsFor(program: EventProgramRef): Parameter[] {
  return (protectedParamsByProgramId.value[programId(program)] || []).filter(
    (param) => visibilityMap.value[param.id],
  )
}

function visibleParams(params: Parameter[]): Parameter[] {
  return params.filter((param) => visibilityMap.value[param.id])
}

function changedSuffixFor(params: Parameter[]): string {
  return changedFromDefaultSuffix(countParametersChangedFromDefault(params))
}
</script>

<template>
  <div v-if="showAdminInline" class="schedule-protected flex flex-col pb-2">
    <ProgramSection
        v-for="program in attachedPrograms"
        :key="programId(program)"
        :program="program.name || 'shared'"
        :heading-suffix="changedSuffixFor(protectedParamsFor(program))"
        collapsible
        default-collapsed
    >
      <ParameterField
          v-for="param in protectedParamsFor(program)"
          :key="param.id"
          :param="param"
          :disabled="disabledMap[param.id]"
          :with-label="true"
          @update="(p: Parameter) => handleParamUpdate({ name: p.name, value: p.value })"
      />
    </ProgramSection>

    <ProgramSection
        v-if="selectedEvent?.level === 3"
        program="shared"
        short-name="Finale"
        title="Finale"
        subtitle="Geschützte Parameter nur für Finalveranstaltungen"
        :heading-suffix="changedSuffixFor(visibleParams(finaleProtectedParams))"
        :show-logo="false"
        collapsible
        default-collapsed
    >
      <ParameterField
          v-for="param in visibleParams(finaleProtectedParams)"
          :key="'pr_' + param.id"
          :param="param"
          :disabled="disabledMap[param.id]"
          :with-label="true"
          @update="(p: Parameter) => handleParamUpdate({ name: p.name, value: p.value })"
      />
    </ProgramSection>
  </div>
</template>

<style scoped>
.schedule-protected {
  gap: 1.15rem;
}
</style>

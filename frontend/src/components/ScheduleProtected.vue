<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import ParameterField from '@/components/molecules/ParameterField.vue'
import ProgramSection from '@/components/atoms/ProgramSection.vue'
import { useAuth } from '@/composables/useAuth'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'
import type { Parameter } from '@/models/Parameter'
import { programId, type EventProgramRef } from '@/utils/eventPrograms'

defineOptions({ name: 'ScheduleProtected' })

const router = useRouter()
const { isAdmin, initializeUserRoles } = useAuth()
const {
  selectedEvent,
  attachedPrograms,
  visibilityMap,
  disabledMap,
  protectedParamsByProgramId,
  finaleProtectedParams,
  handleParamUpdate,
} = useScheduleWorkspace()

function redirectIfNotAdmin() {
  initializeUserRoles()
  if (!isAdmin.value) {
    void router.replace('/plan/schedule')
  }
}

onMounted(redirectIfNotAdmin)
watch(isAdmin, redirectIfNotAdmin)

function protectedParamsFor(program: EventProgramRef): Parameter[] {
  return (protectedParamsByProgramId.value[programId(program)] || []).filter(
    (param) => visibilityMap.value[param.id],
  )
}

function visibleParams(params: Parameter[]): Parameter[] {
  return params.filter((param) => visibilityMap.value[param.id])
}
</script>

<template>
  <div v-if="isAdmin" class="schedule-protected flex flex-col pb-2">
    <ProgramSection
        v-for="program in attachedPrograms"
        :key="programId(program)"
        :program="program.name || 'shared'"
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

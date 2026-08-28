<script setup lang="ts">
import {computed} from 'vue'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {useEventStore} from '@/stores/event'
import {programNameForId, type EventProgramRef} from '@/utils/eventPrograms'
import {
  staffingFilterKeyFromScope,
  staffingScopeIconClass,
  type StaffingFilterKey,
} from '@/utils/volunteerStaffingFilters'

type StaffingScope = {
  is_local: boolean
  first_program: number | null
}

const props = withDefaults(
  defineProps<{
    filterKey?: StaffingFilterKey
    role?: StaffingScope
    size?: 'chip' | 'base' | 'lg'
    boxed?: boolean
  }>(),
  {
    size: 'base',
    boxed: true,
  },
)

const eventStore = useEventStore()

const scopeKey = computed<StaffingFilterKey | null>(() => {
  if (props.filterKey) return props.filterKey
  if (props.role) return staffingFilterKeyFromScope(props.role)
  return null
})

const iconClass = computed(() => (scopeKey.value ? staffingScopeIconClass(scopeKey.value) : null))

const programRef = computed<EventProgramRef | null>(() => {
  if (props.role?.first_program) {
    return {
      first_program: props.role.first_program,
      name: programNameForId(eventStore.selectedEvent, props.role.first_program),
    }
  }
  const key = scopeKey.value
  if (!key?.startsWith('program:')) return null
  const id = Number(key.slice('program:'.length))
  if (!id) return null
  return {
    first_program: id,
    name: programNameForId(eventStore.selectedEvent, id),
  }
})

const showProgramLogo = computed(() => !!programRef.value && scopeKey.value?.startsWith('program:'))
const showScopeIcon = computed(() => !!iconClass.value)
</script>

<template>
  <ProgramLogo
      v-if="showProgramLogo"
      :program="programRef!"
      :size="size"
      decorative
  />
  <i
      v-else-if="showScopeIcon && !boxed"
      class="bi vol-staffing-filter__icon"
      :class="iconClass!"
      aria-hidden="true"
  />
  <div
      v-else-if="showScopeIcon"
      class="vol-staffing-scope-icon"
      :class="`vol-staffing-scope-icon--${size}`"
      aria-hidden="true"
  >
    <i class="bi" :class="iconClass"/>
  </div>
</template>

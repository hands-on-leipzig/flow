<script setup lang="ts">
import {computed} from 'vue'
import {useEventStore} from '@/stores/event'
import {resolveProgramRef, type EventWithPrograms, type ProgramLogoInput} from '@/utils/eventPrograms'
import {
  programLogoAlt,
  programLogoSrc,
  type ProgramLogoOrientation,
  type ProgramLogoRef,
} from '@/utils/images'

const SIZE_CLASS = {
  xs: 'program-logo--xs',
  chip: 'program-logo--chip',
  sm: 'program-logo--sm',
  base: 'program-logo--base',
  md: 'program-logo--md',
  lg: 'program-logo--lg',
  xl: 'program-logo--xl',
  section: 'program-logo--section',
} as const

const props = withDefaults(
  defineProps<{
    program: ProgramLogoRef | ProgramLogoInput
    event?: EventWithPrograms | null
    size?: keyof typeof SIZE_CLASS
    orientation?: ProgramLogoOrientation
    muted?: boolean
    decorative?: boolean
    title?: string
  }>(),
  {
    size: 'md',
    orientation: 'v',
    muted: false,
    decorative: false,
  }
)

const eventStore = useEventStore()

const resolved = computed(() => {
  const input = props.program
  if (input == null || input === '') return null
  const event = props.event !== undefined ? props.event : eventStore.selectedEvent
  return resolveProgramRef(event, input as ProgramLogoInput) ?? (typeof input === 'object' ? input : null)
})

const src = computed(() => {
  const ref = resolved.value ?? props.program
  if (ref == null || ref === '') return ''
  return programLogoSrc(ref, props.orientation)
})

const alt = computed(() => {
  if (props.decorative) return ''
  const ref = resolved.value ?? props.program
  if (ref == null || ref === '') return 'FIRST LEGO League Logo'
  return programLogoAlt(ref)
})
</script>

<template>
  <img
    v-if="src"
    :src="src"
    :alt="alt"
    :aria-hidden="decorative ? 'true' : undefined"
    :title="title"
    class="program-logo"
    :class="[SIZE_CLASS[size], {'program-logo--muted': muted}]"
  >
</template>

<style scoped>
.program-logo {
  flex-shrink: 0;
  object-fit: contain;
}

.program-logo--xs {
  width: 0.75rem;
  height: 0.75rem;
}

.program-logo--chip {
  width: 1rem;
  height: 1rem;
}

.program-logo--sm {
  width: 1.25rem;
  height: 1.25rem;
}

.program-logo--base {
  width: 1.5rem;
  height: 1.5rem;
}

.program-logo--md {
  width: 2rem;
  height: 2rem;
}

.program-logo--lg {
  width: 2.25rem;
  height: 2.25rem;
}

.program-logo--xl {
  width: 2.5rem;
  height: 2.5rem;
}

.program-logo--section {
  width: 2.25rem;
  height: 2.25rem;
}

.program-logo--muted {
  filter: grayscale(0.35);
  opacity: 0.75;
}
</style>

<template>
  <div class="bg-white p-6 rounded-lg shadow-lg w-96 max-w-full">
    <h3 class="text-lg font-bold mb-4">
      <template v-if="mode === 'plan-delete'">
        Plan löschen?
      </template>
      <template v-else-if="mode === 'cleanup' && cleanupType">
        {{ cleanupMeta[cleanupType].title }}
      </template>
    </h3>
    <p class="mb-6 text-sm text-gray-700">
      <template v-if="mode === 'plan-delete'">
        Bist du sicher, dass du den Plan mit der ID
        <span class="font-semibold">{{ planId }}</span>
        löschen möchtest? Diese Aktion kann nicht rückgängig gemacht werden.
      </template>
      <template v-else-if="mode === 'cleanup' && cleanupType">
        {{ cleanupMeta[cleanupType].description }}
      </template>
    </p>
    <div class="flex justify-end gap-2">
      <button class="px-4 py-2 text-gray-600 hover:text-black" @click="$emit('cancel')">Abbrechen</button>
      <button class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700" @click="$emit('confirm')">
        {{ confirmLabel }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

type CleanupTarget = 'events' | 'plans' | 'activity-groups' | 'activities'

const cleanupMeta: Record<
  CleanupTarget,
  { title: string; description: string; confirmLabel: string; orphanKey: 'events' | 'plans' | 'ags' | 'acts' }
> = {
  events: {
    title: 'Events bereinigen?',
    description: 'Alle Events ohne gültigen Regionalpartner werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'events',
  },
  plans: {
    title: 'Pläne bereinigen?',
    description: 'Alle Pläne ohne gültiges Event werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'plans',
  },
  'activity-groups': {
    title: 'Activity Groups bereinigen?',
    description: 'Alle Activity Groups ohne gültigen Plan werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'ags',
  },
  activities: {
    title: 'Activities bereinigen?',
    description: 'Alle Activities ohne gültige Activity Group werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'acts',
  },
}

const props = defineProps<{
  mode: 'plan-delete' | 'cleanup'
  planId: number | null
  cleanupType: CleanupTarget | null
}>()

const emit = defineEmits<{
  (e: 'confirm'): void
  (e: 'cancel'): void
}>()

const confirmLabel = computed(() => {
  if (props.mode === 'cleanup' && props.cleanupType) {
    return cleanupMeta[props.cleanupType].confirmLabel
  }
  return 'Löschen'
})
</script>


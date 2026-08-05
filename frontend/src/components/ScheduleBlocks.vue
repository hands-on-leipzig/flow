<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import InsertBlocks from '@/components/molecules/InsertBlocks.vue'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'

defineOptions({ name: 'ScheduleBlocks' })

const {
  selectedEvent,
  selectedPlanId,
  showExplore,
  showChallenge,
  handleBlockUpdates,
  registerInsertBlocks,
} = useScheduleWorkspace()

const insertBlocksRef = ref<InstanceType<typeof InsertBlocks> | null>(null)

function syncApi() {
  registerInsertBlocks(
    insertBlocksRef.value
      ? { saveAllEnabledBlocks: () => insertBlocksRef.value!.saveAllEnabledBlocks() }
      : null
  )
}

onMounted(syncApi)
watch(insertBlocksRef, syncApi)
onBeforeUnmount(() => registerInsertBlocks(null))
</script>

<template>
  <div class="min-w-0 pb-2">
    <div v-if="!showChallenge" class="text-center py-10 text-[var(--color-text-subtle)]">
      <div class="text-sm font-medium mb-1">Zusatzblöcke sind für Challenge verfügbar</div>
      <div class="text-xs">
        Aktiviere <span class="italic">FIRST</span> LEGO League Challenge unter Allgemein, um Zusatzblöcke zu konfigurieren.
      </div>
    </div>
    <InsertBlocks
        v-else-if="selectedPlanId"
        ref="insertBlocksRef"
        :plan-id="selectedPlanId as number"
        :event-level="selectedEvent?.level ?? null"
        :on-update="handleBlockUpdates"
        :show-explore="showExplore"
        :show-challenge="showChallenge"
    />
  </div>
</template>

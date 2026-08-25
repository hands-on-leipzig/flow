<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Preview from '@/components/molecules/Preview.vue'
import {
  notifyPlanPopoutPresence,
  subscribePlanPreviewReload,
} from '@/utils/planPreviewSync'

defineOptions({ name: 'PlanPopout' })

const props = defineProps<{
  planId: string | number
}>()

const planId = computed(() => Number(props.planId))
const reloadTick = ref(0)

let unsubscribe: (() => void) | null = null
let pingTimer: ReturnType<typeof setInterval> | null = null

function announce(status: 'open' | 'closed' | 'ping') {
  if (planId.value) notifyPlanPopoutPresence(planId.value, status)
}

onMounted(() => {
  document.title = 'Plan · FLOW'
  announce('open')
  pingTimer = setInterval(() => announce('ping'), 2000)
  unsubscribe = subscribePlanPreviewReload(
    () => planId.value,
    () => {
      reloadTick.value += 1
    }
  )
  window.addEventListener('beforeunload', onUnload)
  window.addEventListener('pagehide', onUnload)
})

function onUnload() {
  announce('closed')
}

onBeforeUnmount(() => {
  announce('closed')
  window.removeEventListener('beforeunload', onUnload)
  window.removeEventListener('pagehide', onUnload)
  if (pingTimer) {
    clearInterval(pingTimer)
    pingTimer = null
  }
  unsubscribe?.()
  unsubscribe = null
})
</script>

<template>
  <div class="plan-popout">
    <Preview
        v-if="planId"
        class="plan-popout__preview"
        :plan-id="planId"
        :reload="reloadTick"
        initial-view="overview"
        hide-meta
    />
  </div>
</template>

<style scoped>
.plan-popout {
  box-sizing: border-box;
  min-height: 100dvh;
  height: 100dvh;
  width: 100%;
  padding: 0.75rem 1rem 1rem;
  background: #fff;
  overflow: hidden;
}

.plan-popout__preview {
  height: 100%;
}
</style>

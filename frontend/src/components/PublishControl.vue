<script setup lang="ts">
/**
 * Ausgabe shell: nested publish panes (Veröffentlichung, WLAN, Digital, Drucksachen, Namensschilder, Logos).
 * Route-keyed keep-alive — same pattern as Schedule nested panes.
 */
import {computed} from 'vue'
import {useRoute} from 'vue-router'

defineOptions({name: 'PublishControl'})

const route = useRoute()

/** Veröffentlichung + Logos + Digital + Drucksachen: viewport-height split; other Ausgabe pages stay natural height. */
const isFillSplit = computed(() => {
  const path = route.path.replace(/\/$/, '')
  return path === '/plan/publish'
    || path === '/plan/publish/logos'
    || path === '/plan/publish/digital'
    || path === '/plan/publish/analog'
})
</script>

<template>
  <div
    class="publish-shell"
    :class="{
      'publish-shell--fill': isFillSplit,
      'h-full min-h-0 flex flex-col overflow-hidden': isFillSplit,
    }"
  >
    <router-view v-slot="{ Component, route: paneRoute }">
      <keep-alive include="PublishDistribution,PublishWlan,PublishDigital,PublishAnalog,PublishNameTags,Logos">
        <component
            :is="Component"
            v-if="Component"
            :key="paneRoute.name ?? paneRoute.path"
        />
      </keep-alive>
    </router-view>
  </div>
</template>

<style scoped>
.publish-shell {
  min-height: 0;
  display: flex;
  flex-direction: column;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}

.publish-shell--fill {
  height: 100%;
  min-height: 0;
  overflow: hidden;
  padding-bottom: 0;
}

/* keep-alive has no DOM node — target split page roots explicitly. */
.publish-shell--fill :deep(.pub),
.publish-shell--fill :deep(.logos-page),
.publish-shell--fill :deep(.digital-page),
.publish-shell--fill :deep(.druck-page) {
  flex: 1 1 0%;
  min-height: 0;
  overflow: hidden;
}
</style>

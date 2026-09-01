<script setup lang="ts">
/**
 * Ausgabe shell: nested publish panes (Veröffentlichung, WLAN, Digital, Drucksachen, Namensschilder, Logos).
 * Route-keyed keep-alive — same pattern as Schedule nested panes.
 */
import {computed} from 'vue'
import {useRoute} from 'vue-router'

defineOptions({name: 'PublishControl'})

const route = useRoute()

/** Only Veröffentlichung uses the full-height split; other Ausgabe pages stay natural height. */
const isDistribution = computed(() => route.path.replace(/\/$/, '') === '/plan/publish')
</script>

<template>
  <div class="publish-shell" :class="{'publish-shell--fill': isDistribution}">
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
  overflow: hidden;
}

.publish-shell--fill :deep(keep-alive) {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.publish-shell--fill :deep(keep-alive > *) {
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
  overflow: hidden;
}
</style>

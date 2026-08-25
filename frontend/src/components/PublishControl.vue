<script setup lang="ts">
/**
 * Ausgabe shell: nested publish panes (Veröffentlichung, WLAN, Digital, Analog, Logos).
 * Route-keyed keep-alive — same pattern as Schedule nested panes.
 */
defineOptions({name: 'PublishControl'})
</script>

<template>
  <div class="publish-shell">
    <router-view v-slot="{ Component, route: paneRoute }">
      <keep-alive include="PublishDistribution,PublishWlan,PublishDigital,PublishAnalog,Logos">
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
  height: 100%;
  display: flex;
  flex-direction: column;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}
</style>

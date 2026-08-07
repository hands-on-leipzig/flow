<script setup lang="ts">
/**
 * Ausgabe shell: Verteilung / Digital / Analog as nested routes.
 * Route-keyed keep-alive — same pattern as Schedule nested panes.
 */
defineOptions({name: 'PublishControl'})
</script>

<template>
  <div class="publish-shell">
    <router-view v-slot="{ Component, route: paneRoute }">
      <keep-alive include="PublishDistribution,PublishDigital,PublishAnalog,Logos">
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
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}
</style>

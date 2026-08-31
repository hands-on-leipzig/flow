<script setup lang="ts">
/**
 * am Tag shell: nested panes (Check-In, Cockpit).
 */
defineOptions({name: 'EventDayShell'})
</script>

<template>
  <div class="event-day-shell">
    <router-view v-slot="{ Component, route: paneRoute }">
      <keep-alive include="EventDayCheckIn,EventDayCockpit">
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
.event-day-shell {
  min-height: 0;
  height: 100%;
  display: flex;
  flex-direction: column;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}
</style>

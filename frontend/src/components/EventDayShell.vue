<script setup lang="ts">
/**
 * am Tag shell: nested panes (Check-In, Cockpit).
 */
defineOptions({name: 'EventDayShell'})
</script>

<template>
  <div class="event-day-shell event-day-shell--fill h-full min-h-0 flex flex-col overflow-hidden">
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
.event-day-shell--fill {
  min-height: 0;
  overflow: hidden;
  padding-bottom: 0;
}

/* keep-alive has no DOM node — target split page roots explicitly. */
.event-day-shell--fill :deep(.settings-split) {
  flex: 1 1 0%;
  min-height: 0;
  overflow: hidden;
}
</style>

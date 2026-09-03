<script setup lang="ts">
/**
 * Teams shell: one TeamsProgram child per attached first program.
 */
defineOptions({name: 'Teams'})
</script>

<template>
  <div class="teams-shell">
    <router-view v-slot="{ Component, route: paneRoute }">
      <keep-alive include="TeamsProgram,TeamsTeamData">
        <component
            :is="Component"
            v-if="Component"
            :key="paneRoute.name === 'teams-data' ? 'teams-data' : String(paneRoute.params.program ?? paneRoute.path)"
        />
      </keep-alive>
    </router-view>
  </div>
</template>

<style scoped>
.teams-shell {
  height: 100%;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.teams-shell > :deep(*) {
  flex: 1 1 auto;
  min-height: 0;
}
</style>

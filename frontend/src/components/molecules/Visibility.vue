<template>
  <div class="visibility-admin">
    <div class="mb-4">
      <h3 class="text-lg font-medium text-gray-900 mb-2">
        Visibility Rules Management
      </h3>
      <p class="text-sm text-gray-600 mb-4">
        Manage visibility rules for different roles and activity types.
      </p>
    </div>
    
    <div class="bg-white shadow overflow-hidden sm:rounded-md">
      <div class="p-0">
        <iframe 
          class="w-full h-screen border-0"
          :src="visibilityUrl"
          title="Visibility Management"
        ></iframe>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const visibilityUrl = computed(() => {
  return `https://dev.flow.hands-on-technology.org/output/visibility.cgi`
})
</script>

<style scoped>
.visibility-admin {
  max-width: 100%;
}
</style>

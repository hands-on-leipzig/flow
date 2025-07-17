<script setup>
defineProps({
  opened: {type: Boolean, required: true},
})
</script>

<template>
  <span>
  <svg v-if="opened" class="h-4 w-4" fill="none" stroke="currentColor"
       viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
    <path d="M5 15l7-7 7 7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
  </svg>
  <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"
       xmlns="http://www.w3.org/2000/svg">
    <path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"/>
  </svg>
    </span>
</template>

<style scoped>

</style>
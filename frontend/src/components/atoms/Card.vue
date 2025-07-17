<script setup>
defineProps({
  title: String
})
</script>

<template>
  <div class="bg-white border rounded-xl shadow p-4 space-y-2">
    <h2 v-if="title" class="text-lg font-semibold text-gray-800">{{ title }}</h2>
    <slot/>
  </div>
</template>

<style scoped>

</style>
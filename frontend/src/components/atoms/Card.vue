<script setup>
defineProps({
  title: String,
  widthColumns: Number,
})
</script>

<template>
  <div :class="'rounded-xl shadow bg-white p-4 flex flex-col justify-between col-span-' + widthColumns">
    <h2 class="text-lg font-semibold mb-4">{{ title }}</h2>
    <div>
    </div>
  </div>
</template>

<style scoped>

</style>
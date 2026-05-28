<script setup>
defineProps({
  title: String,
  widthColumns: Number,
})
</script>

<template>
  <div :class="['glass-card liquid-surface-inner flex flex-col justify-between', widthColumns ? `col-span-${widthColumns}` : '']">
    <h2 class="glass-card__title">{{ title }}</h2>
    <div>
      <slot/>
    </div>
  </div>
</template>

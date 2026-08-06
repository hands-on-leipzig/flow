<script setup lang="ts">
import QrWifiBox from '@/components/molecules/QrWifiBox.vue'
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'
import CarouselBox from '@/components/molecules/CarouselBox.vue'

withDefaults(
  defineProps<{
    /** Skip outer surface + title when parent provides chrome. */
    embed?: boolean
  }>(),
  {embed: false}
)
</script>

<template>
  <div :class="embed ? 'space-y-4' : 'glass-surface-lg space-y-4'">
    <h2 v-if="!embed" class="text-lg font-semibold mb-4">Während der Veranstaltung</h2>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
      <QrWifiBox />
      <PdfPlansBox />
      <CarouselBox />
    </div>
  </div>
</template>
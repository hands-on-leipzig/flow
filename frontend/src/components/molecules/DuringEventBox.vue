<script setup lang="ts">
import QrWifiBox from '@/components/molecules/QrWifiBox.vue'
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'
import CarouselBox from '@/components/molecules/CarouselBox.vue'
</script>

<template>
  <div class="glass-surface-lg space-y-4">
    <h2 class="text-lg font-semibold mb-4">Während der Veranstaltung</h2>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
      <!-- Column 1 -->
      <QrWifiBox />
      
      <!-- Column 2 -->
      <PdfPlansBox />
      
      <!-- Column 3 -->
      <CarouselBox />
    </div>
  </div>
</template>
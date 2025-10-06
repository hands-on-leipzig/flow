<script setup lang="ts">
import QrWifiBox from '@/components/molecules/QrWifiBox.vue'
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'
import CarouselBox from '@/components/molecules/CarouselBox.vue'
</script>

<template>
  <div class="rounded-xl shadow bg-white p-6 space-y-4">
    <h2 class="text-lg font-semibold mb-4">Während der Veranstaltung</h2>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <QrWifiBox />
      <PdfPlansBox />
      <CarouselBox />
    </div>
  </div>
</template>
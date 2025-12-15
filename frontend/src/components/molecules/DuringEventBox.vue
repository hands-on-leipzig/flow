<script setup lang="ts">
import QrWifiBox from '@/components/molecules/QrWifiBox.vue'
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'
import MatchPlanBox from '@/components/molecules/MatchPlanBox.vue'
import CarouselBox from '@/components/molecules/CarouselBox.vue'
</script>

<template>
  <div class="rounded-xl shadow bg-white p-6 space-y-4">
    <h2 class="text-lg font-semibold mb-4">Während der Veranstaltung</h2>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
      <!-- Column 1 -->
      <QrWifiBox />
      
      <!-- Column 2 - Two boxes stacked -->
      <div class="space-y-6">
        <MatchPlanBox />
        <PdfPlansBox />
      </div>
      
      <!-- Column 3 -->
      <CarouselBox />
    </div>
  </div>
</template>
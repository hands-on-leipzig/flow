<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import OnlineAccessBox from '@/components/molecules/OnlineAccessBox.vue'
import DuringEventBox from '@/components/molecules/DuringEventBox.vue'

const imagick = ref<{ imagick: boolean; version?: { versionString: string } } | null>(null)

const hasImagick = computed(() => imagick.value?.imagick === true)
const versionString = computed(() => imagick.value?.version?.versionString || '')

onMounted(async () => {
  try {
    const { data } = await axios.get('/check-imagick')
    imagick.value = data
    console.log('Imagick:', data)
  } catch (e) {
    console.error('Fehler beim Prüfen von Imagick:', e)
    imagick.value = { imagick: false }
  }
})
</script>

<template>
  <div class="p-6 space-y-8">
    <h1 class="text-2xl font-bold">Zugriff auf den Ablaufplan</h1>

    <!-- Imagick Status -->
    <div v-if="imagick">
      <div v-if="hasImagick" class="text-green-700 font-medium">
        ✅ Imagick aktiv:
        <span class="text-gray-700">{{ versionString }}</span>
      </div>
      <div v-else class="text-red-700 font-medium">
        ❌ Imagick fehlt – keine Previews möglich
      </div>
    </div>
    <div v-else class="text-gray-500 italic">
      🔄 Prüfe Imagick...
    </div>

    <OnlineAccessBox />
    <DuringEventBox />
  </div>
</template>
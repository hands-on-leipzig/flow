<script setup lang="ts">
/**
 * Ausgabe → WLAN vor Ort: SSID/Passwort und QR-Code.
 */
import QrWifiBox from '@/components/molecules/QrWifiBox.vue'

defineOptions({name: 'PublishWlan'})
</script>

<template>
  <div class="wlan">
    <div class="glass-card liquid-surface-inner">
      <h1 class="glass-card__heading">WLAN vor Ort</h1>
      <QrWifiBox embed section="wifi"/>
    </div>
  </div>
</template>

<style scoped>
.wlan {
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
  max-width: 36rem;
}
</style>

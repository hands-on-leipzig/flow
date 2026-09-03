<script setup lang="ts">
/**
 * Ausgabe → WLAN vor Ort: SSID/Passwort und QR-Code.
 */
import QrWifiBox from '@/components/molecules/QrWifiBox.vue'

defineOptions({name: 'PublishWlan'})
</script>

<template>
  <div class="vol-page wlan-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">WLAN für Teams, Helfer:innen und Besucher</h1>
        <p class="vol-page__sub">Eingabe der Login-Daten für das WLAN vor Ort</p>
      </div>
    </header>

    <div class="wlan-page__box glass-card liquid-surface-inner">
      <QrWifiBox embed section="wifi"/>
    </div>
  </div>
</template>

<style scoped>
.wlan-page__box {
  max-width: 36rem;
}
</style>

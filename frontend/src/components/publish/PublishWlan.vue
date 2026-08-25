<script setup lang="ts">
/**
 * Ausgabe → WLAN vor Ort: SSID/Passwort, QR und Druckposter.
 */
import QrWifiBox from '@/components/molecules/QrWifiBox.vue'

defineOptions({name: 'PublishWlan'})
</script>

<template>
  <div class="wlan">
    <header class="wlan__intro">
      <h1 class="wlan__title">WLAN vor Ort</h1>
      <p class="wlan__sub">
        Zugangdaten, QR-Code und Druckposter für das Event-WLAN.
      </p>
    </header>

    <section class="wlan__panel" aria-labelledby="wlan-access-heading">
      <header class="wlan__panel-head">
        <div>
          <h2 id="wlan-access-heading" class="wlan__panel-title">Zugang &amp; Aushang</h2>
          <p class="wlan__panel-sub">
            Zum Einrichten und Aufhängen — QR und PDF mit Netzwerkdaten.
          </p>
        </div>
      </header>
      <QrWifiBox embed section="wifi"/>
    </section>
  </div>
</template>

<style scoped>
.wlan {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}

.wlan__intro {
  margin-bottom: 0.1rem;
}

.wlan__title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 750;
  letter-spacing: -0.02em;
}

.wlan__sub {
  margin: 0.3rem 0 0;
  font-size: 0.9rem;
  color: var(--color-text-muted);
  max-width: 40rem;
}

.wlan__panel {
  padding: 1.15rem 1.2rem 1.3rem;
  border-radius: 16px;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 48%, transparent);
  background: #fff;
  box-shadow:
    0 10px 28px rgba(15, 23, 42, 0.055),
    0 2px 6px rgba(15, 23, 42, 0.035);
}

.wlan__panel-head {
  margin-bottom: 1rem;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
}

.wlan__panel-title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 750;
  letter-spacing: -0.02em;
}

.wlan__panel-sub {
  margin: 0.25rem 0 0;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}
</style>

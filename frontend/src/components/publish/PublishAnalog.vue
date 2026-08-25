<script setup lang="ts">
/**
 * Ausgabe → Analog: Online-Plan-QR und PDF-Pläne zum Drucken.
 */
import QrWifiBox from '@/components/molecules/QrWifiBox.vue'
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'

defineOptions({name: 'PublishAnalog'})
</script>

<template>
  <div class="analog">
    <header class="analog__intro">
      <h1 class="analog__title">Analog</h1>
      <p class="analog__sub">
        Druckbarer Online-Plan-QR und PDF-Pläne für vor Ort.
      </p>
    </header>

    <section class="analog__panel" aria-labelledby="analog-qr-heading">
      <header class="analog__panel-head">
        <div>
          <h2 id="analog-qr-heading" class="analog__panel-title">Online-Plan QR</h2>
          <p class="analog__panel-sub">
            Zum Ausdrucken und Aufhängen — öffentlicher Plan-Link.
          </p>
        </div>
      </header>
      <QrWifiBox embed section="plan"/>
    </section>

    <section class="analog__panel" aria-labelledby="analog-pdf-heading">
      <header class="analog__panel-head">
        <div>
          <h2 id="analog-pdf-heading" class="analog__panel-title">PDF-Pläne</h2>
          <p class="analog__panel-sub">
            Pläne zum Drucken und Exportieren für Orga und Teams.
          </p>
        </div>
      </header>
      <PdfPlansBox/>
    </section>
  </div>
</template>

<style scoped>
.analog {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}

.analog__intro {
  margin-bottom: 0.1rem;
}

.analog__title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 750;
  letter-spacing: -0.02em;
}

.analog__sub {
  margin: 0.3rem 0 0;
  font-size: 0.9rem;
  color: var(--color-text-muted);
  max-width: 40rem;
}

.analog__panel {
  padding: 1.15rem 1.2rem 1.3rem;
  border-radius: 16px;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 48%, transparent);
  background: #fff;
  box-shadow:
    0 10px 28px rgba(15, 23, 42, 0.055),
    0 2px 6px rgba(15, 23, 42, 0.035);
}

.analog__panel-head {
  margin-bottom: 1rem;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
}

.analog__panel-title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 750;
  letter-spacing: -0.02em;
}

.analog__panel-sub {
  margin: 0.25rem 0 0;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

/* PdfPlansBox brings its own surface — calm nesting */
.analog__panel :deep(.glass-surface-lg) {
  box-shadow: none;
  border-color: color-mix(in srgb, var(--color-border-strong) 35%, transparent);
}
</style>

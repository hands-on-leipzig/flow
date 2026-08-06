<script setup lang="ts">
/**
 * Ausgabe → Verteilung: öffentlicher Link + Detaillevel.
 */
import {computed, ref} from 'vue'
import {useEventStore} from '@/stores/event'
import {useAuth} from '@/composables/useAuth'
import OnlineAccessBox from '@/components/molecules/OnlineAccessBox.vue'
import {showGlassToast} from '@/composables/useGlassToast'

defineOptions({name: 'PublishDistribution'})

const eventStore = useEventStore()
const {isAdmin} = useAuth()
const event = computed(() => eventStore.selectedEvent)
const accessRef = ref<InstanceType<typeof OnlineAccessBox> | null>(null)

const qrSrc = computed(() => {
  const raw = event.value?.qrcode
  if (!raw) return null
  return raw.startsWith('data:') ? raw : `data:image/png;base64,${raw}`
})

async function copyLink() {
  const link = event.value?.link
  if (!link) return
  try {
    await navigator.clipboard.writeText(link)
    showGlassToast('Link kopiert', 'success')
  } catch {
    showGlassToast('Link konnte nicht kopiert werden', 'error')
  }
}
</script>

<template>
  <div class="dist">
    <header class="dist__intro">
      <h1 class="dist__title">Verteilung</h1>
      <p class="dist__sub">
        Öffentlichen Link freigeben und steuern, wie viel online sichtbar ist.
      </p>
    </header>

    <!-- Online-Ausgabe: Link + Detaillevel as one unit -->
    <section class="dist__panel dist__online" aria-labelledby="dist-online-heading">
      <header class="dist__panel-head">
        <div>
          <h2 id="dist-online-heading" class="dist__panel-title">Online-Ausgabe</h2>
          <p class="dist__panel-sub">
            Ein Link für alle — und darunter, wie viel davon sichtbar ist.
          </p>
        </div>
      </header>

      <div class="dist__hero">
        <div class="dist__hero-main">
          <p class="dist__eyebrow">Öffentlicher Link</p>
          <a
              v-if="event?.link"
              :href="event.link"
              target="_blank"
              rel="noopener"
              class="dist__url"
          >{{ event.link }}</a>
          <p v-else class="dist__muted">Noch kein öffentlicher Link vorhanden.</p>
          <p class="dist__lede">
            Für Teams, Freiwillige und Publikum.
          </p>

          <div class="dist__actions">
            <button
                v-if="event?.link"
                type="button"
                class="glass-btn-accent !px-3.5 !py-2 !text-sm"
                @click="copyLink"
            >
              <i class="bi bi-clipboard me-1" aria-hidden="true"/>
              Kopieren
            </button>
            <a
                v-if="event?.link"
                :href="event.link"
                target="_blank"
                rel="noopener"
                class="glass-btn-secondary !px-3.5 !py-2 !text-sm inline-flex items-center"
            >
              <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"/>
              Öffnen
            </a>
            <button
                v-if="isAdmin && event?.id"
                type="button"
                class="glass-btn-secondary !px-3 !py-2 !text-sm"
                :disabled="!!accessRef?.regenerating"
                @click="accessRef?.regenerateLinkAndQR()"
            >
              <i class="bi bi-arrow-repeat me-1" aria-hidden="true"/>
              {{ accessRef?.regenerating ? 'Regeneriere…' : 'Link & QR neu' }}
            </button>
          </div>
        </div>

        <aside class="dist__hero-qr" aria-label="QR-Code zum Link">
          <img
              v-if="qrSrc"
              :src="qrSrc"
              alt="QR-Code zur öffentlichen Seite"
              class="dist__qr-img"
          />
          <div v-else class="dist__qr-empty">Kein QR</div>
          <span class="dist__qr-caption">Scannen öffnet den Link</span>
        </aside>
      </div>

      <div class="dist__level-block">
        <div class="dist__level-head">
          <h3 class="dist__level-title">Detaillevel</h3>
          <p class="dist__level-sub">
            Steuert, wie viel über diesen Link online sichtbar ist.
          </p>
        </div>
        <OnlineAccessBox ref="accessRef" embed/>
      </div>
    </section>
  </div>
</template>

<style scoped>
.dist {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}

.dist__intro {
  margin-bottom: 0.1rem;
}

.dist__title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 750;
  letter-spacing: -0.02em;
}

.dist__sub {
  margin: 0.3rem 0 0;
  font-size: 0.9rem;
  color: var(--color-text-muted);
  max-width: 40rem;
}

.dist__panel {
  padding: 1.15rem 1.2rem 1.3rem;
  border-radius: 16px;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 48%, transparent);
  background: #fff;
  box-shadow:
    0 10px 28px rgba(15, 23, 42, 0.055),
    0 2px 6px rgba(15, 23, 42, 0.035);
}

.dist__online {
  background:
    radial-gradient(120% 70% at 0% 0%, color-mix(in srgb, var(--color-accent) 10%, transparent), transparent 50%),
    #fff;
}

.dist__panel-head {
  margin-bottom: 1rem;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
}

.dist__panel-title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 750;
  letter-spacing: -0.02em;
}

.dist__panel-sub {
  margin: 0.25rem 0 0;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.dist__hero {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.15rem;
  padding-bottom: 1.15rem;
}

@media (min-width: 860px) {
  .dist__hero {
    grid-template-columns: 1fr auto;
    align-items: center;
  }
}

.dist__eyebrow {
  margin: 0 0 0.4rem;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--color-accent);
}

.dist__url {
  display: block;
  font-size: clamp(1.05rem, 1.8vw, 1.3rem);
  font-weight: 750;
  letter-spacing: -0.025em;
  color: var(--color-text);
  text-decoration: none;
  word-break: break-all;
  line-height: 1.3;
}

.dist__url:hover {
  color: var(--color-accent);
}

.dist__lede,
.dist__muted {
  margin: 0.45rem 0 0;
  font-size: 0.88rem;
  color: var(--color-text-muted);
  max-width: 36rem;
  line-height: 1.45;
}

.dist__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-top: 1rem;
}

.dist__hero-qr {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  justify-self: center;
}

.dist__qr-img {
  width: 7.25rem;
  height: 7.25rem;
  object-fit: contain;
  padding: 0.45rem;
  border-radius: 12px;
  background: #fff;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.07);
}

.dist__qr-empty {
  width: 7.25rem;
  height: 7.25rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  border: 1px dashed color-mix(in srgb, var(--color-border-strong) 50%, transparent);
  color: var(--color-text-subtle);
  font-size: 0.8rem;
}

.dist__qr-caption {
  font-size: 0.7rem;
  font-weight: 650;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-muted);
}

.dist__level-block {
  padding-top: 1.1rem;
  border-top: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
}

.dist__level-head {
  margin-bottom: 0.85rem;
}

.dist__level-title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 750;
  letter-spacing: -0.015em;
}

.dist__level-sub {
  margin: 0.2rem 0 0;
  font-size: 0.82rem;
  color: var(--color-text-muted);
}
</style>

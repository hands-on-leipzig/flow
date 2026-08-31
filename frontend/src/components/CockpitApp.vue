<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import {useRoute} from 'vue-router'
import axios from 'axios'
import {imageUrl} from '@/utils/images'
import RobotGameRoundsPanel from '@/components/molecules/RobotGameRoundsPanel.vue'

defineOptions({name: 'CockpitApp'})

type Bootstrap = {
  event_id: number
  event_name: string
  slug: string
  enabled: boolean
}

const route = useRoute()
const slug = computed(() => String(route.params.slug || ''))

const bootstrap = ref<Bootstrap | null>(null)
const bootstrapError = ref('')
const pin = ref('')
const pinError = ref('')
const token = ref('')
const unlocking = ref(false)

const storageKey = computed(() => `flow:cockpit-token:${slug.value}`)

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
})

api.interceptors.request.use((config) => {
  if (token.value) {
    config.headers['X-Cockpit-Token'] = token.value
  }
  return config
})

const unlocked = computed(() => !!token.value && !!bootstrap.value?.enabled)

const roundsApiPath = computed(() =>
    slug.value ? `/cockpit/${slug.value}/rounds` : null,
)

async function loadBootstrap() {
  bootstrapError.value = ''
  try {
    const {data} = await api.get(`/cockpit/${slug.value}/bootstrap`, {
      params: {_: Date.now()},
      headers: {'Cache-Control': 'no-cache', Pragma: 'no-cache'},
    })
    bootstrap.value = data
    if (!data.enabled) {
      token.value = ''
      sessionStorage.removeItem(storageKey.value)
    }
  } catch (e: any) {
    bootstrapError.value = e?.response?.data?.error || 'Event nicht gefunden.'
    bootstrap.value = null
  }
}

async function unlock() {
  pinError.value = ''
  unlocking.value = true
  try {
    const {data} = await api.post(`/cockpit/${slug.value}/session`, {pin: pin.value})
    token.value = data.token
    sessionStorage.setItem(storageKey.value, data.token)
    pin.value = ''
  } catch (e: any) {
    const status = e?.response?.status
    if (status === 423) {
      await loadBootstrap()
      pinError.value = 'Cockpit ist nicht geöffnet.'
    } else {
      pinError.value = e?.response?.data?.error || 'PIN ungültig.'
    }
    token.value = ''
    sessionStorage.removeItem(storageKey.value)
  } finally {
    unlocking.value = false
  }
}

watch(token, (next) => {
  if (next) {
    sessionStorage.setItem(storageKey.value, next)
  } else {
    sessionStorage.removeItem(storageKey.value)
  }
})

watch(slug, async () => {
  token.value = sessionStorage.getItem(storageKey.value) || ''
  await loadBootstrap()
})

onMounted(async () => {
  token.value = sessionStorage.getItem(storageKey.value) || ''
  await loadBootstrap()
})
</script>

<template>
  <div class="cp-app">
    <header class="cp-app__header liquid-surface-inner">
      <div class="cp-app__brand">
        <img
            class="cp-app__logo"
            :src="imageUrl('/flow/flow.png')"
            alt="FLOW"
        />
      </div>
    </header>

    <main class="cp-app__main">
      <p v-if="bootstrapError" class="glass-alert-error !mb-0">{{ bootstrapError }}</p>

      <template v-else-if="bootstrap && !bootstrap.enabled">
        <div class="cp-panel glass-card liquid-surface-inner">
          <h1 class="cp-panel__h">Cockpit geschlossen</h1>
          <p class="cp-muted">Das Cockpit ist derzeit nicht geöffnet.</p>
        </div>
      </template>

      <template v-else-if="bootstrap && !unlocked">
        <div class="cp-panel glass-card liquid-surface-inner">
          <h1 class="cp-panel__h">PIN eingeben</h1>
          <input
              v-model="pin"
              type="text"
              inputmode="numeric"
              maxlength="6"
              autocomplete="one-time-code"
              class="glass-input cp-pin"
              @keydown.enter.prevent="unlock"
          />
          <p v-if="pinError" class="glass-alert-error !mb-0">{{ pinError }}</p>
          <button
              type="button"
              class="glass-btn-accent cp-btn-block"
              :disabled="unlocking || pin.length !== 6"
              @click="unlock"
          >
            Entsperren
          </button>
        </div>
      </template>

      <template v-else-if="unlocked">
        <div class="cp-panel cp-panel--wide">
          <div class="cp-home-brand">
            <div class="cp-home-brand__title">Cockpit</div>
            <div class="cp-home-brand__event">{{ bootstrap?.event_name || slug }}</div>
          </div>

          <RobotGameRoundsPanel
              :event-id="bootstrap?.event_id ?? null"
              :rounds-api-path="roundsApiPath"
              :http="api"
          />

          <section class="glass-card liquid-surface-inner cp-more">
            <h2 class="cp-more__heading">Weitere Live-Tools</h2>
            <p class="cp-more__text">
              Hier werden später weitere mobile Funktionen für den Veranstaltungstag ergänzt werden.
            </p>
          </section>
        </div>
      </template>
    </main>
  </div>
</template>

<style scoped>
.cp-app {
  min-height: 100dvh;
  color: var(--color-text);
  display: flex;
  flex-direction: column;
}

.cp-app__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  padding-top: max(0.85rem, env(safe-area-inset-top));
  border-bottom: 1px solid var(--liquid-border-soft);
  position: sticky;
  top: 0;
  z-index: 2;
  border-radius: 0;
}

.cp-app__brand {
  display: flex;
  align-items: center;
  min-width: 0;
}

.cp-app__logo {
  display: block;
  height: 1.75rem;
  width: auto;
}

.cp-app__main {
  flex: 1;
  padding: 1rem;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}

.cp-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-width: 40rem;
  margin: 0 auto;
}

.cp-panel--wide {
  max-width: 48rem;
}

.cp-panel__h {
  font-size: 1.35rem;
  font-weight: 750;
  margin: 0;
  color: var(--color-text);
}

.cp-muted {
  color: var(--color-text-muted);
  font-size: 0.9rem;
  margin: 0;
}

.cp-pin {
  width: 100%;
  text-align: center;
  letter-spacing: 0.35em;
  font-size: 1.6rem;
  font-variant-numeric: tabular-nums;
}

.cp-btn-block {
  width: 100%;
}

.cp-home-brand {
  text-align: center;
  margin: 0.15rem 0 0.35rem;
}

.cp-home-brand__title {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--color-text-muted);
}

.cp-home-brand__event {
  font-weight: 750;
  font-size: 1.2rem;
  line-height: 1.25;
  color: var(--color-text);
}

.cp-more {
  padding: 1rem 1.25rem;
}

.cp-more__heading {
  font-size: 1rem;
  font-weight: 700;
  margin: 0 0 0.35rem;
  color: var(--color-text);
}

.cp-more__text {
  margin: 0;
  font-size: 0.9rem;
  color: var(--color-text-muted);
}
</style>

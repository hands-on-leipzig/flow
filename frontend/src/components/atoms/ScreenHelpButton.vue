<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref, watch} from 'vue'
import {useRoute} from 'vue-router'
import axios from 'axios'

defineOptions({name: 'ScreenHelpButton'})

type Step = {id: number; body: string; sort_order: number}
type Action = {id: number; title: string; sort_order: number; steps: Step[]}
type ScreenArticle = {
  key: string
  name: string
  description: string | null
  must_do: string | null
  can_do: string | null
  actions: Action[]
}

const ROUTE_KEYS: Record<string, string> = {
  '/plan/publish': 'publish-distribution',
  '/plan/teams/data': 'teams-data',
  '/plan/teams/explore': 'teams-explore',
  '/plan/teams/challenge': 'teams-challenge',
  '/plan/teams/future_8': 'teams-future_8',
}

const route = useRoute()
const open = ref(false)
const article = ref<ScreenArticle | null>(null)
const available = ref(false)

const screenKey = computed(() => {
  const path = (route.path || '').replace(/\/$/, '') || '/'
  return ROUTE_KEYS[path] ?? null
})

async function load() {
  const key = screenKey.value
  if (!key) {
    available.value = false
    article.value = null
    return
  }
  try {
    const {data} = await axios.get(`/help/screens/${key}`)
    article.value = data
    available.value = true
  } catch {
    article.value = null
    available.value = false
    open.value = false
  }
}

async function toggle() {
  if (!available.value) return
  if (!open.value) {
    await load()
    if (!available.value) return
    open.value = true
  } else {
    open.value = false
  }
}

function onKey(event: KeyboardEvent) {
  if (event.key === 'Escape') open.value = false
}

watch(screenKey, () => {
  open.value = false
  void load()
})

onMounted(() => {
  window.addEventListener('keydown', onKey)
  void load()
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKey)
})

const descriptionText = computed(() => article.value?.description?.trim() || 'Noch nicht beschrieben.')
const mustDoText = computed(() => article.value?.must_do?.trim() || 'Alles automatisch')
const canDoText = computed(() => article.value?.can_do?.trim() || 'Noch nicht beschrieben.')
const articleActions = computed(() => article.value?.actions ?? [])
</script>

<template>
  <template v-if="available">
    <button
        type="button"
        class="screen-help-btn"
        aria-label="Über diesen Screen"
        title="Über diesen Screen"
        @click="toggle"
    >
      <i class="bi bi-question-circle" aria-hidden="true"/>
    </button>
    <Teleport to="body">
      <aside
          v-if="open && article"
          class="screen-help-panel"
          role="dialog"
          aria-labelledby="screen-help-title"
      >
        <div class="screen-help-panel__head">
          <h2 id="screen-help-title" class="text-lg font-semibold !mb-0">{{ article.name }}</h2>
          <button type="button" class="glass-btn-secondary !px-2 !py-1" aria-label="Schließen" @click="open = false">
            ×
          </button>
        </div>
        <div class="screen-help-panel__body">
          <section>
            <h3 class="text-sm font-semibold">Beschreibung</h3>
            <p class="whitespace-pre-wrap text-sm">{{ descriptionText }}</p>
          </section>
          <section>
            <h3 class="text-sm font-semibold">Muss ich tun</h3>
            <p class="whitespace-pre-wrap text-sm">{{ mustDoText }}</p>
          </section>
          <section>
            <h3 class="text-sm font-semibold">Kann ich tun</h3>
            <p class="whitespace-pre-wrap text-sm">{{ canDoText }}</p>
          </section>
          <section v-if="articleActions.length" class="space-y-2">
            <details v-for="action in articleActions" :key="action.id" class="screen-help-panel__action">
              <summary class="cursor-pointer font-medium text-sm">{{ action.title }}</summary>
              <ol class="list-decimal ml-5 mt-2 space-y-1 text-sm">
                <li v-for="step in action.steps" :key="step.id">{{ step.body }}</li>
              </ol>
            </details>
          </section>
        </div>
      </aside>
    </Teleport>
  </template>
</template>

<style scoped>
.screen-help-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--color-accent);
  font-size: 1.35rem;
  line-height: 1;
  cursor: pointer;
}
.screen-help-panel {
  position: fixed;
  top: 0;
  right: 0;
  z-index: 80;
  width: 28rem;
  max-width: 100%;
  height: 100vh;
  overflow: auto;
  background: #fff;
  color: var(--color-text);
  border: 1px solid var(--color-accent);
  box-shadow: -8px 0 24px rgba(0, 0, 0, 0.12);
  padding: 1rem 1.25rem 2rem;
}
.screen-help-panel__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
}
.screen-help-panel__body {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
.screen-help-panel__action {
  border-top: 1px solid var(--color-border);
  padding-top: 0.5rem;
}
</style>

<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref, watch} from 'vue'
import {useRoute} from 'vue-router'
import axios from 'axios'
import HelpActionFeedback from '@/components/atoms/HelpActionFeedback.vue'
import {useAdminInlineVisibility} from '@/composables/useAdminInlineVisibility'
import {apiError} from '@/utils/apiError'
import {showGlassToast} from '@/composables/useGlassToast'

defineOptions({name: 'ScreenHelpButton'})

type ActionScreen = {id: number; key: string; name: string; route_path: string}
type Action = {
  id: number
  title: string
  body: string | null
  screens: ActionScreen[]
}
type ScreenArticle = {
  id: number
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
const {showAdminInline} = useAdminInlineVisibility()
type ScreenTextField = 'description' | 'must_do' | 'can_do'

const open = ref(false)
const article = ref<ScreenArticle | null>(null)
const available = ref(false)
const saving = ref(false)
const editingField = ref<ScreenTextField | null>(null)
const description = ref('')
const mustDo = ref('')
const canDo = ref('')

const screenKey = computed(() => {
  const path = (route.path || '').replace(/\/$/, '') || '/'
  return ROUTE_KEYS[path] ?? null
})

function applyArticle(data: ScreenArticle) {
  article.value = data
  description.value = data.description ?? ''
  mustDo.value = data.must_do ?? ''
  canDo.value = data.can_do ?? ''
}

function resetDraftsFromArticle() {
  if (!article.value) return
  description.value = article.value.description ?? ''
  mustDo.value = article.value.must_do ?? ''
  canDo.value = article.value.can_do ?? ''
}

function startEdit(field: ScreenTextField) {
  resetDraftsFromArticle()
  editingField.value = field
}

function draftFor(field: ScreenTextField): string {
  if (field === 'description') return description.value
  if (field === 'must_do') return mustDo.value
  return canDo.value
}

async function load() {
  const key = screenKey.value
  if (!key) {
    available.value = false
    article.value = null
    return
  }
  try {
    const {data} = await axios.get(`/help/screens/${key}`)
    applyArticle(data)
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

function onActionToggle(event: Event, id: number) {
  const details = event.currentTarget as HTMLDetailsElement
  const opened = ('newState' in event && (event as ToggleEvent).newState === 'open') || details.open
  if (!opened) return
  axios.post(`/help/actions/${id}/open`).catch((e) => console.warn(e))
}

async function saveField(field: ScreenTextField) {
  if (!article.value) return
  saving.value = true
  try {
    const {data} = await axios.put(`/admin/help/screens/${article.value.id}`, {
      [field]: draftFor(field),
    })
    applyArticle({...article.value, ...data, actions: article.value.actions})
    editingField.value = null
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    saving.value = false
  }
}

watch(screenKey, () => {
  open.value = false
  editingField.value = null
  void load()
})

watch(open, (isOpen) => {
  if (!isOpen) editingField.value = null
})

watch(showAdminInline, (visible) => {
  if (!visible) editingField.value = null
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
            <div class="screen-help-panel__section-head">
              <h3 class="text-sm font-semibold !mb-0">Worum geht es hier?</h3>
              <div v-if="showAdminInline && editingField !== 'description'" class="screen-help-panel__admin">
                <span class="screen-help-panel__admin-mark" title="Admin" aria-hidden="true">
                  <i class="bi bi-shield-lock"/>
                </span>
                <button type="button" class="glass-btn-secondary !px-3 !py-1" @click="startEdit('description')">
                  Ändern
                </button>
              </div>
            </div>
            <template v-if="editingField === 'description'">
              <textarea v-model="description" rows="4" class="screen-help-panel__input mt-1"/>
              <button
                  type="button"
                  class="glass-btn-accent !px-4 !py-2 mt-2"
                  :disabled="saving"
                  @click="saveField('description')"
              >
                Speichern
              </button>
            </template>
            <p v-else class="whitespace-pre-wrap text-sm mt-1">{{ descriptionText }}</p>
          </section>
          <section>
            <div class="screen-help-panel__section-head">
              <h3 class="text-sm font-semibold !mb-0">Was muss man hier tun?</h3>
              <div v-if="showAdminInline && editingField !== 'must_do'" class="screen-help-panel__admin">
                <span class="screen-help-panel__admin-mark" title="Admin" aria-hidden="true">
                  <i class="bi bi-shield-lock"/>
                </span>
                <button type="button" class="glass-btn-secondary !px-3 !py-1" @click="startEdit('must_do')">
                  Ändern
                </button>
              </div>
            </div>
            <template v-if="editingField === 'must_do'">
              <textarea v-model="mustDo" rows="3" class="screen-help-panel__input mt-1"/>
              <button
                  type="button"
                  class="glass-btn-accent !px-4 !py-2 mt-2"
                  :disabled="saving"
                  @click="saveField('must_do')"
              >
                Speichern
              </button>
            </template>
            <p v-else class="whitespace-pre-wrap text-sm mt-1">{{ mustDoText }}</p>
          </section>
          <section>
            <div class="screen-help-panel__section-head">
              <h3 class="text-sm font-semibold !mb-0">Was kann man hier tun?</h3>
              <div v-if="showAdminInline && editingField !== 'can_do'" class="screen-help-panel__admin">
                <span class="screen-help-panel__admin-mark" title="Admin" aria-hidden="true">
                  <i class="bi bi-shield-lock"/>
                </span>
                <button type="button" class="glass-btn-secondary !px-3 !py-1" @click="startEdit('can_do')">
                  Ändern
                </button>
              </div>
            </div>
            <template v-if="editingField === 'can_do'">
              <textarea v-model="canDo" rows="3" class="screen-help-panel__input mt-1"/>
              <button
                  type="button"
                  class="glass-btn-accent !px-4 !py-2 mt-2"
                  :disabled="saving"
                  @click="saveField('can_do')"
              >
                Speichern
              </button>
            </template>
            <p v-else class="whitespace-pre-wrap text-sm mt-1">{{ canDoText }}</p>
          </section>
          <section v-if="articleActions.length" class="space-y-2">
            <details
                v-for="action in articleActions"
                :key="action.id"
                class="screen-help-panel__action"
                @toggle="onActionToggle($event, action.id)"
            >
              <summary class="cursor-pointer font-medium text-sm">{{ action.title }}</summary>
              <p class="whitespace-pre-wrap text-sm mt-2">{{ action.body }}</p>
              <div class="mt-2">
                <HelpActionFeedback :action-id="action.id"/>
              </div>
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
.screen-help-panel__section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}
.screen-help-panel__admin {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  flex-shrink: 0;
}
.screen-help-panel__admin-mark {
  display: inline-flex;
  align-items: center;
  color: var(--color-text-muted);
  opacity: 0.85;
  line-height: 1;
}
.screen-help-panel__action {
  border-top: 1px solid var(--color-border);
  padding-top: 0.5rem;
}
.screen-help-panel__input {
  display: block;
  width: 100%;
  padding: 0.4rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  background: #fff;
  color: var(--color-text);
}
</style>

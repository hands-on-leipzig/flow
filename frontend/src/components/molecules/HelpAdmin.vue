<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import {RouterLink} from 'vue-router'
import axios from 'axios'
import draggable from 'vuedraggable'
import PanelSplitter from '@/components/atoms/PanelSplitter.vue'
import {apiError} from '@/utils/apiError'
import {showGlassToast} from '@/composables/useGlassToast'
import {useEventStore} from '@/stores/event'
import {helpJumpPath} from '@/utils/helpRoutes'

defineOptions({name: 'HelpAdmin'})

type HelpTopic = {id: number; key: string; name: string; sort_order: number}
type HelpScreen = {
  id: number
  key: string
  name: string
  route_path: string
  description: string | null
  must_do: string | null
  can_do: string | null
  sort_order: number
}
type HelpAction = {
  id: number
  help_topic: number
  title: string
  body: string | null
  open_count: number
  helpful_yes: number
  helpful_no: number
  sort_order: number
  help_screen_ids: number[]
  screens: {id: number; key: string; name: string; route_path: string}[]
  topic: {id: number; key: string; name: string} | null
}

type Tab = 'seiten' | 'aktionen' | 'zuordnung' | 'themen'

const SOURCE_GROUP = {name: 'help-assign', pull: 'clone' as const, put: false}
const DROP_GROUP = {name: 'help-assign', pull: false, put: true}

const tab = ref<Tab>('seiten')
const screens = ref<HelpScreen[]>([])
const topics = ref<HelpTopic[]>([])
const actions = ref<HelpAction[]>([])
const loading = ref(false)
const saving = ref(false)
const selectedScreenId = ref<number | null>(null)
const selectedActionId = ref<number | null>(null)
const selectedTopicId = ref<number | null>(null)
const tapActionId = ref<number | null>(null)
const isDragging = ref(false)
const leftWidth = ref(36)
const assignLeftWidth = ref(68)
const description = ref('')
const mustDo = ref('')
const canDo = ref('')
const actionTitle = ref('')
const actionBody = ref('')
const actionTopic = ref<number | null>(null)
const newTopicKey = ref('')
const newTopicName = ref('')
const topicKey = ref('')
const topicName = ref('')

function byTitleDe<T extends {title: string; id: number}>(a: T, b: T) {
  const t = a.title.localeCompare(b.title, 'de')
  return t !== 0 ? t : a.id - b.id
}

const selectedScreen = computed(() => screens.value.find((s) => s.id === selectedScreenId.value) ?? null)
const selectedAction = computed(() => actions.value.find((a) => a.id === selectedActionId.value) ?? null)
const selectedTopic = computed(() => topics.value.find((t) => t.id === selectedTopicId.value) ?? null)
const eventStore = useEventStore()

function screenHref(screen: HelpScreen): string | null {
  return helpJumpPath(screen, eventStore.selectedEvent)
}

function screenPathLabel(screen: HelpScreen): string {
  return screenHref(screen) ?? screen.route_path
}

function assignedTo(screenId: number): HelpAction[] {
  return actions.value.filter((a) => a.help_screen_ids.includes(screenId)).slice().sort(byTitleDe)
}

function actionsForTopic(topicId: number): HelpAction[] {
  return actions.value.filter((a) => a.help_topic === topicId).slice().sort(byTitleDe)
}

function upsertAction(data: HelpAction) {
  const idx = actions.value.findIndex((a) => a.id === data.id)
  if (idx >= 0) {
    const copy = [...actions.value]
    copy[idx] = data
    actions.value = copy
  } else {
    actions.value = [...actions.value, data]
  }
}

async function loadAll() {
  loading.value = true
  try {
    const [s, t, a] = await Promise.all([
      axios.get('/admin/help/screens'),
      axios.get('/admin/help/topics'),
      axios.get('/admin/help/actions'),
    ])
    screens.value = s.data
    topics.value = t.data
    actions.value = a.data
    if (selectedScreenId.value == null && screens.value.length) {
      selectedScreenId.value = screens.value[0].id
      fillScreenForm(screens.value[0])
    }
    if (selectedActionId.value == null && actions.value.length) {
      selectAction(actions.value.slice().sort(byTitleDe)[0])
    }
    if (selectedTopicId.value == null && topics.value.length) {
      selectTopic(topics.value[0])
    }
  } catch (e) {
    showGlassToast(apiError(e, 'Hilfe konnte nicht geladen werden'), 'error')
  } finally {
    loading.value = false
  }
}

function fillScreenForm(screen: HelpScreen) {
  description.value = screen.description ?? ''
  mustDo.value = screen.must_do ?? ''
  canDo.value = screen.can_do ?? ''
}

function selectScreen(screen: HelpScreen) {
  selectedScreenId.value = screen.id
  fillScreenForm(screen)
}

function selectAction(action: HelpAction) {
  selectedActionId.value = action.id
  actionTitle.value = action.title
  actionBody.value = action.body ?? ''
  actionTopic.value = action.help_topic
}

function selectTopic(topic: HelpTopic) {
  selectedTopicId.value = topic.id
  topicKey.value = topic.key
  topicName.value = topic.name
}

function clearTopicForm() {
  selectedTopicId.value = null
  topicKey.value = ''
  topicName.value = ''
}

async function assign(screenId: number, actionId: number) {
  try {
    const {data} = await axios.post(`/admin/help/screens/${screenId}/actions`, {help_action: actionId})
    upsertAction(data)
  } catch (e) {
    showGlassToast(apiError(e, 'Zuordnung fehlgeschlagen'), 'error')
  }
}

async function unassign(screenId: number, actionId: number) {
  try {
    await axios.delete(`/admin/help/screens/${screenId}/actions/${actionId}`)
    const current = actions.value.find((a) => a.id === actionId)
    if (current) {
      upsertAction({
        ...current,
        help_screen_ids: current.help_screen_ids.filter((id) => id !== screenId),
        screens: current.screens.filter((s) => s.id !== screenId),
      })
    }
  } catch (e) {
    showGlassToast(apiError(e, 'Zuordnung konnte nicht gelöst werden'), 'error')
  }
}

function onDrop(screenId: number, event: {added?: {element: HelpAction}}) {
  const action = event.added?.element
  if (action) void assign(screenId, action.id)
}

function onTapScreen(screenId: number) {
  if (tapActionId.value == null) return
  void assign(screenId, tapActionId.value)
}

async function saveScreen() {
  if (selectedScreenId.value == null) return
  saving.value = true
  try {
    const {data} = await axios.put(`/admin/help/screens/${selectedScreenId.value}`, {
      description: description.value,
      must_do: mustDo.value,
      can_do: canDo.value,
    })
    const idx = screens.value.findIndex((s) => s.id === data.id)
    if (idx >= 0) {
      const copy = [...screens.value]
      copy[idx] = data
      screens.value = copy
    }
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    saving.value = false
  }
}

async function saveAction() {
  if (selectedActionId.value == null || actionTopic.value == null) return
  saving.value = true
  try {
    const {data} = await axios.put(`/admin/help/actions/${selectedActionId.value}`, {
      title: actionTitle.value,
      body: actionBody.value,
      help_topic: actionTopic.value,
    })
    upsertAction(data)
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    saving.value = false
  }
}

async function deleteAction() {
  if (selectedActionId.value == null) return
  if (!window.confirm('Löschen?')) return
  try {
    const id = selectedActionId.value
    await axios.delete(`/admin/help/actions/${id}`)
    actions.value = actions.value.filter((a) => a.id !== id)
    selectedActionId.value = actions.value[0]?.id ?? null
    if (selectedAction.value) selectAction(selectedAction.value)
    else {
      actionTitle.value = ''
      actionBody.value = ''
      actionTopic.value = topics.value[0]?.id ?? null
    }
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Aktion konnte nicht gelöscht werden'), 'error')
  }
}

async function addAction() {
  const topicId = topics.value[0]?.id
  if (topicId == null) {
    showGlassToast('Thema ist nötig', 'info')
    return
  }
  try {
    const {data} = await axios.post('/admin/help/actions', {
      help_topic: topicId,
      title: 'Neue Aktion',
    })
    upsertAction(data)
    selectAction(data)
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Aktion konnte nicht angelegt werden'), 'error')
  }
}

async function copyAction() {
  const topicId = actionTopic.value ?? selectedAction.value?.help_topic ?? null
  if (topicId == null) {
    showGlassToast('Thema ist nötig', 'info')
    return
  }
  const sourceTitle = (actionTitle.value.trim() || selectedAction.value?.title || 'Aktion').slice(0, 255)
  const title = `[Kopie von] ${sourceTitle}`.slice(0, 255)
  try {
    const {data} = await axios.post('/admin/help/actions', {
      help_topic: topicId,
      title,
      body: actionBody.value,
    })
    upsertAction(data)
    selectAction(data)
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Aktion konnte nicht kopiert werden'), 'error')
  }
}

async function addTopic() {
  if (!newTopicKey.value.trim() || !newTopicName.value.trim()) {
    showGlassToast('Schlüssel und Name sind nötig', 'info')
    return
  }
  try {
    const {data} = await axios.post('/admin/help/topics', {
      key: newTopicKey.value.trim(),
      name: newTopicName.value.trim(),
    })
    topics.value = [...topics.value, data]
    newTopicKey.value = ''
    newTopicName.value = ''
    selectTopic(data)
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Thema konnte nicht angelegt werden'), 'error')
  }
}

async function saveTopic() {
  if (selectedTopicId.value == null) return
  saving.value = true
  try {
    const {data} = await axios.put(`/admin/help/topics/${selectedTopicId.value}`, {
      name: topicName.value,
      key: topicKey.value,
    })
    const idx = topics.value.findIndex((t) => t.id === data.id)
    if (idx >= 0) {
      const copy = [...topics.value]
      copy[idx] = data
      topics.value = copy
    }
    topicKey.value = data.key
    topicName.value = data.name
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Thema konnte nicht gespeichert werden'), 'error')
  } finally {
    saving.value = false
  }
}

async function deleteTopic() {
  if (selectedTopic.value == null) return
  try {
    const id = selectedTopic.value.id
    await axios.delete(`/admin/help/topics/${id}`)
    topics.value = topics.value.filter((t) => t.id !== id)
    if (topics.value[0]) selectTopic(topics.value[0])
    else clearTopicForm()
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Thema konnte nicht gelöscht werden'), 'error')
  }
}

async function moveTopic(index: number, direction: -1 | 1) {
  const next = index + direction
  if (next < 0 || next >= topics.value.length) return
  const ids = topics.value.map((t) => t.id)
  const tmp = ids[index]
  ids[index] = ids[next]
  ids[next] = tmp
  try {
    await axios.post('/admin/help/topics/reorder', {ids})
    const copy = [...topics.value]
    const swapped = copy[index]
    copy[index] = copy[next]
    copy[next] = swapped
    topics.value = copy
  } catch (e) {
    showGlassToast(apiError(e, 'Reihenfolge konnte nicht gespeichert werden'), 'error')
  }
}

onMounted(() => {
  void loadAll()
})
</script>

<template>
  <div class="help-admin">
    <div class="help-admin__top">
      <h2 class="text-xl font-bold">Hilfe</h2>
      <div class="glass-tabs">
        <button type="button" class="glass-tab" :class="{'glass-tab--active': tab === 'seiten'}" @click="tab = 'seiten'">Seiten</button>
        <button type="button" class="glass-tab" :class="{'glass-tab--active': tab === 'aktionen'}" @click="tab = 'aktionen'">Aktionen</button>
        <button type="button" class="glass-tab" :class="{'glass-tab--active': tab === 'zuordnung'}" @click="tab = 'zuordnung'">Zuordnung</button>
        <button type="button" class="glass-tab" :class="{'glass-tab--active': tab === 'themen'}" @click="tab = 'themen'">Themen</button>
      </div>
    </div>

    <p v-if="loading" class="text-[var(--color-text-subtle)]">Lade …</p>

    <div v-else-if="tab === 'zuordnung'" class="help-admin__split">
      <section class="help-admin__pane help-admin__pane--left" :style="{ flex: `0 0 ${assignLeftWidth}%` }">
        <div class="help-admin__scroll space-y-3 p-2">
          <div
              v-for="screen in screens"
              :key="screen.id"
              class="glass-card liquid-surface-inner p-3"
              @click="onTapScreen(screen.id)"
          >
            <p class="font-semibold">{{ screen.name }}</p>
            <code class="help-admin__key">{{ screen.key }}</code>
            <div
                class="glass-dropzone mt-2"
                :class="{'glass-dropzone--dragging': isDragging}"
            >
              <p v-if="!assignedTo(screen.id).length" class="glass-dropzone__empty text-sm">Noch nichts zugewiesen</p>
              <draggable
                  class="hidden md:flex flex-wrap gap-2 glass-dropzone__list"
                  :list="assignedTo(screen.id)"
                  item-key="id"
                  :group="DROP_GROUP"
                  @start="isDragging = true"
                  @end="isDragging = false"
                  @change="onDrop(screen.id, $event)"
              >
                <template #item="{ element }">
                  <span class="help-admin__chip">
                    {{ element.title }}
                    <button type="button" class="help-admin__chip-x" aria-label="Entfernen" @click.stop="unassign(screen.id, element.id)">×</button>
                  </span>
                </template>
              </draggable>
              <div class="md:hidden flex flex-wrap gap-2">
                <span v-for="element in assignedTo(screen.id)" :key="element.id" class="help-admin__chip">
                  {{ element.title }}
                  <button type="button" class="help-admin__chip-x" aria-label="Entfernen" @click.stop="unassign(screen.id, element.id)">×</button>
                </span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <PanelSplitter
          v-model="assignLeftWidth"
          class="hidden lg:flex help-admin__splitter"
          :min="40"
          :max="80"
          storage-key="flow-help-assign-split"
      />

      <section class="help-admin__pane help-admin__pane--right">
        <div class="help-admin__scroll p-2 space-y-2">
          <details v-for="topic in topics" :key="topic.id" open class="glass-card liquid-surface-inner p-2">
            <summary class="cursor-pointer font-medium">{{ topic.name }}</summary>
            <draggable
                class="hidden md:flex flex-wrap gap-2 mt-2"
                :list="actionsForTopic(topic.id)"
                item-key="id"
                :group="SOURCE_GROUP"
                :sort="false"
                @start="isDragging = true"
                @end="isDragging = false"
            >
              <template #item="{ element }">
                <span class="help-admin__chip help-admin__chip--source">{{ element.title }}</span>
              </template>
            </draggable>
            <div class="md:hidden flex flex-wrap gap-2 mt-2">
              <button
                  v-for="element in actionsForTopic(topic.id)"
                  :key="element.id"
                  type="button"
                  class="help-admin__chip help-admin__chip--source"
                  :class="{'help-admin__chip--selected': tapActionId === element.id}"
                  @click="tapActionId = element.id"
              >
                {{ element.title }}
              </button>
            </div>
          </details>
        </div>
      </section>
    </div>

    <div v-else class="help-admin__split">
      <section class="help-admin__pane help-admin__pane--left" :style="{ flex: `0 0 ${leftWidth}%` }">
        <div class="help-admin__scroll glass-card liquid-surface-inner !p-2">
          <template v-if="tab === 'seiten'">
            <div
                v-for="screen in screens"
                :key="screen.id"
                class="help-admin__nav"
                :class="{'help-admin__nav--active': screen.id === selectedScreenId}"
            >
              <button type="button" class="help-admin__nav-name" @click="selectScreen(screen)">
                {{ screen.name }}
              </button>
              <RouterLink v-if="screenHref(screen)" :to="screenPathLabel(screen)" class="help-admin__route">
                {{ screenPathLabel(screen) }}
              </RouterLink>
              <code v-else class="help-admin__key">{{ screen.route_path }}</code>
            </div>
          </template>

          <template v-else-if="tab === 'aktionen'">
            <button type="button" class="glass-btn-secondary !px-3 !py-1.5 mb-2" @click="addAction">Hinzufügen</button>
            <details v-for="topic in topics" :key="topic.id" open class="help-admin__topic">
              <summary class="help-admin__topic-summary">{{ topic.name }}</summary>
              <button
                  v-for="action in actionsForTopic(topic.id)"
                  :key="action.id"
                  type="button"
                  class="help-admin__nav"
                  :class="{'help-admin__nav--active': action.id === selectedActionId}"
                  @click="selectAction(action)"
              >
                {{ action.title }}
              </button>
            </details>
          </template>

          <template v-else-if="tab === 'themen'">
            <div class="help-admin__composer">
              <label class="text-sm font-medium">
                Schlüssel
                <input v-model="newTopicKey" class="glass-input glass-input--sm liquid-surface-control mt-1" maxlength="64"/>
              </label>
              <label class="text-sm font-medium">
                Name
                <input v-model="newTopicName" class="glass-input glass-input--sm liquid-surface-control mt-1" maxlength="255"/>
              </label>
              <button type="button" class="glass-btn-secondary !px-3 !py-1.5" @click="addTopic">Hinzufügen</button>
            </div>
            <div
                v-for="(topic, index) in topics"
                :key="topic.id"
                class="help-admin__nav help-admin__nav--row"
                :class="{'help-admin__nav--active': topic.id === selectedTopicId}"
            >
              <button type="button" class="help-admin__nav-name" @click="selectTopic(topic)">
                {{ topic.name }}
              </button>
              <div class="help-admin__order">
                <button type="button" class="glass-btn-secondary !px-2 !py-1" :disabled="index === 0" @click="moveTopic(index, -1)">↑</button>
                <button type="button" class="glass-btn-secondary !px-2 !py-1" :disabled="index === topics.length - 1" @click="moveTopic(index, 1)">↓</button>
              </div>
            </div>
          </template>
        </div>
      </section>

      <PanelSplitter
          v-model="leftWidth"
          class="hidden lg:flex help-admin__splitter"
          :min="24"
          :max="60"
          storage-key="flow-help-split"
      />

      <section class="help-admin__pane help-admin__pane--right">
        <div v-if="tab === 'seiten' && selectedScreen" class="help-admin__scroll help-admin__scroll--fill glass-card liquid-surface-inner p-4">
          <div class="help-admin__editor">
            <p class="text-sm text-[var(--color-text-muted)] shrink-0">
              {{ selectedScreen.name }}
              ·
              <RouterLink v-if="screenHref(selectedScreen)" :to="screenPathLabel(selectedScreen)" class="help-admin__route">
                {{ screenPathLabel(selectedScreen) }}
              </RouterLink>
              <code v-else class="help-admin__key">{{ selectedScreen.route_path }}</code>
            </p>
            <div class="help-admin__fields">
              <label class="help-admin__field text-sm font-medium">
                Worum geht es hier?
                <textarea v-model="description" class="glass-input liquid-surface-control mt-1 help-admin__textarea"/>
              </label>
              <label class="help-admin__field text-sm font-medium">
                Was muss man hier tun?
                <textarea v-model="mustDo" class="glass-input liquid-surface-control mt-1 help-admin__textarea"/>
              </label>
              <label class="help-admin__field text-sm font-medium">
                Was kann man hier tun?
                <textarea v-model="canDo" class="glass-input liquid-surface-control mt-1 help-admin__textarea"/>
              </label>
            </div>
            <button type="button" class="glass-btn-accent !px-4 !py-2 shrink-0 self-start" :disabled="saving" @click="saveScreen">Speichern</button>
          </div>
        </div>

        <div v-else-if="tab === 'aktionen' && selectedAction" class="help-admin__scroll help-admin__scroll--fill glass-card liquid-surface-inner p-4">
          <div class="help-admin__editor">
            <label class="block text-sm font-medium shrink-0">
              Titel
              <input v-model="actionTitle" class="glass-input liquid-surface-control mt-1" maxlength="255"/>
            </label>
            <label class="help-admin__field text-sm font-medium">
              Text
              <textarea v-model="actionBody" class="glass-input liquid-surface-control mt-1 help-admin__textarea"/>
            </label>
            <label class="block text-sm font-medium shrink-0">
              Thema
              <select v-model.number="actionTopic" class="glass-input liquid-surface-control mt-1">
                <option v-for="topic in topics" :key="topic.id" :value="topic.id">{{ topic.name }}</option>
              </select>
            </label>
            <div class="text-sm space-y-0.5 shrink-0">
              <p>Öffnungen: {{ selectedAction.open_count }}</p>
              <p>Ja: {{ selectedAction.helpful_yes }}</p>
              <p>Nein: {{ selectedAction.helpful_no }}</p>
            </div>
            <div class="flex gap-2 shrink-0">
              <button type="button" class="glass-btn-accent !px-4 !py-2" :disabled="saving" @click="saveAction">Speichern</button>
              <button type="button" class="glass-btn-secondary !px-4 !py-2" @click="copyAction">Kopieren</button>
              <button type="button" class="glass-btn-secondary !px-4 !py-2" @click="deleteAction">Löschen</button>
            </div>
          </div>
        </div>

        <div v-else-if="tab === 'themen' && selectedTopic" class="help-admin__scroll help-admin__scroll--fill glass-card liquid-surface-inner p-4">
          <div class="help-admin__editor help-admin__editor--compact">
            <label class="block text-sm font-medium">
              Schlüssel
              <input v-model="topicKey" class="glass-input liquid-surface-control mt-1" maxlength="64"/>
            </label>
            <label class="block text-sm font-medium">
              Name
              <input v-model="topicName" class="glass-input liquid-surface-control mt-1" maxlength="255"/>
            </label>
            <div class="flex gap-2">
              <button type="button" class="glass-btn-accent !px-4 !py-2" :disabled="saving" @click="saveTopic">Speichern</button>
              <button type="button" class="glass-btn-secondary !px-4 !py-2" @click="deleteTopic">Löschen</button>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.help-admin {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  overflow: hidden;
  gap: 0.75rem;
}

.help-admin__top {
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.help-admin__split {
  display: flex;
  flex: 1 1 0%;
  min-height: 0;
  height: 100%;
  flex-direction: column;
  gap: 0.75rem;
  align-items: stretch;
  overflow: hidden;
}

.help-admin__pane {
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  height: 100%;
  overflow: hidden;
}

.help-admin__pane--right {
  flex: 1 1 auto;
}

.help-admin__scroll {
  flex: 1 1 0%;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: auto;
}

.help-admin__scroll--fill {
  display: flex;
  flex-direction: column;
}

.help-admin__splitter {
  flex-shrink: 0;
}

.help-admin__nav {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  width: 100%;
  text-align: left;
  padding: 0.5rem 0.75rem;
  border-radius: 0.5rem;
  color: var(--color-text);
}

.help-admin__nav--row {
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
}

.help-admin__nav-name {
  width: 100%;
  text-align: left;
  color: inherit;
  min-width: 0;
}

.help-admin__nav--active {
  background: var(--color-bg-hover, rgba(0, 0, 0, 0.06));
}

.help-admin__order {
  display: flex;
  flex-shrink: 0;
  gap: 0.25rem;
}

.help-admin__composer {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid var(--color-border);
}

.help-admin__topic {
  margin-top: 0.35rem;
}

.help-admin__topic-summary {
  cursor: pointer;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-muted);
  padding: 0.35rem 0.5rem;
}

.help-admin__key {
  font-size: 0.75rem;
  color: var(--color-text-subtle);
}

.help-admin__route {
  font-size: 0.75rem;
  color: var(--color-accent);
  text-decoration: none;
}

.help-admin__route:hover {
  text-decoration: underline;
  text-underline-offset: 0.14em;
}

.help-admin__editor {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
}

.help-admin__editor--compact {
  height: auto;
}

.help-admin__fields {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.help-admin__field {
  flex: 1 1 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.help-admin__textarea {
  flex: 1 1 auto;
  min-height: 6rem;
  width: 100%;
  resize: none;
}

.help-admin__chip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.25rem 0.5rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  background: #fff;
  font-size: 0.8rem;
}

.help-admin__chip--source {
  cursor: grab;
}

.help-admin__chip--selected {
  outline: 2px solid var(--color-accent);
}

.help-admin__chip-x {
  border: 0;
  background: transparent;
  cursor: pointer;
  line-height: 1;
  padding: 0;
}

@media (min-width: 1024px) {
  .help-admin__split {
    flex-direction: row;
    gap: 0.55rem;
  }

  .help-admin__pane--left {
    height: 100%;
    max-height: 100%;
  }

  .help-admin__pane--right {
    min-height: 0;
    overflow: hidden;
  }
}

@media (max-width: 1023px) {
  .help-admin__pane--left {
    flex: 1 1 auto !important;
    max-height: 50vh;
  }
}
</style>

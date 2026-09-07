<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import {RouterLink} from 'vue-router'
import axios from 'axios'
import draggable from 'vuedraggable'
import {apiError} from '@/utils/apiError'
import {showGlassToast} from '@/composables/useGlassToast'

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
const tapActionId = ref<number | null>(null)
const isDragging = ref(false)
const description = ref('')
const mustDo = ref('')
const canDo = ref('')
const actionTitle = ref('')
const actionBody = ref('')
const actionTopic = ref<number | null>(null)
const newTopicKey = ref('')
const newTopicName = ref('')

function byTitleDe<T extends {title: string; id: number}>(a: T, b: T) {
  const t = a.title.localeCompare(b.title, 'de')
  return t !== 0 ? t : a.id - b.id
}

const selectedScreen = computed(() => screens.value.find((s) => s.id === selectedScreenId.value) ?? null)
const selectedAction = computed(() => actions.value.find((a) => a.id === selectedActionId.value) ?? null)

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
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Thema konnte nicht angelegt werden'), 'error')
  }
}

async function saveTopic(topic: HelpTopic) {
  try {
    const {data} = await axios.put(`/admin/help/topics/${topic.id}`, {name: topic.name, key: topic.key})
    const idx = topics.value.findIndex((t) => t.id === data.id)
    if (idx >= 0) {
      const copy = [...topics.value]
      copy[idx] = data
      topics.value = copy
    }
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Thema konnte nicht gespeichert werden'), 'error')
  }
}

async function deleteTopic(topic: HelpTopic) {
  try {
    await axios.delete(`/admin/help/topics/${topic.id}`)
    topics.value = topics.value.filter((t) => t.id !== topic.id)
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
  <div class="help-admin space-y-4">
    <h2 class="text-xl font-bold">Hilfe</h2>

    <div class="flex flex-wrap gap-2">
      <button type="button" class="glass-btn-secondary !px-3 !py-1.5" :class="{'help-admin__tab--on': tab === 'seiten'}" @click="tab = 'seiten'">Seiten</button>
      <button type="button" class="glass-btn-secondary !px-3 !py-1.5" :class="{'help-admin__tab--on': tab === 'aktionen'}" @click="tab = 'aktionen'">Aktionen</button>
      <button type="button" class="glass-btn-secondary !px-3 !py-1.5" :class="{'help-admin__tab--on': tab === 'zuordnung'}" @click="tab = 'zuordnung'">Zuordnung</button>
      <button type="button" class="glass-btn-secondary !px-3 !py-1.5" :class="{'help-admin__tab--on': tab === 'themen'}" @click="tab = 'themen'">Themen</button>
    </div>

    <p v-if="loading" class="text-[var(--color-text-subtle)]">Lade …</p>

    <div v-else-if="tab === 'zuordnung'" class="vol-staffing-body help-admin__assign">
      <div class="vol-staffing-pane vol-staffing-pane--main space-y-3 overflow-auto p-2">
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
      <aside class="vol-staffing-pane overflow-auto p-2 space-y-2">
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
      </aside>
    </div>

    <div v-else-if="tab === 'seiten'" class="help-admin__split">
      <aside class="glass-card liquid-surface-inner !p-2">
        <div
            v-for="screen in screens"
            :key="screen.id"
            class="help-admin__nav"
            :class="{'help-admin__nav--active': screen.id === selectedScreenId}"
        >
          <button type="button" class="help-admin__nav-name" @click="selectScreen(screen)">
            {{ screen.name }}
          </button>
          <RouterLink :to="screen.route_path" class="help-admin__route">{{ screen.route_path }}</RouterLink>
        </div>
      </aside>
      <section v-if="selectedScreen" class="glass-card liquid-surface-inner p-4 space-y-4 min-w-0">
        <p class="text-sm text-[var(--color-text-muted)]">
          {{ selectedScreen.name }}
          ·
          <RouterLink :to="selectedScreen.route_path" class="help-admin__route">{{ selectedScreen.route_path }}</RouterLink>
        </p>
        <label class="block text-sm font-medium">
          Worum geht es hier?
          <textarea v-model="description" rows="4" class="help-admin__input mt-1"/>
        </label>
        <label class="block text-sm font-medium">
          Was muss man hier tun?
          <textarea v-model="mustDo" rows="3" class="help-admin__input mt-1"/>
        </label>
        <label class="block text-sm font-medium">
          Was kann man hier tun?
          <textarea v-model="canDo" rows="3" class="help-admin__input mt-1"/>
        </label>
        <button type="button" class="glass-btn-accent !px-4 !py-2" :disabled="saving" @click="saveScreen">Speichern</button>
      </section>
    </div>

    <div v-else-if="tab === 'aktionen'" class="help-admin__split">
      <aside class="glass-card liquid-surface-inner !p-2">
        <button type="button" class="glass-btn-secondary !px-3 !py-1.5 mb-2" @click="addAction">Hinzufügen</button>
        <template v-for="topic in topics" :key="topic.id">
          <p class="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mt-2 mb-1">{{ topic.name }}</p>
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
        </template>
      </aside>
      <section v-if="selectedAction" class="glass-card liquid-surface-inner p-4 space-y-4 min-w-0">
        <label class="block text-sm font-medium">
          Titel
          <input v-model="actionTitle" class="help-admin__input mt-1" maxlength="255"/>
        </label>
        <label class="block text-sm font-medium">
          Text
          <textarea v-model="actionBody" rows="6" class="help-admin__input mt-1"/>
        </label>
        <label class="block text-sm font-medium">
          Thema
          <select v-model.number="actionTopic" class="help-admin__input mt-1">
            <option v-for="topic in topics" :key="topic.id" :value="topic.id">{{ topic.name }}</option>
          </select>
        </label>
        <p class="text-sm">Öffnungen: {{ selectedAction.open_count }}</p>
        <p class="text-sm">Ja: {{ selectedAction.helpful_yes }}</p>
        <p class="text-sm">Nein: {{ selectedAction.helpful_no }}</p>
        <div class="flex gap-2">
          <button type="button" class="glass-btn-accent !px-4 !py-2" :disabled="saving" @click="saveAction">Speichern</button>
          <button type="button" class="glass-btn-secondary !px-4 !py-2" @click="deleteAction">Löschen</button>
        </div>
      </section>
    </div>

    <section v-else-if="tab === 'themen'" class="glass-card liquid-surface-inner p-4 space-y-3">
      <h3 class="text-lg font-semibold">Themen</h3>
      <div class="flex flex-wrap gap-2 items-end">
        <label class="text-sm">
          Schlüssel
          <input v-model="newTopicKey" class="help-admin__input mt-1" maxlength="64"/>
        </label>
        <label class="text-sm">
          Name
          <input v-model="newTopicName" class="help-admin__input mt-1" maxlength="255"/>
        </label>
        <button type="button" class="glass-btn-secondary !px-3 !py-2" @click="addTopic">Hinzufügen</button>
      </div>
      <div v-for="(topic, index) in topics" :key="topic.id" class="flex flex-wrap gap-2 items-center">
        <input v-model="topic.key" class="help-admin__input w-36" maxlength="64"/>
        <input v-model="topic.name" class="help-admin__input flex-1 min-w-[8rem]" maxlength="255"/>
        <button type="button" class="glass-btn-secondary !px-2 !py-1" :disabled="index === 0" @click="moveTopic(index, -1)">↑</button>
        <button type="button" class="glass-btn-secondary !px-2 !py-1" :disabled="index === topics.length - 1" @click="moveTopic(index, 1)">↓</button>
        <button type="button" class="glass-btn-accent !px-3 !py-1" @click="saveTopic(topic)">Speichern</button>
        <button type="button" class="glass-btn-secondary !px-3 !py-1" @click="deleteTopic(topic)">Löschen</button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.help-admin__tab--on {
  box-shadow: inset 0 0 0 1px var(--color-accent);
  color: var(--color-accent);
}
.help-admin__assign {
  min-height: 24rem;
  display: flex;
  gap: 0.75rem;
}
.help-admin__split {
  display: grid;
  grid-template-columns: minmax(14rem, 18rem) minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
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
.help-admin__nav-name {
  width: 100%;
  text-align: left;
  color: inherit;
}
.help-admin__nav--active {
  background: var(--color-bg-hover, rgba(0, 0, 0, 0.06));
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
.help-admin__input {
  display: block;
  width: 100%;
  padding: 0.4rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: 0.5rem;
  background: #fff;
  color: var(--color-text);
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
@media (max-width: 800px) {
  .help-admin__split {
    grid-template-columns: 1fr;
  }
  .help-admin__assign {
    flex-direction: column;
  }
}
</style>

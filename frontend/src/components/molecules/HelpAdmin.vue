<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
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
type HelpStep = {id: number; help_action: number; body: string; sort_order: number}
type HelpAction = {
  id: number
  help_screen: number
  help_topic: number
  title: string
  sort_order: number
  steps: HelpStep[]
}

const screens = ref<HelpScreen[]>([])
const topics = ref<HelpTopic[]>([])
const actions = ref<HelpAction[]>([])
const selectedId = ref<number | null>(null)
const loading = ref(false)
const saving = ref(false)
const description = ref('')
const mustDo = ref('')
const canDo = ref('')
const newActionTitle = ref('')
const newActionTopic = ref<number | null>(null)
const expandedActionId = ref<number | null>(null)
const newStepBody = ref('')
const newTopicKey = ref('')
const newTopicName = ref('')

const selected = computed(() => screens.value.find((s) => s.id === selectedId.value) ?? null)

async function loadScreens() {
  const {data} = await axios.get('/admin/help/screens')
  screens.value = data
  if (selectedId.value == null && screens.value.length) {
    selectedId.value = screens.value[0].id
  }
}

async function loadTopics() {
  const {data} = await axios.get('/admin/help/topics')
  topics.value = data
  if (newActionTopic.value == null && topics.value.length) {
    newActionTopic.value = topics.value[0].id
  }
}

async function loadActions() {
  if (selectedId.value == null) {
    actions.value = []
    return
  }
  const {data} = await axios.get('/admin/help/actions', {params: {screen_id: selectedId.value}})
  actions.value = data
}

async function loadAll() {
  loading.value = true
  try {
    await Promise.all([loadScreens(), loadTopics()])
    await loadActions()
  } catch (e) {
    showGlassToast(apiError(e, 'Hilfe konnte nicht geladen werden'), 'error')
  } finally {
    loading.value = false
  }
}

watch(selectedId, async (id) => {
  const screen = screens.value.find((s) => s.id === id)
  description.value = screen?.description ?? ''
  mustDo.value = screen?.must_do ?? ''
  canDo.value = screen?.can_do ?? ''
  expandedActionId.value = null
  newStepBody.value = ''
  newActionTitle.value = ''
  await loadActions()
})

async function saveScreen() {
  if (selectedId.value == null) return
  saving.value = true
  try {
    const {data} = await axios.put(`/admin/help/screens/${selectedId.value}`, {
      description: description.value,
      must_do: mustDo.value,
      can_do: canDo.value,
    })
    const idx = screens.value.findIndex((s) => s.id === data.id)
    if (idx >= 0) screens.value[idx] = data
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    saving.value = false
  }
}

async function addAction() {
  if (selectedId.value == null || !newActionTitle.value.trim() || newActionTopic.value == null) {
    showGlassToast('Titel und Thema sind nötig', 'info')
    return
  }
  try {
    const {data} = await axios.post('/admin/help/actions', {
      help_screen: selectedId.value,
      help_topic: newActionTopic.value,
      title: newActionTitle.value.trim(),
    })
    actions.value = [...actions.value, data]
    newActionTitle.value = ''
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Aktion konnte nicht angelegt werden'), 'error')
  }
}

async function saveAction(action: HelpAction) {
  try {
    const {data} = await axios.put(`/admin/help/actions/${action.id}`, {
      title: action.title,
      help_topic: action.help_topic,
    })
    const idx = actions.value.findIndex((a) => a.id === action.id)
    if (idx >= 0) actions.value[idx] = {...data, steps: action.steps}
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Aktion konnte nicht gespeichert werden'), 'error')
  }
}

async function deleteAction(action: HelpAction) {
  try {
    await axios.delete(`/admin/help/actions/${action.id}`)
    actions.value = actions.value.filter((a) => a.id !== action.id)
    if (expandedActionId.value === action.id) expandedActionId.value = null
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Aktion konnte nicht gelöscht werden'), 'error')
  }
}

async function moveAction(index: number, direction: -1 | 1) {
  const next = index + direction
  if (next < 0 || next >= actions.value.length || selectedId.value == null) return
  const ids = actions.value.map((a) => a.id)
  const tmp = ids[index]
  ids[index] = ids[next]
  ids[next] = tmp
  try {
    await axios.post('/admin/help/actions/reorder', {help_screen: selectedId.value, ids})
    const copy = [...actions.value]
    const swapped = copy[index]
    copy[index] = copy[next]
    copy[next] = swapped
    actions.value = copy
  } catch (e) {
    showGlassToast(apiError(e, 'Reihenfolge konnte nicht gespeichert werden'), 'error')
  }
}

async function addStep(action: HelpAction) {
  if (!newStepBody.value.trim()) return
  try {
    const {data} = await axios.post(`/admin/help/actions/${action.id}/steps`, {body: newStepBody.value.trim()})
    action.steps = [...action.steps, data]
    newStepBody.value = ''
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Schritt konnte nicht angelegt werden'), 'error')
  }
}

async function saveStep(step: HelpStep) {
  try {
    const {data} = await axios.put(`/admin/help/steps/${step.id}`, {body: step.body})
    step.body = data.body
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Schritt konnte nicht gespeichert werden'), 'error')
  }
}

async function deleteStep(action: HelpAction, step: HelpStep) {
  try {
    await axios.delete(`/admin/help/steps/${step.id}`)
    action.steps = action.steps.filter((s) => s.id !== step.id)
    showGlassToast('Gespeichert', 'success')
  } catch (e) {
    showGlassToast(apiError(e, 'Schritt konnte nicht gelöscht werden'), 'error')
  }
}

async function moveStep(action: HelpAction, index: number, direction: -1 | 1) {
  const next = index + direction
  if (next < 0 || next >= action.steps.length) return
  const ids = action.steps.map((s) => s.id)
  const tmp = ids[index]
  ids[index] = ids[next]
  ids[next] = tmp
  try {
    await axios.post(`/admin/help/actions/${action.id}/steps/reorder`, {ids})
    const copy = [...action.steps]
    const swapped = copy[index]
    copy[index] = copy[next]
    copy[next] = swapped
    action.steps = copy
  } catch (e) {
    showGlassToast(apiError(e, 'Reihenfolge konnte nicht gespeichert werden'), 'error')
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
    const idx = topics.value.findIndex((t) => t.id === topic.id)
    if (idx >= 0) topics.value[idx] = data
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
  <div class="help-admin space-y-6">
    <h2 class="text-xl font-bold">Hilfe</h2>

    <p v-if="loading" class="text-[var(--color-text-subtle)]">Lade …</p>

    <div v-else class="help-admin__split">
      <aside class="glass-card liquid-surface-inner !p-2">
        <button
          v-for="screen in screens"
          :key="screen.id"
          type="button"
          class="help-admin__nav"
          :class="{'help-admin__nav--active': screen.id === selectedId}"
          @click="selectedId = screen.id"
        >
          <span>{{ screen.name }}</span>
          <code class="help-admin__key">{{ screen.key }}</code>
        </button>
      </aside>

      <section v-if="selected" class="glass-card liquid-surface-inner p-4 space-y-4 min-w-0">
        <div>
          <p class="text-sm text-[var(--color-text-muted)]">
            {{ selected.name }}
            <code class="help-admin__key">{{ selected.key }}</code>
            · {{ selected.route_path }}
          </p>
        </div>
        <label class="block text-sm font-medium">
          Beschreibung
          <textarea v-model="description" rows="4" class="help-admin__input mt-1"/>
        </label>
        <label class="block text-sm font-medium">
          Muss ich tun
          <textarea v-model="mustDo" rows="3" class="help-admin__input mt-1"/>
        </label>
        <label class="block text-sm font-medium">
          Kann ich tun
          <textarea v-model="canDo" rows="3" class="help-admin__input mt-1"/>
        </label>
        <button type="button" class="glass-btn-accent !px-4 !py-2" :disabled="saving" @click="saveScreen">
          Speichern
        </button>

        <h3 class="text-lg font-semibold pt-2">Aktionen</h3>
        <div class="flex flex-wrap gap-2 items-end">
          <label class="text-sm flex-1 min-w-[12rem]">
            Titel
            <input v-model="newActionTitle" class="help-admin__input mt-1" maxlength="255"/>
          </label>
          <label class="text-sm">
            Thema
            <select v-model.number="newActionTopic" class="help-admin__input mt-1">
              <option v-for="topic in topics" :key="topic.id" :value="topic.id">{{ topic.name }}</option>
            </select>
          </label>
          <button type="button" class="glass-btn-secondary !px-3 !py-2" @click="addAction">Hinzufügen</button>
        </div>

        <div v-for="(action, index) in actions" :key="action.id" class="help-admin__action">
          <div class="flex flex-wrap gap-2 items-center">
            <input v-model="action.title" class="help-admin__input flex-1 min-w-[10rem]" maxlength="255"/>
            <select v-model.number="action.help_topic" class="help-admin__input">
              <option v-for="topic in topics" :key="topic.id" :value="topic.id">{{ topic.name }}</option>
            </select>
            <button type="button" class="glass-btn-secondary !px-2 !py-1" :disabled="index === 0" @click="moveAction(index, -1)">↑</button>
            <button type="button" class="glass-btn-secondary !px-2 !py-1" :disabled="index === actions.length - 1" @click="moveAction(index, 1)">↓</button>
            <button type="button" class="glass-btn-accent !px-3 !py-1" @click="saveAction(action)">Speichern</button>
            <button type="button" class="glass-btn-secondary !px-3 !py-1" @click="deleteAction(action)">Löschen</button>
            <button type="button" class="glass-btn-secondary !px-3 !py-1" @click="expandedActionId = expandedActionId === action.id ? null : action.id">
              Schritte
            </button>
          </div>
          <div v-if="expandedActionId === action.id" class="mt-3 space-y-2 pl-2 border-l border-[var(--color-border)]">
            <div v-for="(step, stepIndex) in action.steps" :key="step.id" class="flex gap-2 items-start">
              <textarea v-model="step.body" rows="2" class="help-admin__input flex-1"/>
              <button type="button" class="glass-btn-secondary !px-2 !py-1" :disabled="stepIndex === 0" @click="moveStep(action, stepIndex, -1)">↑</button>
              <button type="button" class="glass-btn-secondary !px-2 !py-1" :disabled="stepIndex === action.steps.length - 1" @click="moveStep(action, stepIndex, 1)">↓</button>
              <button type="button" class="glass-btn-accent !px-2 !py-1" @click="saveStep(step)">Speichern</button>
              <button type="button" class="glass-btn-secondary !px-2 !py-1" @click="deleteStep(action, step)">Löschen</button>
            </div>
            <div class="flex gap-2">
              <input v-model="newStepBody" class="help-admin__input flex-1" placeholder="Neuer Schritt"/>
              <button type="button" class="glass-btn-secondary !px-3 !py-1" @click="addStep(action)">Hinzufügen</button>
            </div>
          </div>
        </div>
      </section>
    </div>

    <section class="glass-card liquid-surface-inner p-4 space-y-3">
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
.help-admin__nav--active {
  background: var(--color-bg-hover, rgba(0, 0, 0, 0.06));
}
.help-admin__key {
  font-size: 0.75rem;
  color: var(--color-text-subtle);
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
.help-admin__action {
  padding: 0.75rem 0;
  border-top: 1px solid var(--color-border);
}
@media (max-width: 800px) {
  .help-admin__split {
    grid-template-columns: 1fr;
  }
}
</style>

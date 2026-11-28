<script setup lang="ts">
import {onMounted, ref} from 'vue'
import axios from 'axios'
import PageTabs from '@hands-on/glass/page-tabs'

type Item = {
  key: number
  item_type: string
  label: string
  hint: string
  core_value: boolean
  config: any
}

type Section = {
  key: number
  title: string
  kind: string
  note: string
  items: Item[]
}

type Form = {
  id: number
  kind: string
  sections: Section[]
}

const seasons = ref<any[]>([])
const seasonId = ref<number | null>(null)
const forms = ref<Form[]>([])
const columns = ref<any[]>([])
const kind = ref<'jury' | 'referee'>('jury')
const error = ref('')
const notice = ref('')
const busy = ref(false)
let nextKey = 1

const current = () => forms.value.find((form) => form.kind === kind.value)

function freshKey() {
  return nextKey++
}

function stamp(list: Form[]) {
  for (const form of list) {
    for (const section of form.sections || []) {
      section.key ||= freshKey()
      for (const item of section.items || []) item.key ||= freshKey()
    }
  }
}

function blankItem(type: string): Item {
  const base = {key: freshKey(), label: '', hint: '', core_value: false}
  if (type === 'choice') {
    return {...base, item_type: type, config: {options: [{label: '', points: 0}]}}
  }
  if (type === 'count') {
    return {...base, item_type: type, config: {max: 1, points_each: 1}}
  }
  if (type === 'rubric') {
    return {...base, item_type: type, config: {group: '', group_hint: '', cells: ['', '', '']}}
  }
  if (type === 'text') {
    return {...base, item_type: type, config: null}
  }
  return {
    ...base,
    item_type: 'yes_no',
    config: {yes_label: 'Ja', yes_points: 0, no_label: 'Nein', no_points: 0},
  }
}

async function load() {
  error.value = ''
  try {
    const {data} = await axios.get('/admin/scor-forms', {params: {season: seasonId.value || undefined}})
    seasons.value = data.seasons || []
    seasonId.value = data.season
    columns.value = data.columns || []
    forms.value = data.forms || []
    stamp(forms.value)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Bögen konnten nicht geladen werden.'
  }
}

function addSection() {
  const form = current()
  if (!form) return
  form.sections.push({
    key: freshKey(),
    title: kind.value === 'jury' ? 'Neuer Abschnitt' : 'Neue Mission',
    kind: kind.value === 'jury' ? 'rubric' : 'mission',
    note: '',
    items: [],
  })
}

function moveSection(index: number, delta: number) {
  const form = current()
  if (!form) return
  const next = index + delta
  if (next < 0 || next >= form.sections.length) return
  const [row] = form.sections.splice(index, 1)
  form.sections.splice(next, 0, row)
}

function moveItem(section: Section, index: number, delta: number) {
  const next = index + delta
  if (next < 0 || next >= section.items.length) return
  const [row] = section.items.splice(index, 1)
  section.items.splice(next, 0, row)
}

function addRow(section: Section) {
  const type = section.kind === 'feedback' ? 'text' : (kind.value === 'jury' ? 'rubric' : 'yes_no')
  section.items.push(blankItem(type))
}

function changeType(item: Item, type: string) {
  const next = blankItem(type)
  next.key = item.key
  next.label = item.label
  Object.assign(item, next)
}

async function save() {
  const form = current()
  if (!form) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    const {data} = await axios.put(`/admin/scor-forms/${form.id}`, {sections: form.sections})
    const index = forms.value.findIndex((entry) => entry.id === form.id)
    if (index >= 0) {
      stamp([data])
      forms.value[index] = data
    }
    notice.value = 'Bogen gespeichert.'
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Speichern fehlgeschlagen.'
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="max-w-4xl space-y-4">
    <div>
      <h2 class="text-xl font-bold">SCORE Bögen</h2>
      <p class="text-sm text-[var(--color-text-muted)]">Future, einmal pro Saison. Jury: Zeilen und Beschriftungen. Schiri: zusätzlich die Art der Frage.</p>
    </div>

    <label class="text-sm block">
      Saison
      <select v-model="seasonId" class="glass-input glass-input--sm ml-2" @change="load">
        <option v-for="season in seasons" :key="season.id" :value="season.id">{{ season.name || season.year }}</option>
      </select>
    </label>

    <PageTabs
        aria-label="Bogen"
        :tabs="[
          {id: 'jury', label: 'Jury', icon: 'bi-people'},
          {id: 'referee', label: 'Schiri', icon: 'bi-clipboard-check'},
        ]"
        :model-value="kind"
        @update:model-value="kind = $event"
    />

    <p v-if="kind === 'jury'" class="text-xs text-[var(--color-text-muted)]">
      Stufen bleiben fest:
      <span v-for="column in columns" :key="column.value"> {{ column.value }} {{ column.label }}</span>
    </p>
    <p class="text-xs text-[var(--color-text-muted)]">
      Hinweise, Fragen, Gruppentexte und Zellen können Markdown: **fett**, *kursiv*, Zeilen mit - als Liste, Enter für eine neue Zeile, Leerzeile für einen neuen Absatz.
      Einfaches HTML wie &lt;b&gt;, &lt;i&gt;, &lt;u&gt; und &lt;br&gt; geht auch.
    </p>
    <p v-if="error" class="text-sm text-red-700">{{ error }}</p>
    <p v-if="notice" class="text-sm text-[var(--color-text-muted)]">{{ notice }}</p>

    <section v-for="(section, sectionIndex) in current()?.sections || []" :key="section.key" class="glass-card liquid-surface-inner space-y-3 p-4">
      <div class="flex flex-wrap items-center gap-2">
        <div class="scor-move">
          <button type="button" :disabled="sectionIndex === 0" aria-label="Abschnitt nach oben" @click="moveSection(sectionIndex, -1)">
            <i class="bi bi-chevron-up" aria-hidden="true"/>
          </button>
          <button type="button" :disabled="sectionIndex === (current()?.sections.length || 1) - 1" aria-label="Abschnitt nach unten" @click="moveSection(sectionIndex, 1)">
            <i class="bi bi-chevron-down" aria-hidden="true"/>
          </button>
        </div>
        <input v-model="section.title" class="glass-input glass-input--sm flex-1" placeholder="Überschrift"/>
        <button type="button" class="text-sm text-red-700" @click="current()?.sections.splice(sectionIndex, 1)">Abschnitt entfernen</button>
      </div>
      <textarea v-model="section.note" class="glass-input w-full" rows="2" placeholder="Hinweis"/>

      <div v-for="(item, itemIndex) in section.items" :key="item.key" class="space-y-2 rounded-lg border border-[var(--color-border)] p-3">
        <div class="flex flex-wrap items-start gap-2">
          <div class="scor-move">
            <button type="button" :disabled="itemIndex === 0" aria-label="Frage nach oben" @click="moveItem(section, itemIndex, -1)">
              <i class="bi bi-chevron-up" aria-hidden="true"/>
            </button>
            <button type="button" :disabled="itemIndex === section.items.length - 1" aria-label="Frage nach unten" @click="moveItem(section, itemIndex, 1)">
              <i class="bi bi-chevron-down" aria-hidden="true"/>
            </button>
          </div>
          <textarea v-model="item.label" class="glass-input glass-input--sm flex-1" rows="2" :placeholder="item.item_type === 'rubric' ? 'erste Zellbeschriftung' : 'Frage'"/>
          <select v-if="section.kind === 'mission'" v-model="item.item_type" class="glass-input glass-input--sm" @change="changeType(item, item.item_type)">
            <option value="yes_no">Ja/Nein</option>
            <option value="choice">Auswahl</option>
            <option value="count">Anzahl</option>
          </select>
          <button type="button" class="text-sm text-red-700" @click="section.items.splice(itemIndex, 1)">Zeile entfernen</button>
        </div>

        <template v-if="item.item_type === 'rubric'">
          <input v-model="item.config.group" class="glass-input glass-input--sm w-full" placeholder="Gruppe, z. B. Benennen"/>
          <textarea v-model="item.config.group_hint" class="glass-input glass-input--sm w-full" rows="2" placeholder="Gruppentext"/>
          <textarea v-for="index in 3" :key="index" v-model="item.config.cells[index - 1]" class="glass-input glass-input--sm w-full" rows="2" :placeholder="columns[index - 1]?.label || 'Beschriftung'"/>
          <label class="inline-flex items-center gap-1.5 text-sm">
            <input v-model="item.core_value" type="checkbox"/>
            zählt zu den Grundwerten
          </label>
        </template>

        <div v-else-if="item.item_type === 'yes_no'" class="grid gap-2 sm:grid-cols-2">
          <input v-model="item.config.yes_label" class="glass-input glass-input--sm" placeholder="Ja"/>
          <input v-model.number="item.config.yes_points" type="number" class="glass-input glass-input--sm" placeholder="Punkte Ja"/>
          <input v-model="item.config.no_label" class="glass-input glass-input--sm" placeholder="Nein"/>
          <input v-model.number="item.config.no_points" type="number" class="glass-input glass-input--sm" placeholder="Punkte Nein"/>
        </div>

        <div v-else-if="item.item_type === 'choice'" class="space-y-2">
          <div v-for="(option, optionIndex) in item.config.options" :key="optionIndex" class="flex gap-2">
            <input v-model="option.label" class="glass-input glass-input--sm flex-1" placeholder="Beschriftung"/>
            <input v-model.number="option.points" type="number" class="glass-input glass-input--sm w-24" placeholder="Punkte"/>
            <button type="button" class="text-sm text-red-700" @click="item.config.options.splice(optionIndex, 1)">−</button>
          </div>
          <button type="button" class="text-sm underline" @click="item.config.options.push({label: '', points: 0})">Option hinzufügen</button>
        </div>

        <div v-else-if="item.item_type === 'count'" class="flex gap-2">
          <label class="text-sm">Maximum <input v-model.number="item.config.max" type="number" min="0" class="glass-input glass-input--sm ml-1 w-20"/></label>
          <label class="text-sm">Punkte je Stück <input v-model.number="item.config.points_each" type="number" class="glass-input glass-input--sm ml-1 w-20"/></label>
        </div>
      </div>

      <button type="button" class="glass-btn-text" @click="addRow(section)">Zeile hinzufügen</button>
    </section>

    <div class="flex gap-3">
      <button type="button" class="glass-btn-secondary" @click="addSection">Abschnitt hinzufügen</button>
      <button type="button" class="glass-btn-accent" :disabled="busy" @click="save">Speichern</button>
    </div>
  </div>
</template>

<style scoped>
.scor-move {
  display: flex;
  flex-direction: column;
}

.scor-move button {
  display: grid;
  place-items: center;
  width: 1.7rem;
  height: 1.35rem;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
}

.scor-move button:hover:not(:disabled) {
  color: var(--color-text);
}

.scor-move button:disabled {
  opacity: 0.35;
  cursor: default;
}
</style>

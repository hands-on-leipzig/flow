<script setup lang="ts">
import {ref, watch} from 'vue'
import axios from 'axios'

const props = defineProps<{sheet: any}>()
const emit = defineEmits<{update: [sheet: any]}>()

const values = ref<Record<string, number>>({})
const comments = ref<Record<string, string>>({})
const texts = ref<Record<string, string>>({})
const disabled = ref<number[]>([])
const error = ref('')
const busy = ref(false)

watch(() => props.sheet, (sheet) => {
  const nextValues: Record<string, number> = {}
  const nextComments: Record<string, string> = {}
  const nextTexts: Record<string, string> = {}
  const nextDisabled: number[] = []
  for (const section of sheet?.sections || []) {
    if (section.disabled) nextDisabled.push(section.id)
    for (const item of section.items || []) {
      if (item.item_type === 'text') {
        nextTexts[String(item.id)] = item.value || ''
      } else if (item.value !== null && item.value !== undefined && item.value !== '') {
        nextValues[String(item.id)] = Number(item.value)
      }
      nextComments[String(item.id)] = item.comment || ''
    }
  }
  values.value = nextValues
  comments.value = nextComments
  texts.value = nextTexts
  disabled.value = nextDisabled
  error.value = ''
}, {immediate: true})

function groups(items: any[]) {
  const rows: {group: string, hint: string, items: any[]}[] = []
  for (const item of items) {
    const group = item.config?.group || ''
    const last = rows[rows.length - 1]
    if (last && last.group === group) {
      last.items.push(item)
    } else {
      rows.push({group, hint: item.config?.group_hint || '', items: [item]})
    }
  }
  return rows
}

function payload() {
  return {
    values: values.value,
    comments: comments.value,
    texts: texts.value,
    disabled_sections: disabled.value,
  }
}

function url() {
  return `/scor/events/${props.sheet.event.id}/teams/${props.sheet.team.id}/jury`
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const {data} = await axios.put(url(), payload())
    emit('update', data)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Speichern fehlgeschlagen.'
  } finally {
    busy.value = false
  }
}

async function complete() {
  busy.value = true
  error.value = ''
  try {
    await axios.put(url(), payload())
    const {data} = await axios.post(`${url()}/complete`)
    emit('update', data)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Abschließen fehlgeschlagen.'
  } finally {
    busy.value = false
  }
}

async function reopen() {
  busy.value = true
  error.value = ''
  try {
    const {data} = await axios.post(`${url()}/reopen`)
    emit('update', data)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Wieder öffnen fehlgeschlagen.'
  } finally {
    busy.value = false
  }
}

function toggleSection(id: number, checked: boolean) {
  disabled.value = checked ? [...disabled.value, id] : disabled.value.filter((entry) => entry !== id)
}
</script>

<template>
  <div class="space-y-4">
    <header>
      <h2 class="text-xl font-bold">{{ sheet.team.number }} {{ sheet.team.name }}</h2>
      <p class="text-sm text-[var(--color-text-muted)]">Jurybewertung</p>
    </header>

    <p v-if="error" class="text-sm text-red-700">{{ error }}</p>

    <section v-for="section in sheet.sections" :key="section.id" class="glass-card liquid-surface-inner space-y-3 p-4">
      <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 class="font-semibold">{{ section.title }}</h2>
          <p v-if="section.note" class="text-sm text-[var(--color-text-muted)]">{{ section.note }}</p>
        </div>
        <label v-if="section.kind === 'rubric'" class="inline-flex items-center gap-1.5 text-sm">
          <input
              type="checkbox"
              :checked="disabled.includes(section.id)"
              :disabled="sheet.completed"
              @change="toggleSection(section.id, ($event.target as HTMLInputElement).checked)"
          />
          Team ist in dieser Kategorie nicht angetreten
        </label>
      </div>

      <template v-if="section.kind === 'rubric' && !disabled.includes(section.id)">
        <div class="hidden gap-2 text-xs font-semibold text-[var(--color-text-muted)] md:grid md:grid-cols-4">
          <span v-for="column in sheet.columns" :key="column.value">{{ column.label }} · {{ column.value }}</span>
        </div>
        <div v-for="group in groups(section.items)" :key="group.group + group.items[0].id" class="space-y-2">
          <p v-if="group.group" class="text-sm"><strong>{{ group.group }}</strong> <span class="text-[var(--color-text-muted)]">{{ group.hint }}</span></p>
          <div v-for="item in group.items" :key="item.id" class="grid gap-2 md:grid-cols-4">
            <label v-for="(cell, index) in (item.config?.cells || [])" :key="index" class="rounded-lg border border-[var(--color-border)] p-2 text-sm" :class="values[String(item.id)] === index + 1 ? 'border-[var(--color-accent)] bg-[var(--color-bg-hover)]' : ''">
              <input v-model="values[String(item.id)]" class="mr-1" type="radio" :value="index + 1" :disabled="sheet.completed" :name="`item-${item.id}`"/>
              <span class="md:hidden font-semibold">{{ sheet.columns?.[index]?.label }} · </span>{{ cell }}
            </label>
            <label class="rounded-lg border border-[var(--color-border)] p-2 text-sm" :class="values[String(item.id)] === 4 ? 'border-[var(--color-accent)] bg-[var(--color-bg-hover)]' : ''">
              <input v-model="values[String(item.id)]" class="mr-1" type="radio" :value="4" :disabled="sheet.completed" :name="`item-${item.id}`"/>
              Herausragend
              <textarea
                  v-if="values[String(item.id)] === 4"
                  v-model="comments[String(item.id)]"
                  class="glass-input mt-2 w-full"
                  rows="2"
                  maxlength="200"
                  placeholder="Kommentar, mindestens 20 Zeichen"
                  :disabled="sheet.completed"
              />
            </label>
          </div>
        </div>
      </template>

      <template v-else-if="section.kind === 'feedback'">
        <label v-for="item in section.items" :key="item.id" class="block text-sm">
          {{ item.label }}
          <textarea
              v-model="texts[String(item.id)]"
              class="glass-input mt-1 w-full"
              rows="3"
              :disabled="sheet.completed"
          />
        </label>
      </template>
    </section>

    <div class="glass-card liquid-surface-inner space-y-2 p-4">
      <p class="font-semibold">{{ sheet.points }} Punkte</p>
      <p class="text-sm text-[var(--color-text-muted)]">Grundwerte: {{ sheet.core_points }}. {{ sheet.core_values_note }}</p>
      <div class="flex flex-wrap gap-3">
        <button type="button" class="glass-btn-secondary" :disabled="busy || sheet.completed" @click="save">Speichern</button>
        <button type="button" class="glass-btn-accent" :disabled="busy || sheet.completed" @click="complete">Bewertung abschließen</button>
        <button v-if="sheet.roles?.admin && sheet.completed" type="button" class="glass-btn-secondary" :disabled="busy" @click="reopen">Wieder öffnen</button>
      </div>
    </div>
  </div>
</template>

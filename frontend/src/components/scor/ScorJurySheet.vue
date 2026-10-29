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

function setLevel(id: number, level: number) {
  if (props.sheet.completed) return
  values.value = {...values.value, [String(id)]: level}
}
</script>

<template>
  <div class="jury-sheet">
    <header class="jury-sheet__head">
      <h2>{{ sheet.team.number }} {{ sheet.team.name }}</h2>
    </header>

    <p v-if="error" class="jury-sheet__alert" role="alert">{{ error }}</p>

    <section v-for="section in sheet.sections" :key="section.id" class="jury-block">
      <div class="jury-block__head">
        <h2>{{ section.title }}</h2>
        <button
            v-if="section.kind === 'rubric'"
            type="button"
            class="jury-skip"
            :class="{'is-on': disabled.includes(section.id)}"
            :disabled="sheet.completed"
            @click="toggleSection(section.id, !disabled.includes(section.id))"
        >
          {{ disabled.includes(section.id) ? 'Nicht angetreten' : 'Nicht angetreten?' }}
        </button>
      </div>

      <template v-if="section.kind === 'rubric' && !disabled.includes(section.id)">
        <div v-for="group in groups(section.items)" :key="group.group + group.items[0].id" class="jury-group">
          <p v-if="group.group" class="jury-group__title">{{ group.group }}</p>
          <div v-for="item in group.items" :key="item.id" class="jury-row" :class="{'is-done': values[String(item.id)]}">
            <div class="jury-levels">
              <button
                  v-for="(cell, index) in (item.config?.cells || [])"
                  :key="index"
                  type="button"
                  class="jury-level"
                  :class="{'is-on': values[String(item.id)] === index + 1}"
                  :disabled="sheet.completed"
                  @click="setLevel(item.id, index + 1)"
              >
                <span class="jury-level__n">{{ index + 1 }}</span>
                <span class="jury-level__name">{{ sheet.columns?.[index]?.label }}</span>
                <span class="jury-level__text">{{ cell }}</span>
              </button>
              <button
                  type="button"
                  class="jury-level"
                  :class="{'is-on': values[String(item.id)] === 4}"
                  :disabled="sheet.completed"
                  @click="setLevel(item.id, 4)"
              >
                <span class="jury-level__n">4</span>
                <span class="jury-level__name">Herausragend</span>
                <span class="jury-level__text">Kurz begründen</span>
              </button>
            </div>
            <textarea
                v-if="values[String(item.id)] === 4"
                v-model="comments[String(item.id)]"
                class="glass-input jury-comment"
                rows="2"
                maxlength="200"
                placeholder="Kommentar, mindestens 20 Zeichen"
                :disabled="sheet.completed"
            />
          </div>
        </div>
      </template>

      <template v-else-if="section.kind === 'feedback'">
        <label v-for="item in section.items" :key="item.id" class="jury-feedback">
          {{ item.label }}
          <textarea
              v-model="texts[String(item.id)]"
              class="glass-input"
              rows="3"
              :disabled="sheet.completed"
          />
        </label>
      </template>
    </section>

    <div class="jury-bar">
      <p class="jury-bar__points">{{ sheet.points }} <span>Punkte</span></p>
      <p class="jury-bar__core">Grundwerte {{ sheet.core_points }}</p>
      <button type="button" class="glass-btn-accent" :disabled="busy || sheet.completed" @click="complete">Bewertung abschließen</button>
      <button type="button" class="glass-btn-secondary" :disabled="busy || sheet.completed" @click="save">Speichern</button>
      <button v-if="sheet.roles?.admin && sheet.completed" type="button" class="glass-btn-secondary" :disabled="busy" @click="reopen">Wieder öffnen</button>
    </div>
  </div>
</template>

<style scoped>
.jury-sheet {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.jury-sheet__head h2 {
  margin: 0;
  font-size: 1.7rem;
  font-weight: 800;
  line-height: 1.1;
}

.jury-sheet__alert {
  margin: 0;
  padding: 0.85rem 1rem;
  border-radius: 0.75rem;
  background: #b42318;
  color: #fff;
  font-size: 1.25rem;
  font-weight: 800;
}

.jury-block__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.65rem;
}

.jury-block__head h2 {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 800;
}

.jury-skip {
  min-height: 3rem;
  padding: 0.4rem 0.8rem;
  border: 3px solid #c8c8c8;
  border-radius: 0.8rem;
  background: #fff;
  font-size: 1rem;
  font-weight: 800;
}

.jury-skip.is-on {
  border-color: #8a5a00;
  background: #8a5a00;
  color: #fff;
}

.jury-group {
  margin-bottom: 1rem;
}

.jury-group__title {
  margin: 0 0 0.4rem;
  font-size: 1.15rem;
  font-weight: 800;
}

.jury-row {
  margin-bottom: 0.75rem;
}

.jury-row.is-done {
  position: relative;
  padding-left: 0.85rem;
}

.jury-row.is-done::before {
  content: "";
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  width: 4px;
  border-radius: 4px;
  background: #146c36;
}

.jury-levels {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.45rem;
}

.jury-level {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.1rem;
  min-height: 5.5rem;
  padding: 0.65rem 0.75rem;
  border: 3px solid #c8c8c8;
  border-radius: 0.85rem;
  background: #fff;
  color: #1a1a1a;
  text-align: left;
}

.jury-level.is-on {
  border-color: #146c36;
  background: #146c36;
  color: #fff;
}

.jury-level__n {
  font-size: 1.7rem;
  font-weight: 800;
  line-height: 1;
}

.jury-level__name {
  font-size: 1.05rem;
  font-weight: 800;
}

.jury-level__text {
  font-size: 0.95rem;
  line-height: 1.25;
}

.jury-comment {
  margin-top: 0.45rem;
  width: 100%;
  font-size: 1.15rem;
}

.jury-feedback {
  display: block;
  margin-bottom: 0.75rem;
  font-size: 1.15rem;
  font-weight: 800;
}

.jury-feedback textarea {
  margin-top: 0.35rem;
  width: 100%;
  font-size: 1.15rem;
  font-weight: 500;
}

.jury-bar {
  position: sticky;
  bottom: 0;
  z-index: 40;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.45rem;
  margin-top: 0.5rem;
  padding: 0.65rem 0.75rem calc(0.65rem + env(safe-area-inset-bottom));
  background: #fff;
  box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.12);
}

.jury-bar__points {
  grid-column: 1 / -1;
  margin: 0;
  font-size: 2rem;
  font-weight: 800;
  line-height: 1;
}

.jury-bar__points span,
.jury-bar__core {
  font-size: 1.05rem;
  font-weight: 700;
  color: #5c5c5c;
}

.jury-bar__core {
  grid-column: 1 / -1;
  margin: 0;
}

.jury-bar .glass-btn-accent,
.jury-bar .glass-btn-secondary {
  min-height: 3.4rem;
  justify-content: center;
}

@media (min-width: 900px) {
  .jury-levels {
    grid-template-columns: repeat(4, 1fr);
  }
}
</style>

<script setup lang="ts">
import {onMounted, ref} from 'vue'
import axios from 'axios'

const props = defineProps<{eventId: string}>()
const sheet = ref<any>(null)
const values = ref<Record<string, string | number>>({})
const points = ref<number | null>(null)
const error = ref('')

onMounted(async () => {
  try {
    const {data} = await axios.get(`/scor/public/practice/${props.eventId}`)
    sheet.value = data
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Der Übungsbogen ist nicht erreichbar.'
  }
})

async function score() {
  error.value = ''
  try {
    const {data} = await axios.post('/scor/public/practice/score', {values: values.value})
    points.value = data.points
  } catch (e: any) {
    points.value = null
    error.value = e?.response?.data?.error || 'Die Punkte konnten nicht berechnet werden.'
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-4 p-4">
    <header>
      <h1 class="text-2xl font-bold">Übungsbogen</h1>
      <p class="text-lg text-[var(--color-text-muted)]">{{ sheet?.event?.name || 'Future' }}. Nichts wird gespeichert.</p>
    </header>
    <p v-if="error" class="text-lg text-red-700">{{ error }}</p>
    <section v-for="section in sheet?.sections || []" :key="section.title" class="glass-card liquid-surface-inner space-y-3 p-4">
      <h2 class="font-semibold">{{ section.title }}</h2>
      <p v-if="section.note" class="text-lg text-[var(--color-text-muted)]">{{ section.note }}</p>
      <div v-for="item in section.items" :key="item.id" class="space-y-1">
        <p class="text-lg">{{ item.label }}</p>
        <div v-if="item.item_type === 'yes_no'" class="flex gap-2">
          <button type="button" class="glass-btn-secondary" @click="values[item.id] = 'yes'">Ja · {{ item.config?.yes_points ?? 0 }}</button>
          <button type="button" class="glass-btn-secondary" @click="values[item.id] = 'no'">Nein · {{ item.config?.no_points ?? 0 }}</button>
        </div>
        <label v-else-if="item.item_type === 'count'" class="text-lg">
          Anzahl
          <input v-model.number="values[item.id]" type="number" min="0" :max="item.config?.max ?? 0" class="glass-input ml-2 w-24"/>
        </label>
        <select v-else-if="item.item_type === 'choice'" v-model="values[item.id]" class="glass-input">
          <option value="">–</option>
          <option v-for="(option, index) in item.config?.options || []" :key="index" :value="index">{{ option.label }} · {{ option.points }}</option>
        </select>
      </div>
    </section>
    <div v-if="sheet" class="flex items-center gap-4">
      <button type="button" class="glass-btn-accent" @click="score">Punkte berechnen</button>
      <p v-if="points !== null" class="text-xl font-bold">{{ points }} Punkte</p>
    </div>
  </div>
</template>

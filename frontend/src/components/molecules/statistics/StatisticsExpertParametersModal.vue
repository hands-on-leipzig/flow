<template>
  <div class="glass-modal p-6 w-[90vw] max-w-4xl max-h-[90vh] overflow-auto">
    <h3 class="text-lg font-bold mb-4">
      Veränderte Parameter für Plan {{ planId }}
    </h3>
    
    <div v-if="loading" class="text-[var(--color-text-subtle)] py-4">
      Lade Parameter...
    </div>
    
    <div v-else-if="inputParameters.length === 0 && expertParameters.length === 0 && tableNames.length === 0" class="text-[var(--color-text-subtle)] py-4">
      Keine veränderten Parameter gefunden.
    </div>
    
    <div v-else class="space-y-6">
      <!-- Input Parameters Table -->
      <div v-if="inputParameters.length > 0">
        <h4 class="text-md font-semibold mb-2">Input-Parameter</h4>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm border-collapse">
            <thead class="bg-[var(--color-bg-muted)] text-left">
              <tr>
                <th class="px-3 py-2 border border-[var(--color-border)]">Name</th>
                <th class="px-3 py-2 border border-[var(--color-border)]">UI Label</th>
                <th class="px-3 py-2 border border-[var(--color-border)]">Set Value</th>
                <th class="px-3 py-2 border border-[var(--color-border)]">Default Value</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="param in inputParameters"
                :key="param.name"
                class="hover:bg-[var(--color-bg-hover)]"
              >
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ param.name }}</td>
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ param.ui_label ?? '–' }}</td>
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ param.set_value ?? '–' }}</td>
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ param.default_value ?? '–' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      
      <!-- Expert Parameters Table -->
      <div v-if="expertParameters.length > 0">
        <h4 class="text-md font-semibold mb-2">Expert-Parameter</h4>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm border-collapse">
            <thead class="bg-[var(--color-bg-muted)] text-left">
              <tr>
                <th class="px-3 py-2 border border-[var(--color-border)]">Name</th>
                <th class="px-3 py-2 border border-[var(--color-border)]">UI Label</th>
                <th class="px-3 py-2 border border-[var(--color-border)]">Set Value</th>
                <th class="px-3 py-2 border border-[var(--color-border)]">Default Value</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="param in expertParameters"
                :key="param.name"
                class="hover:bg-[var(--color-bg-hover)]"
              >
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ param.name }}</td>
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ param.ui_label ?? '–' }}</td>
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ param.set_value ?? '–' }}</td>
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ param.default_value ?? '–' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      
      <!-- Table Names Table -->
      <div v-if="tableNames.length > 0">
        <h4 class="text-md font-semibold mb-2">Tischnamen (überschrieben)</h4>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm border-collapse">
            <thead class="bg-[var(--color-bg-muted)] text-left">
              <tr>
                <th class="px-3 py-2 border border-[var(--color-border)]">Tisch Nummer</th>
                <th class="px-3 py-2 border border-[var(--color-border)]">Tischname</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="table in tableNames"
                :key="table.table_number"
                class="hover:bg-[var(--color-bg-hover)]"
              >
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ table.table_number }}</td>
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ table.table_name }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    
    <div class="flex justify-end gap-2 mt-6">
      <button class="px-4 py-2 text-[var(--color-text-muted)] hover:text-black" @click="$emit('close')">Schließen</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import axios from 'axios'

const props = defineProps<{
  planId: number
}>()

const emit = defineEmits<{
  (e: 'close'): void
}>()

const inputParameters = ref<Array<{
  name: string
  ui_label: string | null
  set_value: string | null
  default_value: string | null
  sequence: number
}>>([])
const expertParameters = ref<Array<{
  name: string
  ui_label: string | null
  set_value: string | null
  default_value: string | null
  sequence: number
}>>([])
const tableNames = ref<Array<{
  table_number: number
  table_name: string
}>>([])
const loading = ref(false)

async function loadNonDefaultParameters() {
  if (!props.planId) return
  
  loading.value = true
  inputParameters.value = []
  expertParameters.value = []
  tableNames.value = []
  
  try {
    const response = await axios.get(`/plans/${props.planId}/non-default-parameters`)
    inputParameters.value = response.data.input || []
    expertParameters.value = response.data.expert || []
    tableNames.value = response.data.table_names || []
  } catch (err) {
    console.error('Error loading changed parameters:', err)
    alert('Fehler beim Laden der veränderten Parameter')
  } finally {
    loading.value = false
  }
}

watch(() => props.planId, () => {
  if (props.planId) {
    loadNonDefaultParameters()
  }
}, { immediate: true })
</script>


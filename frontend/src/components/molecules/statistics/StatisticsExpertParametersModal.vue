<template>
  <div class="bg-white p-6 rounded-lg shadow-lg w-[90vw] max-w-4xl max-h-[90vh] overflow-auto">
    <h3 class="text-lg font-bold mb-4">
      Veränderte Parameter für Plan {{ planId }}
    </h3>
    
    <div v-if="loading" class="text-gray-500 py-4">
      Lade Parameter...
    </div>
    
    <div v-else-if="inputParameters.length === 0 && expertParameters.length === 0" class="text-gray-500 py-4">
      Keine veränderten Parameter gefunden.
    </div>
    
    <div v-else class="space-y-6">
      <!-- Input Parameters Table -->
      <div v-if="inputParameters.length > 0">
        <h4 class="text-md font-semibold mb-2">Input-Parameter</h4>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm border-collapse">
            <thead class="bg-gray-100 text-left">
              <tr>
                <th class="px-3 py-2 border border-gray-300">Name</th>
                <th class="px-3 py-2 border border-gray-300">UI Label</th>
                <th class="px-3 py-2 border border-gray-300">Set Value</th>
                <th class="px-3 py-2 border border-gray-300">Default Value</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="param in inputParameters"
                :key="param.name"
                class="hover:bg-gray-50"
              >
                <td class="px-3 py-2 border border-gray-300">{{ param.name }}</td>
                <td class="px-3 py-2 border border-gray-300">{{ param.ui_label ?? '–' }}</td>
                <td class="px-3 py-2 border border-gray-300">{{ param.set_value ?? '–' }}</td>
                <td class="px-3 py-2 border border-gray-300">{{ param.default_value ?? '–' }}</td>
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
            <thead class="bg-gray-100 text-left">
              <tr>
                <th class="px-3 py-2 border border-gray-300">Name</th>
                <th class="px-3 py-2 border border-gray-300">UI Label</th>
                <th class="px-3 py-2 border border-gray-300">Set Value</th>
                <th class="px-3 py-2 border border-gray-300">Default Value</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="param in expertParameters"
                :key="param.name"
                class="hover:bg-gray-50"
              >
                <td class="px-3 py-2 border border-gray-300">{{ param.name }}</td>
                <td class="px-3 py-2 border border-gray-300">{{ param.ui_label ?? '–' }}</td>
                <td class="px-3 py-2 border border-gray-300">{{ param.set_value ?? '–' }}</td>
                <td class="px-3 py-2 border border-gray-300">{{ param.default_value ?? '–' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    
    <div class="flex justify-end gap-2 mt-6">
      <button class="px-4 py-2 text-gray-600 hover:text-black" @click="$emit('close')">Schließen</button>
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
const loading = ref(false)

async function loadNonDefaultParameters() {
  if (!props.planId) return
  
  loading.value = true
  inputParameters.value = []
  expertParameters.value = []
  
  try {
    const response = await axios.get(`/plans/${props.planId}/non-default-parameters`)
    inputParameters.value = response.data.input || []
    expertParameters.value = response.data.expert || []
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


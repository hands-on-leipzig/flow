<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import ParameterField from '@/components/molecules/ParameterField.vue'

const props = defineProps<{
  parameters: any[]
  visibilityMap: Record<string, boolean>
  disabledMap: Record<string, boolean>
  showExplore?: boolean
  showChallenge?: boolean
}>()

const emit = defineEmits<{
  (e: 'update-param', param: any): void
}>()

// Visibility matrix from API
const visibilityMatrix = ref<Record<string, any>>({})

// Build quick lookup by name
const byName = computed<Record<string, any>>(
    () => Object.fromEntries(props.parameters.map((p: any) => [p.name, p]))
)

// Current modes
const eMode = computed(() => Number(byName.value['e_mode']?.value || 0))
const cMode = computed(() => Number(byName.value['c_mode']?.value || 1)) // Default to 1 (challenge enabled)

// Get current visibility state from matrix
const currentVisibility = computed(() => {
  const key = `e${eMode.value}_c${cMode.value}`
  return visibilityMatrix.value[key]?.fields || {}
})

// Get current columns layout from matrix
const currentColumns = computed(() => {
  const key = `e${eMode.value}_c${cMode.value}`
  return visibilityMatrix.value[key]?.columns || []
})

// Column labels mapping
const columnLabels: Record<string, string> = {
  'g': 'Gemeinsam',
  'e1': 'Explore Vormittag',
  'e2': 'Explore Nachmittag',
  'c': 'Challenge'
}

// Helper to get field prefix from column name
function getFieldPrefix(column: string): 'g' | 'c' | 'e1' | 'e2' {
  return column as 'g' | 'c' | 'e1' | 'e2'
}

// Helper: safely get a param by name
function getParam(name: string) {
  return byName.value[name] ?? null
}

// Cell renderer
function cellParam(prefix: 'g' | 'c' | 'e1' | 'e2', key: 'start_opening' | 'duration_opening' | 'duration_awards') {
  const name = `${prefix}_${key}`
  return getParam(name)
}

// Check if a field should be editable
function isFieldEditable(prefix: 'g' | 'c' | 'e1' | 'e2', key: 'start_opening' | 'duration_opening' | 'duration_awards'): boolean {
  // First check toggle state
  if (prefix === 'c' && props.showChallenge === false) return false // Challenge disabled
  if ((prefix === 'e1' || prefix === 'e2') && props.showExplore === false) return false // Explore disabled

  // Then check visibility matrix
  const fieldName = `${prefix}_${key}`
  return currentVisibility.value[fieldName]?.editable || false
}

// Forward updates
function updateParam(p: any) {
  emit('update-param', p)
}

// Helper functions to check if any fields of each type are available
function hasAnyStartField(): boolean {
  return isFieldEditable('e1', 'start_opening') || isFieldEditable('e2', 'start_opening') ||
      isFieldEditable('c', 'start_opening') || isFieldEditable('g', 'start_opening')
}

function hasAnyDurationField(): boolean {
  return isFieldEditable('e1', 'duration_opening') || isFieldEditable('e2', 'duration_opening') ||
      isFieldEditable('c', 'duration_opening') || isFieldEditable('g', 'duration_opening')
}

function hasAnyAwardsField(): boolean {
  return isFieldEditable('e1', 'duration_awards') || isFieldEditable('e2', 'duration_awards') ||
      isFieldEditable('c', 'duration_awards') || isFieldEditable('g', 'duration_awards')
}

// Fetch visibility matrix
onMounted(async () => {
  try {
    const response = await axios.get('/parameters/visibility')
    visibilityMatrix.value = response.data.matrix
  } catch (error) {
    console.error('Failed to fetch visibility matrix:', error)
  }
})
</script>

<template>
  <div class="p-3 border rounded shadow">
    <h2 class="text-lg font-semibold mb-3">Zeiten</h2>

    <div class="space-y-3">
      <!-- Unified header structure -->
      <div class="p-2">
        <!-- Dynamic rows with left column labels -->
        <div class="space-y-3 text-xs">
          <!-- Header row: Dynamic columns from backend -->
          <div class="grid gap-4 items-center mb-2" :style="`grid-template-columns: auto repeat(${currentColumns.length}, 1fr)`">
            <div class="text-right text-sm font-medium text-gray-600"></div>
            <div 
                v-for="col in currentColumns" 
                :key="col"
                class="text-center text-sm font-medium text-gray-600"
            >
              {{ columnLabels[col] }}
            </div>
          </div>
          <!-- Row 1: Start Times -->
          <div v-if="hasAnyStartField()" class="grid gap-4 items-center" :style="`grid-template-columns: auto repeat(${currentColumns.length}, 1fr)`">
            <div class="text-right text-xs font-medium text-gray-500">Beginn<br>Eröffnung</div>
            <div 
                v-for="col in currentColumns" 
                :key="`start_${col}`"
            >
              <ParameterField
                  v-if="isFieldEditable(getFieldPrefix(col), 'start_opening') && cellParam(getFieldPrefix(col), 'start_opening') && visibilityMap[cellParam(getFieldPrefix(col), 'start_opening').id]"
                  :disabled="disabledMap[cellParam(getFieldPrefix(col), 'start_opening').id]"
                  :horizontal="false"
                  :with-label="true"
                  :compact="true"
                  :param="cellParam(getFieldPrefix(col), 'start_opening')"
                  @update="updateParam"
              />
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
          </div>

          <!-- Row 2: Duration Opening -->
          <div v-if="hasAnyDurationField()" class="grid gap-4 items-center" :style="`grid-template-columns: auto repeat(${currentColumns.length}, 1fr)`">
            <div class="text-right text-xs font-medium text-gray-500">Dauer<br>Eröffnung</div>
            <div 
                v-for="col in currentColumns" 
                :key="`duration_${col}`"
            >
              <ParameterField
                  v-if="isFieldEditable(getFieldPrefix(col), 'duration_opening') && cellParam(getFieldPrefix(col), 'duration_opening') && visibilityMap[cellParam(getFieldPrefix(col), 'duration_opening').id]"
                  :disabled="disabledMap[cellParam(getFieldPrefix(col), 'duration_opening').id]"
                  :horizontal="false"
                  :with-label="true"
                  :compact="true"
                  :param="cellParam(getFieldPrefix(col), 'duration_opening')"
                  @update="updateParam"
              />
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
          </div>

          <!-- Row 3: Duration Awards -->
          <div v-if="hasAnyAwardsField()" class="grid gap-4 items-center" :style="`grid-template-columns: auto repeat(${currentColumns.length}, 1fr)`">
            <div class="text-right text-xs font-medium text-gray-500">Dauer<br>Preisverleihung</div>
            <div 
                v-for="col in currentColumns" 
                :key="`awards_${col}`"
            >
              <ParameterField
                  v-if="isFieldEditable(getFieldPrefix(col), 'duration_awards') && cellParam(getFieldPrefix(col), 'duration_awards') && visibilityMap[cellParam(getFieldPrefix(col), 'duration_awards').id]"
                  :disabled="disabledMap[cellParam(getFieldPrefix(col), 'duration_awards').id]"
                  :horizontal="false"
                  :with-label="true"
                  :compact="true"
                  :param="cellParam(getFieldPrefix(col), 'duration_awards')"
                  @update="updateParam"
              />
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

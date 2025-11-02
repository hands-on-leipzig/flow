<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import ParameterField from '@/components/molecules/ParameterField.vue'
import {programLogoSrc, imageUrl} from '@/utils/images'

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

// Column icons mapping
const columnIcons: Record<string, string> = {
  'g': imageUrl('/flow/first_v.png'),
  'e1': programLogoSrc('explore'),
  'e2': programLogoSrc('explore'),
  'c': programLogoSrc('challenge')
}

// Helper to check if a column should be visible
function isColumnVisible(column: string): boolean {
  return currentColumns.value.includes(column)
}

// Fixed column order: g, e1, e2, c
const allColumns = ['g', 'e1', 'e2', 'c']

// Computed: visible columns in order
const visibleColumns = computed(() => {
  return allColumns.filter(col => isColumnVisible(col))
})

// Computed: column widths - label column gets 25%, data columns share the rest
const labelColumnWidth = computed(() => '25%')
const dataColumnWidth = computed(() => {
  return visibleColumns.value.length > 0 ? `${75 / visibleColumns.value.length}%` : '0%'
})

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

    <div class="p-2">
      <table class="text-xs w-full" style="table-layout: fixed">
        <thead>
          <tr>
            <th class="text-right text-sm font-medium text-gray-600 pr-3" :style="`width: ${labelColumnWidth}`"></th>
            <th 
                v-for="col in visibleColumns" 
                :key="col"
                class="text-left text-sm font-medium text-gray-600 px-1 whitespace-normal break-words"
                :style="`width: ${dataColumnWidth}`"
            >
              <div class="flex items-center gap-1">
                <img :src="columnIcons[col]" :alt="columnLabels[col]" class="w-10 h-10 flex-shrink-0 object-contain">
                <span>{{ columnLabels[col] }}</span>
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <!-- Row 1: Start Times -->
          <tr v-if="hasAnyStartField()">
            <td class="text-right text-xs font-medium text-gray-500 pr-3 align-top" :style="`width: ${labelColumnWidth}`">
              Beginn<br>Eröffnung
            </td>
            <td 
                v-for="col in visibleColumns" 
                :key="`start_${col}`"
                class="text-center px-1 align-top"
                :style="`width: ${dataColumnWidth}`"
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
              <div v-else class="text-gray-400">-</div>
            </td>
          </tr>

          <!-- Row 2: Duration Opening -->
          <tr v-if="hasAnyDurationField()">
            <td class="text-right text-xs font-medium text-gray-500 pr-3 align-top" :style="`width: ${labelColumnWidth}`">
              Dauer<br>Eröffnung
            </td>
            <td 
                v-for="col in visibleColumns" 
                :key="`duration_${col}`"
                class="text-center px-1 align-top"
                :style="`width: ${dataColumnWidth}`"
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
              <div v-else class="text-gray-400">-</div>
            </td>
          </tr>

          <!-- Row 3: Duration Awards -->
          <tr v-if="hasAnyAwardsField()">
            <td class="text-right text-xs font-medium text-gray-500 pr-3 align-top" :style="`width: ${labelColumnWidth}`">
              Dauer<br>Preisverleihung
            </td>
            <td 
                v-for="col in visibleColumns" 
                :key="`awards_${col}`"
                class="text-center px-1 align-top"
                :style="`width: ${dataColumnWidth}`"
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
              <div v-else class="text-gray-400">-</div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

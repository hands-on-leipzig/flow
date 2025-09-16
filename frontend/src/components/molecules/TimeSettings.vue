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

// Check if a section should be shown (has at least one editable field AND toggle is enabled)
function shouldShowSection(prefix: 'g' | 'c' | 'e1' | 'e2'): boolean {
  // First check toggle state
  if (prefix === 'g') return true // Gemeinsam is always shown
  if (prefix === 'c') {
    if (props.showChallenge === false) return false // Challenge disabled
  }
  if (prefix === 'e1' || prefix === 'e2') {
    if (props.showExplore === false) return false // Explore disabled
  }

  // Then check if there are editable fields
  const fields = ['start_opening', 'duration_opening', 'duration_awards']
  return fields.some(field => isFieldEditable(prefix, field as any))
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
          <!-- Header row: Program types -->
          <div class="grid grid-cols-4 gap-4 items-center mb-2">
            <div class="text-right text-sm font-medium text-gray-600"></div>
            <div class="text-center text-sm font-medium text-gray-600">
              <span v-if="shouldShowSection('e1') && shouldShowSection('e2')">Explore Vormittag</span>
              <span v-else-if="shouldShowSection('e1') || shouldShowSection('e2')">Explore</span>
              <span v-else class="text-gray-400">-</span>
            </div>
            <div class="text-center text-sm font-medium text-gray-600">
              <span v-if="shouldShowSection('e1') && shouldShowSection('e2')">Explore Nachmittag</span>
              <span v-else-if="shouldShowSection('c')">Challenge</span>
              <span v-else class="text-gray-400">-</span>
            </div>
            <div class="text-center text-sm font-medium text-gray-600">
              <span v-if="shouldShowSection('e1') && shouldShowSection('e2')">Challenge</span>
              <span v-else-if="shouldShowSection('g')">Gemeinsam</span>
              <span v-else class="text-gray-400">-</span>
            </div>
          </div>
          <!-- Row 1: Start Times -->
          <div v-if="hasAnyStartField()" class="grid grid-cols-4 gap-4 items-center">
            <div class="text-right text-xs font-medium text-gray-500">Beginn<br>Eröffnung</div>
            <!-- Column 1: Explore Morning (e1) or Explore Afternoon (e2) if no e1 -->
            <div>
              <div v-if="isFieldEditable('e1', 'start_opening')">
                <ParameterField
                    v-if="cellParam('e1', 'start_opening') && visibilityMap[cellParam('e1', 'start_opening').id]"
                    :disabled="disabledMap[cellParam('e1', 'start_opening').id]"
                    :horizontal="false"
                    :with-label="true"
                    :compact="true"
                    :param="cellParam('e1', 'start_opening')"
                    @update="updateParam"
                />
              </div>
              <div v-else-if="isFieldEditable('e2', 'start_opening') && !isFieldEditable('e1', 'start_opening')">
                <ParameterField
                    v-if="cellParam('e2', 'start_opening') && visibilityMap[cellParam('e2', 'start_opening').id]"
                    :disabled="disabledMap[cellParam('e2', 'start_opening').id]"
                    :horizontal="false"
                    :param="cellParam('e2', 'start_opening')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
            <!-- Column 2: Explore Afternoon (e2) if e1 exists, or Challenge (c) if no e1 -->
            <div>
              <div v-if="isFieldEditable('e2', 'start_opening') && isFieldEditable('e1', 'start_opening')">
                <ParameterField
                    v-if="cellParam('e2', 'start_opening') && visibilityMap[cellParam('e2', 'start_opening').id]"
                    :disabled="disabledMap[cellParam('e2', 'start_opening').id]"
                    :horizontal="false"
                    :param="cellParam('e2', 'start_opening')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else-if="isFieldEditable('c', 'start_opening')">
                <ParameterField
                    v-if="cellParam('c', 'start_opening') && visibilityMap[cellParam('c', 'start_opening').id]"
                    :disabled="disabledMap[cellParam('c', 'start_opening').id]"
                    :horizontal="false"
                    :param="cellParam('c', 'start_opening')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
            <!-- Column 3: Challenge (c) if both e1 and e2 exist, or Gemeinsam (g) if no e1 -->
            <div>
              <div
                  v-if="isFieldEditable('c', 'start_opening') && isFieldEditable('e1', 'start_opening') && isFieldEditable('e2', 'start_opening')">
                <ParameterField
                    v-if="cellParam('c', 'start_opening') && visibilityMap[cellParam('c', 'start_opening').id]"
                    :disabled="disabledMap[cellParam('c', 'start_opening').id]"
                    :horizontal="false"
                    :param="cellParam('c', 'start_opening')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else-if="isFieldEditable('g', 'start_opening')">
                <ParameterField
                    v-if="cellParam('g', 'start_opening') && visibilityMap[cellParam('g', 'start_opening').id]"
                    :disabled="disabledMap[cellParam('g', 'start_opening').id]"
                    :horizontal="false"
                    :param="cellParam('g', 'start_opening')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
      </div>

          <!-- Row 2: Duration -->
          <div v-if="hasAnyDurationField()" class="grid grid-cols-4 gap-4 items-center">
            <div class="text-right text-xs font-medium text-gray-500">Dauer<br>Eröffnung</div>
            <!-- Column 1: Explore Morning (e1) or Explore Afternoon (e2) if no e1 -->
            <div>
              <div v-if="isFieldEditable('e1', 'duration_opening')">
                <ParameterField
                    v-if="cellParam('e1', 'duration_opening') && visibilityMap[cellParam('e1', 'duration_opening').id]"
                    :disabled="disabledMap[cellParam('e1', 'duration_opening').id]"
                    :horizontal="false"
                    :param="cellParam('e1', 'duration_opening')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else-if="isFieldEditable('e2', 'duration_opening') && !isFieldEditable('e1', 'duration_opening')">
                <ParameterField
                    v-if="cellParam('e2', 'duration_opening') && visibilityMap[cellParam('e2', 'duration_opening').id]"
                    :disabled="disabledMap[cellParam('e2', 'duration_opening').id]"
                    :horizontal="false"
                    :param="cellParam('e2', 'duration_opening')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
            <!-- Column 2: Explore Afternoon (e2) if e1 exists, or Challenge (c) if no e1 -->
            <div>
              <div v-if="isFieldEditable('e2', 'duration_opening') && isFieldEditable('e1', 'duration_opening')">
                <ParameterField
                    v-if="cellParam('e2', 'duration_opening') && visibilityMap[cellParam('e2', 'duration_opening').id]"
                    :disabled="disabledMap[cellParam('e2', 'duration_opening').id]"
                    :horizontal="false"
                    :param="cellParam('e2', 'duration_opening')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else-if="isFieldEditable('c', 'duration_opening')">
                <ParameterField
                    v-if="cellParam('c', 'duration_opening') && visibilityMap[cellParam('c', 'duration_opening').id]"
                    :disabled="disabledMap[cellParam('c', 'duration_opening').id]"
                    :horizontal="false"
                    :param="cellParam('c', 'duration_opening')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
            <!-- Column 3: Challenge (c) if both e1 and e2 exist, or Gemeinsam (g) if no e1 -->
            <div>
              <div
                  v-if="isFieldEditable('c', 'duration_opening') && isFieldEditable('e1', 'duration_opening') && isFieldEditable('e2', 'duration_opening')">
                <ParameterField
                    v-if="cellParam('c', 'duration_opening') && visibilityMap[cellParam('c', 'duration_opening').id]"
                    :disabled="disabledMap[cellParam('c', 'duration_opening').id]"
                    :horizontal="false"
                    :param="cellParam('c', 'duration_opening')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else-if="isFieldEditable('g', 'duration_opening')">
                <ParameterField
                    v-if="cellParam('g', 'duration_opening') && visibilityMap[cellParam('g', 'duration_opening').id]"
                    :disabled="disabledMap[cellParam('g', 'duration_opening').id]"
                    :horizontal="false"
                    :param="cellParam('g', 'duration_opening')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
      </div>

          <!-- Row 3: Awards -->
          <div v-if="hasAnyAwardsField()" class="grid grid-cols-4 gap-4 items-center">
            <div class="text-right text-xs font-medium text-gray-500">Dauer<br>Preisverleihung</div>
            <!-- Column 1: Explore Morning (e1) or Explore Afternoon (e2) if no e1 -->
            <div>
              <div v-if="isFieldEditable('e1', 'duration_awards')">
                <ParameterField
                    v-if="cellParam('e1', 'duration_awards') && visibilityMap[cellParam('e1', 'duration_awards').id]"
                    :disabled="disabledMap[cellParam('e1', 'duration_awards').id]"
                    :horizontal="false"
                    :param="cellParam('e1', 'duration_awards')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else-if="isFieldEditable('e2', 'duration_awards') && !isFieldEditable('e1', 'duration_awards')">
                <ParameterField
                    v-if="cellParam('e2', 'duration_awards') && visibilityMap[cellParam('e2', 'duration_awards').id]"
                    :disabled="disabledMap[cellParam('e2', 'duration_awards').id]"
                    :horizontal="false"
                    :param="cellParam('e2', 'duration_awards')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
            <!-- Column 2: Explore Afternoon (e2) if e1 exists, or Challenge (c) if no e1 -->
            <div>
              <div v-if="isFieldEditable('e2', 'duration_awards') && isFieldEditable('e1', 'duration_awards')">
                <ParameterField
                    v-if="cellParam('e2', 'duration_awards') && visibilityMap[cellParam('e2', 'duration_awards').id]"
                    :disabled="disabledMap[cellParam('e2', 'duration_awards').id]"
                    :horizontal="false"
                    :param="cellParam('e2', 'duration_awards')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else-if="isFieldEditable('c', 'duration_awards')">
                <ParameterField
                    v-if="cellParam('c', 'duration_awards') && visibilityMap[cellParam('c', 'duration_awards').id]"
                    :disabled="disabledMap[cellParam('c', 'duration_awards').id]"
                    :horizontal="false"
                    :param="cellParam('c', 'duration_awards')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
            <!-- Column 3: Challenge (c) if both e1 and e2 exist, or Gemeinsam (g) if no e1 -->
            <div>
              <div
                  v-if="isFieldEditable('c', 'duration_awards') && isFieldEditable('e1', 'duration_awards') && isFieldEditable('e2', 'duration_awards')">
                <ParameterField
                    v-if="cellParam('c', 'duration_awards') && visibilityMap[cellParam('c', 'duration_awards').id]"
                    :disabled="disabledMap[cellParam('c', 'duration_awards').id]"
                    :horizontal="false"
                    :param="cellParam('c', 'duration_awards')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else-if="isFieldEditable('g', 'duration_awards')">
                <ParameterField
                    v-if="cellParam('g', 'duration_awards') && visibilityMap[cellParam('g', 'duration_awards').id]"
                    :disabled="disabledMap[cellParam('g', 'duration_awards').id]"
                    :horizontal="false"
                    :param="cellParam('g', 'duration_awards')"
                    :with-label="true"
                    :compact="true"
                    @update="updateParam"
                />
              </div>
              <div v-else class="text-gray-400 text-center">-</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

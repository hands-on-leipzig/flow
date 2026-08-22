<script setup lang="ts">
import {ref, watch} from 'vue'
import axios from 'axios'
import draggable from 'vuedraggable'
import IconDraggable from '@/components/icons/IconDraggable.vue'
import ParameterField from '@/components/molecules/ParameterField.vue'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import type {Parameter} from '@/models/Parameter'
import {programLogoAlt, programLogoSrc} from '@/utils/images'

defineOptions({ name: 'ScheduleAfternoon' })

type AfternoonBlock = {
  id: number
  code: string
  name: string
  name_preview: string | null
  afternoon_chain: number
  afternoon_default: number | null
  afternoon_parameter: number | null
  first_program: number | null
  program: string | null
}

const {
  selectedPlanId,
  paramMap,
  visibilityMap,
  disabledMap,
  handleParamUpdate,
} = useScheduleWorkspace()
const blocks = ref<AfternoonBlock[]>([])
const loading = ref(false)

function orderRespectsChains(order: AfternoonBlock[]): boolean {
  const indexById = new Map<number, number>()
  for (let i = 0; i < order.length; i++) {
    indexById.set(Number(order[i].id), i)
  }
  for (const block of order) {
    const previousId = Number(block.afternoon_chain)
    if (!previousId) continue
    const previousIndex = indexById.get(previousId)
    if (previousIndex === undefined) continue
    const index = indexById.get(Number(block.id))
    if (index === undefined || index <= previousIndex) return false
  }
  return true
}

function allowMove(event: {draggedContext: {index: number; futureIndex: number}}): boolean {
  const from = event.draggedContext.index
  const to = event.draggedContext.futureIndex
  if (from === to || to == null) return true
  const next = blocks.value.slice()
  const [moved] = next.splice(from, 1)
  next.splice(to, 0, moved)
  return orderRespectsChains(next)
}

function embeddedParam(block: AfternoonBlock): Parameter | undefined {
  const id = Number(block.afternoon_parameter)
  if (!id) return undefined
  const param = paramMap.value[id]
  if (!param || visibilityMap.value[param.id] === false) return undefined
  return param
}

function embeddedParams(block: AfternoonBlock): Parameter[] {
  const param = embeddedParam(block)
  return param ? [param] : []
}

function isOff(block: AfternoonBlock): boolean {
  const param = embeddedParam(block)
  if (!param) return false
  if (param.type === 'boolean') {
    return !(param.value === 1 || param.value === true || param.value === '1')
  }
  if (param.type === 'integer') {
    return Number(param.value) === 0
  }
  return false
}

async function loadBlocks() {
  const planId = selectedPlanId.value
  if (!planId) {
    blocks.value = []
    return
  }
  loading.value = true
  try {
    const response = await axios.get(`/plans/${planId}/afternoon/blocks`)
    blocks.value = response.data.blocks || []
  } catch (error) {
    console.error('Failed to fetch afternoon blocks:', error)
    blocks.value = []
  } finally {
    loading.value = false
  }
}

async function saveOrder() {
  const planId = selectedPlanId.value
  if (!planId || loading.value) return
  try {
    const response = await axios.put(`/plans/${planId}/afternoon/blocks`, {
      ids: blocks.value.map((block) => Number(block.id)),
    })
    if (response.data.blocks) {
      blocks.value = response.data.blocks
    }
  } catch (error) {
    console.error('Failed to save afternoon block order:', error)
  }
}

watch(selectedPlanId, loadBlocks, {immediate: true})
</script>

<template>
  <div class="schedule-afternoon flex flex-col pb-2">
    <section class="afternoon-anchor glass-stack-card glass-stack-card--dashed">
      <h2 class="afternoon-anchor__title">Vorrunden</h2>
    </section>

    <draggable
        v-model="blocks"
        animation="150"
        chosen-class="drag-chosen"
        class="afternoon-stack"
        drag-class="drag-dragging"
        ghost-class="drag-ghost"
        handle=".drag-handle"
        filter=".afternoon-block__param"
        :prevent-on-filter="true"
        item-key="id"
        :move="allowMove"
        @end="saveOrder"
    >
      <template #item="{ element }">
        <div
            class="afternoon-block glass-card liquid-surface-inner"
            :class="{ 'afternoon-block--off': isOff(element) }"
        >
          <div class="afternoon-block__header">
            <span class="drag-handle" aria-label="Reihenfolge ändern">
              <IconDraggable/>
            </span>
            <img
                :alt="programLogoAlt(element.program || element.first_program)"
                :src="programLogoSrc(element.program || element.first_program)"
                class="afternoon-block__logo"
            >
            <span class="afternoon-block__label">{{ element.name }}</span>
          </div>
          <ParameterField
              v-for="param in embeddedParams(element)"
              :key="param.id"
              class="afternoon-block__param"
              :param="param"
              :disabled="disabledMap[param.id]"
              :with-label="false"
              :compact="true"
              @pointerdown.stop
              @update="(p: Parameter) => handleParamUpdate({ name: p.name, value: p.value })"
          />
        </div>
      </template>
    </draggable>

    <section class="afternoon-anchor glass-stack-card glass-stack-card--dashed">
      <h2 class="afternoon-anchor__title">Preisverleihung</h2>
    </section>
  </div>
</template>

<style scoped>
.schedule-afternoon {
  gap: 1.15rem;
}

.afternoon-stack {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.afternoon-anchor {
  padding: 0.7rem 0.9rem;
}

.afternoon-anchor__title {
  margin: 0;
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--color-text-muted);
  line-height: 1.3;
}

.afternoon-block {
  display: flex;
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem 1rem;
  padding: 0.7rem 0.9rem;
}

.afternoon-block__header {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
  flex: 1;
}

.drag-handle {
  display: inline-flex;
  color: var(--color-text-muted);
  cursor: grab;
  flex-shrink: 0;
}

.drag-handle :deep(svg) {
  width: 1.15rem;
  height: 1.15rem;
}

.afternoon-block__logo {
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  object-fit: contain;
}

.afternoon-block__label {
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--color-text);
  line-height: 1.3;
  min-width: 0;
}

.afternoon-block__param {
  min-width: 0;
  flex-shrink: 0;
  margin-left: auto;
}

.afternoon-block__param :deep(.glass-settings-row) {
  flex-wrap: nowrap;
  justify-content: flex-end;
}

.afternoon-block--off {
  border-style: dashed;
  border-width: 2px;
  background: color-mix(in srgb, var(--color-bg-muted) 72%, transparent);
  box-shadow: none;
}

.afternoon-block--off .afternoon-block__logo {
  opacity: 0.4;
  filter: grayscale(1);
}

.afternoon-block--off .afternoon-block__label {
  color: var(--color-text-muted);
}

.drag-ghost {
  opacity: 0.4;
}

.drag-chosen {
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-accent) 45%, transparent);
}

.drag-dragging {
  cursor: grabbing;
}
</style>

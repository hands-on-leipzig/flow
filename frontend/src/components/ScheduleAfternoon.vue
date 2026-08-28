<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import draggable from 'vuedraggable'
import IconDraggable from '@/components/icons/IconDraggable.vue'
import ParameterField from '@/components/molecules/ParameterField.vue'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import type {Parameter} from '@/models/Parameter'
import {programDisplayName} from '@/utils/eventPrograms'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import ProgramSection from '@/components/atoms/ProgramSection.vue'

defineOptions({ name: 'ScheduleAfternoon' })

const CHALLENGE_FP = 3
const FUTURE_8_FP = 8

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
  handleBlockUpdates,
} = useScheduleWorkspace()
const blocks = ref<AfternoonBlock[]>([])
const lastSavedIds = ref<number[]>([])
const loading = ref(false)

function asBool(value: unknown): boolean {
  return value === 1 || value === true || value === '1'
}

const separateRooms = computed(() => {
  const param = Object.values(paramMap.value).find((p) => p.name === 'g_separate_rooms')
  return param ? asBool(param.value) : false
})

/** Policy C UI: two lists when separate rooms and both Challenge + Future blocks exist. */
const splitLists = computed(() => {
  if (!separateRooms.value) return false
  const programs = new Set(
      blocks.value.map((b) => Number(b.first_program)).filter((id) => id > 0)
  )
  return programs.has(CHALLENGE_FP) && programs.has(FUTURE_8_FP)
})

const challengeBlocks = computed({
  get: () => blocks.value.filter((b) => Number(b.first_program) === CHALLENGE_FP),
  set: (next: AfternoonBlock[]) => {
    const rest = blocks.value.filter((b) => Number(b.first_program) !== CHALLENGE_FP)
    blocks.value = [...next, ...rest]
  },
})

const futureBlocks = computed({
  get: () => blocks.value.filter((b) => Number(b.first_program) === FUTURE_8_FP),
  set: (next: AfternoonBlock[]) => {
    const rest = blocks.value.filter((b) => Number(b.first_program) !== FUTURE_8_FP)
    // Keep Challenge first in underlying array for concat save stability.
    const challenge = rest.filter((b) => Number(b.first_program) === CHALLENGE_FP)
    const other = rest.filter((b) => Number(b.first_program) !== CHALLENGE_FP)
    blocks.value = [...challenge, ...next, ...other]
  },
})

const challengeLabel = computed(() => programDisplayName('CHALLENGE') || 'Challenge')
const futureLabel = computed(() => programDisplayName('FUTURE_8') || 'Future 8+')

function blockIds(order: AfternoonBlock[]): number[] {
  return order.map((block) => Number(block.id))
}

function sameIds(left: number[], right: number[]): boolean {
  return left.length === right.length && left.every((id, i) => id === right[i])
}

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

function allowMoveIn(list: AfternoonBlock[]) {
  return (event: {draggedContext: {index: number; futureIndex: number}}): boolean => {
    const from = event.draggedContext.index
    const to = event.draggedContext.futureIndex
    if (from === to || to == null) return true
    const next = list.slice()
    const [moved] = next.splice(from, 1)
    next.splice(to, 0, moved)
    return orderRespectsChains(next)
  }
}

function allowMove(event: {draggedContext: {index: number; futureIndex: number}}): boolean {
  return allowMoveIn(blocks.value)(event)
}

function allowMoveChallenge(event: {draggedContext: {index: number; futureIndex: number}}): boolean {
  return allowMoveIn(challengeBlocks.value)(event)
}

function allowMoveFuture(event: {draggedContext: {index: number; futureIndex: number}}): boolean {
  return allowMoveIn(futureBlocks.value)(event)
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

function successorBlock(block: AfternoonBlock): AfternoonBlock | undefined {
  return blocks.value.find((candidate) => Number(candidate.afternoon_chain) === Number(block.id))
}

function predecessorBlock(block: AfternoonBlock): AfternoonBlock | undefined {
  const previousId = Number(block.afternoon_chain)
  if (!previousId) return undefined
  return blocks.value.find((candidate) => Number(candidate.id) === previousId)
}

function successorIsOn(block: AfternoonBlock): boolean {
  const next = successorBlock(block)
  if (!next || !embeddedParam(next)) return false
  return !isOff(next)
}

function predecessorIsOff(block: AfternoonBlock): boolean {
  const previous = predecessorBlock(block)
  if (!previous || !embeddedParam(previous)) return false
  return isOff(previous)
}

function booleanOffBlocked(block: AfternoonBlock, param: Parameter): boolean {
  return param.type === 'boolean' && successorIsOn(block)
}

function booleanOnBlocked(block: AfternoonBlock, param: Parameter): boolean {
  return param.type === 'boolean' && predecessorIsOff(block)
}

function isParamOn(value: Parameter['value']): boolean {
  return value === 1 || value === true || value === '1'
}

function onParamUpdate(block: AfternoonBlock, param: Parameter) {
  if (booleanOffBlocked(block, param) && !isParamOn(param.value)) return
  if (booleanOnBlocked(block, param) && isParamOn(param.value)) return
  handleParamUpdate({name: param.name, value: param.value})
}

function orderedIdsForSave(): number[] {
  if (!splitLists.value) {
    return blockIds(blocks.value)
  }
  const other = blocks.value.filter((b) => {
    const fp = Number(b.first_program)
    return fp !== CHALLENGE_FP && fp !== FUTURE_8_FP
  })
  return [
    ...blockIds(challengeBlocks.value),
    ...blockIds(futureBlocks.value),
    ...blockIds(other),
  ]
}

async function loadBlocks() {
  const planId = selectedPlanId.value
  if (!planId) {
    blocks.value = []
    lastSavedIds.value = []
    return
  }
  loading.value = true
  try {
    const response = await axios.get(`/plans/${planId}/afternoon/blocks`)
    blocks.value = response.data.blocks || []
    lastSavedIds.value = orderedIdsForSave()
  } catch (error) {
    console.error('Failed to fetch afternoon blocks:', error)
    blocks.value = []
    lastSavedIds.value = []
  } finally {
    loading.value = false
  }
}

async function saveOrder() {
  const planId = selectedPlanId.value
  const ids = orderedIdsForSave()
  if (!planId || loading.value || sameIds(ids, lastSavedIds.value)) return
  try {
    const response = await axios.put(`/plans/${planId}/afternoon/blocks`, {
      ids,
    })
    if (response.data.blocks) {
      blocks.value = response.data.blocks
    }
    lastSavedIds.value = orderedIdsForSave()
    handleBlockUpdates([{name: 'afternoon_order', value: lastSavedIds.value.join(',')}])
  } catch (error) {
    console.error('Failed to save afternoon block order:', error)
  }
}

watch(selectedPlanId, loadBlocks, {immediate: true})
</script>

<template>
  <div class="schedule-afternoon flex flex-col pb-2">
    <!-- Policy C: vertical stack of program tiles (same rhythm as Ablauf → Allgemein) -->
    <template v-if="splitLists">
      <ProgramSection program="challenge" :short-name="challengeLabel">
        <div class="afternoon-tile flex flex-col">
          <section class="afternoon-anchor glass-stack-card glass-stack-card--dashed">
            <h2 class="afternoon-anchor__title">Vorrunden {{ challengeLabel }}</h2>
          </section>
          <draggable
              v-model="challengeBlocks"
              animation="150"
              chosen-class="drag-chosen"
              class="afternoon-stack"
              drag-class="drag-dragging"
              ghost-class="drag-ghost"
              handle=".drag-handle"
              item-key="id"
              :move="allowMoveChallenge"
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
                  <span class="afternoon-block__label">{{ element.name }}</span>
                </div>
                <ParameterField
                    v-for="param in embeddedParams(element)"
                    :key="param.id"
                    class="afternoon-block__param"
                    :param="param"
                    :disabled="disabledMap[param.id]"
                    :off-disabled="booleanOffBlocked(element, param)"
                    :on-disabled="booleanOnBlocked(element, param)"
                    :with-label="false"
                    :compact="true"
                    @pointerdown.stop
                    @update="(p: Parameter) => onParamUpdate(element, p)"
                />
              </div>
            </template>
          </draggable>
          <section class="afternoon-anchor glass-stack-card glass-stack-card--dashed">
            <h2 class="afternoon-anchor__title">Preisverleihung</h2>
          </section>
        </div>
      </ProgramSection>

      <ProgramSection program="future8" :short-name="futureLabel">
        <div class="afternoon-tile flex flex-col">
          <section class="afternoon-anchor glass-stack-card glass-stack-card--dashed">
            <h2 class="afternoon-anchor__title">Vorrunden {{ futureLabel }}</h2>
          </section>
          <draggable
              v-model="futureBlocks"
              animation="150"
              chosen-class="drag-chosen"
              class="afternoon-stack"
              drag-class="drag-dragging"
              ghost-class="drag-ghost"
              handle=".drag-handle"
              item-key="id"
              :move="allowMoveFuture"
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
                  <span class="afternoon-block__label">{{ element.name }}</span>
                </div>
                <ParameterField
                    v-for="param in embeddedParams(element)"
                    :key="param.id"
                    class="afternoon-block__param"
                    :param="param"
                    :disabled="disabledMap[param.id]"
                    :off-disabled="booleanOffBlocked(element, param)"
                    :on-disabled="booleanOnBlocked(element, param)"
                    :with-label="false"
                    :compact="true"
                    @pointerdown.stop
                    @update="(p: Parameter) => onParamUpdate(element, p)"
                />
              </div>
            </template>
          </draggable>
          <section class="afternoon-anchor glass-stack-card glass-stack-card--dashed">
            <h2 class="afternoon-anchor__title">Preisverleihung</h2>
          </section>
        </div>
      </ProgramSection>
    </template>

    <!-- Other profiles: one shared stage -->
    <template v-else>
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
              <ProgramLogo
                  :program="element.program || element.first_program"
                  size="section"
                  class="afternoon-block__logo"
              />
              <span class="afternoon-block__label">{{ element.name }}</span>
            </div>
            <ParameterField
                v-for="param in embeddedParams(element)"
                :key="param.id"
                class="afternoon-block__param"
                :param="param"
                :disabled="disabledMap[param.id]"
                :off-disabled="booleanOffBlocked(element, param)"
                :on-disabled="booleanOnBlocked(element, param)"
                :with-label="false"
                :compact="true"
                @pointerdown.stop
                @update="(p: Parameter) => onParamUpdate(element, p)"
            />
          </div>
        </template>
      </draggable>

      <section class="afternoon-anchor glass-stack-card glass-stack-card--dashed">
        <h2 class="afternoon-anchor__title">Preisverleihung</h2>
      </section>
    </template>
  </div>
</template>

<style scoped>
.schedule-afternoon {
  gap: 1.15rem;
}

.afternoon-tile {
  gap: 0.85rem;
  min-width: 0;
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

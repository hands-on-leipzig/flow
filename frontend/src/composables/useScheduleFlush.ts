import axios from 'axios'
import type {Ref} from 'vue'
import type {Parameter} from '@/models/Parameter'
import {pollPlanUntilReady, runGenerateLite} from '@/composables/usePlanGeneratorPoll'
import {orderDebouncedUpdates} from '@/utils/extraBlockSaveKeys'
import type {FreeExtraBlock, SlotExtraBlock} from '@/types/extraBlock'
import type {TeamSavePayload} from '@/components/molecules/SlotTeamPanel.vue'
import {parseExtraBlockSaveError} from '@/utils/extraBlockApiErrors'
import {normalizeDurationMinutes} from '@/utils/extraBlockDuration'

export const EXTRA_BLOCK_PREFIX = 'extra_block'
export const SLOT_BLOCK_PREFIX = 'slot_block'

export function isExtraBlockKey(key: string): boolean {
  return key.startsWith(`${EXTRA_BLOCK_PREFIX}_`)
}

export function isSlotBlockKey(key: string): boolean {
  return key.startsWith(`${SLOT_BLOCK_PREFIX}_`)
}

export function isBlockTriggerKey(key: string): boolean {
  return key.startsWith('block_')
}

export function isParamKey(key: string): boolean {
  return !isExtraBlockKey(key) && !isSlotBlockKey(key) && !isBlockTriggerKey(key)
}

export function toastActionFromKeys(keys: string[]): 'generate' | 'update' {
  return keys.some((key) => isParamKey(key) || isBlockTriggerKey(key)) ? 'generate' : 'update'
}

export function splitPendingUpdates(updates: Record<string, unknown>) {
  const paramUpdates: Record<string, unknown> = {}
  const blockTriggers: Record<string, unknown> = {}
  const extraBlockUpdates: Record<string, unknown> = {}
  const slotBlockUpdates: Record<string, unknown> = {}

  for (const [key, value] of Object.entries(updates)) {
    if (isExtraBlockKey(key)) extraBlockUpdates[key] = value
    else if (isSlotBlockKey(key)) slotBlockUpdates[key] = value
    else if (isBlockTriggerKey(key)) blockTriggers[key] = value
    else paramUpdates[key] = value
  }

  return {paramUpdates, blockTriggers, extraBlockUpdates, slotBlockUpdates}
}

export function scheduleChangeDetection(key: string, newValue: unknown, oldValue: unknown): boolean {
  if (isExtraBlockKey(key) || isSlotBlockKey(key)) {
    return JSON.stringify(newValue) !== JSON.stringify(oldValue)
  }
  return String(oldValue ?? '') !== String(newValue ?? '')
}

function normalizeParamValue(value: unknown, type: string | undefined) {
  if (type === 'boolean') return value ? 1 : 0
  return value
}

export interface ScheduleFlushDeps {
  planId: number | null
  paramMapByName: Record<string, Parameter>
  isGenerating: Ref<boolean>
  generatorError: Ref<string | null>
  errorDetails: Ref<string | null>
  loading: Ref<boolean>
  setOriginal: (key: string, value: unknown) => void
  runFullGenerate: () => Promise<void>
  refreshReadiness: () => Promise<void>
  freeBlockFlush?: (updates: Record<string, unknown>, options: {skipPostGeneration: boolean}) => Promise<boolean>
  slotBlockFlush?: (updates: Record<string, unknown>, options: {skipPostGeneration: boolean}) => Promise<boolean>
}

export async function executeScheduleFlush(
  updates: Record<string, unknown>,
  deps: ScheduleFlushDeps,
): Promise<void> {
  if (!deps.planId) return

  const {paramUpdates, blockTriggers, extraBlockUpdates, slotBlockUpdates} = splitPendingUpdates(updates)
  const paramEntries = Object.entries(paramUpdates)
  const blockTriggerEntries = Object.entries(blockTriggers)
  const needsFullGenerate = paramEntries.length > 0 || blockTriggerEntries.length > 0
  const hasExtraBlocks = Object.keys(extraBlockUpdates).length > 0
  const hasSlotBlocks = Object.keys(slotBlockUpdates).length > 0

  if (blockTriggerEntries.length > 0) {
    deps.isGenerating.value = true
  }

  deps.loading.value = true
  try {
    if (paramEntries.length > 0) {
      await axios.post(`/plans/${deps.planId}/parameters`, {
        parameters: paramEntries.map(([name, value]) => {
          const param = deps.paramMapByName[name]
          return {
            id: param?.id,
            value: normalizeParamValue(value, param?.type)?.toString() ?? '',
          }
        }),
      })
      paramEntries.forEach(([name, value]) => deps.setOriginal(name, value))
    }

    if (hasExtraBlocks && deps.freeBlockFlush) {
      const ok = await deps.freeBlockFlush(extraBlockUpdates, {skipPostGeneration: needsFullGenerate})
      if (!ok) return
    }

    if (hasSlotBlocks && !deps.slotBlockFlush) {
      throw new Error('Slot block flush handler not registered')
    }

    if (hasSlotBlocks && deps.slotBlockFlush) {
      const ok = await deps.slotBlockFlush(slotBlockUpdates, {skipPostGeneration: needsFullGenerate})
      if (!ok) return
    }

    if (needsFullGenerate) {
      await deps.runFullGenerate()
      await deps.refreshReadiness()
    } else if (hasExtraBlocks || hasSlotBlocks) {
      // Handlers run lite/poll when skipPostGeneration is false.
    }
  } catch (error) {
    if (import.meta.env.DEV) console.error('Error during schedule flush:', error)
  } finally {
    deps.loading.value = false
  }
}

export interface FreeBlockFlushDeps {
  planId: number
  blocks: Ref<FreeExtraBlock[]>
  loadBlocks: () => Promise<void>
  toApiPayload: (block: FreeExtraBlock) => Record<string, unknown>
  isGenerating: Ref<boolean>
  generatorError: Ref<string | null>
  errorDetails: Ref<string | null>
  onChanged: () => void
}

export async function flushFreeBlockUpdates(
  updates: Record<string, unknown>,
  deps: FreeBlockFlushDeps,
  options: {skipPostGeneration: boolean},
): Promise<boolean> {
  deps.generatorError.value = null
  deps.errorDetails.value = null
  deps.isGenerating.value = true

  try {
    for (const [name, value] of orderDebouncedUpdates(updates, EXTRA_BLOCK_PREFIX)) {
      if (name.startsWith(`${EXTRA_BLOCK_PREFIX}_update`) && value) {
        const response = await axios.post(
          `/plans/${deps.planId}/extra-blocks`,
          deps.toApiPayload(value as FreeExtraBlock),
        )
        if (response.data?.error) {
          deps.generatorError.value = response.data.error
          deps.errorDetails.value = response.data.details || null
          deps.isGenerating.value = false
          await deps.loadBlocks()
          return false
        }
      }
      if (name.startsWith(`${EXTRA_BLOCK_PREFIX}_delete`) && value && (value as FreeExtraBlock).id) {
        const deleteResponse = await axios.delete(`/extra-blocks/${(value as FreeExtraBlock).id}`)
        if (deleteResponse.data?.error) {
          deps.generatorError.value = deleteResponse.data.error
          deps.errorDetails.value = deleteResponse.data.details || null
          deps.isGenerating.value = false
          await deps.loadBlocks()
          return false
        }
      }
      if (name.startsWith(`${EXTRA_BLOCK_PREFIX}_add`) && value) {
        const block = value as FreeExtraBlock
        if (block._clientKey && !deps.blocks.value.some((row) => row._clientKey === block._clientKey)) {
          continue
        }
        const response = await axios.post(
          `/plans/${deps.planId}/extra-blocks`,
          deps.toApiPayload(block),
        )
        if (response.data?.error) {
          deps.generatorError.value = response.data.error
          deps.errorDetails.value = response.data.details || null
          deps.isGenerating.value = false
          await deps.loadBlocks()
          return false
        }
        const saved = response.data?.block || response.data
        if (block._clientKey && saved?.id) {
          const idx = deps.blocks.value.findIndex((row) => row._clientKey === block._clientKey)
          if (idx !== -1) deps.blocks.value[idx] = saved
        }
      }
    }

    await deps.loadBlocks()

    if (!options.skipPostGeneration) {
      await pollPlanUntilReady(deps.planId, deps.isGenerating, deps.generatorError, deps.errorDetails)
      deps.onChanged()
    } else {
      deps.isGenerating.value = false
    }

    return !deps.generatorError.value
  } catch (error: unknown) {
    console.error('Error flushing free block updates:', error)
    deps.isGenerating.value = false
    const parsed = parseExtraBlockSaveError(error, 'Fehler beim Speichern der Blöcke')
    deps.generatorError.value = parsed.message
    deps.errorDetails.value = parsed.details
    return false
  }
}

export interface SlotBlockFlushDeps {
  planId: number
  blocks: Ref<SlotExtraBlock[]>
  loadBlocks: () => Promise<void>
  reloadTeamPanels: () => Promise<void>
  toApiPayload: (block: SlotExtraBlock) => Record<string, unknown>
  isGenerating: Ref<boolean>
  generatorError: Ref<string | null>
  errorDetails: Ref<string | null>
  onChanged: () => void
}

export async function flushSlotBlockUpdates(
  updates: Record<string, unknown>,
  deps: SlotBlockFlushDeps,
  options: {skipPostGeneration: boolean},
): Promise<boolean> {
  deps.generatorError.value = null
  deps.errorDetails.value = null
  let needsLite = false

  try {
    const ordered = orderDebouncedUpdates(updates, SLOT_BLOCK_PREFIX)

    for (const [name, value] of ordered) {
      if (name.startsWith(`${SLOT_BLOCK_PREFIX}_delete`) && value && (value as SlotExtraBlock).id) {
        const block = value as SlotExtraBlock
        const deleteResponse = await axios.delete(
          `/plans/${deps.planId}/extra-blocks/slot/${block.id}`,
        )
        if (deleteResponse.data?.error) {
          deps.generatorError.value = deleteResponse.data.error
          deps.errorDetails.value = deleteResponse.data.details || null
          await deps.loadBlocks()
          return false
        }
        deps.onChanged()
      }

      if (name.startsWith(`${SLOT_BLOCK_PREFIX}_add`) && value) {
        const block = value as SlotExtraBlock
        if (block._clientKey && !deps.blocks.value.some((row) => row._clientKey === block._clientKey)) {
          continue
        }
        const response = await axios.post(
          `/plans/${deps.planId}/extra-blocks/slot`,
          deps.toApiPayload(block),
        )
        const saved = response.data?.block ?? response.data
        if (block._clientKey && saved?.id) {
          const idx = deps.blocks.value.findIndex((row) => row._clientKey === block._clientKey)
          if (idx !== -1) {
            deps.blocks.value[idx] = {
              ...deps.blocks.value[idx],
              ...saved,
              duration: normalizeDurationMinutes(saved.duration),
            }
          }
        }
        needsLite = true
      }

      if (name.startsWith(`${SLOT_BLOCK_PREFIX}_update`) && value && (value as SlotExtraBlock).id) {
        const block = value as SlotExtraBlock
        await axios.put(
          `/plans/${deps.planId}/extra-blocks/slot/${block.id}`,
          deps.toApiPayload(block),
        )
        needsLite = true
      }

      if (name.startsWith(`${SLOT_BLOCK_PREFIX}_team_`) && value) {
        const payload = value as TeamSavePayload
        await axios.patch(
          `/plans/${deps.planId}/extra-blocks/slot/${payload.blockId}/teams/${payload.first_program}/${payload.team_number_plan}`,
          {start: payload.start},
        )
        needsLite = true
      }
    }

    await deps.loadBlocks()

    if (needsLite && !options.skipPostGeneration) {
      const ok = await runGenerateLite(
        deps.planId,
        deps.isGenerating,
        deps.generatorError,
        deps.errorDetails,
      )
      if (ok) deps.onChanged()
    } else if (options.skipPostGeneration) {
      deps.isGenerating.value = false
    }

    await deps.reloadTeamPanels()
    return !deps.generatorError.value
  } catch (error: unknown) {
    console.error('Error flushing slot block updates:', error)
    deps.isGenerating.value = false
    const parsed = parseExtraBlockSaveError(error, 'Fehler beim Speichern der Slots')
    deps.generatorError.value = parsed.message
    deps.errorDetails.value = parsed.details
    await deps.loadBlocks()
    return false
  }
}

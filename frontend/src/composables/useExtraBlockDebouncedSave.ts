import {onMounted, onUnmounted} from 'vue'
import {useDebouncedSave} from '@/composables/useDebouncedSave'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import {DEBOUNCE_DELAY} from '@/constants/extraBlocks'
import {createBlockSaveKeys} from '@/utils/extraBlockSaveKeys'
import type {DraftableBlock} from '@/types/extraBlock'

export function useExtraBlockDebouncedSave(options: {
  keyPrefix: string
  onFlush: (updates: Record<string, unknown>) => Promise<void>
}) {
  const {
    isGenerating,
    generatorError,
    errorDetails,
    countdownSeconds,
    registerExtraBlockImmediateFlush,
    registerExtraBlockDebounceApi,
  } = useScheduleWorkspace()

  const {blockSaveKey, blockDeleteKey} = createBlockSaveKeys(options.keyPrefix)

  const {
    scheduleUpdate,
    cancelUpdate,
    immediateFlush,
    setOriginal,
    setOriginals,
    freeze,
    unfreeze,
    pendingCount,
  } = useDebouncedSave({
    delay: DEBOUNCE_DELAY,
    isGenerating: () => isGenerating.value,
    onShowToast: (countdown) => {
      countdownSeconds.value = countdown
    },
    onHideToast: () => {
      countdownSeconds.value = null
    },
    onCountdownUpdate: (seconds) => {
      countdownSeconds.value = seconds
    },
    changeDetection: (_key, newValue, oldValue) =>
      JSON.stringify(newValue) !== JSON.stringify(oldValue),
    onSave: options.onFlush,
  })

  onMounted(() => {
    registerExtraBlockImmediateFlush(options.keyPrefix, () => immediateFlush())
    registerExtraBlockDebounceApi(options.keyPrefix, {freeze, unfreeze})
  })

  onUnmounted(() => {
    registerExtraBlockImmediateFlush(options.keyPrefix, null)
    registerExtraBlockDebounceApi(options.keyPrefix, null)
  })

  function scheduleBlockSave(block: DraftableBlock & Record<string, unknown>) {
    scheduleUpdate(blockSaveKey(block), {...block})
  }

  function cancelPendingBlockSave(block: DraftableBlock) {
    cancelUpdate(blockSaveKey(block))
  }

  function scheduleBlockDelete(block: DraftableBlock & {id: number}) {
    scheduleUpdate(blockDeleteKey(block.id), {...block})
  }

  return {
    isGenerating,
    generatorError,
    errorDetails,
    scheduleUpdate,
    cancelUpdate,
    scheduleBlockSave,
    cancelPendingBlockSave,
    scheduleBlockDelete,
    blockSaveKey,
    blockDeleteKey,
    immediateFlush,
    setOriginal,
    setOriginals,
    pendingCount,
  }
}

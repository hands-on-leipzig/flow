import {onMounted, onUnmounted, watch} from 'vue'
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
    setExtraBlockPendingCount,
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

  watch(pendingCount, (count) => {
    setExtraBlockPendingCount(count)
  }, {immediate: true})

  onMounted(() => {
    registerExtraBlockImmediateFlush(() => immediateFlush())
    registerExtraBlockDebounceApi({freeze, unfreeze})
  })

  onUnmounted(() => {
    registerExtraBlockImmediateFlush(null)
    registerExtraBlockDebounceApi(null)
    setExtraBlockPendingCount(0)
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

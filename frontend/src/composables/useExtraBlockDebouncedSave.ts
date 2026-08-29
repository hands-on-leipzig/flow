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
  } = useScheduleWorkspace()

  const {blockSaveKey, blockDeleteKey} = createBlockSaveKeys(options.keyPrefix)

  const {scheduleUpdate, cancelUpdate, immediateFlush} = useDebouncedSave({
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
    onSave: options.onFlush,
  })

  onMounted(() => {
    registerExtraBlockImmediateFlush(() => immediateFlush())
  })

  onUnmounted(() => {
    registerExtraBlockImmediateFlush(null)
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
  }
}

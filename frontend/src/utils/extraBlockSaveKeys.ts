import type {DraftableBlock} from '@/types/extraBlock'

let draftBlockSeq = 0

export function nextClientKey(): string {
  draftBlockSeq += 1
  return `draft-${draftBlockSeq}`
}

export function blockRowKey(block: DraftableBlock): string {
  if (block.id != null) return `id-${block.id}`
  if (block._clientKey) return block._clientKey
  return JSON.stringify(block)
}

export function createBlockSaveKeys(prefix: string) {
  function blockSaveKey(block: DraftableBlock): string {
    if (block.id) return `${prefix}_update_${block.id}`
    if (!block._clientKey) {
      block._clientKey = nextClientKey()
    }
    return `${prefix}_add_${block._clientKey}`
  }

  function blockDeleteKey(id: number): string {
    return `${prefix}_delete_${id}`
  }

  return {blockSaveKey, blockDeleteKey}
}

export function orderDebouncedUpdates(
  updates: Record<string, unknown>,
  prefix: string,
): Array<[string, unknown]> {
  const entries = Object.entries(updates)
  return [
    ...entries.filter(([name]) => name.startsWith(`${prefix}_delete`)),
    ...entries.filter(([name]) => name.startsWith(`${prefix}_add`)),
    ...entries.filter(([name]) => name.startsWith(`${prefix}_update`)),
    ...entries.filter(([name]) => name.startsWith(`${prefix}_team_`)),
  ]
}

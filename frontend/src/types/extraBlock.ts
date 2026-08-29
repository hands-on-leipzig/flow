/** Client-side draft key for blocks not yet persisted */
export type DraftableBlock = {
  id?: number
  _clientKey?: string
}

export type ExtraBlockBase = DraftableBlock & {
  plan: number
  first_program: number | null | 0
  name: string
  description: string
  link?: string | null
  active?: boolean
}

export type FreeExtraBlock = ExtraBlockBase & {
  start?: string | null
  end?: string | null
  room?: number | null
}

export type SlotExtraBlock = ExtraBlockBase & {
  duration: number
}

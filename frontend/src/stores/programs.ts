import {defineStore} from 'pinia'
import axios from 'axios'
import type {EventProgramRef} from '@/utils/eventPrograms'
import {setProgramIdentityCatalog} from '@/utils/eventPrograms'
import {setProgramLogoCatalog} from '@/utils/images'

interface ProgramsStoreState {
  catalog: EventProgramRef[]
  loaded: boolean
  loading: Promise<void> | null
}

export const useProgramsStore = defineStore('programs', {
  state: (): ProgramsStoreState => ({
    catalog: [],
    loaded: false,
    loading: null,
  }),

  actions: {
    async ensureLoaded(): Promise<void> {
      if (this.loaded) return
      if (this.loading) {
        await this.loading
        return
      }
      this.loading = this.fetch()
      try {
        await this.loading
      } finally {
        this.loading = null
      }
    },

    async fetch(): Promise<void> {
      try {
        const {data} = await axios.get<any[]>('/programs')
        this.catalog = (Array.isArray(data) ? data : []).map((program) => ({
          id: program.id,
          first_program: program.id,
          name: program.name,
          display_name: program.display_name ?? null,
          letter: program.letter ?? null,
          family: program.family ?? null,
          sequence: program.sequence ?? null,
          color_hex: program.color_hex ?? null,
          logo_stem: program.logo_stem ?? null,
          logo_white: program.logo_white ?? null,
        }))
        setProgramIdentityCatalog(this.catalog)
        setProgramLogoCatalog(this.catalog)
        this.loaded = true
      } catch (error) {
        console.error('Failed to load program catalog', error)
      }
    },
  },
})

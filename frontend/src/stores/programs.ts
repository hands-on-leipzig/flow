import {defineStore} from 'pinia'
import axios from 'axios'
import type {EventProgramRef} from '@/utils/eventPrograms'
import {setProgramIdentityCatalog} from '@/utils/eventPrograms'
import {setProgramLogoCatalog} from '@/utils/images'

interface ProgramsStoreState {
  catalog: EventProgramRef[]
  afternoonFirstPrograms: number[]
  loaded: boolean
  loading: Promise<void> | null
}

export const useProgramsStore = defineStore('programs', {
  state: (): ProgramsStoreState => ({
    catalog: [],
    afternoonFirstPrograms: [],
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
        const [{data: programData}, {data: afternoonData}] = await Promise.all([
          axios.get<any[]>('/programs'),
          axios.get<{first_programs?: number[]}>('/parameter/afternoon-programs'),
        ])
        this.catalog = (Array.isArray(programData) ? programData : []).map((program) => ({
          id: program.id,
          first_program: program.id,
          name: program.name,
          display_name: program.display_name ?? null,
          letter: program.letter ?? null,
          sequence: program.sequence ?? null,
          color_hex: program.color_hex ?? null,
          logo_stem: program.logo_stem ?? null,
          logo_white: program.logo_white ?? null,
        }))
        this.afternoonFirstPrograms = Array.isArray(afternoonData?.first_programs)
          ? afternoonData.first_programs.map(Number).filter((id) => id > 0)
          : []
        setProgramIdentityCatalog(this.catalog)
        setProgramLogoCatalog(this.catalog)
        this.loaded = true
      } catch (error) {
        console.error('Failed to load program catalog', error)
      }
    },
  },
})

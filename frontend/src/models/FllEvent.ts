import {Slideshow} from "./slideshow";
import type {EventProgramRef} from "@/utils/eventPrograms";

export default class FllEvent {
    id: number
    name: string | null
    slug: string | null
    programs: EventProgramRef[]
    regional_partner: number
    level: number
    season: number
    date: string // ISO 8601 format, e.g. '2025-07-10'
    enddate: string | null
    days: number
    qrcode: string | null
    wifi_ssid: string | null
    wifi_password: string | null
    wifi_instruction: string | null
    wifi_qrcode: string | null
    slideshows: Slideshow[] | null

    // DRAHT team counts
    drahtTeamsExplore: number
    drahtTeamsChallenge: number
    hasTeamDiscrepancy: boolean

    // DRAHT team capacity
    drahtCapacityExplore: number
    drahtCapacityChallenge: number

    // Attention status
    needs_attention?: boolean
    needs_attention_checked_at?: string

    constructor(data: any) {
        Object.assign(this, data)
        this.programs = Array.isArray(data.programs) ? data.programs : []
        this.drahtTeamsExplore = data.drahtTeamsExplore || 0
        this.drahtTeamsChallenge = data.drahtTeamsChallenge || 0
        this.hasTeamDiscrepancy = data.hasTeamDiscrepancy || false
        this.drahtCapacityExplore = data.drahtCapacityExplore || 0
        this.drahtCapacityChallenge = data.drahtCapacityChallenge || 0
    }

    isFinalEvent(): boolean {
        return this.level === 3
    }

    getTotalDrahtTeams(): number {
        return this.drahtTeamsExplore + this.drahtTeamsChallenge
    }
}

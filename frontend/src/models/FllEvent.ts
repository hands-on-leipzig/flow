import {Slideshow} from "./slideshow";

export default class FllEvent {
    id: number
    name: string | null
    slug: string | null
    event_explore: number | null
    event_challenge: number | null
    regional_partner: number
    level: number
    season: number
    date: string // ISO 8601 format, e.g. '2025-07-10'
    enddate: string | null
    days: number
    qrcode: string | null
    wifi_ssid: string | null
    wifi_password: string | null
    slideshows: Slideshow[] | null

    constructor(data: any) {
        Object.assign(this, data)
    }

    isFinalEvent(): boolean {
        return this.level === 3
    }
}
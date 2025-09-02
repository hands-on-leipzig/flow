import {Slide} from "./slide";

export class Slideshow {
    id: number
    name: string
    slides: Slide[] | null

    constructor(data: any) {
        Object.assign(this, data)
    }
}
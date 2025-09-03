import {Slide} from "./slide";

export class Slideshow {
    id: number;
    name: string;
    slides: Slide[] | null;
    transition_time: number; // in seconds

    constructor(data: any) {
        Object.assign(this, data)
    }
}
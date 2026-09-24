import {SlideContent} from "./slideContent";

export class TeamsMapSlideContent extends SlideContent {

    public programs: number[] | null = null;

    constructor(data: object) {
        super();
        const hasPrograms = Object.prototype.hasOwnProperty.call(data, 'programs');
        Object.assign(this, data);
        this.programs = hasPrograms ? positiveProgramIds((data as {programs?: unknown}).programs) : null;
    }

    public toJSON(): object {
        const json: {type: string; programs?: number[]} = {
            type: "TeamsMapSlideContent",
        };
        if (Array.isArray(this.programs)) {
            json.programs = this.programs;
        }
        return json;
    }
}

function positiveProgramIds(raw: unknown): number[] {
    if (!Array.isArray(raw)) {
        return [];
    }
    return raw
        .map((id) => Number(id))
        .filter((id) => Number.isInteger(id) && id > 0);
}

import {SlideContent} from "./slideContent";
import {readShowSeasonLogo} from "./seasonLogo";

export class TeamsMapSlideContent extends SlideContent {

    public programs: number[] | null = null;

    constructor(data: object) {
        super();
        const hasPrograms = Object.prototype.hasOwnProperty.call(data, 'programs');
        Object.assign(this, data);
        this.programs = hasPrograms ? positiveProgramIds((data as {programs?: unknown}).programs) : null;
        this.showSeasonLogo = readShowSeasonLogo(data, 'TeamsMapSlideContent');
    }

    public toJSON(): object {
        const json: {type: string; showSeasonLogo: boolean; programs?: number[]} = {
            type: "TeamsMapSlideContent",
            showSeasonLogo: this.showSeasonLogo,
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

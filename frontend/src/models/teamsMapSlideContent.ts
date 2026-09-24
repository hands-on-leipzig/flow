import {SlideContent} from "./slideContent";
import {readShowSeasonLogo, slideBackgroundOrDefault} from "./seasonLogo";

export class TeamsMapSlideContent extends SlideContent {

    public programs: number[] | null = null;

    constructor(data: object) {
        super();
        const hasPrograms = Object.prototype.hasOwnProperty.call(data, 'programs');
        Object.assign(this, data);
        this.programs = hasPrograms ? positiveProgramIds((data as {programs?: unknown}).programs) : null;
        this.showSeasonLogo = readShowSeasonLogo(data, 'TeamsMapSlideContent');
        this.background = slideBackgroundOrDefault(this.background);
    }

    public toJSON(): object {
        const json: {type: string; showSeasonLogo: boolean; background: object; programs?: number[]} = {
            type: "TeamsMapSlideContent",
            showSeasonLogo: this.showSeasonLogo,
            background: this.background,
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

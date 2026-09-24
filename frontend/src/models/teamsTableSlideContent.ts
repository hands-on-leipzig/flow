import {SlideContent} from "./slideContent";
import {readShowSeasonLogo} from "./seasonLogo";

export class TeamsTableSlideContent extends SlideContent {

    public teamsPerPage: number = 8;
    public secondsPerPage: number = 15;
    public programs: number[] | null = null;

    constructor(data: object) {
        super();
        const hasPrograms = Object.prototype.hasOwnProperty.call(data, 'programs');
        Object.assign(this, data);
        this.programs = hasPrograms ? positiveProgramIds((data as {programs?: unknown}).programs) : null;
        this.showSeasonLogo = readShowSeasonLogo(data, 'TeamsTableSlideContent');
    }

    public toJSON(): object {
        const json: {
            type: string;
            teamsPerPage: number;
            secondsPerPage: number;
            showSeasonLogo: boolean;
            programs?: number[];
        } = {
            type: "TeamsTableSlideContent",
            teamsPerPage: this.teamsPerPage,
            secondsPerPage: this.secondsPerPage,
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

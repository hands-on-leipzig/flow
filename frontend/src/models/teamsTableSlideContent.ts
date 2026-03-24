import {SlideContent} from "./slideContent";

export class TeamsTableSlideContent extends SlideContent {

    public teamsPerPage: number = 8;
    public secondsPerPage: number = 15;

    constructor(data: object) {
        super();
        Object.assign(this, data);
    }

    public toJSON(): object {
        return {
            type: "TeamsTableSlideContent",
            teamsPerPage: this.teamsPerPage,
            secondsPerPage: this.secondsPerPage,
        };
    }
}

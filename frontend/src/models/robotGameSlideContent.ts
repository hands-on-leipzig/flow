import {SlideContent} from "./slideContent";

export class RobotGameSlideContent extends SlideContent {

    public backgroundImageUrl: string;
    public teamsPerPage: number = 8;
    public secondsPerPage: number = 15;
    public highlightColor: string = '#F78B1F';
    public textColor: string = '#222222';

    constructor(data: object) {
        super();
        Object.assign(this, data);
    }

    public toJSON(): object {
        return {
            type: "RobotGameSlideContent",
            backgroundImageUrl: this.backgroundImageUrl,
            teamsPerPage: this.teamsPerPage,
            secondsPerPage: this.secondsPerPage,
            highlightColor: this.highlightColor,
            textColor: this.textColor,
            background: this.background
        };
    }
}

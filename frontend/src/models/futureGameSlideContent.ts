import {SlideContent} from "./slideContent";
import {readShowSeasonLogo} from "./seasonLogo";

export type FutureGameRound = { round: number, label: string };

export type FutureGameTeam = {
    id: number,
    number?: string | number | null,
    name: string,
    points: (number | null)[],
    average: number,
    rank: number,
};

export class FutureGameSlideContent extends SlideContent {

    public backgroundImageUrl: string;
    public teamsPerPage: number = 8;
    public secondsPerPage: number = 15;
    public textColor: string = '#222222';
    public tableBackgroundColor: string = 'rgba(255, 255, 255, 0.4)';
    public tableBorderColor: string = '#000000';

    constructor(data: object) {
        super();
        Object.assign(this, data);
        this.showSeasonLogo = readShowSeasonLogo(data, 'FutureGameSlideContent');
    }

    public toJSON(): object {
        return {
            type: "FutureGameSlideContent",
            backgroundImageUrl: this.backgroundImageUrl,
            teamsPerPage: this.teamsPerPage,
            secondsPerPage: this.secondsPerPage,
            textColor: this.textColor,
            tableBackgroundColor: this.tableBackgroundColor,
            tableBorderColor: this.tableBorderColor,
            background: this.background,
            showSeasonLogo: this.showSeasonLogo,
        };
    }
}

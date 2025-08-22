import {SlideContent} from "./slideContent";

export class RobotGameSlideContent extends SlideContent {

    public backgroundImageUrl: string;
    public teamsPerPage: number = 8;
    public showFooter: boolean = false;
    public footerImages: string[] = [];
    public highlightColor: string = '#F78B1F';

    public toJSON(): object {
        return {
            type: "RobotGameSlideContent",
            backgroundImageUrl: this.backgroundImageUrl,
            teamsPerPage: this.teamsPerPage,
            showFooter: this.showFooter,
            footerImages: this.footerImages,
            highlightColor: this.highlightColor
        };
    }
}

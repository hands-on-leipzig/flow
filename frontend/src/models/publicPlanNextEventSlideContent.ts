import {AbstractPublicPlanSlideContent} from "./abstractPublicPlanSlideContent";
import {readShowSeasonLogo} from "./seasonLogo";

export class PublicPlanNextEventSlideContent extends AbstractPublicPlanSlideContent {

    constructor(data: object) {
        super(data);
        this.showSeasonLogo = readShowSeasonLogo(data, 'PublicPlanNextEventSlideContent');
    }

    public toJSON(): object {
        return {
            type: 'PublicPlanNextEventSlideContent',
            planId: this.planId,
            interval: this.interval,
            ...this.audienceFields(),
            room: this.room,
            background: this.background,
            showSeasonLogo: this.showSeasonLogo,
        };
    }
}

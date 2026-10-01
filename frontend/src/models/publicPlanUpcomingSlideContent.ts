import {AbstractPublicPlanSlideContent} from "./abstractPublicPlanSlideContent";
import {readShowSeasonLogo} from "./seasonLogo";

export class PublicPlanUpcomingSlideContent extends AbstractPublicPlanSlideContent {

    constructor(data: object) {
        super(data);
        Object.assign(this, data);
        this.showSeasonLogo = readShowSeasonLogo(data, 'PublicPlanUpcomingSlideContent');
    }

    public toJSON(): object {
        return {
            type: 'PublicPlanUpcomingSlideContent',
            planId: this.planId,
            interval: this.interval,
            ...this.audienceFields(),
            room: this.room,
            background: this.background,
            showSeasonLogo: this.showSeasonLogo,
        };
    }
}

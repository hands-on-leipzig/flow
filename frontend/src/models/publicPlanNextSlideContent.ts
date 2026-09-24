import {AbstractPublicPlanSlideContent} from "./abstractPublicPlanSlideContent";
import {readShowSeasonLogo} from "./seasonLogo";

export class PublicPlanNextSlideContent extends AbstractPublicPlanSlideContent {

    constructor(data: object) {
        super(data);
        Object.assign(this, data);
        this.showSeasonLogo = readShowSeasonLogo(data, 'PublicPlanNextSlideContent');
    }

    public toJSON(): object {
        return {
            type: 'PublicPlanNextSlideContent',
            planId: this.planId,
            interval: this.interval,
            ...this.audienceFields(),
            room: this.room,
            background: this.background,
            showSeasonLogo: this.showSeasonLogo,
        };
    }
}

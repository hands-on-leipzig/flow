import {AbstractPublicPlanSlideContent} from "./abstractPublicPlanSlideContent";
import {readShowSeasonLogo} from "./seasonLogo";

export class PublicPlanSlideContent extends AbstractPublicPlanSlideContent {

    constructor(data: object) {
        super(data);
        Object.assign(this, data);
        this.showSeasonLogo = readShowSeasonLogo(data, 'PublicPlanSlideContent');
    }

    public toJSON(): object {
        return {
            type: "PublicPlanSlideContent",
            planId: this.planId,
            ...this.audienceFields(),
            room: this.room,
            background: this.background,
            showSeasonLogo: this.showSeasonLogo,
        };
    }
}

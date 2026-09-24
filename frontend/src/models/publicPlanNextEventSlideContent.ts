import {AbstractPublicPlanSlideContent} from "./abstractPublicPlanSlideContent";

export class PublicPlanNextEventSlideContent extends AbstractPublicPlanSlideContent {

    constructor(data: object) {
        super(data);
    }

    public toJSON(): object {
        return {
            type: 'PublicPlanNextEventSlideContent',
            planId: this.planId,
            interval: this.interval,
            ...this.audienceFields(),
            room: this.room,
            background: this.background,
        };
    }
}

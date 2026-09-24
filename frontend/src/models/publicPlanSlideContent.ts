import {AbstractPublicPlanSlideContent} from "./abstractPublicPlanSlideContent";

export class PublicPlanSlideContent extends AbstractPublicPlanSlideContent {

    constructor(data: object) {
        super(data);
        Object.assign(this, data);
    }

    public toJSON(): object {
        return {
            type: "PublicPlanSlideContent",
            planId: this.planId,
            ...this.audienceFields(),
            room: this.room,
            background: this.background
        };
    }
}

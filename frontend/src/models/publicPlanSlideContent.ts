import {SlideContent} from "./slideContent";

export class PublicPlanSlideContent extends SlideContent {

    public planId: number;

    // 14: Besucher Allgemein
    // 6: Besucher Challenge
    // 10: Besucher Explore
    public role: number = 14;

    constructor(data: object) {
        super();
        Object.assign(this, data);
    }

    public toJSON(): object {
        return {
            type: "PublicPlanSlideContent",
            planId: this.planId,
            role: this.role,
            background: this.background
        };
    }
}

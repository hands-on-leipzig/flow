import {SlideContent} from "./slideContent";

export class PublicPlanSlideContent extends SlideContent {

    public planId: number;
    // Wie viele Stunden nach vorne soll geschaut werden
    public hours: number;

    constructor(planId: number, hours: number) {
        super();
        this.planId = planId;
        this.hours = hours;
    }

    public toJSON(): object {
        return {
            type: "PublicPlanSlideContent",
            planId: this.planId,
            hours: this.hours,
        };
    }
}

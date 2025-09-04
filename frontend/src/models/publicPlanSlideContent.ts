import {SlideContent} from "./slideContent";

export class PublicPlanSlideContent extends SlideContent {

    public planId: number;
    // Wie viele Stunden nach vorne soll geschaut werden
    public hours: number;

    // 14: Besucher Allgemein
    // 6: Besucher Challenge
    // 10: Besucher Explore
    public role: number;

    constructor(data: object) {
        super();
        Object.assign(this, data);
    }

    public toJSON(): object {
        return {
            type: "PublicPlanSlideContent",
            planId: this.planId,
            hours: this.hours,
            role: this.role,
            background: this.background
        };
    }
}

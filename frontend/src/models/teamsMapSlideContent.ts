import {SlideContent} from "./slideContent";

export class TeamsMapSlideContent extends SlideContent {

    constructor(data: object) {
        super();
        Object.assign(this, data);
    }

    public toJSON(): object {
        return {
            type: "TeamsMapSlideContent",
        };
    }
}

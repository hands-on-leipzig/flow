import {SlideContent} from "./slideContent";

export class FabricSlideContent extends SlideContent {

    constructor(data: object) {
        super();
        Object.assign(this, data);
    }

    public toJSON(): object {
        return {
            type: "FabricSlideContent",
            background: this.background,
        };
    }
}

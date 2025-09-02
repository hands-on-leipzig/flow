import {SlideContent} from "./slideContent";

export class FabricSlideContent extends SlideContent {

    public json: string;

    constructor(json: string) {
        super();
        this.json = json;
    }

    public toJSON(): object {
        return {
            type: "FabricSlideContent",
            json: this.json,
        };
    }
}

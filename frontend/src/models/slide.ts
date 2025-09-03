import {SlideContent} from "./slideContent";
import {ImageSlideContent} from "./imageSlideContent";
import {RobotGameSlideContent} from "./robotGameSlideContent";
import {UrlSlideContent} from "./urlSlideContent";
import {PhotoSlideContent} from "./photoSlideContent";
import {FabricSlideContent} from "./fabricSlideContent";
import {PublicPlanSlideContent} from "./publicPlanSlideContent";

export class Slide {

    public id: number;
    public name: string;
    public content: SlideContent;

    constructor(id: number, name: string, content: SlideContent) {
        this.id = id;
        this.name = name;
        this.content = content;
    }

    public static fromObject(obj: any): Slide {
        let content: SlideContent;

        // @ts-ignore
        if (obj.content) {
            const c = JSON.parse(obj.content);
            switch (c.type) {
                case "ImageSlideContent":
                    content = new ImageSlideContent(c.imageUrl);
                    break;
                case "RobotGameSlideContent":
                    content = new RobotGameSlideContent();
                    break;
                case "UrlSlideContent":
                    content = new UrlSlideContent(c.url);
                    break;
                case "PhotoSlideContent":
                    content = new PhotoSlideContent();
                    break;
                case "FabricSlideContent":
                    content = new FabricSlideContent(c.json);
                    break;
                case "PublicPlanSlideContent":
                    content = new PublicPlanSlideContent(c.planId, c.hours);
                    break;
                default:
                    console.error("Unknown slide content type: " + c.type);
                    content = null;
            }
        }
        return new Slide(obj.id, obj.name, content);
    }
}

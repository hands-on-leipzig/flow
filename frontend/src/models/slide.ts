import {SlideContent} from "./slideContent";
import {ImageSlideContent} from "./imageSlideContent";
import {RobotGameSlideContent} from "./robotGameSlideContent";
import {UrlSlideContent} from "./urlSlideContent";
import {PhotoSlideContent} from "./photoSlideContent";
import {FabricSlideContent} from "./fabricSlideContent";

export class Slide {

    public id: number;
    public title: string;
    public content: SlideContent;

    constructor(id: number, title: string, content: SlideContent) {
        this.id = id;
        this.title = title;
        this.content = content;
    }

    public static fromArray(arr: object[]): Slide[] {
        return arr.map(obj => {
            let content: SlideContent;

            if (obj['content']) {
                switch (obj['content'].type) {
                    case "ImageSlideContent":
                        content = new ImageSlideContent(obj['content'].imageUrl);
                        break;
                    case "RobotGameSlideContent":
                        content = new RobotGameSlideContent();
                        break;
                    case "UrlSlideContent":
                        content = new UrlSlideContent(obj['content'].url);
                        break;
                    case "PhotoSlideContent":
                        content = new PhotoSlideContent();
                        break;
                    case "FabricSlideContent":
                        content = new FabricSlideContent(obj['content'].json);
                        break;
                    default:
                        console.error("Unknown slide content type: " + obj['content'].type);
                        content = null;
                }
            }
            return new Slide(obj['id'], obj['title'], content);
        });
    }

    public static fromObject(obj: any): Slide {
        let content: SlideContent;

        // @ts-ignore
        if (obj.content) {
            switch (obj.content.type) {
                case "ImageSlideContent":
                    content = new ImageSlideContent(obj.content.imageUrl);
                    break;
                case "RobotGameSlideContent":
                    content = new RobotGameSlideContent();
                    break;
                case "UrlSlideContent":
                    content = new UrlSlideContent(obj.content.url);
                    break;
                case "PhotoSlideContent":
                    content = new PhotoSlideContent();
                    break;
                case "FabricSlideContent":
                    content = new FabricSlideContent(obj.content.json);
                    break;
                default:
                    console.error("Unknown slide content type: " + obj.content.type);
                    content = null;
            }
        }
        return new Slide(obj.id, obj.title, content);
    }
}

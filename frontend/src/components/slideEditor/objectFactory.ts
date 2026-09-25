import {Circle, FabricObject, Line, Path, Polygon, Rect, Textbox, Triangle} from 'fabric';
import {SLIDE_HEIGHT, SLIDE_WIDTH} from './constants';

const CENTER = {left: SLIDE_WIDTH / 2, top: SLIDE_HEIGHT / 2};
const SHAPE_FILL = '#ff7a00';
const LINE_COLOR = '#0f172a';

export type TextPresetId = 'heading' | 'subheading' | 'body';

export const TEXT_PRESETS: { id: TextPresetId, label: string, sample: string, fontSize: number, bold: boolean, width: number }[] = [
  {id: 'heading', label: 'Überschrift', sample: 'Überschrift', fontSize: 56, bold: true, width: 560},
  {id: 'subheading', label: 'Untertitel', sample: 'Untertitel', fontSize: 32, bold: false, width: 460},
  {id: 'body', label: 'Fließtext', sample: 'Hier steht dein Text', fontSize: 20, bold: false, width: 360},
];

export function createText(presetId: TextPresetId): Textbox {
  const preset = TEXT_PRESETS.find(p => p.id === presetId) ?? TEXT_PRESETS[2];
  return new Textbox(preset.sample, {
    ...CENTER,
    width: preset.width,
    fontFamily: 'Uniform',
    fontSize: preset.fontSize,
    fontWeight: preset.bold ? 'bold' : 'normal',
    fill: '#0f172a',
    textAlign: 'center',
    lineHeight: 1.16,
    editable: true,
  });
}

export type ShapeId = 'rect' | 'rounded' | 'circle' | 'triangle' | 'diamond' | 'star' | 'line' | 'arrow';

export const SHAPES: { id: ShapeId, label: string, icon: string }[] = [
  {id: 'rect', label: 'Rechteck', icon: 'bi-square-fill'},
  {id: 'rounded', label: 'Abgerundet', icon: 'bi-app'},
  {id: 'circle', label: 'Kreis', icon: 'bi-circle-fill'},
  {id: 'triangle', label: 'Dreieck', icon: 'bi-triangle-fill'},
  {id: 'diamond', label: 'Raute', icon: 'bi-diamond-fill'},
  {id: 'star', label: 'Stern', icon: 'bi-star-fill'},
  {id: 'line', label: 'Linie', icon: 'bi-slash-lg'},
  {id: 'arrow', label: 'Pfeil', icon: 'bi-arrow-right'},
];

function starPoints(spikes: number, outer: number, inner: number) {
  const points = [];
  for (let i = 0; i < spikes * 2; i++) {
    const r = i % 2 === 0 ? outer : inner;
    const a = (Math.PI / spikes) * i - Math.PI / 2;
    points.push({x: Math.cos(a) * r, y: Math.sin(a) * r});
  }
  return points;
}

export function createShape(id: ShapeId): FabricObject {
  const base = {...CENTER, fill: SHAPE_FILL, strokeWidth: 0, strokeUniform: true};
  switch (id) {
    case 'rounded':
      return new Rect({...base, width: 180, height: 110, rx: 18, ry: 18});
    case 'circle':
      return new Circle({...base, radius: 60});
    case 'triangle':
      return new Triangle({...base, width: 130, height: 115});
    case 'diamond':
      return new Polygon([{x: 0, y: -65}, {x: 65, y: 0}, {x: 0, y: 65}, {x: -65, y: 0}], base);
    case 'star':
      return new Polygon(starPoints(5, 65, 28), base);
    case 'line':
      return new Line([0, 0, 220, 0], {
        ...CENTER, stroke: LINE_COLOR, strokeWidth: 4, strokeLineCap: 'round', strokeUniform: true,
      });
    case 'arrow':
      return new Path('M 0 0 L 200 0 M 180 -16 L 200 0 L 180 16', {
        ...CENTER, fill: '', stroke: LINE_COLOR, strokeWidth: 4,
        strokeLineCap: 'round', strokeLineJoin: 'round', strokeUniform: true,
      });
    case 'rect':
    default:
      return new Rect({...base, width: 180, height: 110});
  }
}

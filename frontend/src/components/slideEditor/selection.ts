import {
  ActiveSelection,
  Circle,
  Ellipse,
  FabricImage,
  FabricObject,
  FabricText,
  Group,
  Line,
  Path,
  Point,
  Polygon,
  Rect,
  Shadow,
  Textbox,
  Triangle,
} from 'fabric';

export type SelectionKind = 'none' | 'text' | 'shape' | 'line' | 'image' | 'group' | 'multi';
export type StrokeDash = 'solid' | 'dashed' | 'dotted';

export interface SelectionState {
  kind: SelectionKind;
  count: number;
  x: number;
  y: number;
  width: number;
  height: number;
  angle: number;
  opacity: number;
  fill: string | null;
  stroke: string | null;
  strokeWidth: number;
  strokeDash: StrokeDash;
  radius: number;
  hasRadius: boolean;
  shadow: boolean;
  locked: boolean;
  flipX: boolean;
  flipY: boolean;
  fontFamily: string;
  fontSize: number;
  bold: boolean;
  italic: boolean;
  underline: boolean;
  linethrough: boolean;
  textAlign: string;
  lineHeight: number;
  charSpacing: number;
  textBackgroundColor: string | null;
}

export interface LayerItem {
  id: number;
  label: string;
  icon: string;
  visible: boolean;
  locked: boolean;
}

export function emptySelection(): SelectionState {
  return {
    kind: 'none', count: 0,
    x: 0, y: 0, width: 0, height: 0, angle: 0, opacity: 100,
    fill: null, stroke: null, strokeWidth: 0, strokeDash: 'solid',
    radius: 0, hasRadius: false, shadow: false, locked: false, flipX: false, flipY: false,
    fontFamily: 'Uniform', fontSize: 24, bold: false, italic: false, underline: false, linethrough: false,
    textAlign: 'left', lineHeight: 1.16, charSpacing: 0, textBackgroundColor: null,
  };
}

export function kindOf(obj: FabricObject | undefined | null): SelectionKind {
  if (!obj) return 'none';
  if (obj instanceof ActiveSelection) return 'multi';
  if (obj instanceof Group) return 'group';
  if (obj instanceof FabricText) return 'text';
  if (obj instanceof FabricImage) return 'image';
  if (obj instanceof Line || obj instanceof Path) return 'line';
  return 'shape';
}

export function layerLabel(obj: FabricObject): string {
  const name = (obj as any).name;
  if (name) return name;
  if (obj instanceof FabricText) {
    const text = (obj.text ?? '').replace(/\s+/g, ' ').trim();
    return text ? (text.length > 28 ? text.slice(0, 28) + '…' : text) : 'Text';
  }
  if (obj instanceof FabricImage) return 'Bild';
  if (obj instanceof Group) return 'Gruppe';
  if (obj instanceof Line) return 'Linie';
  if (obj instanceof Path) return 'Pfeil';
  if (obj instanceof Circle) return 'Kreis';
  if (obj instanceof Ellipse) return 'Ellipse';
  if (obj instanceof Triangle) return 'Dreieck';
  if (obj instanceof Polygon) return 'Form';
  if (obj instanceof Rect) return 'Rechteck';
  return 'Objekt';
}

export function layerIcon(obj: FabricObject): string {
  switch (kindOf(obj)) {
    case 'text':
      return 'bi-fonts';
    case 'image':
      return 'bi-image';
    case 'group':
      return 'bi-collection';
    case 'line':
      return obj instanceof Path ? 'bi-arrow-right' : 'bi-slash-lg';
    default:
      if (obj instanceof Circle || obj instanceof Ellipse) return 'bi-circle';
      if (obj instanceof Triangle) return 'bi-triangle';
      if (obj instanceof Polygon) return 'bi-star';
      return 'bi-square';
  }
}

function dashOf(obj: FabricObject): StrokeDash {
  const dash = obj.strokeDashArray;
  if (!dash || dash.length === 0) return 'solid';
  return dash[0] <= 1 ? 'dotted' : 'dashed';
}

function dashArray(style: StrokeDash, width: number): number[] | null {
  const w = Math.max(1, width);
  if (style === 'dashed') return [w * 3, w * 2];
  if (style === 'dotted') return [1, w * 2];
  return null;
}

function isBold(weight: unknown) {
  return weight === 'bold' || Number(weight) >= 600;
}

const round = (v: number, digits = 0) => {
  const f = 10 ** digits;
  return Math.round((v ?? 0) * f) / f;
};

/** Style of the current character range while editing, merged over the object defaults. */
function textStyle(obj: FabricText) {
  const text = obj as Textbox;
  if (text.isEditing && text.selectionStart !== text.selectionEnd) {
    return {...obj, ...(text.getSelectionStyles()[0] ?? {})};
  }
  return obj;
}

export function readSelection(obj: FabricObject | undefined | null): SelectionState {
  const state = emptySelection();
  if (!obj) return state;

  const topLeft = obj.getPositionByOrigin('left', 'top');
  Object.assign(state, {
    kind: kindOf(obj),
    count: obj instanceof ActiveSelection ? obj.size() : 1,
    x: round(topLeft.x),
    y: round(topLeft.y),
    width: round(obj.getScaledWidth()),
    height: round(obj.getScaledHeight()),
    angle: round(obj.angle ?? 0),
    opacity: Math.round((obj.opacity ?? 1) * 100),
    fill: typeof obj.fill === 'string' ? obj.fill : null,
    stroke: typeof obj.stroke === 'string' ? obj.stroke : null,
    strokeWidth: obj.stroke ? round(obj.strokeWidth ?? 0, 1) : 0,
    strokeDash: dashOf(obj),
    radius: obj instanceof Rect ? round(obj.rx ?? 0) : 0,
    hasRadius: obj instanceof Rect,
    shadow: !!obj.shadow,
    locked: !!(obj as any).locked,
    flipX: !!obj.flipX,
    flipY: !!obj.flipY,
  });

  if (obj instanceof FabricText) {
    const style = textStyle(obj) as any;
    Object.assign(state, {
      fill: typeof style.fill === 'string' ? style.fill : state.fill,
      fontFamily: style.fontFamily ?? 'Uniform',
      fontSize: round(style.fontSize ?? 24, 1),
      bold: isBold(style.fontWeight),
      italic: style.fontStyle === 'italic',
      underline: !!style.underline,
      linethrough: !!style.linethrough,
      textAlign: obj.textAlign ?? 'left',
      lineHeight: round(obj.lineHeight ?? 1.16, 2),
      charSpacing: round(obj.charSpacing ?? 0),
      textBackgroundColor: style.textBackgroundColor || null,
    });
  }
  return state;
}

const TEXT_RANGE_KEYS = new Set(['fill', 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'underline', 'linethrough', 'textBackgroundColor']);
const GEOMETRY_KEYS = new Set(['x', 'y', 'width', 'height', 'angle']);

function toFabricProps(obj: FabricObject, patch: Partial<SelectionState>): Record<string, unknown> {
  const props: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(patch)) {
    switch (key) {
      case 'opacity':
        props.opacity = Number(value) / 100;
        break;
      case 'radius':
        if (obj instanceof Rect) Object.assign(props, {rx: value, ry: value});
        break;
      case 'shadow':
        props.shadow = value
          ? new Shadow({color: 'rgba(15, 23, 42, 0.35)', blur: 18, offsetX: 0, offsetY: 8})
          : null;
        break;
      case 'strokeWidth': {
        const width = Number(value);
        props.strokeWidth = width;
        if (width > 0 && !obj.stroke) props.stroke = '#0f172a';
        const dash = patch.strokeDash ?? dashOf(obj);
        props.strokeDashArray = dashArray(dash, width);
        break;
      }
      case 'strokeDash':
        props.strokeDashArray = dashArray(value as StrokeDash, Number(patch.strokeWidth ?? obj.strokeWidth));
        break;
      case 'bold':
        props.fontWeight = value ? 'bold' : 'normal';
        break;
      case 'italic':
        props.fontStyle = value ? 'italic' : 'normal';
        break;
      case 'textBackgroundColor':
        props.textBackgroundColor = value || '';
        break;
      case 'fill':
      case 'stroke':
      case 'flipX':
      case 'flipY':
      case 'fontFamily':
      case 'fontSize':
      case 'underline':
      case 'linethrough':
      case 'textAlign':
      case 'lineHeight':
      case 'charSpacing':
        props[key] = value;
        break;
    }
  }
  return props;
}

function applyGeometry(obj: FabricObject, patch: Partial<SelectionState>) {
  if (patch.angle !== undefined) {
    obj.rotate(Number(patch.angle));
  }
  if (patch.width !== undefined && patch.width > 0) {
    if (obj instanceof Textbox) {
      obj.set('width', patch.width / (obj.scaleX || 1));
      obj.initDimensions();
    } else {
      obj.set('scaleX', patch.width / (obj.width || 1));
    }
  }
  if (patch.height !== undefined && patch.height > 0 && !(obj instanceof Textbox)) {
    obj.set('scaleY', patch.height / (obj.height || 1));
  }
  if (patch.x !== undefined || patch.y !== undefined) {
    const current = obj.getPositionByOrigin('left', 'top');
    obj.setPositionByOrigin(new Point(patch.x ?? current.x, patch.y ?? current.y), 'left', 'top');
  }
}

function applyStyle(obj: FabricObject, patch: Partial<SelectionState>) {
  const props = toFabricProps(obj, patch);
  if (!Object.keys(props).length) return;

  if (obj instanceof Textbox && obj.isEditing && obj.selectionStart !== obj.selectionEnd) {
    const rangeProps: Record<string, unknown> = {};
    const objectProps: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(props)) {
      (TEXT_RANGE_KEYS.has(k) ? rangeProps : objectProps)[k] = v;
    }
    if (Object.keys(rangeProps).length) obj.setSelectionStyles(rangeProps);
    obj.set(objectProps);
  } else {
    obj.set(props);
  }

  if (obj instanceof FabricText) {
    obj.initDimensions();
  }
  obj.set('dirty', true);
}

/** Applies inspector changes to the selected object (or each object of a multi-selection). */
export function applyPatch(obj: FabricObject, patch: Partial<SelectionState>) {
  const geometry: Partial<SelectionState> = {};
  const style: Partial<SelectionState> = {};
  for (const [key, value] of Object.entries(patch)) {
    (GEOMETRY_KEYS.has(key) ? geometry : style)[key as keyof SelectionState] = value as never;
  }

  applyGeometry(obj, geometry);

  if (obj instanceof ActiveSelection) {
    obj.getObjects().forEach(child => applyStyle(child, style));
  } else {
    applyStyle(obj, style);
  }
  obj.setCoords();
}

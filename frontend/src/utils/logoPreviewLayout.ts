/**
 * Adaptive logo layout for the QR-Aushang PDF content area.
 * Mirrors backend/resources/views/pdf/content/qr_codes.blade.php
 */

export type AushangLayoutType = 'single' | 'grid' | 'asymmetric'

export type AushangLogoLayout = {
  logoSize: number
  layoutType: AushangLayoutType
  colsPerRow: number
  singleRowMinHeight: number
}

export type AushangCell =
    | {type: 'logo'; url: string; widthPercent: number}
    | {type: 'spacer'; widthPercent: number}

export type AushangRow = {
  cells: AushangCell[]
  minHeight?: number
}

export function getAushangLogoLayout(count: number): AushangLogoLayout {
  const singleRowMinHeight = 210

  if (count <= 0) {
    return {logoSize: 150, layoutType: 'single', colsPerRow: 1, singleRowMinHeight}
  }
  if (count === 1) {
    return {logoSize: 225, layoutType: 'single', colsPerRow: 1, singleRowMinHeight}
  }
  if (count === 2) {
    return {logoSize: 210, layoutType: 'single', colsPerRow: 2, singleRowMinHeight}
  }
  if (count === 3) {
    return {logoSize: 203, layoutType: 'single', colsPerRow: 3, singleRowMinHeight}
  }
  if (count === 4) {
    return {logoSize: 150, layoutType: 'grid', colsPerRow: 2, singleRowMinHeight}
  }
  if (count === 5) {
    return {logoSize: 138, layoutType: 'asymmetric', colsPerRow: 3, singleRowMinHeight}
  }
  if (count === 6) {
    return {logoSize: 132, layoutType: 'grid', colsPerRow: 3, singleRowMinHeight}
  }
  return {logoSize: 150, layoutType: 'grid', colsPerRow: 4, singleRowMinHeight}
}

/** Build table rows matching the Blade adaptive grid. */
export function buildAushangRows(urls: string[]): {layout: AushangLogoLayout; rows: AushangRow[]} {
  const count = urls.length
  const layout = getAushangLogoLayout(count)

  if (count === 0) {
    return {layout, rows: []}
  }

  if (layout.layoutType === 'single') {
    const widthPercent = 100 / count
    return {
      layout,
      rows: [{
        minHeight: layout.singleRowMinHeight,
        cells: urls.map((url) => ({type: 'logo' as const, url, widthPercent})),
      }],
    }
  }

  if (layout.layoutType === 'asymmetric' && count === 5) {
    const top = urls.slice(0, 3)
    const bottom = urls.slice(3)
    return {
      layout,
      rows: [
        {
          cells: top.map((url) => ({type: 'logo' as const, url, widthPercent: 100 / 3})),
        },
        {
          cells: [
            {type: 'spacer', widthPercent: 25},
            ...bottom.map((url) => ({type: 'logo' as const, url, widthPercent: 25})),
            {type: 'spacer', widthPercent: 25},
          ],
        },
      ],
    }
  }

  // grid: 4, 6, or 7+
  const colsPerRow = layout.colsPerRow
  const rows: AushangRow[] = []
  const rowCount = Math.ceil(count / colsPerRow)

  for (let row = 0; row < rowCount; row++) {
    const start = row * colsPerRow
    const rowUrls = urls.slice(start, Math.min(start + colsPerRow, count))
    const colsInThisRow = rowUrls.length
    const isLastRow = row === rowCount - 1
    const needsCentering = isLastRow && colsInThisRow < colsPerRow

    if (needsCentering) {
      const logoWidthPercent = 100 / colsPerRow
      const usedWidth = logoWidthPercent * colsInThisRow
      const spacerWidth = (100 - usedWidth) / 2
      rows.push({
        cells: [
          {type: 'spacer', widthPercent: spacerWidth},
          ...rowUrls.map((url) => ({type: 'logo' as const, url, widthPercent: logoWidthPercent})),
          {type: 'spacer', widthPercent: spacerWidth},
        ],
      })
    } else {
      const logoWidthPercent = 100 / colsInThisRow
      rows.push({
        cells: rowUrls.map((url) => ({type: 'logo' as const, url, widthPercent: logoWidthPercent})),
      })
    }
  }

  return {layout, rows}
}

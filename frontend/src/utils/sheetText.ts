/**
 * Texts on the WISE sheets: a small Markdown subset plus a few plain HTML tags.
 * Everything else is escaped, so admin texts can never inject markup.
 */

const KEEP = new Set(['B', 'STRONG', 'I', 'EM', 'U', 'BR', 'P', 'UL', 'OL', 'LI', 'A'])
const DROP = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'TEMPLATE'])
const BULLET = /^\s*[-*•]\s+/
const NUMBERED = /^\s*\d+[.)]\s+/

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function inline(text: string): string {
  return text
    .replace(/\*\*(?=\S)(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*(?=\S)([^*]+?)\*/g, '$1<em>$2</em>')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2">$1</a>')
}

function list(lines: string[], marker: RegExp, tag: 'ul' | 'ol'): string {
  return `<${tag}>${lines.map((line) => `<li>${inline(line.replace(marker, ''))}</li>`).join('')}</${tag}>`
}

function markdown(raw: string): string {
  const blocks = raw.replace(/\r\n?/g, '\n').trim().split(/\n\s*\n/)
  const html = blocks.map((block) => {
    const lines = block.split('\n')
    if (lines.every((line) => BULLET.test(line))) return list(lines, BULLET, 'ul')
    if (lines.every((line) => NUMBERED.test(line))) return list(lines, NUMBERED, 'ol')
    return lines.map(inline).join('<br>')
  })
  const isList = (block: string) => block.startsWith('<ul>') || block.startsWith('<ol>')
  if (html.length === 1 && !isList(html[0])) return html[0]
  return html.map((block) => (isList(block) ? block : `<p>${block}</p>`)).join('')
}

function sanitize(html: string): string {
  if (typeof DOMParser === 'undefined') return escapeHtml(html.replace(/<[^>]*>/g, ''))
  const root = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html').body.firstElementChild
  if (!root) return ''

  const walk = (node: Node): string => {
    if (node.nodeType === Node.TEXT_NODE) return escapeHtml(node.textContent ?? '')
    if (node.nodeType !== Node.ELEMENT_NODE) return ''
    const el = node as Element
    if (DROP.has(el.tagName)) return ''
    const inner = Array.from(el.childNodes).map(walk).join('')
    if (!KEEP.has(el.tagName)) return inner
    const tag = el.tagName.toLowerCase()
    if (tag === 'br') return '<br>'
    if (tag === 'a') {
      const href = el.getAttribute('href') || ''
      if (!/^https?:\/\//i.test(href)) return inner
      return `<a href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer">${inner}</a>`
    }
    return `<${tag}>${inner}</${tag}>`
  }

  return Array.from(root.childNodes).map(walk).join('')
}

export function sheetHtml(text: string | null | undefined): string {
  const raw = String(text ?? '')
  if (raw.trim() === '') return ''
  return sanitize(markdown(raw))
}

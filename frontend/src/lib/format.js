const numberFormat = new Intl.NumberFormat('en-US')

export const formatNumber = (value) => numberFormat.format(value ?? 0)

export function formatDuration(ms) {
  if (ms === null || ms === undefined) {
    return ''
  }

  return ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`
}

export function truncate(text, length = 80) {
  if (!text) {
    return ''
  }

  return text.length > length ? `${text.slice(0, length)}…` : text
}

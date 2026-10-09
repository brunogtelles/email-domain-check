const join = (values, separator = '; ') => (values || []).filter(Boolean).join(separator)

const COMMON_START = [
  ['Input', (row) => row.input],
  ['Domain', (row) => row.domain],
  ['Check status', (row) => row.status],
]

const COMMON_END = [
  ['Errors', (row) => join([row.error, ...(row.result?.errors || [])])],
  ['Duration (ms)', (row) => row.duration_ms],
]

export const EXPORT_COLUMNS = {
  health: [
    ...COMMON_START,
    ['Blacklist status', (row) => row.result?.blacklist?.status],
    ['Listed on', (row) => join(row.result?.blacklist?.listed_on?.map((l) => `${l.list} (${l.target})`))],
    ['Blacklist IPs checked', (row) => join(row.result?.blacklist?.checked_ips)],
    ['MX status', (row) => row.result?.mx?.status],
    ['MX records', (row) => join(row.result?.mx?.records?.map((r) => `${r.priority} ${r.host}`))],
    ['SPF status', (row) => row.result?.spf?.status],
    ['SPF value', (row) => row.result?.spf?.value],
    ['DMARC status', (row) => row.result?.dmarc?.status],
    ['DMARC policy', (row) => row.result?.dmarc?.policy],
    ['DMARC value', (row) => row.result?.dmarc?.value],
    ['DKIM status', (row) => row.result?.dkim?.status],
    ['DKIM selectors found', (row) => join(row.result?.dkim?.records?.map((r) => r.selector))],
    ['Nameservers', (row) => join(row.result?.ns?.records)],
    ['A', (row) => join(row.result?.records?.a)],
    ['AAAA', (row) => join(row.result?.records?.aaaa)],
    ['CNAME', (row) => join(row.result?.records?.cname)],
    ['PTR', (row) => join(row.result?.records?.ptr?.map((p) => `${p.ip} -> ${p.host || 'none'}`))],
    ['SOA', (row) => row.result?.records?.soa],
    ...COMMON_END,
  ],
  provider: [
    ...COMMON_START,
    ['Provider', (row) => row.result?.provider_label],
    ['Provider detail', (row) => row.result?.provider_name],
    ['Detection status', (row) => (row.result?.status === 'detected' ? 'Active/Detected' : row.result ? 'Not Detected' : '')],
    ['Confidence', (row) => row.result?.confidence],
    ['MX records', (row) => join(row.result?.mx?.map((r) => `${r.priority} ${r.host}`))],
    ['Reason', (row) => row.result?.reason],
    ['Evidence', (row) => join(row.result?.evidence?.map((e) => e.detail))],
    ['SPF', (row) => row.result?.spf],
    ...COMMON_END,
  ],
}

function escapeCell(value) {
  if (value === null || value === undefined) {
    return ''
  }

  let text = String(value)

  // Prevent spreadsheet formula injection from DNS-controlled values.
  if (/^[=+\-@\t\r]/.test(text)) {
    text = `'${text}`
  }

  return /[",\n\r;]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

export function rowsToCsv(rows, columns) {
  const lines = [columns.map(([header]) => escapeCell(header)).join(',')]

  for (const row of rows) {
    lines.push(columns.map(([, value]) => escapeCell(value(row))).join(','))
  }

  return lines.join('\r\n')
}

export function downloadCsv(filename, csv) {
  // The BOM makes Excel open the file as UTF-8.
  const blob = new Blob(['﻿', csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

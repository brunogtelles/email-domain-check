import { Fragment, useEffect, useState } from 'react'
import { formatDuration, formatNumber, truncate } from '../lib/format'
import HealthDetail from './HealthDetail'
import ProviderDetail from './ProviderDetail'
import { BlacklistBadge, DmarcBadge, PresenceBadge, ProviderBadge, RowStatusBadge } from './StatusBadges'
import { Button } from './ui'

const PAGE_SIZE = 100

const COLUMNS = {
  health: [
    { header: 'Blacklist', cell: (r) => (
      <div className="space-y-0.5">
        <BlacklistBadge blacklist={r.blacklist} />
        {r.blacklist.listed_on.length > 0 && <div className="text-xs text-red-700">{[...new Set(r.blacklist.listed_on.map((l) => l.list))].join(', ')}</div>}
      </div>
    ) },
    { header: 'MX', cell: (r) => (
      <div className="space-y-0.5">
        <PresenceBadge status={r.mx.status} label={r.mx.status === 'found' ? `${r.mx.records.length} found` : undefined} />
        {r.mx.records[0] && <div className="text-xs text-slate-500">{truncate(r.mx.records[0].host, 32)}</div>}
      </div>
    ) },
    { header: 'SPF', cell: (r) => <PresenceBadge status={r.spf.status} label={r.spf.status === 'found' && r.spf.all ? r.spf.all.split(' ')[0] : undefined} /> },
    { header: 'DMARC', cell: (r) => <DmarcBadge dmarc={r.dmarc} /> },
    { header: 'DKIM', cell: (r) => (
      <div className="space-y-0.5">
        <PresenceBadge status={r.dkim.status} />
        {r.dkim.records.length > 0 && <div className="text-xs text-slate-500">{r.dkim.records.map((d) => d.selector).join(', ')}</div>}
      </div>
    ) },
    { header: 'Nameservers', cell: (r) => <span className="text-xs text-slate-600">{r.ns.records.length ? truncate(r.ns.records.join(', '), 40) : '—'}</span> },
  ],
  provider: [
    { header: 'Provider', cell: (r) => (
      <div className="space-y-0.5">
        <ProviderBadge result={r} />
        {r.provider_name && r.provider_name !== r.provider_label && <div className="text-xs text-slate-500">{r.provider_name}</div>}
      </div>
    ) },
    { header: 'MX records', cell: (r) => <span className="text-xs text-slate-600">{r.mx.length ? truncate(r.mx.map((m) => m.host).join(', '), 48) : '—'}</span> },
    { header: 'Evidence / reason', cell: (r) => <span className="text-xs text-slate-600">{truncate(r.reason, 110)}</span> },
    { header: 'Status', cell: (r) => <span className="text-xs">{r.status === 'detected' ? 'Active / Detected' : 'Not Detected'}</span> },
  ],
}

export default function ResultsTable({ type, rows, totalRows }) {
  const [expanded, setExpanded] = useState(() => new Set())
  const [limit, setLimit] = useState(PAGE_SIZE)
  const columns = COLUMNS[type]

  // Auto-open the newest single check so its result is visible immediately.
  const newestSingle = rows.find((row) => row.source === 'single')
  const newestKey = newestSingle?.result ? newestSingle.key : null

  useEffect(() => {
    if (newestKey) {
      setExpanded((current) => new Set(current).add(newestKey))
    }
  }, [newestKey])

  const toggle = (key) => {
    setExpanded((current) => {
      const next = new Set(current)
      next.has(key) ? next.delete(key) : next.add(key)
      return next
    })
  }

  if (totalRows === 0) {
    return <p className="px-4 py-10 text-center text-sm text-slate-500">No results yet. Run a single check or upload a file.</p>
  }

  if (rows.length === 0) {
    return <p className="px-4 py-10 text-center text-sm text-slate-500">No results match this filter.</p>
  }

  const visible = rows.slice(0, limit)
  const colSpan = columns.length + 3

  return (
    <div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[860px] text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-4 py-2 font-medium">Domain</th>
              <th className="px-3 py-2 font-medium">Check</th>
              {columns.map((column) => (
                <th key={column.header} className="px-3 py-2 font-medium">{column.header}</th>
              ))}
              <th className="px-3 py-2 text-right font-medium">Time</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {visible.map((row) => {
              const isOpen = expanded.has(row.key) && row.result
              const canOpen = Boolean(row.result)

              return (
                <Fragment key={row.key}>
                  <tr
                    onClick={canOpen ? () => toggle(row.key) : undefined}
                    className={`align-top ${canOpen ? 'cursor-pointer hover:bg-slate-50' : ''} ${isOpen ? 'bg-slate-50' : ''}`}
                  >
                    <td className="px-4 py-2">
                      <div className="flex items-start gap-2">
                        <span className={`mt-0.5 text-xs text-slate-400 transition ${isOpen ? 'rotate-90' : ''} ${canOpen ? '' : 'invisible'}`}>▶</span>
                        <div>
                          <div className="font-medium text-slate-900">{row.domain || row.input}</div>
                          {row.domain && row.input !== row.domain && <div className="text-xs text-slate-500">{truncate(row.input, 40)}</div>}
                        </div>
                      </div>
                    </td>
                    <td className="px-3 py-2">
                      <RowStatusBadge status={row.status} error={row.error} />
                      {row.error && <div className="mt-0.5 max-w-48 text-xs text-red-600">{row.error}</div>}
                    </td>
                    {columns.map((column) => (
                      <td key={column.header} className="px-3 py-2">{row.result ? column.cell(row.result) : null}</td>
                    ))}
                    <td className="px-3 py-2 text-right text-xs text-slate-500">{formatDuration(row.duration_ms)}</td>
                  </tr>
                  {isOpen && (
                    <tr className="bg-slate-50">
                      <td colSpan={colSpan} className="px-4 pb-4 pt-1">
                        {type === 'health' ? <HealthDetail result={row.result} /> : <ProviderDetail result={row.result} />}
                      </td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>

      {rows.length > limit && (
        <div className="flex items-center justify-center gap-3 border-t border-slate-100 py-3 text-sm text-slate-500">
          Showing {formatNumber(limit)} of {formatNumber(rows.length)}
          <Button variant="secondary" onClick={() => setLimit((current) => current + PAGE_SIZE * 5)}>
            Show more
          </Button>
        </div>
      )}
    </div>
  )
}

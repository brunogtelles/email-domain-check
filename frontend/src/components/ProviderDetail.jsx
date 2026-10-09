import { Badge, Mono } from './ui'
import { ProviderBadge } from './StatusBadges'

const SIGNAL_LABELS = { mx: 'MX', spf: 'SPF', txt: 'TXT', dkim: 'DKIM', autodiscover: 'Autodiscover' }
const PROVIDER_LABELS = { google: 'Google', microsoft: 'Microsoft' }

export default function ProviderDetail({ result }) {
  return (
    <div className="grid gap-3 md:grid-cols-2">
      <div className="space-y-2 rounded-lg border border-slate-200 bg-white p-3 text-sm">
        <div className="flex flex-wrap items-center gap-2">
          <ProviderBadge result={result} />
          {result.provider_name && result.provider_name !== result.provider_label && <span className="font-medium">{result.provider_name}</span>}
          <Badge tone={result.status === 'detected' ? 'green' : 'gray'}>{result.status === 'detected' ? 'Active / Detected' : 'Not Detected'}</Badge>
          {result.confidence && <span className="text-xs text-slate-500">{result.confidence} confidence</span>}
        </div>
        <p className="text-slate-700">{result.reason}</p>

        <h3 className="pt-1 text-xs font-semibold uppercase tracking-wide text-slate-500">MX records</h3>
        {result.mx.length ? (
          <ul className="space-y-0.5">
            {result.mx.map((record) => (
              <li key={`${record.priority}-${record.host}`} className="text-xs">
                <Mono>
                  {record.priority} {record.host}
                </Mono>
                {record.name && <span className="text-slate-500"> · {record.name}</span>}
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-xs text-slate-400">No MX records</p>
        )}

        {result.spf && (
          <>
            <h3 className="pt-1 text-xs font-semibold uppercase tracking-wide text-slate-500">SPF</h3>
            <Mono>{result.spf}</Mono>
          </>
        )}
      </div>

      <div className="rounded-lg border border-slate-200 bg-white p-3">
        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Detection evidence</h3>
        {result.evidence.length ? (
          <ul className="space-y-1">
            {result.evidence.map((item, index) => (
              <li key={index} className="flex items-start gap-2 text-xs">
                <Badge tone={item.provider === 'google' ? 'blue' : 'violet'}>{PROVIDER_LABELS[item.provider] || item.provider}</Badge>
                <span className="font-medium text-slate-500">{SIGNAL_LABELS[item.signal] || item.signal}</span>
                <span className="break-all text-slate-700">{item.detail}</span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-xs text-slate-400">No Google or Microsoft indicators found.</p>
        )}

        {result.errors.length > 0 && (
          <>
            <h3 className="mb-1 mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Errors / timeouts</h3>
            <ul className="list-inside list-disc text-xs text-amber-700">
              {result.errors.map((error) => <li key={error}>{error}</li>)}
            </ul>
          </>
        )}
      </div>
    </div>
  )
}

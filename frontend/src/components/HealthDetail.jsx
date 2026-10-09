import { Badge, Mono } from './ui'
import { BlacklistBadge, DmarcBadge, PresenceBadge } from './StatusBadges'

function Block({ title, badge, children }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-3">
      <div className="mb-2 flex items-center justify-between gap-2">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">{title}</h3>
        {badge}
      </div>
      <div className="space-y-1.5 text-sm">{children}</div>
    </div>
  )
}

function Warnings({ items }) {
  if (!items?.length) {
    return null
  }

  return (
    <ul className="list-inside list-disc text-xs text-amber-700">
      {items.map((warning) => (
        <li key={warning}>{warning}</li>
      ))}
    </ul>
  )
}

function Empty({ children = 'None' }) {
  return <p className="text-xs text-slate-400">{children}</p>
}

const LIST_STATUS_TONE = { listed: 'red', clean: 'green', error: 'amber' }

export default function HealthDetail({ result }) {
  const { blacklist, mx, spf, dmarc, dkim, ns, records, errors } = result

  return (
    <div className="space-y-3">
      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <Block title="Blacklists" badge={<BlacklistBadge blacklist={blacklist} />}>
          {blacklist.listed_on.length > 0 && (
            <ul className="space-y-1">
              {blacklist.listed_on.map((listing) => (
                <li key={`${listing.list}-${listing.target}`} className="text-xs">
                  <span className="font-medium text-red-700">{listing.list}</span> · {listing.target} · {listing.reason}
                  {listing.details && <div className="text-slate-500">{listing.details}</div>}
                </li>
              ))}
            </ul>
          )}
          <div className="flex flex-wrap gap-1">
            {blacklist.lists.map((list) => (
              <Badge
                key={list.zone}
                tone={LIST_STATUS_TONE[list.status]}
                title={list.targets.map((t) => `${t.target}: ${t.status}${t.error ? ` - ${t.error}` : ''}`).join('\n')}
              >
                {list.name}
              </Badge>
            ))}
          </div>
          <p className="text-xs text-slate-500">IPs checked: {blacklist.checked_ips.join(', ') || 'none'}</p>
        </Block>

        <Block title="MX" badge={<PresenceBadge status={mx.status} />}>
          {mx.records.length ? (
            mx.records.map((record) => (
              <div key={`${record.priority}-${record.host}`} className="text-xs">
                <Mono>
                  {record.priority} {record.host}
                </Mono>
                {record.ips.length > 0 && <span className="text-slate-500"> → {record.ips.join(', ')}</span>}
              </div>
            ))
          ) : (
            <Empty>{mx.error || 'No MX records'}</Empty>
          )}
        </Block>

        <Block title="SPF" badge={<PresenceBadge status={spf.status} />}>
          {spf.value ? <Mono>{spf.value}</Mono> : <Empty>{spf.error || 'No SPF record'}</Empty>}
          {spf.all && <p className="text-xs text-slate-500">Policy: {spf.all} · {spf.lookups} DNS lookups</p>}
          <Warnings items={spf.warnings} />
        </Block>

        <Block title="DMARC" badge={<DmarcBadge dmarc={dmarc} />}>
          {dmarc.value ? <Mono>{dmarc.value}</Mono> : <Empty>{dmarc.error || 'No DMARC record'}</Empty>}
          <Warnings items={dmarc.warnings} />
        </Block>

        <Block title="DKIM" badge={<PresenceBadge status={dkim.status} />}>
          {dkim.records.length ? (
            dkim.records.map((record) => (
              <div key={record.selector} className="text-xs">
                <span className="font-medium">{record.selector}</span>
                <span className="text-slate-500"> · {record.key_type}{record.revoked && ' · revoked'}</span>
                <Mono className="mt-0.5 block text-slate-500">{record.value.slice(0, 120)}{record.value.length > 120 && '…'}</Mono>
              </div>
            ))
          ) : (
            <Empty>
              No key found for {dkim.explicit_selectors.length ? dkim.explicit_selectors.join(', ') : `${dkim.selectors_checked.length} common selectors`}
            </Empty>
          )}
        </Block>

        <Block title="Nameservers" badge={<PresenceBadge status={ns.status} />}>
          {ns.records.length ? ns.records.map((server) => <Mono key={server} className="block">{server}</Mono>) : <Empty />}
        </Block>
      </div>

      <Block title="Other DNS records">
        <dl className="grid gap-x-4 gap-y-1 text-xs sm:grid-cols-[6rem_1fr]">
          <dt className="font-medium text-slate-500">A</dt>
          <dd><Mono>{records.a.join(', ') || '—'}</Mono></dd>
          <dt className="font-medium text-slate-500">AAAA</dt>
          <dd><Mono>{records.aaaa.join(', ') || '—'}</Mono></dd>
          <dt className="font-medium text-slate-500">CNAME</dt>
          <dd><Mono>{records.cname.join(', ') || '—'}</Mono></dd>
          <dt className="font-medium text-slate-500">PTR</dt>
          <dd>
            <Mono>{records.ptr.map((ptr) => `${ptr.ip} → ${ptr.host || 'none'}`).join(', ') || '—'}</Mono>
          </dd>
          <dt className="font-medium text-slate-500">SOA</dt>
          <dd><Mono>{records.soa || '—'}</Mono></dd>
          <dt className="font-medium text-slate-500">TXT</dt>
          <dd className="space-y-0.5">{records.txt.length ? records.txt.map((txt) => <Mono key={txt} className="block">{txt}</Mono>) : '—'}</dd>
        </dl>
      </Block>

      {result.all_records && (
        <Block title={`All DNS records (${result.all_records.length})`}>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="text-slate-500">
                <tr>
                  <th className="py-1 pr-3 font-medium">Name</th>
                  <th className="py-1 pr-3 font-medium">Type</th>
                  <th className="py-1 pr-3 font-medium">TTL</th>
                  <th className="py-1 font-medium">Value</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {result.all_records.map((set) => (
                  <tr key={`${set.name}-${set.type}`} className="align-top">
                    <td className="py-1 pr-3"><Mono>{set.name}</Mono></td>
                    <td className="py-1 pr-3 font-medium">{set.type}</td>
                    <td className="py-1 pr-3 text-slate-500">{set.ttl}</td>
                    <td className="py-1">
                      {set.cname.length > 0 && <div className="text-slate-500">CNAME → {set.cname.join(' → ')}</div>}
                      {set.values.map((value) => <Mono key={value} className="block">{value}</Mono>)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Block>
      )}

      {errors.length > 0 && (
        <Block title="Errors / timeouts">
          <ul className="list-inside list-disc text-xs text-amber-700">
            {errors.map((error) => <li key={error}>{error}</li>)}
          </ul>
        </Block>
      )}
    </div>
  )
}

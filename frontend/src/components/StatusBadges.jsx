import { Badge, Spinner } from './ui'

const ROW_STATUS = {
  queued: { tone: 'gray', label: 'Queued' },
  checking: { tone: 'blue', label: 'Checking' },
  completed: { tone: 'green', label: 'Completed' },
  failed: { tone: 'red', label: 'Failed' },
}

export function RowStatusBadge({ status, error }) {
  const { tone, label } = ROW_STATUS[status] || ROW_STATUS.queued

  return (
    <Badge tone={tone} title={error || undefined}>
      {status === 'checking' && <Spinner className="size-2.5" />}
      {label}
    </Badge>
  )
}

export function BlacklistBadge({ blacklist }) {
  if (!blacklist) {
    return null
  }

  if (blacklist.status === 'listed') {
    return <Badge tone="red">Listed ({blacklist.listed_on.length})</Badge>
  }

  if (blacklist.status === 'unknown') {
    return <Badge tone="amber" title="No blacklist could be queried">Unknown</Badge>
  }

  return (
    <Badge tone="green" title={blacklist.error_count ? `${blacklist.error_count} queries failed or were refused` : undefined}>
      Clean{blacklist.error_count > 0 && <span className="text-amber-700">*</span>}
    </Badge>
  )
}

const PRESENCE = {
  found: { tone: 'green', label: 'Found' },
  missing: { tone: 'red', label: 'Missing' },
  not_found: { tone: 'gray', label: 'Not found' },
  null_mx: { tone: 'gray', label: 'Null MX' },
  multiple: { tone: 'amber', label: 'Multiple' },
  invalid: { tone: 'amber', label: 'Invalid' },
  error: { tone: 'amber', label: 'Error' },
}

export function PresenceBadge({ status, label }) {
  if (!status) {
    return null
  }

  const preset = PRESENCE[status] || { tone: 'gray', label: status }

  return <Badge tone={preset.tone}>{label || preset.label}</Badge>
}

export function DmarcBadge({ dmarc }) {
  if (!dmarc) {
    return null
  }

  if (dmarc.status === 'found') {
    return <Badge tone={dmarc.policy === 'none' ? 'amber' : 'green'}>p={dmarc.policy}</Badge>
  }

  return <PresenceBadge status={dmarc.status} />
}

const PROVIDERS = {
  google: 'blue',
  microsoft: 'violet',
  other: 'amber',
  none: 'gray',
}

export function ProviderBadge({ result }) {
  if (!result) {
    return null
  }

  return <Badge tone={PROVIDERS[result.provider]}>{result.provider_label}</Badge>
}

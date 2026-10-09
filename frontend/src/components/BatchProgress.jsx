import { formatNumber } from '../lib/format'
import { Badge, Button, Spinner } from './ui'

const BATCH_STATUS = {
  processing: { tone: 'blue', label: 'Processing' },
  completed: { tone: 'green', label: 'Completed' },
  cancelled: { tone: 'gray', label: 'Cancelled' },
}

export default function BatchProgress({ batch, onCancel }) {
  const { counts, total, processed } = batch
  const percent = total > 0 ? Math.round((processed / total) * 100) : 100
  const status = BATCH_STATUS[batch.status] || BATCH_STATUS.processing
  const completedWidth = total > 0 ? (counts.completed / total) * 100 : 0
  const failedWidth = total > 0 ? (counts.failed / total) * 100 : 0

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <Badge tone={status.tone}>
            {batch.status === 'processing' && <Spinner className="size-2.5" />}
            {status.label}
          </Badge>
          <span className="text-sm font-medium text-slate-900">
            {formatNumber(processed)} / {formatNumber(total)} checked
          </span>
          <span className="text-sm text-slate-500">({percent}%)</span>
          {batch.filename && <span className="hidden text-sm text-slate-400 sm:inline">· {batch.filename}</span>}
        </div>

        {batch.status === 'processing' && (
          <Button variant="danger" onClick={onCancel}>
            Cancel
          </Button>
        )}
      </div>

      <div className="mt-3 flex h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100}>
        <div className="bg-emerald-500 transition-all" style={{ width: `${completedWidth}%` }} />
        <div className="bg-red-400 transition-all" style={{ width: `${failedWidth}%` }} />
      </div>

      <div className="mt-2 flex flex-wrap gap-4 text-xs text-slate-600">
        <span>Queued {formatNumber(counts.queued)}</span>
        <span>Checking {formatNumber(counts.checking)}</span>
        <span className="text-emerald-700">Completed {formatNumber(counts.completed)}</span>
        <span className="text-red-600">Failed {formatNumber(counts.failed)}</span>
      </div>
    </div>
  )
}

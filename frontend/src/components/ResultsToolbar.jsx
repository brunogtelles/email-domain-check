import { formatNumber } from '../lib/format'
import { Button, TextInput } from './ui'

export default function ResultsToolbar({ filters, counts, filter, onFilterChange, search, onSearchChange, exportCount, onExport, onClear }) {
  return (
    <div className="flex flex-col gap-3 border-b border-slate-200 p-4 lg:flex-row lg:items-center lg:justify-between">
      <div className="flex flex-wrap gap-1.5" role="tablist" aria-label="Filter results">
        {filters.map((option) => (
          <button
            key={option.id}
            type="button"
            role="tab"
            aria-selected={filter === option.id}
            onClick={() => onFilterChange(option.id)}
            className={`rounded-full px-3 py-1 text-xs font-medium transition ${
              filter === option.id ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
            }`}
          >
            {option.label}
            <span className={`ml-1.5 ${filter === option.id ? 'text-slate-300' : 'text-slate-400'}`}>{formatNumber(counts[option.id])}</span>
          </button>
        ))}
      </div>

      <div className="flex items-center gap-2">
        <TextInput value={search} onChange={(event) => onSearchChange(event.target.value)} placeholder="Search domain…" className="w-44" aria-label="Search domain" />
        <Button variant="secondary" onClick={onExport} disabled={exportCount === 0} title="Export finished results in the current filter">
          Export CSV ({formatNumber(exportCount)})
        </Button>
        <Button variant="secondary" onClick={onClear}>
          Clear
        </Button>
      </div>
    </div>
  )
}

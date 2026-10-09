import { useCallback, useMemo, useState } from 'react'
import { cancelBatch, createBatch, runSingleCheck } from '../api/checks'
import { errorMessage } from '../api/client'
import { useBatchPolling } from '../hooks/useBatchPolling'
import { useResults } from '../hooks/useResults'
import { downloadCsv, EXPORT_COLUMNS, rowsToCsv } from '../lib/csv'
import { FILTERS, matchesSearch } from '../lib/filters'
import BatchProgress from './BatchProgress'
import BulkUpload from './BulkUpload'
import ResultsTable from './ResultsTable'
import ResultsToolbar from './ResultsToolbar'
import SingleCheckForm from './SingleCheckForm'

/**
 * One tool page (health or provider): single check, bulk upload, live results.
 */
export default function CheckerTool({ type }) {
  const results = useResults()
  const [batch, setBatch] = useState(null)
  const [filter, setFilter] = useState('all')
  const [search, setSearch] = useState('')
  const filters = FILTERS[type]

  const { upsertBatchItems } = results
  const handlePage = useCallback(
    (page) => {
      setBatch(page.data)
      upsertBatchItems(page.data.id, page.items)
    },
    [upsertBatchItems],
  )

  useBatchPolling(batch?.id, handlePage)

  const handleSingle = async (values) => {
    const key = results.addSingle(values.input)

    try {
      results.updateSingle(key, await runSingleCheck(type, values))
    } catch (error) {
      const message = errorMessage(error)

      // Invalid input is shown on the form only; other errors (network, 429...) stay in the table.
      if (error.response?.status === 422) {
        results.remove(key)
        throw new Error(message)
      }

      results.updateSingle(key, { input: values.input, status: 'failed', error: message, result: null })
    }
  }

  const handleUpload = async (file, options) => {
    try {
      const response = await createBatch(type, file, options)
      setBatch(response.data)
      return response
    } catch (error) {
      throw new Error(errorMessage(error))
    }
  }

  const handleCancel = async () => {
    try {
      setBatch(await cancelBatch(batch.id))
    } catch {
      // The next poll shows the real state.
    }
  }

  const counts = useMemo(
    () => Object.fromEntries(filters.map((option) => [option.id, results.rows.filter(option.test).length])),
    [filters, results.rows],
  )

  const visibleRows = useMemo(() => {
    const active = filters.find((option) => option.id === filter) || filters[0]
    return results.rows.filter((row) => active.test(row) && matchesSearch(row, search))
  }, [filters, filter, search, results.rows])

  const exportable = visibleRows.filter((row) => row.status === 'completed' || row.status === 'failed')

  const handleExport = () => {
    const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')
    downloadCsv(`${type === 'health' ? 'domain-health' : 'mail-provider'}-${filter}-${stamp}.csv`, rowsToCsv(exportable, EXPORT_COLUMNS[type]))
  }

  const handleClear = () => {
    results.clear()
    setBatch(null)
    setFilter('all')
  }

  return (
    <div className="space-y-6">
      <div className="grid gap-6 lg:grid-cols-2">
        <SingleCheckForm type={type} onSubmit={handleSingle} />
        <BulkUpload type={type} onUpload={handleUpload} />
      </div>

      {batch && <BatchProgress batch={batch} onCancel={handleCancel} />}

      <section className="rounded-xl border border-slate-200 bg-white shadow-sm">
        <ResultsToolbar
          filters={filters}
          counts={counts}
          filter={filter}
          onFilterChange={setFilter}
          search={search}
          onSearchChange={setSearch}
          exportCount={exportable.length}
          onExport={handleExport}
          onClear={handleClear}
        />
        <ResultsTable type={type} rows={visibleRows} totalRows={results.rows.length} />
      </section>
    </div>
  )
}

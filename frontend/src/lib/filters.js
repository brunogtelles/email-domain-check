const isPending = (row) => row.status === 'queued' || row.status === 'checking'
const isCompleted = (row) => row.status === 'completed'

export const FILTERS = {
  health: [
    { id: 'all', label: 'All', test: () => true },
    { id: 'clean', label: 'Clean', test: (row) => isCompleted(row) && row.result?.blacklist?.status === 'clean' },
    { id: 'listed', label: 'Blacklisted', test: (row) => isCompleted(row) && row.result?.blacklist?.status === 'listed' },
    { id: 'no-mx', label: 'No MX', test: (row) => isCompleted(row) && row.result?.mx?.status !== 'found' },
    { id: 'no-dmarc', label: 'No DMARC', test: (row) => isCompleted(row) && row.result?.dmarc?.status === 'missing' },
    { id: 'failed', label: 'Failed', test: (row) => row.status === 'failed' },
    { id: 'pending', label: 'In progress', test: isPending },
  ],
  provider: [
    { id: 'all', label: 'All', test: () => true },
    { id: 'google', label: 'Google', test: (row) => isCompleted(row) && row.result?.provider === 'google' },
    { id: 'microsoft', label: 'Microsoft', test: (row) => isCompleted(row) && row.result?.provider === 'microsoft' },
    { id: 'other', label: 'Other', test: (row) => isCompleted(row) && row.result?.provider === 'other' },
    { id: 'none', label: 'Not Detected', test: (row) => isCompleted(row) && row.result?.provider === 'none' },
    { id: 'failed', label: 'Failed', test: (row) => row.status === 'failed' },
    { id: 'pending', label: 'In progress', test: isPending },
  ],
}

export function matchesSearch(row, search) {
  if (!search) {
    return true
  }

  const needle = search.trim().toLowerCase()

  return (row.domain || '').includes(needle) || (row.input || '').toLowerCase().includes(needle)
}

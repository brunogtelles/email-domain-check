import { api } from './client'

/**
 * Run a single check synchronously. Returns an item: { input, domain, status, error, result, duration_ms }.
 */
export async function runSingleCheck(type, { input, selector, allRecords }) {
  const { data } = await api.post(`/checks/${type}`, {
    input,
    selector: selector || null,
    all_records: Boolean(allRecords),
  })

  return data.data
}

/**
 * Upload a CSV/TXT file. Returns { data: batch, meta: { duplicates_removed } }.
 */
export async function createBatch(type, file, { selector, allRecords }) {
  const form = new FormData()
  form.append('type', type)
  form.append('file', file)
  form.append('all_records', allRecords ? '1' : '0')

  if (selector) {
    form.append('selector', selector)
  }

  const { data } = await api.post('/batches', form)

  return data
}

/**
 * Fetch the batch summary plus every item that changed after the cursor.
 * Returns { data: batch, items: [...], cursor, has_more }.
 */
export async function pollBatch(batchId, after) {
  const { data } = await api.get(`/batches/${batchId}`, { params: { after } })

  return data
}

export async function cancelBatch(batchId) {
  const { data } = await api.post(`/batches/${batchId}/cancel`)

  return data.data
}

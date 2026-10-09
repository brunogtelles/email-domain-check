import { useEffect, useRef } from 'react'
import { pollBatch } from '../api/checks'

const POLL_INTERVAL_MS = 1000
const MAX_BACKOFF_MS = 15000

/**
 * Poll a bulk batch until it is finished, calling onPage with every response.
 *
 * Each request only asks for items that changed after the last cursor, so rows
 * update as soon as each domain finishes without re-downloading the whole list.
 * Pages are fetched back to back while the server reports has_more.
 */
export function useBatchPolling(batchId, onPage) {
  const onPageRef = useRef(onPage)

  useEffect(() => {
    onPageRef.current = onPage
  })

  useEffect(() => {
    if (!batchId) {
      return undefined
    }

    let stopped = false
    let timer = null
    let cursor = 0
    let failures = 0

    const tick = async () => {
      try {
        const page = await pollBatch(batchId, cursor)

        if (stopped) {
          return
        }

        cursor = page.cursor
        failures = 0
        onPageRef.current(page)

        const { status, counts } = page.data
        const finished = status !== 'processing' && counts.queued === 0 && counts.checking === 0

        if (page.has_more) {
          timer = setTimeout(tick, 0)
        } else if (!finished) {
          timer = setTimeout(tick, POLL_INTERVAL_MS)
        }
      } catch (error) {
        if (stopped) {
          return
        }

        failures += 1
        const retryAfter = Number(error.response?.headers?.['retry-after'])
        const delay = retryAfter > 0 ? retryAfter * 1000 : Math.min(POLL_INTERVAL_MS * 2 ** failures, MAX_BACKOFF_MS)

        timer = setTimeout(tick, delay)
      }
    }

    tick()

    return () => {
      stopped = true
      clearTimeout(timer)
    }
  }, [batchId])
}

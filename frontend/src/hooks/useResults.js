import { useCallback, useMemo, useReducer, useRef } from 'react'

const initialState = { order: [], rows: {} }

function reducer(state, action) {
  switch (action.type) {
    case 'upsert': {
      const rows = { ...state.rows }
      const appended = []

      for (const row of action.rows) {
        const existing = rows[row.key]

        if (!existing) {
          appended.push(row.key)
          rows[row.key] = row
        } else if ((row.seq ?? 0) >= (existing.seq ?? 0)) {
          // Never let an older poll response overwrite a newer state.
          rows[row.key] = { ...existing, ...row }
        }
      }

      const order = action.prepend ? [...appended, ...state.order] : [...state.order, ...appended]

      return { order, rows }
    }

    case 'remove': {
      const { [action.key]: _removed, ...rows } = state.rows

      return { order: state.order.filter((key) => key !== action.key), rows }
    }

    case 'clear':
      return initialState

    default:
      return state
  }
}

/**
 * Results shown in the table: single checks and bulk items, keyed and ordered.
 */
export function useResults() {
  const [state, dispatch] = useReducer(reducer, initialState)
  const singleCounter = useRef(0)

  const addSingle = useCallback((input) => {
    singleCounter.current += 1
    const key = `single-${singleCounter.current}`

    dispatch({
      type: 'upsert',
      prepend: true,
      rows: [{ key, source: 'single', input, domain: null, status: 'checking', error: null, result: null, seq: 0 }],
    })

    return key
  }, [])

  const updateSingle = useCallback((key, item) => {
    dispatch({ type: 'upsert', rows: [{ ...item, key, source: 'single', seq: 1 }] })
  }, [])

  const upsertBatchItems = useCallback((batchId, items) => {
    if (items.length === 0) {
      return
    }

    dispatch({
      type: 'upsert',
      rows: items.map((item) => ({ ...item, key: `batch-${batchId}-${item.id}`, source: 'bulk', batchId })),
    })
  }, [])

  const remove = useCallback((key) => dispatch({ type: 'remove', key }), [])

  const clear = useCallback(() => dispatch({ type: 'clear' }), [])

  const rows = useMemo(() => state.order.map((key) => state.rows[key]), [state])

  return {
    rows,
    addSingle,
    updateSingle,
    upsertBatchItems,
    remove,
    clear,
  }
}

import { useState } from 'react'
import { Button, Card, Checkbox, ErrorText, Spinner, TextInput } from './ui'

/**
 * One domain or e-mail address, checked immediately.
 */
export default function SingleCheckForm({ type, onSubmit }) {
  const [input, setInput] = useState('')
  const [selector, setSelector] = useState('')
  const [allRecords, setAllRecords] = useState(false)
  const [running, setRunning] = useState(0)
  const [error, setError] = useState(null)

  const handleSubmit = async (event) => {
    event.preventDefault()

    if (!input.trim()) {
      setError('Enter a domain or e-mail address.')
      return
    }

    setError(null)
    setRunning((count) => count + 1)

    try {
      await onSubmit({ input: input.trim(), selector: selector.trim(), allRecords })
      setInput('')
    } catch (submitError) {
      setError(submitError.message)
    } finally {
      setRunning((count) => count - 1)
    }
  }

  return (
    <Card title="Single check" subtitle="Enter a domain or e-mail address. The domain is extracted from e-mails automatically.">
      <form onSubmit={handleSubmit} className="space-y-3">
        <div className="flex gap-2">
          <TextInput
            value={input}
            onChange={(event) => setInput(event.target.value)}
            placeholder="example.com or john@example.com"
            aria-label="Domain or e-mail"
            autoComplete="off"
          />
          <Button type="submit" className="shrink-0">
            {running > 0 && <Spinner />}
            Check
          </Button>
        </div>

        {type === 'health' && (
          <div className="flex flex-wrap items-center gap-4">
            <TextInput
              value={selector}
              onChange={(event) => setSelector(event.target.value)}
              placeholder="DKIM selector (optional)"
              aria-label="DKIM selector"
              className="max-w-56"
            />
            <Checkbox label="Show all DNS records" checked={allRecords} onChange={setAllRecords} />
          </div>
        )}

        <ErrorText>{error}</ErrorText>
      </form>
    </Card>
  )
}

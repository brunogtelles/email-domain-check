import { useRef, useState } from 'react'
import { Button, Card, Checkbox, ErrorText, Spinner, TextInput } from './ui'

/**
 * CSV / TXT upload. The file is queued on the server and processed in the background.
 */
export default function BulkUpload({ type, onUpload }) {
  const fileInput = useRef(null)
  const [file, setFile] = useState(null)
  const [dragging, setDragging] = useState(false)
  const [selector, setSelector] = useState('')
  const [allRecords, setAllRecords] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const pickFile = (picked) => {
    setError(null)
    setNotice(null)

    if (picked && !/\.(csv|txt)$/i.test(picked.name)) {
      setError('Only .csv and .txt files are supported.')
      return
    }

    setFile(picked || null)
  }

  const handleUpload = async () => {
    if (!file) {
      setError('Choose a CSV or TXT file first.')
      return
    }

    setUploading(true)
    setError(null)

    try {
      const { data: batch, meta } = await onUpload(file, { selector: selector.trim(), allRecords })
      const duplicates = meta?.duplicates_removed ? ` (${meta.duplicates_removed} duplicates removed)` : ''

      setNotice(`${batch.total} domains queued${duplicates}.`)
      setFile(null)
      fileInput.current.value = ''
    } catch (uploadError) {
      setError(uploadError.message)
    } finally {
      setUploading(false)
    }
  }

  return (
    <Card title="Bulk check" subtitle="Upload a CSV or TXT with one domain or e-mail per line (a 'domain' or 'email' header column is detected).">
      <div className="space-y-3">
        <label
          onDragOver={(event) => {
            event.preventDefault()
            setDragging(true)
          }}
          onDragLeave={() => setDragging(false)}
          onDrop={(event) => {
            event.preventDefault()
            setDragging(false)
            pickFile(event.dataTransfer.files[0])
          }}
          className={`flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed px-4 py-5 text-center text-sm transition ${
            dragging ? 'border-slate-500 bg-slate-50' : 'border-slate-300 hover:border-slate-400'
          }`}
        >
          <input ref={fileInput} type="file" accept=".csv,.txt,text/csv,text/plain" className="sr-only" onChange={(event) => pickFile(event.target.files[0])} />
          {file ? (
            <span className="font-medium text-slate-800">{file.name}</span>
          ) : (
            <span className="text-slate-500">
              <span className="font-medium text-slate-800">Choose a file</span> or drag it here
            </span>
          )}
        </label>

        <div className="flex flex-wrap items-center justify-between gap-3">
          {type === 'health' ? (
            <div className="flex flex-wrap items-center gap-4">
              <TextInput value={selector} onChange={(event) => setSelector(event.target.value)} placeholder="Default DKIM selector" aria-label="Default DKIM selector" className="max-w-48" />
              <Checkbox label="All DNS records" checked={allRecords} onChange={setAllRecords} />
            </div>
          ) : (
            <span />
          )}

          <Button onClick={handleUpload} disabled={uploading || !file}>
            {uploading && <Spinner />}
            Start bulk check
          </Button>
        </div>

        <ErrorText>{error}</ErrorText>
        {notice && <p className="text-sm text-emerald-700">{notice}</p>}
      </div>
    </Card>
  )
}

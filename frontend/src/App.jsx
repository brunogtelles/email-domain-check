import { useState } from 'react'
import CheckerTool from './components/CheckerTool'

const TOOLS = [
  { id: 'health', label: 'Blacklist & DNS', description: 'DNSBL status, MX, SPF, DMARC, DKIM, NS and other DNS records' },
  { id: 'provider', label: 'Email Provider', description: 'Google Workspace / Microsoft 365 / other provider detection' },
]

export default function App() {
  const [active, setActive] = useState('health')

  return (
    <div className="min-h-screen">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto max-w-7xl px-4 pt-5">
          <h1 className="text-lg font-semibold text-slate-900">Domain Health Checker</h1>
          <p className="text-sm text-slate-500">{TOOLS.find((tool) => tool.id === active).description}</p>

          <nav className="mt-4 flex gap-6" role="tablist">
            {TOOLS.map((tool) => (
              <button
                key={tool.id}
                type="button"
                role="tab"
                aria-selected={active === tool.id}
                onClick={() => setActive(tool.id)}
                className={`-mb-px border-b-2 pb-3 text-sm font-medium transition ${
                  active === tool.id ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-700'
                }`}
              >
                {tool.label}
              </button>
            ))}
          </nav>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-6">
        {/* Both tools stay mounted so a running bulk job keeps updating while the other tab is open. */}
        {TOOLS.map((tool) => (
          <div key={tool.id} hidden={active !== tool.id}>
            <CheckerTool type={tool.id} />
          </div>
        ))}
      </main>
    </div>
  )
}

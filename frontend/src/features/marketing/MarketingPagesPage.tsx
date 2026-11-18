import { Link } from '@tanstack/react-router'
import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, EmptyState, Field, PageHeader, PageLoader, Select, Table } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDateTime } from '../../lib/formatting'
import {
  PAGE_STATUSES,
  useCreateMarketingPage,
  useMarketingPages,
  type PageStatus,
} from '../../lib/marketing/hooks'

const statusTone: Record<PageStatus, 'gray' | 'green' | 'red' | 'amber'> = {
  draft: 'gray',
  review: 'amber',
  approved: 'amber',
  scheduled: 'amber',
  published: 'green',
  archived: 'red',
}

export function MarketingPagesPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const [title, setTitle] = useState('')
  const [slug, setSlug] = useState('')
  const [pageType, setPageType] = useState('page')
  const [error, setError] = useState<string | null>(null)

  const { data: user } = useCurrentUser()
  const canEdit = user?.permissions.includes('cms.edit') ?? false
  const pages = useMarketingPages({ search, status, type })
  const createPage = useCreateMarketingPage()

  function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    createPage.mutate(
      { title, slug: slug || title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''), type: pageType as 'page' },
      {
        onSuccess: () => {
          setTitle('')
          setSlug('')
          setShowCreate(false)
        },
        onError: (err) => setError(err instanceof ApiError ? err.message : 'Could not create the page.'),
      },
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Marketing pages"
        description="Manage every public page, its workflow status and publishing schedule."
        actions={
          canEdit ? (
            <Button onClick={() => setShowCreate((value) => !value)}>
              {showCreate ? 'Close' : 'New page'}
            </Button>
          ) : undefined
        }
      />

      {error && <Alert kind="error">{error}</Alert>}

      {showCreate && (
        <form onSubmit={submit} className="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Field label="Title" name="page-title" value={title} onChange={(event) => setTitle(event.target.value)} required />
            <Field
              label="Slug"
              name="page-slug"
              value={slug}
              onChange={(event) => setSlug(event.target.value)}
              placeholder="auto from title"
            />
            <Select label="Type" name="page-type" value={pageType} onChange={(event) => setPageType(event.target.value)}>
              <option value="page">Page</option>
              <option value="landing">Landing page</option>
              <option value="legal">Legal</option>
              <option value="system">System</option>
            </Select>
            <div className="flex items-end">
              <Button type="submit" loading={createPage.isPending} disabled={title.trim().length === 0}>
                Create draft
              </Button>
            </div>
          </div>
        </form>
      )}

      <div className="flex flex-wrap items-end gap-3">
        <Field
          label="Search"
          name="page-search"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          placeholder="Title or slug"
        />
        <Select label="Status" name="page-status" value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">All statuses</option>
          {PAGE_STATUSES.map((value) => (
            <option key={value} value={value}>
              {value}
            </option>
          ))}
        </Select>
        <Select label="Type" name="page-type-filter" value={type} onChange={(event) => setType(event.target.value)}>
          <option value="">All types</option>
          {['page', 'landing', 'legal', 'system'].map((value) => (
            <option key={value} value={value}>
              {value}
            </option>
          ))}
        </Select>
      </div>

      {pages.isLoading ? (
        <PageLoader label="Loading pages…" />
      ) : pages.isError ? (
        <Alert kind="error">{(pages.error as ApiError).message}</Alert>
      ) : (
        <Table
          headers={['Page', 'Status', 'Type', 'Live', 'Updated', '']}
          empty={<EmptyState title="No pages match" description="Adjust the filters or create a new page." />}
        >
          {pages.data?.pages.map((page) => (
            <tr key={page.id} className="hover:bg-gray-50">
              <td className="px-4 py-3">
                <Link
                  to="/platform/marketing/pages/$pageId"
                  params={{ pageId: page.id }}
                  className="text-sm font-medium text-brand-800 hover:underline"
                  data-testid="marketing-page-row"
                >
                  {page.title}
                </Link>
                <p className="text-xs text-gray-500">/{page.effective_slug}</p>
              </td>
              <td className="px-4 py-3">
                <div className="flex flex-wrap gap-1">
                  <Badge tone={statusTone[page.status]}>{page.status}</Badge>
                  {page.has_pending_changes && <Badge tone="amber">Unpublished edits</Badge>}
                </div>
              </td>
              <td className="px-4 py-3 text-sm text-gray-600">{page.type}</td>
              <td className="px-4 py-3 text-sm">
                {page.is_live ? (
                  <a href={`/${page.effective_slug}`} target="_blank" rel="noreferrer" className="text-brand-700 hover:underline">
                    View live
                  </a>
                ) : (
                  <span className="text-gray-500">
                    {page.scheduled_at ? `Scheduled ${formatDateTime(page.scheduled_at)}` : 'Not live'}
                  </span>
                )}
              </td>
              <td className="px-4 py-3 text-xs text-gray-500">
                {page.updated_at ? formatDateTime(page.updated_at) : '—'}
              </td>
              <td className="px-4 py-3 text-right">
                <Link
                  to="/platform/marketing/pages/$pageId"
                  params={{ pageId: page.id }}
                  className="text-sm font-medium text-brand-700 hover:underline"
                >
                  Edit
                </Link>
              </td>
            </tr>
          ))}
        </Table>
      )}
    </div>
  )
}

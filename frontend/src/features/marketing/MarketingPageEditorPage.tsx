import { Link, useParams } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Field,
  PageHeader,
  PageLoader,
  Select,
} from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDateTime } from '../../lib/formatting'
import {
  SECTION_TYPES,
  useDeleteMarketingPage,
  useMarketingPage,
  usePagePreview,
  usePageTransition,
  useReplaceSections,
  useRestorePageVersion,
  useUpdateMarketingPage,
  type PageSection,
  type PageStatus,
  type PageTransition,
  type PageType,
  type SectionType,
} from '../../lib/marketing/hooks'
import { StructuredDataPanel } from './StructuredDataPanel'

const statusTone: Record<PageStatus, 'gray' | 'green' | 'red' | 'amber'> = {
  draft: 'gray',
  review: 'amber',
  approved: 'amber',
  scheduled: 'amber',
  published: 'green',
  archived: 'red',
}

interface EditableSection {
  key: string
  type: string
  is_visible: boolean
  contentText: string
  settingsText: string
}

const sectionTemplates: Record<string, Record<string, unknown>> = {
  hero: {
    eyebrow: '',
    headline: '',
    subheadline: '',
    bullets: [],
    primary_cta: { label: '', url: '' },
  },
  rich_text: { heading: '', paragraphs: [], bullets: [] },
  cta: { heading: '', body: '', primary_cta: { label: '', url: '' } },
  faq: { heading: '', intro: '', items: [{ question: '', answer: '' }] },
  faq_library: { heading: '', intro: '' },
  testimonials: { heading: '', intro: '', items: [] },
  service_grid: { heading: '', intro: '', items: [{ title: '', body: '', url: '' }] },
  industries: { heading: '', intro: '', items: [{ title: '', body: '', url: '' }] },
  pos_features: { heading: '', intro: '', items: [{ title: '', body: '', url: '' }] },
  process: { heading: '', intro: '', steps: [{ title: '', body: '' }] },
  contact: { heading: '' },
  form: { heading: '', body: '', form: 'contact', submit_label: 'Send', items: [] },
  legal: { heading: '', updated: '', intro: [], blocks: [] },
}

function toStrings(sections: PageSection[]): EditableSection[] {
  return sections.map((section, index) => ({
    key: `${section.id ?? index}-${section.type}`,
    type: section.type,
    is_visible: section.is_visible,
    contentText: JSON.stringify(section.content ?? {}, null, 2),
    settingsText: JSON.stringify(section.settings ?? {}, null, 2),
  }))
}

function toPayloads(sections: EditableSection[]): { sections: PageSection[]; error: string | null } {
  const payloads: PageSection[] = []

  for (const [index, section] of sections.entries()) {
    try {
      const content = JSON.parse(section.contentText || '{}') as Record<string, unknown>
      const settings = JSON.parse(section.settingsText || '{}') as Record<string, unknown>
      payloads.push({ type: section.type, content, settings, is_visible: section.is_visible })
    } catch {
      return { sections: [], error: `Section ${index + 1} (${section.type}) contains invalid JSON.` }
    }
  }

  return { sections: payloads, error: null }
}

export function MarketingPageEditorPage() {
  const { pageId } = useParams({ from: '/platform/marketing/pages/$pageId' })
  const pageQuery = useMarketingPage(pageId)
  const queryClient = useQueryClient()
  const { data: user } = useCurrentUser()
  const canEdit = user?.permissions.includes('cms.edit') ?? false
  const canReview = user?.permissions.includes('cms.review') ?? false
  const canPublish = user?.permissions.includes('cms.publish') ?? false

  const updatePage = useUpdateMarketingPage(pageId)
  const replaceSections = useReplaceSections(pageId)
  const transition = usePageTransition(pageId)
  const restoreVersion = useRestorePageVersion(pageId)
  const preview = usePagePreview(pageId)
  const deletePage = useDeleteMarketingPage()

  const [title, setTitle] = useState('')
  const [slug, setSlug] = useState('')
  const [type, setType] = useState<PageType>('page')
  const [template, setTemplate] = useState('public.page')
  const [locale, setLocale] = useState('en')
  const [requiresAuth, setRequiresAuth] = useState(false)
  const [noindex, setNoindex] = useState(false)
  const [seo, setSeo] = useState({
    title: '',
    description: '',
    canonical_url: '',
    robots_index: true,
    robots_follow: true,
    og_title: '',
    og_description: '',
    twitter_card: 'summary_large_image',
    priority: 0.5,
    change_frequency: 'monthly',
  })
  const [sections, setSections] = useState<EditableSection[]>([])
  const [newSectionType, setNewSectionType] = useState<SectionType>('rich_text')
  const [changeSummary, setChangeSummary] = useState('')
  const [rejectReason, setRejectReason] = useState('')
  const [scheduledAt, setScheduledAt] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const page = pageQuery.data?.page
  const revision = page?.updated_at ?? ''

  useEffect(() => {
    if (!page) return
    setTitle(page.title)
    setSlug(page.effective_slug)
    setType(page.type)
    setTemplate(page.template)
    setLocale(page.locale)
    setRequiresAuth(page.requires_auth)
    setNoindex(page.noindex)
    setSeo({
      title: page.seo.title ?? '',
      description: page.seo.description ?? '',
      canonical_url: page.seo.canonical_url ?? '',
      robots_index: page.seo.robots_index,
      robots_follow: page.seo.robots_follow,
      og_title: page.seo.og_title ?? '',
      og_description: page.seo.og_description ?? '',
      twitter_card: page.seo.twitter_card,
      priority: page.seo.priority,
      change_frequency: page.seo.change_frequency,
    })
    setSections(toStrings(page.sections))
    // Reset the editor whenever the server snapshot changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page?.id, revision])

  if (pageQuery.isLoading) return <PageLoader label="Loading page…" />
  if (pageQuery.isError || !page) {
    return <Alert kind="error">{(pageQuery.error as ApiError | null)?.message ?? 'Page not found.'}</Alert>
  }

  function report(err: unknown, fallback: string) {
    setNotice(null)
    if (err instanceof ApiError) {
      const detail = Object.values(err.details)[0]?.[0]
      setError(detail ?? err.message)
      return
    }
    setError(fallback)
  }

  function saveBasics() {
    setError(null)
    setNotice(null)
    updatePage.mutate(
      {
        title,
        slug,
        type,
        template,
        locale,
        requires_auth: requiresAuth,
        noindex,
        seo: {
          title: seo.title || null,
          description: seo.description || null,
          canonical_url: seo.canonical_url || null,
          robots_index: seo.robots_index,
          robots_follow: seo.robots_follow,
          og_title: seo.og_title || null,
          og_description: seo.og_description || null,
          twitter_card: seo.twitter_card,
          priority: Number(seo.priority),
          change_frequency: seo.change_frequency,
        },
      },
      {
        onSuccess: () => {
          setNotice('Page details and SEO saved. The page is back in draft until reviewed again.')
          void queryClient.invalidateQueries({ queryKey: ['marketing', 'structured-data', pageId] })
        },
        onError: (err) => report(err, 'Could not save the page.'),
      },
    )
  }

  function saveSections() {
    const { sections: payloads, error: parseError } = toPayloads(sections)
    if (parseError) {
      setNotice(null)
      setError(parseError)
      return
    }

    setError(null)
    setNotice(null)
    replaceSections.mutate(payloads, {
      onSuccess: () => {
        setNotice('Sections saved.')
        void queryClient.invalidateQueries({ queryKey: ['marketing', 'structured-data', pageId] })
      },
      onError: (err) => report(err, 'Could not save the sections.'),
    })
  }

  function runTransition(action: PageTransition, payload?: Record<string, unknown>) {
    setError(null)
    setNotice(null)
    transition.mutate(
      { action, payload },
      {
        onSuccess: () => {
          setNotice(`Page ${action.replace('-', ' ')} completed.`)
          setRejectReason('')
          setChangeSummary('')
        },
        onError: (err) => report(err, 'The workflow action failed.'),
      },
    )
  }

  function openPreview(versionId?: string) {
    const win = window.open('', '_blank')
    preview.mutate(versionId, {
      onSuccess: (result) => {
        if (win) win.location.href = result.url
        else window.location.assign(result.url)
      },
      onError: (err) => {
        win?.close()
        report(err, 'Could not create a preview link.')
      },
    })
  }

  function move(index: number, direction: -1 | 1) {
    const target = index + direction
    if (target < 0 || target >= sections.length) return
    setSections((current) => {
      const next = [...current]
      const [item] = next.splice(index, 1)
      next.splice(target, 0, item)
      return next
    })
  }

  function addSection() {
    setSections((current) => [
      ...current,
      {
        key: `${Date.now()}-${newSectionType}`,
        type: newSectionType,
        is_visible: true,
        contentText: JSON.stringify(sectionTemplates[newSectionType] ?? {}, null, 2),
        settingsText: '{}',
      },
    ])
  }

  const versions = [...page.versions].sort((a, b) => b.version_no - a.version_no)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-2 text-sm text-gray-500">
        <Link to="/platform/marketing/pages" className="hover:underline">
          Marketing pages
        </Link>
        <span aria-hidden>/</span>
        <span className="text-gray-800">{page.title}</span>
      </div>

      <PageHeader
        title={page.title}
        description={`/${page.effective_slug}`}
        actions={
          <div className="flex flex-wrap gap-2">
            <Badge tone={statusTone[page.status]}>
              <span data-testid="page-status">{page.status}</span>
            </Badge>
            {page.is_live && <Badge tone="green">Live</Badge>}
            {page.has_pending_changes && <Badge tone="amber">Unpublished edits</Badge>}
            <Button variant="secondary" onClick={() => openPreview()} loading={preview.isPending}>
              Preview
            </Button>
            {page.is_live && (
              <a
                href={`/${page.effective_slug}`}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                Open live page
              </a>
            )}
          </div>
        }
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div className="space-y-6 xl:col-span-2">
          <Card
            title="Page details"
            actions={canEdit ? <Button onClick={saveBasics} loading={updatePage.isPending}>Save details</Button> : undefined}
          >
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Title" name="page-title" value={title} disabled={!canEdit} onChange={(event) => setTitle(event.target.value)} />
              <Field
                label="URL slug"
                name="page-slug"
                value={slug}
                disabled={!canEdit}
                onChange={(event) => setSlug(event.target.value)}
                hint="Changes go live only after the page is published again."
              />
              <Select label="Type" name="page-type" value={type} disabled={!canEdit} onChange={(event) => setType(event.target.value as PageType)}>
                <option value="page">Page</option>
                <option value="landing">Landing page</option>
                <option value="legal">Legal</option>
                <option value="system">System</option>
              </Select>
              <Field label="Template" name="page-template" value={template} disabled={!canEdit} onChange={(event) => setTemplate(event.target.value)} />
              <Field label="Locale" name="page-locale" value={locale} disabled={!canEdit} onChange={(event) => setLocale(event.target.value)} />
              <div className="space-y-2 pt-6 text-sm text-gray-700">
                <label className="flex items-center gap-2">
                  <input type="checkbox" checked={requiresAuth} disabled={!canEdit} onChange={(event) => setRequiresAuth(event.target.checked)} />
                  Requires sign-in (never public)
                </label>
                <label className="flex items-center gap-2">
                  <input type="checkbox" checked={noindex} disabled={!canEdit} onChange={(event) => setNoindex(event.target.checked)} />
                  noindex on the public site
                </label>
              </div>
            </div>
          </Card>

          <Card
            title={`Sections (${sections.length})`}
            actions={
              canEdit ? (
                <Button onClick={saveSections} loading={replaceSections.isPending}>
                  Save sections
                </Button>
              ) : undefined
            }
          >
            {canEdit && (
              <div className="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-dashed border-gray-300 p-3">
                <Select
                  label="Section type"
                  name="new-section-type"
                  value={newSectionType}
                  onChange={(event) => setNewSectionType(event.target.value as SectionType)}
                >
                  {SECTION_TYPES.map((value) => (
                    <option key={value} value={value}>
                      {value}
                    </option>
                  ))}
                </Select>
                <Button variant="secondary" onClick={addSection}>
                  Add section
                </Button>
              </div>
            )}

            {sections.length === 0 ? (
              <EmptyState title="No sections" description="Add a section to start composing this page." />
            ) : (
              <ol className="space-y-4" data-testid="section-list">
                {sections.map((section, index) => (
                  <li key={section.key} className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div className="flex items-center gap-2">
                        <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">#{index + 1}</span>
                        <Select
                          label=""
                          aria-label={`Section ${index + 1} type`}
                          value={section.type}
                          disabled={!canEdit}
                          onChange={(event) =>
                            setSections((current) =>
                              current.map((item, itemIndex) =>
                                itemIndex === index ? { ...item, type: event.target.value } : item,
                              ),
                            )
                          }
                        >
                          {SECTION_TYPES.map((value) => (
                            <option key={value} value={value}>
                              {value}
                            </option>
                          ))}
                        </Select>
                        <Badge tone={section.is_visible ? 'green' : 'gray'}>
                          {section.is_visible ? 'visible' : 'hidden'}
                        </Badge>
                      </div>
                      {canEdit && (
                        <div className="flex flex-wrap gap-1">
                          <Button variant="secondary" onClick={() => move(index, -1)} disabled={index === 0}>
                            Move up
                          </Button>
                          <Button variant="secondary" onClick={() => move(index, 1)} disabled={index === sections.length - 1}>
                            Move down
                          </Button>
                          <Button
                            variant="secondary"
                            onClick={() =>
                              setSections((current) =>
                                current.map((item, itemIndex) =>
                                  itemIndex === index ? { ...item, is_visible: !item.is_visible } : item,
                                ),
                              )
                            }
                          >
                            {section.is_visible ? 'Hide' : 'Show'}
                          </Button>
                          <Button
                            variant="danger"
                            onClick={() => setSections((current) => current.filter((_, itemIndex) => itemIndex !== index))}
                          >
                            Remove
                          </Button>
                        </div>
                      )}
                    </div>

                    <div className="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
                      <label className="space-y-1 text-sm text-gray-700">
                        <span className="font-medium">Content (JSON)</span>
                        <textarea
                          className="block min-h-40 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:outline-2 focus:outline-brand-600"
                          value={section.contentText}
                          disabled={!canEdit}
                          onChange={(event) =>
                            setSections((current) =>
                              current.map((item, itemIndex) =>
                                itemIndex === index ? { ...item, contentText: event.target.value } : item,
                              ),
                            )
                          }
                        />
                      </label>
                      <label className="space-y-1 text-sm text-gray-700">
                        <span className="font-medium">Settings (JSON)</span>
                        <textarea
                          className="block min-h-40 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:outline-2 focus:outline-brand-600"
                          value={section.settingsText}
                          disabled={!canEdit}
                          onChange={(event) =>
                            setSections((current) =>
                              current.map((item, itemIndex) =>
                                itemIndex === index ? { ...item, settingsText: event.target.value } : item,
                              ),
                            )
                          }
                        />
                      </label>
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </Card>

          <Card
            title="SEO"
            actions={canEdit ? <Button onClick={saveBasics} loading={updatePage.isPending}>Save SEO</Button> : undefined}
          >
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="SEO title" name="seo-title" value={seo.title} disabled={!canEdit} onChange={(event) => setSeo({ ...seo, title: event.target.value })} />
              <Field label="Canonical URL" name="seo-canonical" value={seo.canonical_url} disabled={!canEdit} onChange={(event) => setSeo({ ...seo, canonical_url: event.target.value })} />
              <label className="space-y-1 text-sm text-gray-700 sm:col-span-2">
                <span className="font-medium">Meta description</span>
                <textarea
                  className="block min-h-20 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
                  value={seo.description}
                  disabled={!canEdit}
                  onChange={(event) => setSeo({ ...seo, description: event.target.value })}
                />
              </label>
              <Field label="Open Graph title" name="seo-og-title" value={seo.og_title} disabled={!canEdit} onChange={(event) => setSeo({ ...seo, og_title: event.target.value })} />
              <Field label="Open Graph description" name="seo-og-description" value={seo.og_description} disabled={!canEdit} onChange={(event) => setSeo({ ...seo, og_description: event.target.value })} />
              <Select label="Twitter card" name="seo-twitter" value={seo.twitter_card} disabled={!canEdit} onChange={(event) => setSeo({ ...seo, twitter_card: event.target.value })}>
                <option value="summary">summary</option>
                <option value="summary_large_image">summary_large_image</option>
              </Select>
              <Select label="Change frequency" name="seo-change-frequency" value={seo.change_frequency} disabled={!canEdit} onChange={(event) => setSeo({ ...seo, change_frequency: event.target.value })}>
                {['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'].map((value) => (
                  <option key={value} value={value}>
                    {value}
                  </option>
                ))}
              </Select>
              <Field
                label="Sitemap priority"
                name="seo-priority"
                type="number"
                step="0.1"
                min="0"
                max="1"
                value={String(seo.priority)}
                disabled={!canEdit}
                onChange={(event) => setSeo({ ...seo, priority: Number(event.target.value) })}
              />
              <div className="space-y-2 pt-6 text-sm text-gray-700">
                <label className="flex items-center gap-2">
                  <input type="checkbox" checked={seo.robots_index} disabled={!canEdit} onChange={(event) => setSeo({ ...seo, robots_index: event.target.checked })} />
                  Allow search engine indexing
                </label>
                <label className="flex items-center gap-2">
                  <input type="checkbox" checked={seo.robots_follow} disabled={!canEdit} onChange={(event) => setSeo({ ...seo, robots_follow: event.target.checked })} />
                  Follow links
                </label>
              </div>
            </div>
          </Card>

          <StructuredDataPanel pageId={page.id} canEdit={canEdit} />
        </div>

        <div className="space-y-6">
          <Card title="Publishing workflow">
            <div className="space-y-3 text-sm text-gray-600">
              <p>
                Status: <Badge tone={statusTone[page.status]}>{page.status}</Badge>
              </p>
              {page.scheduled_at && <p>Scheduled for {formatDateTime(page.scheduled_at)}</p>}
              {page.published_at && <p>Live since {formatDateTime(page.published_at)}</p>}
              <p className="text-xs text-gray-500">
                Saving details or sections returns the page to draft. The published snapshot stays live until you
                publish again.
              </p>

              <Field
                label="Change summary (optional)"
                name="workflow-summary"
                value={changeSummary}
                onChange={(event) => setChangeSummary(event.target.value)}
                placeholder="What changed in this revision?"
              />

              <div className="flex flex-wrap gap-2">
                {page.status === 'draft' && canEdit && (
                  <Button loading={transition.isPending} onClick={() => runTransition('submit-review', { change_summary: changeSummary || undefined })}>
                    Submit for review
                  </Button>
                )}
                {page.status === 'review' && canReview && (
                  <Button loading={transition.isPending} onClick={() => runTransition('approve', { change_summary: changeSummary || undefined })}>
                    Approve
                  </Button>
                )}
                {page.status === 'approved' && canPublish && (
                  <Button loading={transition.isPending} onClick={() => runTransition('publish', { change_summary: changeSummary || undefined })}>
                    Publish now
                  </Button>
                )}
                {page.status === 'scheduled' && canPublish && (
                  <Button loading={transition.isPending} onClick={() => runTransition('publish', { change_summary: changeSummary || undefined })}>
                    Publish now
                  </Button>
                )}
                {page.is_live && canPublish && (
                  <Button variant="danger" loading={transition.isPending} onClick={() => runTransition('unpublish')}>
                    Unpublish
                  </Button>
                )}
                {!canEdit && !canReview && !canPublish && (
                  <p className="text-xs text-gray-500">You have read-only access to marketing pages.</p>
                )}
              </div>

              {page.status === 'review' && canReview && (
                <div className="space-y-2 border-t border-gray-100 pt-3">
                  <Field
                    label="Rejection reason"
                    name="reject-reason"
                    value={rejectReason}
                    onChange={(event) => setRejectReason(event.target.value)}
                    placeholder="Explain what needs to change"
                  />
                  <Button
                    variant="secondary"
                    loading={transition.isPending}
                    disabled={rejectReason.trim().length === 0}
                    onClick={() => runTransition('reject', { reason: rejectReason })}
                  >
                    Reject for changes
                  </Button>
                </div>
              )}

              {page.status === 'approved' && canPublish && (
                <div className="space-y-2 border-t border-gray-100 pt-3">
                  <Field
                    label="Schedule publish"
                    name="scheduled-at"
                    type="datetime-local"
                    value={scheduledAt}
                    onChange={(event) => setScheduledAt(event.target.value)}
                  />
                  <Button
                    variant="secondary"
                    loading={transition.isPending}
                    disabled={!scheduledAt}
                    onClick={() =>
                      runTransition('schedule', {
                        scheduled_at: scheduledAt ? new Date(scheduledAt).toISOString() : undefined,
                      })
                    }
                  >
                    Schedule
                  </Button>
                </div>
              )}

              {canEdit && !page.is_live && (
                <div className="border-t border-gray-100 pt-3">
                  <Button
                    variant="danger"
                    loading={deletePage.isPending}
                    onClick={() => {
                      if (!window.confirm('Delete this page permanently?')) return
                      deletePage.mutate(page.id, {
                        onSuccess: () => {
                          window.location.assign('/platform/marketing/pages')
                        },
                        onError: (err) => report(err, 'Could not delete the page.'),
                      })
                    }}
                  >
                    Delete page
                  </Button>
                </div>
              )}
            </div>
          </Card>

          <Card title={`Version history (${versions.length})`}>
            {versions.length === 0 ? (
              <EmptyState title="No versions yet" description="Versions are created by workflow transitions." />
            ) : (
              <ul className="divide-y divide-gray-100" data-testid="version-list">
                {versions.map((version) => (
                  <li key={version.id} className="py-3">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <p className="text-sm font-medium text-gray-900">
                          v{version.version_no} · {version.status}
                          {version.id === page.current_version_id && <span className="ml-2 text-xs text-brand-700">live</span>}
                        </p>
                        <p className="text-xs text-gray-500">
                          {version.change_summary ?? 'No summary'} ·{' '}
                          {version.created_at ? formatDateTime(version.created_at) : '—'}
                        </p>
                      </div>
                      <div className="flex shrink-0 gap-1">
                        <Button variant="secondary" onClick={() => openPreview(version.id)}>
                          Preview
                        </Button>
                        {canEdit && version.id !== page.current_version_id && (
                          <Button
                            variant="secondary"
                            loading={restoreVersion.isPending}
                            onClick={() =>
                              restoreVersion.mutate(version.id, {
                                onSuccess: () => setNotice(`Restored version ${version.version_no} as a draft.`),
                                onError: (err) => report(err, 'Could not restore the version.'),
                              })
                            }
                          >
                            Restore
                          </Button>
                        )}
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}

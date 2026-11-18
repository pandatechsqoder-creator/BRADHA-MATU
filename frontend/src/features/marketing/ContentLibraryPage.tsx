import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, PageLoader, Select } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import {
  useBanners,
  useCreateBanner,
  useCreateCta,
  useCreateFaq,
  useCreateTestimonial,
  useCtas,
  useDeleteBanner,
  useDeleteCta,
  useDeleteFaq,
  useDeleteTestimonial,
  useFaqs,
  useMediaAssets,
  useTestimonials,
  useUpdateBanner,
  useUpdateCta,
  useUpdateFaq,
  useUpdateTestimonial,
} from '../../lib/marketing/hooks'

type Tab = 'banners' | 'ctas' | 'faqs' | 'testimonials'

const tabs: { key: Tab; label: string }[] = [
  { key: 'banners', label: 'Banners' },
  { key: 'ctas', label: 'Calls to action' },
  { key: 'faqs', label: 'FAQs' },
  { key: 'testimonials', label: 'Testimonials' },
]

export function ContentLibraryPage() {
  const [tab, setTab] = useState<Tab>('banners')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  function report(err: unknown, fallback: string) {
    setNotice(null)
    setError(err instanceof ApiError ? err.message : fallback)
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Content library"
        description="Announcement banners, reusable calls to action, FAQ entries and compliant testimonials."
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      <div className="flex flex-wrap gap-2">
        {tabs.map((item) => (
          <button
            key={item.key}
            type="button"
            onClick={() => setTab(item.key)}
            className={`rounded-full px-4 py-1.5 text-sm font-medium ${
              tab === item.key ? 'bg-brand-700 text-white' : 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50'
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === 'banners' && <BannersSection report={report} notify={setNotice} />}
      {tab === 'ctas' && <CtasSection report={report} notify={setNotice} />}
      {tab === 'faqs' && <FaqsSection report={report} notify={setNotice} />}
      {tab === 'testimonials' && <TestimonialsSection report={report} notify={setNotice} />}
    </div>
  )
}

interface SectionProps {
  report: (err: unknown, fallback: string) => void
  notify: (message: string) => void
}

function BannersSection({ report, notify }: SectionProps) {
  const { data: user } = useCurrentUser()
  const canEdit = user?.permissions.includes('cms.edit') ?? false
  const banners = useBanners()
  const assets = useMediaAssets({})
  const createBanner = useCreateBanner()
  const updateBanner = useUpdateBanner()
  const deleteBanner = useDeleteBanner()
  const [form, setForm] = useState({ title: '', body: '', link_url: '', media_asset_id: '', starts_at: '', ends_at: '', is_active: true })

  function submit(event: FormEvent) {
    event.preventDefault()
    createBanner.mutate(
      {
        title: form.title,
        body: form.body || null,
        link_url: form.link_url || null,
        media_asset_id: form.media_asset_id || null,
        starts_at: form.starts_at || null,
        ends_at: form.ends_at || null,
        is_active: form.is_active,
      },
      {
        onSuccess: () => {
          notify('Banner saved. Activating it deactivates other banners with the same key.')
          setForm({ title: '', body: '', link_url: '', media_asset_id: '', starts_at: '', ends_at: '', is_active: true })
        },
        onError: (err) => report(err, 'Could not save the banner.'),
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
      <div className="xl:col-span-2">
        <Card title={`Banners (${banners.data?.banners.length ?? 0})`}>
          {banners.isLoading ? (
            <PageLoader />
          ) : (banners.data?.banners.length ?? 0) === 0 ? (
            <EmptyState title="No banners" description="Create an announcement banner for the public site." />
          ) : (
            <ul className="divide-y divide-gray-100">
              {banners.data?.banners.map((banner) => (
                <li key={banner.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                  <div>
                    <p className="text-sm font-medium text-gray-900">{banner.title}</p>
                    <p className="text-xs text-gray-500">{banner.body ?? 'No supporting text'}</p>
                    <p className="mt-1 text-xs text-gray-500">
                      {banner.starts_at ? `From ${banner.starts_at}` : 'No start'} ·{' '}
                      {banner.ends_at ? `Until ${banner.ends_at}` : 'No end'}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone={banner.is_active ? 'green' : 'gray'}>{banner.is_active ? 'active' : 'inactive'}</Badge>
                    {canEdit && (
                      <>
                        <Button
                          variant="secondary"
                          onClick={() =>
                            updateBanner.mutate(
                              { bannerId: banner.id, input: { is_active: !banner.is_active } },
                              { onSuccess: () => notify('Banner status updated.'), onError: (err) => report(err, 'Could not update the banner.') },
                            )
                          }
                        >
                          {banner.is_active ? 'Deactivate' : 'Activate'}
                        </Button>
                        <Button
                          variant="danger"
                          onClick={() =>
                            deleteBanner.mutate(banner.id, {
                              onSuccess: () => notify('Banner deleted.'),
                              onError: (err) => report(err, 'Could not delete the banner.'),
                            })
                          }
                        >
                          Delete
                        </Button>
                      </>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {canEdit && (
        <Card title="New banner">
          <form onSubmit={submit} className="space-y-3">
            <Field label="Title" name="banner-title" value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} />
            <Field label="Body" name="banner-body" value={form.body} onChange={(event) => setForm({ ...form, body: event.target.value })} />
            <Field label="Link URL" name="banner-link" value={form.link_url} onChange={(event) => setForm({ ...form, link_url: event.target.value })} />
            <Select label="Image" name="banner-image" value={form.media_asset_id} onChange={(event) => setForm({ ...form, media_asset_id: event.target.value })}>
              <option value="">No image</option>
              {assets.data?.assets.map((asset) => (
                <option key={asset.id} value={asset.id}>
                  {asset.filename}
                </option>
              ))}
            </Select>
            <Field label="Starts at" name="banner-starts" type="datetime-local" value={form.starts_at} onChange={(event) => setForm({ ...form, starts_at: event.target.value })} />
            <Field label="Ends at" name="banner-ends" type="datetime-local" value={form.ends_at} onChange={(event) => setForm({ ...form, ends_at: event.target.value })} />
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} />
              Active on the public site
            </label>
            <Button type="submit" loading={createBanner.isPending} disabled={!form.title}>
              Save banner
            </Button>
          </form>
        </Card>
      )}
    </div>
  )
}

function CtasSection({ report, notify }: SectionProps) {
  const { data: user } = useCurrentUser()
  const canEdit = user?.permissions.includes('cms.edit') ?? false
  const ctas = useCtas()
  const createCta = useCreateCta()
  const updateCta = useUpdateCta()
  const deleteCta = useDeleteCta()
  const [form, setForm] = useState({ key: '', label: '', url: '', style: 'primary', is_active: true })

  function submit(event: FormEvent) {
    event.preventDefault()
    createCta.mutate(
      { ...form },
      {
        onSuccess: () => {
          notify('Call to action saved.')
          setForm({ key: '', label: '', url: '', style: 'primary', is_active: true })
        },
        onError: (err) => report(err, 'Could not save the call to action.'),
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
      <div className="xl:col-span-2">
        <Card title={`Calls to action (${ctas.data?.ctas.length ?? 0})`}>
          {ctas.isLoading ? (
            <PageLoader />
          ) : (ctas.data?.ctas.length ?? 0) === 0 ? (
            <EmptyState title="No calls to action" />
          ) : (
            <ul className="divide-y divide-gray-100">
              {ctas.data?.ctas.map((cta) => (
                <li key={cta.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                  <div>
                    <p className="text-sm font-medium text-gray-900">
                      {cta.label} <span className="text-xs text-gray-500">({cta.key})</span>
                    </p>
                    <p className="text-xs text-gray-500">
                      {cta.url} · {cta.style}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone={cta.is_active ? 'green' : 'gray'}>{cta.is_active ? 'active' : 'inactive'}</Badge>
                    {canEdit && (
                      <>
                        <Button
                          variant="secondary"
                          onClick={() =>
                            updateCta.mutate(
                              { ctaId: cta.id, input: { is_active: !cta.is_active } },
                              { onSuccess: () => notify('Call to action updated.'), onError: (err) => report(err, 'Could not update it.') },
                            )
                          }
                        >
                          {cta.is_active ? 'Deactivate' : 'Activate'}
                        </Button>
                        <Button
                          variant="danger"
                          onClick={() =>
                            deleteCta.mutate(cta.id, {
                              onSuccess: () => notify('Call to action deleted.'),
                              onError: (err) => report(err, 'Could not delete it.'),
                            })
                          }
                        >
                          Delete
                        </Button>
                      </>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {canEdit && (
        <Card title="New call to action">
          <form onSubmit={submit} className="space-y-3">
            <Field label="Key" name="cta-key" value={form.key} onChange={(event) => setForm({ ...form, key: event.target.value })} placeholder="request-quote" />
            <Field label="Label" name="cta-label" value={form.label} onChange={(event) => setForm({ ...form, label: event.target.value })} />
            <Field label="URL" name="cta-url" value={form.url} onChange={(event) => setForm({ ...form, url: event.target.value })} />
            <Select label="Style" name="cta-style" value={form.style} onChange={(event) => setForm({ ...form, style: event.target.value })}>
              <option value="primary">Primary</option>
              <option value="secondary">Secondary</option>
              <option value="link">Link</option>
            </Select>
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} />
              Active
            </label>
            <Button type="submit" loading={createCta.isPending} disabled={!form.key || !form.label || !form.url}>
              Save
            </Button>
          </form>
        </Card>
      )}
    </div>
  )
}

function FaqsSection({ report, notify }: SectionProps) {
  const { data: user } = useCurrentUser()
  const canEdit = user?.permissions.includes('cms.edit') ?? false
  const faqs = useFaqs()
  const createFaq = useCreateFaq()
  const updateFaq = useUpdateFaq()
  const deleteFaq = useDeleteFaq()
  const [form, setForm] = useState({ question: '', answer: '', category: '', is_visible: true })

  function submit(event: FormEvent) {
    event.preventDefault()
    createFaq.mutate(
      { question: form.question, answer: form.answer, category: form.category || null, is_visible: form.is_visible },
      {
        onSuccess: () => {
          notify('FAQ saved.')
          setForm({ question: '', answer: '', category: '', is_visible: true })
        },
        onError: (err) => report(err, 'Could not save the FAQ.'),
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
      <div className="xl:col-span-2">
        <Card title={`FAQ entries (${faqs.data?.faqs.length ?? 0})`}>
          {faqs.isLoading ? (
            <PageLoader />
          ) : (faqs.data?.faqs.length ?? 0) === 0 ? (
            <EmptyState title="No FAQs" description="Global FAQ entries can be embedded on any page with the FAQ library section." />
          ) : (
            <ul className="divide-y divide-gray-100">
              {faqs.data?.faqs.map((faq) => (
                <li key={faq.id} className="py-3">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="text-sm font-medium text-gray-900">{faq.question}</p>
                      <p className="mt-1 text-xs text-gray-500">{faq.answer}</p>
                    </div>
                    <div className="flex items-center gap-2">
                      <Badge tone={faq.is_visible ? 'green' : 'gray'}>{faq.is_visible ? 'visible' : 'hidden'}</Badge>
                      {canEdit && (
                        <>
                          <Button
                            variant="secondary"
                            onClick={() =>
                              updateFaq.mutate(
                                { faqId: faq.id, input: { is_visible: !faq.is_visible } },
                                { onSuccess: () => notify('FAQ updated.'), onError: (err) => report(err, 'Could not update the FAQ.') },
                              )
                            }
                          >
                            {faq.is_visible ? 'Hide' : 'Show'}
                          </Button>
                          <Button
                            variant="danger"
                            onClick={() =>
                              deleteFaq.mutate(faq.id, {
                                onSuccess: () => notify('FAQ deleted.'),
                                onError: (err) => report(err, 'Could not delete the FAQ.'),
                              })
                            }
                          >
                            Delete
                          </Button>
                        </>
                      )}
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {canEdit && (
        <Card title="New FAQ">
          <form onSubmit={submit} className="space-y-3">
            <Field label="Question" name="faq-question" value={form.question} onChange={(event) => setForm({ ...form, question: event.target.value })} />
            <label className="space-y-1 text-sm text-gray-700">
              <span className="font-medium">Answer</span>
              <textarea
                className="block min-h-24 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
                value={form.answer}
                onChange={(event) => setForm({ ...form, answer: event.target.value })}
              />
            </label>
            <Field label="Category" name="faq-category" value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })} />
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" checked={form.is_visible} onChange={(event) => setForm({ ...form, is_visible: event.target.checked })} />
              Visible
            </label>
            <Button type="submit" loading={createFaq.isPending} disabled={!form.question || !form.answer}>
              Save FAQ
            </Button>
          </form>
        </Card>
      )}
    </div>
  )
}

function TestimonialsSection({ report, notify }: SectionProps) {
  const { data: user } = useCurrentUser()
  const canEdit = user?.permissions.includes('cms.edit') ?? false
  const canReview = user?.permissions.includes('cms.review') ?? false
  const testimonials = useTestimonials()
  const createTestimonial = useCreateTestimonial()
  const updateTestimonial = useUpdateTestimonial()
  const deleteTestimonial = useDeleteTestimonial()
  const [form, setForm] = useState({ quote: '', author_name: '', author_role: '', business_name: '' })

  function submit(event: FormEvent) {
    event.preventDefault()
    createTestimonial.mutate(
      {
        quote: form.quote,
        author_name: form.author_name,
        author_role: form.author_role || null,
        business_name: form.business_name || null,
        is_approved: false,
      },
      {
        onSuccess: () => {
          notify('Testimonial saved. A reviewer must approve it before it appears publicly.')
          setForm({ quote: '', author_name: '', author_role: '', business_name: '' })
        },
        onError: (err) => report(err, 'Could not save the testimonial.'),
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
      <div className="xl:col-span-2">
        <Card title={`Testimonials (${testimonials.data?.testimonials.length ?? 0})`}>
          {testimonials.isLoading ? (
            <PageLoader />
          ) : (testimonials.data?.testimonials.length ?? 0) === 0 ? (
            <EmptyState title="No testimonials" description="Only approved, real testimonials are shown publicly." />
          ) : (
            <ul className="divide-y divide-gray-100">
              {testimonials.data?.testimonials.map((testimonial) => (
                <li key={testimonial.id} className="py-3">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="text-sm text-gray-800">“{testimonial.quote}”</p>
                      <p className="mt-1 text-xs text-gray-500">
                        {testimonial.author_name}
                        {testimonial.author_role ? ` · ${testimonial.author_role}` : ''}
                        {testimonial.business_name ? ` · ${testimonial.business_name}` : ''}
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      <Badge tone={testimonial.is_approved ? 'green' : 'amber'}>
                        {testimonial.is_approved ? 'approved' : 'pending'}
                      </Badge>
                      {canReview && (
                        <Button
                          variant="secondary"
                          onClick={() =>
                            updateTestimonial.mutate(
                              { testimonialId: testimonial.id, input: { is_approved: !testimonial.is_approved } },
                              {
                                onSuccess: () => notify('Testimonial approval updated.'),
                                onError: (err) => report(err, 'Could not update approval.'),
                              },
                            )
                          }
                        >
                          {testimonial.is_approved ? 'Revoke approval' : 'Approve'}
                        </Button>
                      )}
                      {canEdit && (
                        <Button
                          variant="danger"
                          onClick={() =>
                            deleteTestimonial.mutate(testimonial.id, {
                              onSuccess: () => notify('Testimonial deleted.'),
                              onError: (err) => report(err, 'Could not delete the testimonial.'),
                            })
                          }
                        >
                          Delete
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

      {canEdit && (
        <Card title="New testimonial">
          <form onSubmit={submit} className="space-y-3">
            <label className="space-y-1 text-sm text-gray-700">
              <span className="font-medium">Quote</span>
              <textarea
                className="block min-h-24 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
                value={form.quote}
                onChange={(event) => setForm({ ...form, quote: event.target.value })}
              />
            </label>
            <Field label="Author name" name="testimonial-author" value={form.author_name} onChange={(event) => setForm({ ...form, author_name: event.target.value })} />
            <Field label="Author role" name="testimonial-role" value={form.author_role} onChange={(event) => setForm({ ...form, author_role: event.target.value })} />
            <Field label="Business name" name="testimonial-business" value={form.business_name} onChange={(event) => setForm({ ...form, business_name: event.target.value })} />
            <Button type="submit" loading={createTestimonial.isPending} disabled={!form.quote || !form.author_name}>
              Save testimonial
            </Button>
          </form>
        </Card>
      )}
    </div>
  )
}

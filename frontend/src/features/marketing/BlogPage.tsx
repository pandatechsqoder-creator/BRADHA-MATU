import { useEffect, useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, PageLoader, Select } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useMediaAssets } from '../../lib/marketing/hooks'
import { publicSiteUrl } from '../../lib/publicSite'
import {
  BLOG_STATUSES,
  useBlogPosts,
  useBlogTransition,
  useCreateBlogPost,
  useCreateEditorialItem,
  useCreatePostCategory,
  useCreatePostTag,
  useCreateResourceDownload,
  useDeleteBlogPost,
  useEditorialItems,
  usePostCategories,
  usePostTags,
  useResourceDownloads,
  useUpdateBlogPost,
  useUpdateEditorialItem,
  useUpdateResourceDownload,
  type BlogPost,
  type BlogStatus,
  type EditorialItem,
  type EditorialStatus,
  type ResourceDownload,
} from '../../lib/marketing/blogHooks'

type Tab = 'posts' | 'taxonomy' | 'downloads' | 'editorial'

const tabs: { key: Tab; label: string }[] = [
  { key: 'posts', label: 'Articles' },
  { key: 'taxonomy', label: 'Categories & tags' },
  { key: 'downloads', label: 'Downloads' },
  { key: 'editorial', label: 'Editorial calendar' },
]

const statusTone: Record<BlogStatus, 'gray' | 'blue' | 'amber' | 'green' | 'red'> = {
  draft: 'gray',
  review: 'amber',
  approved: 'blue',
  scheduled: 'blue',
  published: 'green',
  archived: 'red',
}

export function BlogPage() {
  const [tab, setTab] = useState<Tab>('posts')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  function report(err: unknown, fallback: string) {
    setNotice(null)
    setError(err instanceof ApiError ? err.message : fallback)
  }

  function notify(message: string) {
    setError(null)
    setNotice(message)
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Blog & resources"
        description="Articles, categories, downloads and the editorial calendar for the public resource hub."
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      <div className="flex flex-wrap gap-2">
        {tabs.map((item) => (
          <button
            key={item.key}
            type="button"
            data-testid={`blog-tab-${item.key}`}
            onClick={() => setTab(item.key)}
            className={`rounded-full px-4 py-1.5 text-sm font-medium ${
              tab === item.key ? 'bg-brand-700 text-white' : 'bg-white text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50'
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === 'posts' && <PostsSection report={report} notify={notify} />}
      {tab === 'taxonomy' && <TaxonomySection report={report} notify={notify} />}
      {tab === 'downloads' && <DownloadsSection report={report} notify={notify} />}
      {tab === 'editorial' && <EditorialSection report={report} notify={notify} />}
    </div>
  )
}

interface SectionProps {
  report: (err: unknown, fallback: string) => void
  notify: (message: string) => void
}

function PostsSection({ report, notify }: SectionProps) {
  const posts = useBlogPosts()
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  if (posts.isLoading) return <PageLoader label="Loading articles…" />
  if (posts.isError) return <Alert kind="error">{(posts.error as ApiError).message}</Alert>

  const data = posts.data
  const selected = data?.posts.find((post) => post.id === selectedId) ?? null
  const counts = data?.status_counts ?? {}

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2 text-xs text-gray-600">
          {BLOG_STATUSES.map((status) => (
            <Badge key={status} tone={statusTone[status]}>
              {status}: {counts[status] ?? 0}
            </Badge>
          ))}
        </div>
        <Button
          type="button"
          data-testid="blog-new-post"
          onClick={() => {
            setCreating(true)
            setSelectedId(null)
          }}
        >
          New article
        </Button>
      </div>

      {creating && (
        <PostEditor
          post={null}
          categories={data?.categories ?? []}
          onSaved={(post) => {
            setCreating(false)
            setSelectedId(post.id)
            notify('Article saved as a draft.')
          }}
          onError={(err) => report(err, 'Could not save the article.')}
        />
      )}

      {data && data.posts.length === 0 && !creating && (
        <EmptyState title="No articles yet" description="Create the first article to start the resource hub." />
      )}

      {data && data.posts.length > 0 && (
        <Card title="Articles">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm" data-testid="blog-posts-table">
              <thead>
                <tr className="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500">
                  <th className="py-2 pr-4">Title</th>
                  <th className="py-2 pr-4">Category</th>
                  <th className="py-2 pr-4">Author</th>
                  <th className="py-2 pr-4">Status</th>
                  <th className="py-2 pr-4">Updated</th>
                  <th className="py-2" />
                </tr>
              </thead>
              <tbody>
                {data.posts.map((post) => (
                  <tr key={post.id} className="border-b border-gray-100" data-testid={`blog-post-row-${post.slug}`}>
                    <td className="py-2 pr-4 font-medium text-gray-900">{post.title}</td>
                    <td className="py-2 pr-4 text-gray-600">{post.category?.name ?? '—'}</td>
                    <td className="py-2 pr-4 text-gray-600">{post.author_name}</td>
                    <td className="py-2 pr-4">
                      <Badge tone={statusTone[post.status]}>{post.status}</Badge>
                    </td>
                    <td className="py-2 pr-4 text-gray-500">
                      {post.updated_at ? new Date(post.updated_at).toLocaleDateString() : '—'}
                    </td>
                    <td className="py-2 text-right">
                      <Button
                        type="button"
                        variant="secondary"
                        onClick={() => {
                          setCreating(false)
                          setSelectedId(post.id)
                        }}
                      >
                        Edit
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {selected && (
        <PostEditor
          key={selected.id}
          post={selected}
          categories={data?.categories ?? []}
          onSaved={() => notify('Article saved.')}
          onError={(err) => report(err, 'Could not save the article.')}
          onClose={() => setSelectedId(null)}
          onDeleted={() => {
            setSelectedId(null)
            notify('Article deleted.')
          }}
        />
      )}
    </div>
  )
}

function PostEditor({
  post,
  categories,
  onSaved,
  onError,
  onClose,
  onDeleted,
}: {
  post: BlogPost | null
  categories: { id: string; name: string; slug: string }[]
  onSaved: (post: BlogPost) => void
  onError: (err: unknown) => void
  onClose?: () => void
  onDeleted?: () => void
}) {
  const createPost = useCreateBlogPost()
  const updatePost = useUpdateBlogPost(post?.id ?? '')
  const transition = useBlogTransition(post?.id ?? '')
  const removePost = useDeleteBlogPost(post?.id ?? '')
  const [scheduleAt, setScheduleAt] = useState('')
  const [form, setForm] = useState({
    title: post?.title ?? '',
    slug: post?.slug ?? '',
    excerpt: post?.excerpt ?? '',
    body: post?.body ?? '',
    format: post?.format ?? 'markdown',
    category_id: post?.category?.id ?? categories[0]?.id ?? '',
    tags: post?.tags.map((tag) => tag.name).join(', ') ?? '',
    author_name: post?.author_name ?? 'BRATHAMATU Editorial',
    seo_title: post?.seo_title ?? '',
    seo_description: post?.seo_description ?? '',
    noindex: post?.noindex ?? false,
  })

  useEffect(() => {
    if (!post) return
    setForm({
      title: post.title,
      slug: post.slug,
      excerpt: post.excerpt ?? '',
      body: post.body,
      format: post.format,
      category_id: post.category?.id ?? categories[0]?.id ?? '',
      tags: post.tags.map((tag) => tag.name).join(', '),
      author_name: post.author_name,
      seo_title: post.seo_title ?? '',
      seo_description: post.seo_description ?? '',
      noindex: post.noindex,
    })
  }, [post, categories])

  function save(event: FormEvent) {
    event.preventDefault()
    const payload = {
      title: form.title,
      slug: form.slug || null,
      excerpt: form.excerpt || null,
      body: form.body,
      format: form.format as 'markdown' | 'plain',
      category_id: form.category_id || null,
      tags: form.tags
        .split(',')
        .map((tag) => tag.trim())
        .filter(Boolean),
      author_name: form.author_name || null,
      seo_title: form.seo_title || null,
      seo_description: form.seo_description || null,
      noindex: form.noindex,
    }

    if (post) {
      updatePost.mutate(payload, {
        onSuccess: (response) => onSaved(response.post),
        onError,
      })
    } else {
      createPost.mutate(payload, {
        onSuccess: (response) => onSaved(response.post),
        onError,
      })
    }
  }

  function act(action: string, payload?: Record<string, unknown>) {
    transition.mutate(
      { action, payload },
      {
        onSuccess: () => onSaved(post as BlogPost),
        onError,
      },
    )
  }

  return (
    <Card title={post ? `Edit: ${post.title}` : 'New article'}
      actions={
        <div className="flex items-center gap-2">
          <span data-testid="blog-post-status">
            {post && <Badge tone={statusTone[post.status]}>{post.status}</Badge>}
          </span>
          {onClose && (
            <Button type="button" variant="secondary" onClick={onClose}>
              Close
            </Button>
          )}
        </div>
      }
    >
      <form className="space-y-4" onSubmit={save}>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field
            label="Title"
            name="title"
            data-testid="blog-post-title"
            value={form.title}
            onChange={(event) => setForm({ ...form, title: event.target.value })}
            required
          />
          <Field
            label="Slug"
            name="slug"
            data-testid="blog-post-slug"
            hint="Leave empty to derive from the title. Must be unique."
            value={form.slug}
            onChange={(event) => setForm({ ...form, slug: event.target.value })}
          />
          <Select
            label="Primary category"
            name="category_id"
            data-testid="blog-post-category"
            value={form.category_id}
            onChange={(event) => setForm({ ...form, category_id: event.target.value })}
          >
            <option value="">No category</option>
            {categories.map((category) => (
              <option key={category.id} value={category.id}>
                {category.name}
              </option>
            ))}
          </Select>
          <Select
            label="Format"
            name="format"
            value={form.format}
            onChange={(event) => setForm({ ...form, format: event.target.value as 'markdown' | 'plain' })}
          >
            <option value="markdown">Markdown</option>
            <option value="plain">Plain text</option>
          </Select>
        </div>

        <Field
          label="Excerpt"
          name="excerpt"
          data-testid="blog-post-excerpt"
          hint="Used in listings and as the meta description fallback."
          value={form.excerpt}
          onChange={(event) => setForm({ ...form, excerpt: event.target.value })}
        />

        <Field label="Body" name="body" hint="Markdown is rendered to HTML on the public site. Raw HTML is stripped.">
          <textarea
            id="body"
            data-testid="blog-post-body"
            className="block w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
            rows={12}
            value={form.body}
            onChange={(event) => setForm({ ...form, body: event.target.value })}
            required
          />
        </Field>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field
            label="Tags"
            name="tags"
            data-testid="blog-post-tags"
            hint="Comma separated. New tags are created automatically."
            value={form.tags}
            onChange={(event) => setForm({ ...form, tags: event.target.value })}
          />
          <Field
            label="Author name"
            name="author_name"
            value={form.author_name}
            onChange={(event) => setForm({ ...form, author_name: event.target.value })}
          />
          <Field
            label="SEO title"
            name="seo_title"
            value={form.seo_title}
            onChange={(event) => setForm({ ...form, seo_title: event.target.value })}
          />
          <Field
            label="SEO description"
            name="seo_description"
            data-testid="blog-post-seo-description"
            value={form.seo_description}
            onChange={(event) => setForm({ ...form, seo_description: event.target.value })}
          />
        </div>

        <label className="flex items-center gap-2 text-sm text-gray-700">
          <input
            type="checkbox"
            checked={form.noindex}
            onChange={(event) => setForm({ ...form, noindex: event.target.checked })}
          />
          Exclude this article from search indexing
        </label>

        <div className="flex flex-wrap items-center gap-2">
          <Button type="submit" data-testid="blog-save-post" disabled={createPost.isPending || updatePost.isPending}>
            {post ? 'Save article' : 'Create draft'}
          </Button>

          {post && post.status === 'draft' && (
            <Button type="button" variant="secondary" data-testid="blog-action-submit-review" onClick={() => act('submit-review')}>
              Submit for review
            </Button>
          )}
          {post && post.status === 'review' && (
            <>
              <Button type="button" data-testid="blog-action-approve" onClick={() => act('approve')}>
                Approve
              </Button>
              <Button
                type="button"
                variant="secondary"
                data-testid="blog-action-reject"
                onClick={() => {
                  const reason = window.prompt('Rejection reason')
                  if (reason) act('reject', { reason })
                }}
              >
                Reject
              </Button>
            </>
          )}
          {post && post.status === 'approved' && (
            <>
              <input
                type="datetime-local"
                data-testid="blog-schedule-at"
                className="rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm"
                value={scheduleAt}
                onChange={(event) => setScheduleAt(event.target.value)}
              />
              <Button
                type="button"
                variant="secondary"
                data-testid="blog-action-schedule"
                onClick={() => scheduleAt && act('schedule', { scheduled_at: new Date(scheduleAt).toISOString() })}
              >
                Schedule
              </Button>
              <Button type="button" data-testid="blog-action-publish" onClick={() => act('publish')}>
                Publish now
              </Button>
            </>
          )}
          {post && post.status === 'scheduled' && (
            <Button type="button" data-testid="blog-action-publish" onClick={() => act('publish')}>
              Publish now
            </Button>
          )}
          {post && post.status === 'published' && (
            <>
              <Button type="button" variant="secondary" data-testid="blog-action-unpublish" onClick={() => act('unpublish')}>
                Unpublish
              </Button>
              <a
                href={publicSiteUrl(`/resources/${post.slug}`)}
                target="_blank"
                rel="noreferrer"
                className="text-sm font-medium text-brand-700 hover:underline"
                data-testid="blog-post-link"
              >
                View on public site ↗
              </a>
            </>
          )}
          {post && (post.status === 'draft' || post.status === 'published') && (
            <Button
              type="button"
              variant="secondary"
              onClick={() => {
                if (post.status === 'published' && !window.confirm('Archive this published article?')) return
                act('archive')
              }}
            >
              Archive
            </Button>
          )}
          {post && (
            <Button
              type="button"
              variant="danger"
              onClick={() => {
                if (!window.confirm('Delete this article? This cannot be undone from the interface.')) return
                removePost.mutate(undefined, {
                  onSuccess: () => {
                    onClose?.()
                    onDeleted?.()
                  },
                  onError,
                })
              }}
            >
              Delete
            </Button>
          )}
        </div>

        {post && (
          <p className="text-xs text-gray-500">
            Published articles emit Article JSON-LD built from the visible title, excerpt, author and category.
            Publishing is blocked if the markup fails validation.
          </p>
        )}
      </form>
    </Card>
  )
}

function TaxonomySection({ report, notify }: SectionProps) {
  const categories = usePostCategories()
  const tags = usePostTags()
  const createCategory = useCreatePostCategory()
  const createTag = useCreatePostTag()
  const [category, setCategory] = useState({ name: '', slug: '', description: '' })
  const [tag, setTag] = useState({ name: '', slug: '' })

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <Card title="Categories">
        <form
          className="mb-4 grid grid-cols-1 gap-3"
          onSubmit={(event) => {
            event.preventDefault()
            createCategory.mutate(
              { name: category.name, slug: category.slug || null, description: category.description || null },
              {
                onSuccess: () => {
                  setCategory({ name: '', slug: '', description: '' })
                  notify('Category created.')
                },
                onError: (err) => report(err, 'Could not create the category.'),
              },
            )
          }}
        >
          <Field
            label="Name"
            name="category-name"
            data-testid="category-name"
            value={category.name}
            onChange={(event) => setCategory({ ...category, name: event.target.value })}
            required
          />
          <Field
            label="Slug"
            name="category-slug"
            hint="Optional; derived from the name when empty."
            value={category.slug}
            onChange={(event) => setCategory({ ...category, slug: event.target.value })}
          />
          <Field
            label="Description"
            name="category-description"
            value={category.description}
            onChange={(event) => setCategory({ ...category, description: event.target.value })}
          />
          <div>
            <Button type="submit" data-testid="category-create" disabled={createCategory.isPending}>
              Create category
            </Button>
          </div>
        </form>
        {categories.isLoading ? (
          <PageLoader label="Loading categories…" />
        ) : (
          <ul className="space-y-2 text-sm">
            {(categories.data?.categories ?? []).map((item) => (
              <li key={item.id} className="flex items-center justify-between border-b border-gray-100 pb-2">
                <span>
                  <span className="font-medium text-gray-900">{item.name}</span>{' '}
                  <code className="text-xs text-gray-500">{item.slug}</code>
                </span>
                <Badge tone="gray">{item.post_count} live</Badge>
              </li>
            ))}
            {(categories.data?.categories ?? []).length === 0 && <li className="text-gray-500">No categories yet.</li>}
          </ul>
        )}
      </Card>

      <Card title="Tags">
        <form
          className="mb-4 grid grid-cols-1 gap-3"
          onSubmit={(event) => {
            event.preventDefault()
            createTag.mutate(
              { name: tag.name, slug: tag.slug || null },
              {
                onSuccess: () => {
                  setTag({ name: '', slug: '' })
                  notify('Tag created.')
                },
                onError: (err) => report(err, 'Could not create the tag.'),
              },
            )
          }}
        >
          <Field
            label="Name"
            name="tag-name"
            data-testid="tag-name"
            value={tag.name}
            onChange={(event) => setTag({ ...tag, name: event.target.value })}
            required
          />
          <Field
            label="Slug"
            name="tag-slug"
            hint="Optional; derived from the name when empty."
            value={tag.slug}
            onChange={(event) => setTag({ ...tag, slug: event.target.value })}
          />
          <div>
            <Button type="submit" data-testid="tag-create" disabled={createTag.isPending}>
              Create tag
            </Button>
          </div>
        </form>
        {tags.isLoading ? (
          <PageLoader label="Loading tags…" />
        ) : (
          <ul className="space-y-2 text-sm">
            {(tags.data?.tags ?? []).map((item) => (
              <li key={item.id} className="flex items-center justify-between border-b border-gray-100 pb-2">
                <span>
                  <span className="font-medium text-gray-900">{item.name}</span>{' '}
                  <code className="text-xs text-gray-500">{item.slug}</code>
                </span>
                <Badge tone="gray">{item.post_count} posts</Badge>
              </li>
            ))}
            {(tags.data?.tags ?? []).length === 0 && <li className="text-gray-500">No tags yet.</li>}
          </ul>
        )}
      </Card>
    </div>
  )
}

function DownloadsSection({ report, notify }: SectionProps) {
  const resources = useResourceDownloads()
  const assets = useMediaAssets({})
  const createResource = useCreateResourceDownload()
  const [form, setForm] = useState({
    title: '',
    slug: '',
    description: '',
    media_asset_id: '',
    requires_consent: true,
    is_published: false,
  })

  return (
    <div className="space-y-4">
      <Card title="New download">
        <form
          className="grid grid-cols-1 gap-3 md:grid-cols-2"
          onSubmit={(event) => {
            event.preventDefault()
            createResource.mutate(
              {
                title: form.title,
                slug: form.slug || null,
                description: form.description || null,
                media_asset_id: form.media_asset_id || null,
                requires_consent: form.requires_consent,
                is_published: form.is_published,
              },
              {
                onSuccess: () => {
                  setForm({ title: '', slug: '', description: '', media_asset_id: '', requires_consent: true, is_published: false })
                  notify('Download created.')
                },
                onError: (err) => report(err, 'Could not create the download.'),
              },
            )
          }}
        >
          <Field
            label="Title"
            name="resource-title"
            data-testid="resource-title"
            value={form.title}
            onChange={(event) => setForm({ ...form, title: event.target.value })}
            required
          />
          <Field
            label="Slug"
            name="resource-slug"
            hint="Optional; derived from the title when empty."
            value={form.slug}
            onChange={(event) => setForm({ ...form, slug: event.target.value })}
          />
          <Field
            label="Description"
            name="resource-description"
            value={form.description}
            onChange={(event) => setForm({ ...form, description: event.target.value })}
          />
          <Select
            label="File"
            name="resource-asset"
            value={form.media_asset_id}
            onChange={(event) => setForm({ ...form, media_asset_id: event.target.value })}
          >
            <option value="">No file attached</option>
            {(assets.data?.assets ?? []).map((asset) => (
              <option key={asset.id} value={asset.id}>
                {asset.filename}
              </option>
            ))}
          </Select>
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={form.requires_consent}
              onChange={(event) => setForm({ ...form, requires_consent: event.target.checked })}
            />
            Require consent before download
          </label>
          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={form.is_published}
              onChange={(event) => setForm({ ...form, is_published: event.target.checked })}
            />
            Publish immediately
          </label>
          <div className="md:col-span-2">
            <Button type="submit" data-testid="resource-create" disabled={createResource.isPending}>
              Create download
            </Button>
          </div>
        </form>
      </Card>

      <Card title="Downloads">
        {resources.isLoading ? (
          <PageLoader label="Loading downloads…" />
        ) : (
          <div className="space-y-2">
            {(resources.data?.resource_downloads ?? []).map((resource) => (
              <ResourceRow key={resource.id} resource={resource} notify={notify} report={report} />
            ))}
            {(resources.data?.resource_downloads ?? []).length === 0 && (
              <p className="text-sm text-gray-500">No downloads yet.</p>
            )}
          </div>
        )}
      </Card>
    </div>
  )
}

function ResourceRow({
  resource,
  notify,
  report,
}: {
  resource: ResourceDownload
  notify: (message: string) => void
  report: (err: unknown, fallback: string) => void
}) {
  const update = useUpdateResourceDownload(resource.id)

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3 text-sm">
      <div>
        <p className="font-medium text-gray-900">{resource.title}</p>
        <p className="text-xs text-gray-500">
          /resources/downloads/{resource.slug} · {resource.download_count} downloads ·{' '}
          {resource.requires_consent ? 'consent required' : 'open download'}
        </p>
      </div>
      <div className="flex items-center gap-2">
        <Badge tone={resource.is_published ? 'green' : 'gray'}>{resource.is_published ? 'published' : 'draft'}</Badge>
        <Button
          type="button"
          variant="secondary"
          disabled={update.isPending}
          onClick={() =>
            update.mutate(
              { is_published: !resource.is_published },
              {
                onSuccess: () => notify(resource.is_published ? 'Download unpublished.' : 'Download published.'),
                onError: (err) => report(err, 'Could not update the download.'),
              },
            )
          }
        >
          {resource.is_published ? 'Unpublish' : 'Publish'}
        </Button>
      </div>
    </div>
  )
}

const editorialStatuses: EditorialStatus[] = ['planned', 'in_progress', 'review', 'done']

const editorialTone: Record<EditorialStatus, 'gray' | 'blue' | 'amber' | 'green'> = {
  planned: 'gray',
  in_progress: 'blue',
  review: 'amber',
  done: 'green',
}

function EditorialSection({ report, notify }: SectionProps) {
  const items = useEditorialItems()
  const createItem = useCreateEditorialItem()
  const [form, setForm] = useState({ title: '', brief: '', due_on: '', status: 'planned' as EditorialStatus })

  return (
    <div className="space-y-4">
      <Card title="New editorial item">
        <form
          className="grid grid-cols-1 gap-3 md:grid-cols-2"
          onSubmit={(event) => {
            event.preventDefault()
            createItem.mutate(
              { title: form.title, brief: form.brief || null, due_on: form.due_on || null, status: form.status },
              {
                onSuccess: () => {
                  setForm({ title: '', brief: '', due_on: '', status: 'planned' })
                  notify('Editorial item created.')
                },
                onError: (err) => report(err, 'Could not create the editorial item.'),
              },
            )
          }}
        >
          <Field
            label="Title"
            name="editorial-title"
            data-testid="editorial-title"
            value={form.title}
            onChange={(event) => setForm({ ...form, title: event.target.value })}
            required
          />
          <Field
            label="Due date"
            name="editorial-due"
            type="date"
            value={form.due_on}
            onChange={(event) => setForm({ ...form, due_on: event.target.value })}
          />
          <Field
            label="Brief"
            name="editorial-brief"
            value={form.brief}
            onChange={(event) => setForm({ ...form, brief: event.target.value })}
          />
          <Select
            label="Status"
            name="editorial-status"
            value={form.status}
            onChange={(event) => setForm({ ...form, status: event.target.value as EditorialStatus })}
          >
            {editorialStatuses.map((status) => (
              <option key={status} value={status}>
                {status.replace('_', ' ')}
              </option>
            ))}
          </Select>
          <div className="md:col-span-2">
            <Button type="submit" data-testid="editorial-create" disabled={createItem.isPending}>
              Add to calendar
            </Button>
          </div>
        </form>
      </Card>

      <Card title="Editorial calendar">
        {items.isLoading ? (
          <PageLoader label="Loading editorial items…" />
        ) : (
          <ul className="space-y-2">
            {(items.data?.editorial_items ?? []).map((item) => (
              <EditorialRow key={item.id} item={item} notify={notify} report={report} />
            ))}
            {(items.data?.editorial_items ?? []).length === 0 && (
              <li className="text-sm text-gray-500">No editorial items yet.</li>
            )}
          </ul>
        )}
      </Card>
    </div>
  )
}

function EditorialRow({
  item,
  notify,
  report,
}: {
  item: EditorialItem
  notify: (message: string) => void
  report: (err: unknown, fallback: string) => void
}) {
  const update = useUpdateEditorialItem(item.id)

  return (
    <li className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-3 text-sm">
      <div>
        <p className="font-medium text-gray-900">{item.title}</p>
        <p className="text-xs text-gray-500">
          {item.due_on ? `Due ${item.due_on}` : 'No due date'}
          {item.blog_post_title ? ` · ${item.blog_post_title}` : ''}
        </p>
      </div>
      <div className="flex items-center gap-2">
        <Badge tone={editorialTone[item.status]}>{item.status.replace('_', ' ')}</Badge>
        <select
          aria-label={`Status for ${item.title}`}
          className="rounded-lg border border-gray-300 px-2 py-1 text-sm"
          value={item.status}
          onChange={(event) => {
            update.mutate(
              { status: event.target.value as EditorialStatus },
              {
                onSuccess: () => notify('Editorial status updated.'),
                onError: (err) => report(err, 'Could not update the editorial item.'),
              },
            )
          }}
        >
          {editorialStatuses.map((status) => (
            <option key={status} value={status}>
              {status.replace('_', ' ')}
            </option>
          ))}
        </select>
      </div>
    </li>
  )
}

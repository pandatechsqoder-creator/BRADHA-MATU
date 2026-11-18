import { useMemo, useState } from 'react'
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
  Table,
} from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import { useMetaIntegration } from '../../lib/marketing/metaHooks'
import {
  useCreateSocialPost,
  useDeleteSocialPost,
  useSocialPostAction,
  useSocialPosts,
  useUpdateSocialPost,
  type SocialPost,
  type SocialPostAction,
  type SocialPostInput,
} from '../../lib/marketing/socialHooks'

const statuses = ['draft', 'review', 'approved', 'rejected', 'scheduled', 'published', 'failed'] as const

const emptyForm = {
  pageId: '',
  instagramId: '',
  caption: '',
  mediaId: '',
  linkUrl: '',
}

function toneFor(status: string): 'green' | 'red' | 'amber' | 'blue' | 'gray' {
  switch (status) {
    case 'published':
    case 'approved':
      return 'green'
    case 'failed':
    case 'rejected':
      return 'red'
    case 'review':
    case 'scheduled':
      return 'amber'
    case 'draft':
      return 'gray'
    default:
      return 'blue'
  }
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleString() : '—'
}

function targetLabel(post: SocialPost): string {
  if (post.instagram_account) return `Instagram @${post.instagram_account.username ?? post.instagram_account.id}`
  if (post.page) return `Facebook · ${post.page.name}`
  return 'No target selected'
}

export function SocialPostsPage() {
  const user = useCurrentUser().data
  const canApprove = user?.permissions.includes('social.approve') ?? false

  const [filter, setFilter] = useState('')
  const posts = useSocialPosts(filter || undefined)
  const meta = useMetaIntegration()
  const createPost = useCreateSocialPost()
  const updatePost = useUpdateSocialPost()
  const deletePost = useDeleteSocialPost()
  const action = useSocialPostAction()

  const [form, setForm] = useState(emptyForm)
  const [editingId, setEditingId] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const options = posts.data?.options
  const pages = options?.pages ?? []
  const selectedPage = pages.find((page) => page.id === form.pageId)
  const instagramAccounts = selectedPage?.instagram_accounts ?? []
  const media = useMemo(
    () => (options?.media_assets ?? []).filter((asset) => asset.mime.startsWith('image/')),
    [options],
  )

  const busy = createPost.isPending || updatePost.isPending || action.isPending || deletePost.isPending
  const metaConnected = meta.data?.connection?.status === 'connected'
  const pagesAvailable = pages.length > 0

  function report(err: unknown, fallback: string) {
    setNotice(null)
    setError(err instanceof ApiError ? err.message : fallback)
  }

  function resetForm() {
    setEditingId(null)
    setForm(emptyForm)
  }

  function startEdit(post: SocialPost) {
    setEditingId(post.id)
    setForm({
      pageId: post.page?.id ?? '',
      instagramId: post.instagram_account?.id ?? '',
      caption: post.caption,
      mediaId: post.media_asset?.id ?? '',
      linkUrl: post.link_url ?? '',
    })
    setError(null)
    setNotice(null)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  function saveDraft() {
    setError(null)
    setNotice(null)

    if (form.caption.trim() === '') {
      setError('Write a caption before saving the post.')
      return
    }

    const input: SocialPostInput = {
      caption: form.caption.trim(),
      meta_page_id: form.pageId || null,
      meta_instagram_account_id: form.instagramId || null,
      media_asset_id: form.mediaId || null,
      link_url: form.linkUrl.trim() || null,
    }

    const onSuccess = (message: string) => {
      setNotice(message)
      resetForm()
    }

    if (editingId) {
      updatePost.mutate(
        { id: editingId, input },
        { onSuccess: () => onSuccess('Draft updated.'), onError: (err) => report(err, 'Could not save the draft.') },
      )
    } else {
      createPost.mutate(input, {
        onSuccess: () => onSuccess('Draft saved. Submit it for review when it is ready.'),
        onError: (err) => report(err, 'Could not save the draft.'),
      })
    }
  }

  function runAction(post: SocialPost, next: SocialPostAction, payload?: Record<string, unknown>, success?: string) {
    setError(null)
    setNotice(null)
    action.mutate(
      { id: post.id, action: next, payload },
      {
        onSuccess: () => setNotice(success ?? `Post ${next.replace('-', ' ')}.`),
        onError: (err) => report(err, 'The post action failed.'),
      },
    )
  }

  function approve(post: SocialPost) {
    runAction(post, 'approve', {}, 'Post approved. You can schedule it or publish now.')
  }

  function reject(post: SocialPost) {
    const notes = window.prompt('Why is this post being rejected? The author will see this reason.')
    if (notes === null) return
    if (notes.trim() === '') {
      setError('A rejection reason is required.')
      return
    }
    runAction(post, 'reject', { notes: notes.trim() }, 'Post rejected and returned to the author.')
  }

  function schedule(post: SocialPost) {
    const suggestion = new Date(Date.now() + 24 * 60 * 60 * 1000)
    suggestion.setSeconds(0, 0)
    const answer = window.prompt(
      'Schedule for (date and time). Use your local time.',
      suggestion.toISOString().slice(0, 16),
    )
    if (answer === null) return
    const at = new Date(answer)
    if (Number.isNaN(at.getTime())) {
      setError('Enter a valid date and time to schedule this post.')
      return
    }
    runAction(post, 'schedule', { scheduled_at: at.toISOString() }, 'Post scheduled. The scheduler publishes it at the set time.')
  }

  function publish(post: SocialPost) {
    if (!window.confirm(`Publish this post to ${targetLabel(post)} now? This cannot be undone from the admin.`)) {
      return
    }
    runAction(post, 'publish', {}, 'Post published to Meta.')
  }

  function remove(post: SocialPost) {
    if (!window.confirm('Delete this draft post?')) return
    setError(null)
    setNotice(null)
    deletePost.mutate(post.id, {
      onSuccess: () => setNotice('Draft deleted.'),
      onError: (err) => report(err, 'Could not delete the draft.'),
    })
  }

  if (posts.isLoading) return <PageLoader label="Loading social posts…" />

  if (posts.isError) {
    return <Alert kind="error">{(posts.error as ApiError).message}</Alert>
  }

  return (
    <div className="space-y-6" data-testid="social-posts-page">
      <PageHeader
        title="Social publishing"
        description="Draft Facebook Page and Instagram posts, route them through review, then schedule or publish with Meta. Publishing needs approval by a second user."
        actions={
          editingId ? (
            <Button variant="secondary" onClick={resetForm} data-testid="social-cancel-edit">
              Cancel edit
            </Button>
          ) : undefined
        }
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      {!metaConnected && (
        <Alert kind="info">
          <p className="font-medium">Meta is not connected</p>
          <p className="mt-1">
            Drafting and approvals work without a Meta connection, but publishing stays blocked until an account is
            connected. <a className="font-medium underline" href="/platform/marketing/integrations/meta">Open Meta integrations</a>{' '}
            to connect and sync Pages.
          </p>
        </Alert>
      )}

      <Card title={editingId ? 'Edit draft' : 'New post'}>
        <div className="space-y-4" data-testid="social-composer">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Select
              label="Facebook Page"
              value={form.pageId}
              onChange={(event) => {
                const pageId = event.target.value
                const page = pages.find((item) => item.id === pageId)
                const keepInstagram = page?.instagram_accounts.some((account) => account.id === form.instagramId)
                setForm((current) => ({
                  ...current,
                  pageId,
                  instagramId: keepInstagram ? current.instagramId : '',
                }))
              }}
              hint={pagesAvailable ? undefined : 'Sync Meta assets to choose a Page.'}
              data-testid="social-page-select"
            >
              <option value="">No Page (draft only)</option>
              {pages.map((page) => (
                <option key={page.id} value={page.id}>{page.name}</option>
              ))}
            </Select>

            <Select
              label="Channel"
              value={form.instagramId}
              onChange={(event) => setForm((current) => ({ ...current, instagramId: event.target.value }))}
              disabled={instagramAccounts.length === 0}
              hint={instagramAccounts.length === 0 ? 'No Instagram account is linked to the selected Page.' : undefined}
              data-testid="social-instagram-select"
            >
              <option value="">Facebook Page feed</option>
              {instagramAccounts.map((account) => (
                <option key={account.id} value={account.id}>Instagram @{account.username ?? account.id}</option>
              ))}
            </Select>
          </div>

          <div>
            <label htmlFor="social-caption" className="block text-sm font-medium text-gray-700">Caption</label>
            <textarea
              id="social-caption"
              value={form.caption}
              onChange={(event) => setForm((current) => ({ ...current, caption: event.target.value }))}
              rows={4}
              maxLength={2200}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
              placeholder="What should business owners know about stock-taking this week?"
              data-testid="social-caption"
            />
            <p className="mt-1 text-xs text-gray-500">{form.caption.length} / 2200 characters. Contact details in posts must match approved business settings.</p>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field
              label="Link URL (optional)"
              type="url"
              value={form.linkUrl}
              onChange={(event) => setForm((current) => ({ ...current, linkUrl: event.target.value }))}
              placeholder="https://brathamatu.com/stock-taking"
              data-testid="social-link-url"
            />
            <Select
              label="Image (required for Instagram)"
              value={form.mediaId}
              onChange={(event) => setForm((current) => ({ ...current, mediaId: event.target.value }))}
              hint={media.length === 0 ? 'Upload a JPEG in the media library first.' : 'Instagram feed posts require a JPEG image.'}
              data-testid="social-media-select"
            >
              <option value="">No image</option>
              {media.map((asset) => (
                <option key={asset.id} value={asset.id}>{asset.filename}</option>
              ))}
            </Select>
          </div>

          <div className="flex flex-wrap gap-2">
            <Button onClick={saveDraft} loading={createPost.isPending || updatePost.isPending} disabled={busy && !(createPost.isPending || updatePost.isPending)} data-testid="social-save-draft">
              {editingId ? 'Save changes' : 'Save draft'}
            </Button>
          </div>
          {form.instagramId && !form.mediaId && (
            <p className="text-sm text-amber-700">Instagram posts need a JPEG image before they can be saved.</p>
          )}
        </div>
      </Card>

      <Card
        title="Posts"
        actions={
          <Select
            label="Filter"
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
            data-testid="social-status-filter"
          >
            <option value="">All statuses</option>
            {statuses.map((status) => (
              <option key={status} value={status}>{status}</option>
            ))}
          </Select>
        }
      >
        <div data-testid="social-posts-table">
          <Table
            headers={['Post', 'Status', 'Timing', 'Approval', 'Actions']}
            empty={<EmptyState title="No posts yet" description="Save a draft above to start the social publishing workflow." />}
          >
            {(posts.data?.posts ?? []).map((post) => {
              const latestApproval = post.approvals[0]
              return (
                <tr key={post.id} data-testid={`social-post-row-${post.id}`}>
                  <td className="max-w-md px-4 py-3">
                    <p className="font-medium text-gray-900">{post.caption.length > 140 ? `${post.caption.slice(0, 140)}…` : post.caption}</p>
                    <p className="text-xs text-gray-500">{targetLabel(post)}</p>
                    {post.link_url && <p className="truncate text-xs text-brand-700">{post.link_url}</p>}
                    {post.error && <p className="mt-1 text-xs text-red-600">{post.error}</p>}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3">
                    <span data-testid={`social-post-status-${post.id}`}>
                      <Badge tone={toneFor(post.status)}>{post.status}</Badge>
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-xs text-gray-600">
                    {post.status === 'scheduled' && <p>Scheduled {formatDate(post.scheduled_at)}</p>}
                    {post.status === 'published' && <p>Published {formatDate(post.published_at)}</p>}
                    {post.status !== 'scheduled' && post.status !== 'published' && <p>Created {formatDate(post.created_at)}</p>}
                    {post.external_id && <p className="text-gray-500">Meta ID {post.external_id}</p>}
                  </td>
                  <td className="px-4 py-3 text-xs text-gray-600">
                    {latestApproval ? (
                      <>
                        <p className="capitalize">{latestApproval.decision}{latestApproval.decided_by ? ` · ${latestApproval.decided_by}` : ''}</p>
                        {latestApproval.notes && <p className="text-gray-500">{latestApproval.notes}</p>}
                      </>
                    ) : '—'}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      {(post.status === 'draft' || post.status === 'rejected') && (
                        <>
                          <Button variant="secondary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => startEdit(post)} data-testid={`social-edit-${post.id}`}>
                            Edit
                          </Button>
                          <Button variant="secondary" className="!px-2 !py-1 text-xs" disabled={busy || !post.page} onClick={() => runAction(post, 'submit-review', {}, 'Post submitted for review.')} data-testid={`social-submit-${post.id}`}>
                            Submit for review
                          </Button>
                          <Button variant="danger" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => remove(post)} data-testid={`social-delete-${post.id}`}>
                            Delete
                          </Button>
                        </>
                      )}
                      {post.status === 'review' && canApprove && (
                        <>
                          <Button variant="secondary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => approve(post)} data-testid={`social-approve-${post.id}`}>
                            Approve
                          </Button>
                          <Button variant="danger" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => reject(post)} data-testid={`social-reject-${post.id}`}>
                            Reject
                          </Button>
                        </>
                      )}
                      {post.status === 'review' && !canApprove && <span className="text-xs text-gray-500">Awaiting approver</span>}
                      {post.status === 'approved' && (
                        <>
                          <Button variant="secondary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => schedule(post)} data-testid={`social-schedule-${post.id}`}>
                            Schedule
                          </Button>
                          <Button variant="primary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => publish(post)} data-testid={`social-publish-${post.id}`}>
                            Publish now
                          </Button>
                        </>
                      )}
                      {post.status === 'scheduled' && (
                        <Button variant="primary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => publish(post)} data-testid={`social-publish-${post.id}`}>
                          Publish now
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              )
            })}
          </Table>
        </div>
        <p className="mt-3 text-xs text-gray-500">
          Separating authoring, approval and publishing prevents a single user from pushing unreviewed content to Meta.
        </p>
      </Card>
    </div>
  )
}

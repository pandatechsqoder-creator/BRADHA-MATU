import { useRef, useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, PageLoader, Select } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { formatDateTime } from '../../lib/formatting'
import {
  useCreateMediaFolder,
  useDeleteMediaAsset,
  useDeleteMediaFolder,
  useMediaAsset,
  useMediaAssets,
  useMediaFolders,
  useReplaceMediaAsset,
  useUpdateMediaAsset,
  useUploadMedia,
  type MediaAsset,
} from '../../lib/marketing/hooks'

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function MediaLibraryPage() {
  const [folder, setFolder] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [selectedId, setSelectedId] = useState<string | undefined>()
  const [uploadFile, setUploadFile] = useState<File | null>(null)
  const [uploadAlt, setUploadAlt] = useState('')
  const [uploadCaption, setUploadCaption] = useState('')
  const [folderName, setFolderName] = useState('')
  const [folderParent, setFolderParent] = useState('')
  const [altText, setAltText] = useState('')
  const [caption, setCaption] = useState('')
  const [description, setDescription] = useState('')
  const [assetFolder, setAssetFolder] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const replaceInput = useRef<HTMLInputElement>(null)

  const folders = useMediaFolders()
  const assets = useMediaAssets({ folder: folder || undefined, search: search || undefined, page })
  const detail = useMediaAsset(selectedId)
  const createFolder = useCreateMediaFolder()
  const deleteFolder = useDeleteMediaFolder()
  const upload = useUploadMedia()
  const updateAsset = useUpdateMediaAsset()
  const replaceAsset = useReplaceMediaAsset()
  const deleteAsset = useDeleteMediaAsset()

  function report(err: unknown, fallback: string) {
    setNotice(null)
    setError(err instanceof ApiError ? err.message : fallback)
  }

  function selectAsset(asset: MediaAsset) {
    setSelectedId(asset.id)
    setAltText(asset.alt_text ?? '')
    setCaption(asset.caption ?? '')
    setDescription(asset.description ?? '')
    setAssetFolder(asset.folder_id ?? '')
  }

  function submitUpload(event: FormEvent) {
    event.preventDefault()
    if (!uploadFile) return
    setError(null)
    setNotice(null)
    upload.mutate(
      { file: uploadFile, folderId: folder || null, altText: uploadAlt || undefined, caption: uploadCaption || undefined },
      {
        onSuccess: (result) => {
          setNotice(
            result.duplicate
              ? 'That file already exists in the library; the existing asset was returned.'
              : 'Media uploaded.',
          )
          setUploadFile(null)
          setUploadAlt('')
          setUploadCaption('')
          if (replaceInput.current) replaceInput.current.value = ''
          selectAsset(result.asset)
        },
        onError: (err) => report(err, 'Could not upload the file.'),
      },
    )
  }

  function submitFolder(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    createFolder.mutate(
      { name: folderName, parent_id: folderParent || null },
      {
        onSuccess: () => {
          setNotice('Folder created.')
          setFolderName('')
        },
        onError: (err) => report(err, 'Could not create the folder.'),
      },
    )
  }

  const current = detail.data?.asset

  return (
    <div className="space-y-6">
      <PageHeader
        title="Media library"
        description="Upload imagery, keep alt text accurate and see every place an asset is used."
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-4">
        <div className="space-y-6">
          <Card title="Folders">
            <div className="space-y-2">
              <button
                type="button"
                onClick={() => {
                  setFolder('')
                  setPage(1)
                }}
                className={`w-full rounded-lg px-3 py-2 text-left text-sm ${
                  folder === '' ? 'bg-brand-50 font-medium text-brand-800' : 'text-gray-700 hover:bg-gray-50'
                }`}
              >
                All media
              </button>
              {folders.data?.folders.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => {
                    setFolder(item.id)
                    setPage(1)
                  }}
                  className={`flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm ${
                    folder === item.id ? 'bg-brand-50 font-medium text-brand-800' : 'text-gray-700 hover:bg-gray-50'
                  }`}
                >
                  <span className="truncate">{item.path}</span>
                  <span className="text-xs text-gray-500">{item.asset_count ?? 0}</span>
                </button>
              ))}
              {folder && (
                <Button
                  variant="danger"
                  loading={deleteFolder.isPending}
                  onClick={() =>
                    deleteFolder.mutate(folder, {
                      onSuccess: () => {
                        setFolder('')
                        setNotice('Folder deleted.')
                      },
                      onError: (err) => report(err, 'Could not delete the folder.'),
                    })
                  }
                >
                  Delete selected folder
                </Button>
              )}
            </div>

            <form onSubmit={submitFolder} className="mt-4 space-y-2 border-t border-gray-100 pt-4">
              <Field label="New folder" name="folder-name" value={folderName} onChange={(event) => setFolderName(event.target.value)} />
              <Select label="Parent folder" name="folder-parent" value={folderParent} onChange={(event) => setFolderParent(event.target.value)}>
                <option value="">Library root</option>
                {folders.data?.folders.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.path}
                  </option>
                ))}
              </Select>
              <Button type="submit" variant="secondary" loading={createFolder.isPending} disabled={folderName.trim().length === 0}>
                Create folder
              </Button>
            </form>
          </Card>

          <Card title="Upload">
            <form onSubmit={submitUpload} className="space-y-3">
              <label className="space-y-1 text-sm text-gray-700">
                <span className="font-medium">File</span>
                <input
                  ref={replaceInput}
                  type="file"
                  accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
                  onChange={(event) => setUploadFile(event.target.files?.[0] ?? null)}
                  className="block w-full text-sm"
                />
                <span className="block text-xs text-gray-500">JPEG, PNG, WebP, GIF or PDF up to 8 MB.</span>
              </label>
              <Field label="Alt text" name="upload-alt" value={uploadAlt} onChange={(event) => setUploadAlt(event.target.value)} />
              <Field label="Caption" name="upload-caption" value={uploadCaption} onChange={(event) => setUploadCaption(event.target.value)} />
              <Button type="submit" loading={upload.isPending} disabled={!uploadFile}>
                Upload to {folder ? 'selected folder' : 'library'}
              </Button>
            </form>
          </Card>
        </div>

        <div className="space-y-6 xl:col-span-3">
          <Card
            title="Assets"
            actions={
              <Field label="" aria-label="Search media" placeholder="Search filename" name="media-search" value={search} onChange={(event) => setSearch(event.target.value)} />
            }
          >
            {assets.isLoading ? (
              <PageLoader label="Loading media…" />
            ) : assets.isError ? (
              <Alert kind="error">{(assets.error as ApiError).message}</Alert>
            ) : (assets.data?.assets.length ?? 0) === 0 ? (
              <EmptyState title="No assets" description="Upload an image or PDF to get started." />
            ) : (
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4" data-testid="media-grid">
                {assets.data?.assets.map((asset) => (
                  <button
                    key={asset.id}
                    type="button"
                    onClick={() => selectAsset(asset)}
                    className={`overflow-hidden rounded-lg border text-left transition-shadow hover:shadow-md ${
                      selectedId === asset.id ? 'border-brand-500 ring-2 ring-brand-200' : 'border-gray-200'
                    }`}
                  >
                    <div className="flex h-28 items-center justify-center bg-gray-100">
                      {asset.mime.startsWith('image/') ? (
                        <img src={asset.url} alt={asset.alt_text ?? ''} className="h-full w-full object-cover" loading="lazy" />
                      ) : (
                        <span className="text-xs font-medium uppercase text-gray-500">{asset.extension}</span>
                      )}
                    </div>
                    <div className="space-y-1 px-2 py-2">
                      <p className="truncate text-xs font-medium text-gray-800">{asset.filename}</p>
                      <p className="text-[11px] text-gray-500">
                        {formatBytes(asset.size_bytes)} · v{asset.version}
                      </p>
                      <Badge tone={asset.usage_count > 0 ? 'amber' : 'gray'}>{asset.usage_count} uses</Badge>
                    </div>
                  </button>
                ))}
              </div>
            )}

            {assets.data && assets.data.pagination.last_page > 1 && (
              <div className="mt-4 flex items-center justify-between text-sm text-gray-600">
                <Button variant="secondary" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>
                  Previous
                </Button>
                <span>
                  Page {assets.data.pagination.page} of {assets.data.pagination.last_page} · {assets.data.pagination.total} assets
                </span>
                <Button
                  variant="secondary"
                  disabled={page >= assets.data.pagination.last_page}
                  onClick={() => setPage((value) => value + 1)}
                >
                  Next
                </Button>
              </div>
            )}
          </Card>

          <Card title={current ? current.filename : 'Asset detail'}>
            {!selectedId || !current ? (
              <EmptyState title="Select an asset" description="Choose an asset to edit alt text, replace the file or review usage." />
            ) : (
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div className="space-y-3">
                  <div className="flex min-h-40 items-center justify-center rounded-lg bg-gray-100 p-2">
                    {current.mime.startsWith('image/') ? (
                      <img src={current.url} alt={current.alt_text ?? ''} className="max-h-64 rounded" />
                    ) : (
                      <a href={current.url} target="_blank" rel="noreferrer" className="text-sm text-brand-700 underline">
                        Open {current.extension.toUpperCase()}
                      </a>
                    )}
                  </div>
                  <dl className="grid grid-cols-2 gap-2 text-xs text-gray-500">
                    <div>
                      <dt>Type</dt>
                      <dd className="font-medium text-gray-800">{current.mime}</dd>
                    </div>
                    <div>
                      <dt>Size</dt>
                      <dd className="font-medium text-gray-800">{formatBytes(current.size_bytes)}</dd>
                    </div>
                    <div>
                      <dt>Dimensions</dt>
                      <dd className="font-medium text-gray-800">
                        {current.width && current.height ? `${current.width} × ${current.height}` : '—'}
                      </dd>
                    </div>
                    <div>
                      <dt>Version</dt>
                      <dd className="font-medium text-gray-800">v{current.version}</dd>
                    </div>
                    <div>
                      <dt>Folder</dt>
                      <dd className="font-medium text-gray-800">{current.folder_path ?? 'Library root'}</dd>
                    </div>
                    <div>
                      <dt>Uploaded</dt>
                      <dd className="font-medium text-gray-800">{current.created_at ? formatDateTime(current.created_at) : '—'}</dd>
                    </div>
                    <div className="col-span-2">
                      <dt>Asset ID</dt>
                      <dd className="break-all font-mono text-[11px] text-gray-800" data-testid="media-asset-id">
                        {current.id}
                      </dd>
                    </div>
                  </dl>

                  <div className="space-y-2 rounded-lg border border-gray-200 p-3">
                    <p className="text-sm font-medium text-gray-800">Replace file</p>
                    <p className="text-xs text-gray-500">
                      Replacing keeps the same URL and updates every usage instantly.
                    </p>
                    <input
                      type="file"
                      accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
                      data-testid="media-replace-input"
                      onChange={(event) => {
                        const file = event.target.files?.[0]
                        if (!file) return
                        replaceAsset.mutate(
                          { assetId: current.id, file },
                          {
                            onSuccess: (result) => {
                              setNotice(result.usage_warning ?? 'Asset replaced.')
                              setSelectedId(result.asset.id)
                            },
                            onError: (err) => report(err, 'Could not replace the asset.'),
                          },
                        )
                      }}
                      className="block w-full text-sm"
                    />
                  </div>
                </div>

                <div className="space-y-3">
                  <Field label="Alt text" name="asset-alt" value={altText} onChange={(event) => setAltText(event.target.value)} />
                  <Field label="Caption" name="asset-caption" value={caption} onChange={(event) => setCaption(event.target.value)} />
                  <label className="space-y-1 text-sm text-gray-700">
                    <span className="font-medium">Description</span>
                    <textarea
                      className="block min-h-20 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
                      value={description}
                      onChange={(event) => setDescription(event.target.value)}
                    />
                  </label>
                  <Select label="Folder" name="asset-folder" value={assetFolder} onChange={(event) => setAssetFolder(event.target.value)}>
                    <option value="">Library root</option>
                    {folders.data?.folders.map((item) => (
                      <option key={item.id} value={item.id}>
                        {item.path}
                      </option>
                    ))}
                  </Select>
                  <div className="flex flex-wrap gap-2">
                    <Button
                      loading={updateAsset.isPending}
                      onClick={() =>
                        updateAsset.mutate(
                          {
                            assetId: current.id,
                            input: {
                              alt_text: altText || null,
                              caption: caption || null,
                              description: description || null,
                              folder_id: assetFolder || null,
                            },
                          },
                          {
                            onSuccess: () => setNotice('Asset details saved.'),
                            onError: (err) => report(err, 'Could not save the asset.'),
                          },
                        )
                      }
                    >
                      Save details
                    </Button>
                    <Button
                      variant="danger"
                      loading={deleteAsset.isPending}
                      onClick={() =>
                        deleteAsset.mutate(current.id, {
                          onSuccess: (result) => {
                            setNotice(result.usage_warning ?? 'Asset deleted.')
                            setSelectedId(undefined)
                          },
                          onError: (err) => report(err, 'Could not delete the asset.'),
                        })
                      }
                    >
                      Delete
                    </Button>
                  </div>

                  <div className="border-t border-gray-100 pt-3">
                    <p className="text-sm font-medium text-gray-800">Used in {detail.data?.usages.length ?? 0} place(s)</p>
                    {(detail.data?.usages.length ?? 0) === 0 ? (
                      <p className="mt-1 text-xs text-gray-500">This asset is not referenced anywhere yet.</p>
                    ) : (
                      <ul className="mt-2 divide-y divide-gray-100 text-xs text-gray-600" data-testid="media-usages">
                        {detail.data?.usages.map((usage) => (
                          <li key={usage.id} className="flex items-center justify-between py-2">
                            <span>
                              {usage.usable_type} #{usage.usable_id} · {usage.field}
                            </span>
                            <span className="text-gray-500">{usage.used_at ? formatDateTime(usage.used_at) : '—'}</span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>

                  <div className="border-t border-gray-100 pt-3">
                    <p className="text-sm font-medium text-gray-800">Versions</p>
                    <ul className="mt-2 space-y-1 text-xs text-gray-600">
                      {detail.data?.versions.map((version) => (
                        <li key={version.version} className="flex justify-between">
                          <span>
                            v{version.version} · {formatBytes(version.size_bytes)}
                          </span>
                          <span className="text-gray-500">{version.created_at ? formatDateTime(version.created_at) : '—'}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                </div>
              </div>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}

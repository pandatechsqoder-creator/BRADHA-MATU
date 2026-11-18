import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, PageLoader, Select } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import {
  useCreateMenu,
  useCreateMenuItem,
  useDeleteMenu,
  useDeleteMenuItem,
  useMarketingPages,
  useMenus,
  useUpdateMenuItem,
  type MenuItem,
  type MenuItemInput,
} from '../../lib/marketing/hooks'

interface ItemForm {
  label: string
  targetType: 'page' | 'url'
  pageId: string
  url: string
  parentId: string
  target: string
  sortOrder: string
  isVisible: boolean
}

const emptyForm: ItemForm = {
  label: '',
  targetType: 'page',
  pageId: '',
  url: '',
  parentId: '',
  target: '_self',
  sortOrder: '',
  isVisible: true,
}

function flatten(items: MenuItem[]): MenuItem[] {
  return items.flatMap((item) => [item, ...item.children])
}

export function NavigationPage() {
  const { data: user } = useCurrentUser()
  const canEdit = user?.permissions.includes('cms.edit') ?? false
  const menus = useMenus()
  const pages = useMarketingPages({})
  const createMenu = useCreateMenu()
  const deleteMenu = useDeleteMenu()

  const [selectedMenuId, setSelectedMenuId] = useState('')
  const [menuKey, setMenuKey] = useState('')
  const [menuName, setMenuName] = useState('')
  const [form, setForm] = useState<ItemForm>(emptyForm)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const menu = menus.data?.menus.find((item) => item.id === Number(selectedMenuId)) ?? menus.data?.menus[0]
  const activeMenuKey = menu?.key ?? ''
  const createItem = useCreateMenuItem(activeMenuKey)
  const updateItem = useUpdateMenuItem(activeMenuKey)
  const deleteItem = useDeleteMenuItem(activeMenuKey)
  const rootItems = menu?.items ?? []

  function report(err: unknown, fallback: string) {
    setNotice(null)
    setError(err instanceof ApiError ? err.message : fallback)
  }

  function submitMenu(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    createMenu.mutate(
      { key: menuKey, name: menuName },
      {
        onSuccess: (result) => {
          setNotice('Menu created.')
          setMenuKey('')
          setMenuName('')
          setSelectedMenuId(String(result.menu.id))
        },
        onError: (err) => report(err, 'Could not create the menu.'),
      },
    )
  }

  function editItem(item: MenuItem) {
    setEditingId(item.id)
    setError(null)
    setNotice(null)
    setForm({
      label: item.label,
      targetType: item.page_id ? 'page' : 'url',
      pageId: item.page_id ?? '',
      url: item.page_id ? '' : item.url ?? '',
      parentId: '',
      target: item.target,
      sortOrder: String(item.sort_order),
      isVisible: item.is_visible,
    })
  }

  function submitItem(event: FormEvent) {
    event.preventDefault()
    if (!menu) return
    const input: MenuItemInput = {
      label: form.label,
      page_id: form.targetType === 'page' ? form.pageId || null : null,
      url: form.targetType === 'url' ? form.url || null : null,
      parent_id: form.parentId ? Number(form.parentId) : null,
      target: form.target,
      is_visible: form.isVisible,
      ...(form.sortOrder ? { sort_order: Number(form.sortOrder) } : {}),
    }

    setError(null)
    setNotice(null)
    const handlers = {
      onSuccess: () => {
        setNotice(editingId ? 'Menu item updated.' : 'Menu item added.')
        setForm(emptyForm)
        setEditingId(null)
      },
      onError: (err: unknown) => report(err, 'Could not save the menu item.'),
    }

    if (editingId) updateItem.mutate({ itemId: editingId, input }, handlers)
    else createItem.mutate(input, handlers)
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Navigation"
        description="Two-level header and footer menus. Each item points to a published page or an external URL."
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div className="space-y-6">
          <Card title="Menus">
            {menus.isLoading ? (
              <PageLoader />
            ) : (
              <div className="space-y-2">
                {menus.data?.menus.map((item) => (
                  <button
                    key={item.id}
                    type="button"
                    onClick={() => {
                      setSelectedMenuId(String(item.id))
                      setEditingId(null)
                      setForm(emptyForm)
                    }}
                    className={`flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm ${
                      menu?.id === item.id ? 'bg-brand-50 font-medium text-brand-800' : 'text-gray-700 hover:bg-gray-50'
                    }`}
                  >
                    <span>
                      {item.name} <span className="text-xs text-gray-500">({item.key})</span>
                    </span>
                    <span className="text-xs text-gray-500">{flatten(item.items).length}</span>
                  </button>
                ))}
                {(menus.data?.menus.length ?? 0) === 0 && <EmptyState title="No menus yet" />}
              </div>
            )}

            {canEdit && (
              <form onSubmit={submitMenu} className="mt-4 space-y-2 border-t border-gray-100 pt-4">
                <Field label="Menu key" name="menu-key" value={menuKey} onChange={(event) => setMenuKey(event.target.value)} placeholder="primary" />
                <Field label="Menu name" name="menu-name" value={menuName} onChange={(event) => setMenuName(event.target.value)} placeholder="Primary navigation" />
                <Button type="submit" variant="secondary" loading={createMenu.isPending} disabled={!menuKey || !menuName}>
                  Create menu
                </Button>
              </form>
            )}
          </Card>

          {menu && (
            <Card
              title="Menu item"
              actions={
                editingId ? (
                  <Button variant="secondary" onClick={() => { setEditingId(null); setForm(emptyForm) }}>
                    New item
                  </Button>
                ) : undefined
              }
            >
              <form onSubmit={submitItem} className="space-y-3">
                <Field label="Label" name="item-label" value={form.label} onChange={(event) => setForm({ ...form, label: event.target.value })} />
                <Select
                  label="Links to"
                  name="item-target-type"
                  value={form.targetType}
                  onChange={(event) => setForm({ ...form, targetType: event.target.value as 'page' | 'url' })}
                >
                  <option value="page">Published page</option>
                  <option value="url">External or relative URL</option>
                </Select>
                {form.targetType === 'page' ? (
                  <Select
                    label="Page"
                    name="item-page"
                    value={form.pageId}
                    onChange={(event) => setForm({ ...form, pageId: event.target.value })}
                  >
                    <option value="">Select a page</option>
                    {pages.data?.pages.map((page) => (
                      <option key={page.id} value={page.id}>
                        {page.title} (/{page.effective_slug})
                      </option>
                    ))}
                  </Select>
                ) : (
                  <Field
                    label="URL"
                    name="item-url"
                    value={form.url}
                    onChange={(event) => setForm({ ...form, url: event.target.value })}
                    placeholder="/pricing or https://example.com"
                  />
                )}
                {!editingId && (
                  <Select
                    label="Parent (optional)"
                    name="item-parent"
                    value={form.parentId}
                    onChange={(event) => setForm({ ...form, parentId: event.target.value })}
                  >
                    <option value="">Top level</option>
                    {rootItems.map((item) => (
                      <option key={item.id} value={item.id}>
                        {item.label}
                      </option>
                    ))}
                  </Select>
                )}
                <div className="grid grid-cols-2 gap-3">
                  <Select label="Opens in" name="item-browser-target" value={form.target} onChange={(event) => setForm({ ...form, target: event.target.value })}>
                    <option value="_self">Same tab</option>
                    <option value="_blank">New tab</option>
                  </Select>
                  <Field
                    label="Sort order"
                    name="item-sort"
                    type="number"
                    min="0"
                    value={form.sortOrder}
                    onChange={(event) => setForm({ ...form, sortOrder: event.target.value })}
                  />
                </div>
                <label className="flex items-center gap-2 text-sm text-gray-700">
                  <input type="checkbox" checked={form.isVisible} onChange={(event) => setForm({ ...form, isVisible: event.target.checked })} />
                  Visible on the public site
                </label>
                <Button type="submit" loading={createItem.isPending || updateItem.isPending} disabled={!form.label || (form.targetType === 'page' ? !form.pageId : !form.url)}>
                  {editingId ? 'Save item' : 'Add item'}
                </Button>
              </form>
            </Card>
          )}
        </div>

        <div className="xl:col-span-2">
          <Card
            title={menu ? `${menu.name} structure` : 'Menu structure'}
            actions={
              menu && canEdit ? (
                <Button
                  variant="danger"
                  loading={deleteMenu.isPending}
                  onClick={() =>
                    deleteMenu.mutate(menu.key, {
                      onSuccess: () => {
                        setSelectedMenuId('')
                        setNotice('Menu deleted.')
                      },
                      onError: (err) => report(err, 'Could not delete the menu.'),
                    })
                  }
                >
                  Delete menu
                </Button>
              ) : undefined
            }
          >
            {!menu ? (
              <EmptyState title="Select a menu" />
            ) : menu.items.length === 0 ? (
              <EmptyState title="No items" description="Add the first item using the form." />
            ) : (
              <ul className="space-y-3" data-testid="menu-tree">
                {menu.items.map((item) => (
                  <li key={item.id}>
                    <MenuItemRow
                      item={item}
                      canEdit={canEdit}
                      onEdit={editItem}
                      onDelete={(itemId) =>
                        deleteItem.mutate(itemId, {
                          onSuccess: () => setNotice('Menu item deleted.'),
                          onError: (err) => report(err, 'Could not delete the menu item.'),
                        })
                      }
                    />
                    {item.children.length > 0 && (
                      <ul className="ml-6 mt-2 space-y-2 border-l border-gray-200 pl-4">
                        {item.children.map((child) => (
                          <li key={child.id}>
                            <MenuItemRow
                              item={child}
                              canEdit={canEdit}
                              onEdit={editItem}
                              onDelete={(itemId) =>
                                deleteItem.mutate(itemId, {
                                  onSuccess: () => setNotice('Menu item deleted.'),
                                  onError: (err) => report(err, 'Could not delete the menu item.'),
                                })
                              }
                            />
                          </li>
                        ))}
                      </ul>
                    )}
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

function MenuItemRow({
  item,
  canEdit,
  onEdit,
  onDelete,
}: {
  item: MenuItem
  canEdit: boolean
  onEdit: (item: MenuItem) => void
  onDelete: (itemId: number) => void
}) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2">
      <div className="min-w-0">
        <p className="text-sm font-medium text-gray-900">
          {item.label} {!item.is_visible && <span className="text-xs text-gray-500">(hidden)</span>}
        </p>
        <p className="truncate text-xs text-gray-500">
          {item.url ?? '—'} · {item.target} · order {item.sort_order}
        </p>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        <Badge tone={item.page_id ? 'green' : 'gray'}>{item.page_id ? 'page' : 'url'}</Badge>
        {canEdit && (
          <>
            <Button variant="secondary" onClick={() => onEdit(item)}>
              Edit
            </Button>
            <Button variant="danger" onClick={() => onDelete(item.id)}>
              Remove
            </Button>
          </>
        )}
      </div>
    </div>
  )
}

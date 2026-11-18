import { Fragment, useState, type FormEvent } from 'react'
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
import type { Invitation, Member, TenantRole } from '../../lib/api/schemas'
import {
  useBranches,
  useCreateInvitation,
  useCurrentUser,
  useInvitations,
  useMemberDirectory,
  useRemoveMember,
  useRevokeInvitation,
  useTenantRoles,
  useUpdateMember,
} from '../../lib/auth/hooks'
import { formatDateTime } from '../../lib/formatting'

function describeError(error: unknown): string {
  return error instanceof ApiError ? error.message : 'The change could not be saved. Try again.'
}

function statusTone(status: string): 'green' | 'amber' | 'gray' | 'red' {
  if (status === 'active' || status === 'accepted') return 'green'
  if (status === 'pending') return 'amber'
  if (status === 'revoked' || status === 'expired') return 'red'
  return 'gray'
}

function MemberEditor({
  tenantId,
  member,
  roles,
  branchOptions,
  onDone,
  onError,
}: {
  tenantId: string
  member: Member
  roles: TenantRole[]
  branchOptions: { id: string; name: string }[]
  onDone: () => void
  onError: (message: string) => void
}) {
  const update = useUpdateMember(tenantId)
  const remove = useRemoveMember(tenantId)
  const [selectedRoles, setSelectedRoles] = useState<string[]>(member.roles.map((role) => role.slug))
  const [selectedBranches, setSelectedBranches] = useState<string[]>(
    member.branch_scopes.map((scope) => scope.id),
  )

  function toggle(list: string[], value: string): string[] {
    return list.includes(value) ? list.filter((item) => item !== value) : [...list, value]
  }

  return (
    <div className="space-y-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Roles</p>
          <div className="space-y-1">
            {roles.map((role) => (
              <label key={role.slug} className="flex items-center gap-2 text-sm text-gray-700">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-gray-300"
                  checked={selectedRoles.includes(role.slug)}
                  onChange={() => setSelectedRoles((current) => toggle(current, role.slug))}
                />
                {role.name}
              </label>
            ))}
          </div>
        </div>
        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
            Branch access
          </p>
          {branchOptions.length === 0 ? (
            <p className="text-sm text-gray-500">Create a branch first to scope access.</p>
          ) : (
            <>
              <div className="space-y-1">
                {branchOptions.map((branch) => (
                  <label key={branch.id} className="flex items-center gap-2 text-sm text-gray-700">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-gray-300"
                      checked={selectedBranches.includes(branch.id)}
                      onChange={() => setSelectedBranches((current) => toggle(current, branch.id))}
                    />
                    {branch.name}
                  </label>
                ))}
              </div>
              <p className="mt-2 text-xs text-gray-500">
                Leave all unchecked for access to every branch.
              </p>
            </>
          )}
        </div>
      </div>

      <div className="flex flex-wrap gap-2">
        <Button
          loading={update.isPending}
          onClick={() => {
            update.mutate(
              { userId: member.id, roles: selectedRoles, branch_ids: selectedBranches },
              {
                onSuccess: onDone,
                onError: (error) => onError(describeError(error)),
              },
            )
          }}
        >
          Save access
        </Button>
        <Button variant="secondary" onClick={onDone}>
          Cancel
        </Button>
        <Button
          variant="danger"
          loading={remove.isPending}
          onClick={() => {
            if (!window.confirm(`Remove ${member.name} from this organisation?`)) return
            remove.mutate(member.id, {
              onSuccess: onDone,
              onError: (error) => onError(describeError(error)),
            })
          }}
        >
          Remove member
        </Button>
      </div>
    </div>
  )
}

function InvitationsSection({
  tenantId,
  invitations,
  roles,
  branchOptions,
  canManage,
}: {
  tenantId: string
  invitations: Invitation[]
  roles: TenantRole[]
  branchOptions: { id: string; name: string }[]
  canManage: boolean
}) {
  const create = useCreateInvitation(tenantId)
  const revoke = useRevokeInvitation(tenantId)
  const [values, setValues] = useState({ email: '', role: '', branch_id: '' })
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const pending = invitations.filter((invitation) => invitation.status === 'pending')

  if (!canManage) {
    return (
      <Card title="Invitations">
        <p className="text-sm text-gray-500">
          Only administrators with team-management permission can invite people or change access.
        </p>
      </Card>
    )
  }

  function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    create.mutate(
      {
        email: values.email,
        role: values.role,
        branch_id: values.branch_id || null,
      },
      {
        onSuccess: (invitation) => {
          setValues({ email: '', role: '', branch_id: '' })
          setNotice(`Invitation sent to ${invitation.email}.`)
        },
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  return (
    <Card title="Invite a team member">
      <div className="space-y-5">
        {error && <Alert kind="error">{error}</Alert>}
        {notice && <Alert kind="success">{notice}</Alert>}
        <form
          className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_200px_200px_auto] lg:items-end"
          onSubmit={submit}
        >
          <Field
            label="Email address"
            name="invite_email"
            type="email"
            required
            value={values.email}
            onChange={(event) => setValues((v) => ({ ...v, email: event.target.value }))}
          />
          <Select
            label="Role"
            name="invite_role"
            required
            value={values.role}
            onChange={(event) => setValues((v) => ({ ...v, role: event.target.value }))}
          >
            <option value="">Select a role</option>
            {roles.map((role) => (
              <option key={role.slug} value={role.slug}>
                {role.name}
              </option>
            ))}
          </Select>
          <Select
            label="Branch access"
            name="invite_branch"
            value={values.branch_id}
            onChange={(event) => setValues((v) => ({ ...v, branch_id: event.target.value }))}
          >
            <option value="">All branches</option>
            {branchOptions.map((branch) => (
              <option key={branch.id} value={branch.id}>
                {branch.name}
              </option>
            ))}
          </Select>
          <Button type="submit" loading={create.isPending}>
            Create invitation
          </Button>
        </form>

        <div>
          <h3 className="mb-2 text-sm font-semibold text-gray-900">Pending invitations</h3>
          {pending.length === 0 ? (
            <p className="text-sm text-gray-500">No invitations awaiting acceptance.</p>
          ) : (
            <Table headers={['Email', 'Role', 'Branch', 'Expires', 'Status', '']}>
              {pending.map((invitation) => (
                <tr key={invitation.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 font-medium text-gray-900">{invitation.email}</td>
                  <td className="px-4 py-3 text-gray-600">{invitation.role_name ?? invitation.role ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-600">{invitation.branch_name ?? 'All branches'}</td>
                  <td className="px-4 py-3 text-gray-600">
                    {invitation.expires_at ? formatDateTime(invitation.expires_at) : '—'}
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(invitation.status)}>{invitation.status}</Badge>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <Button
                      variant="secondary"
                      loading={revoke.isPending && revoke.variables === invitation.id}
                      onClick={() => {
                        if (!window.confirm(`Revoke the invitation for ${invitation.email}?`)) return
                        revoke.mutate(invitation.id, {
                          onError: (err) => setError(describeError(err)),
                        })
                      }}
                    >
                      Revoke
                    </Button>
                  </td>
                </tr>
              ))}
            </Table>
          )}
        </div>
      </div>
    </Card>
  )
}

export function TeamPage() {
  const { data: user, isLoading } = useCurrentUser()
  const tenant = user?.current_tenant ?? null
  const tenantId = tenant?.id
  const directory = useMemberDirectory(tenantId)
  const roles = useTenantRoles(tenantId)
  const branches = useBranches(tenantId)
  const invitations = useInvitations(tenantId)
  const [editingId, setEditingId] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  if (isLoading) return <PageLoader />
  if (!tenant || !tenantId) {
    return <Alert kind="error">Select a business before viewing its team.</Alert>
  }

  const members = directory.data?.members ?? []
  const canManage = directory.data?.canManage ?? false
  const branchOptions = (branches.data ?? []).map((branch) => ({ id: branch.id, name: branch.name }))

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Team members"
        description={`People with access to ${tenant.name}. Roles and branch scopes are enforced by the backend.`}
      />

      {error && <Alert kind="error">{error}</Alert>}
      {directory.isError && <Alert kind="error">Could not load team members.</Alert>}
      {directory.isLoading && <PageLoader label="Loading members…" />}

      {directory.data && members.length === 0 && (
        <EmptyState title="No members yet" description="Invite your branch and inventory managers below." />
      )}

      {directory.data && members.length > 0 && (
        <Card title={`Members (${members.length})`}>
          <Table headers={['Member', 'Roles', 'Branch access', 'Status', 'Joined', '']}>
            {members.map((member) => (
              <Fragment key={member.id}>
                <tr className="hover:bg-gray-50">
                  <td className="px-4 py-3">
                    <p className="font-medium text-gray-900">
                      {member.name}
                      {member.id === user?.id && (
                        <span className="ml-2 text-xs font-normal text-gray-500">You</span>
                      )}
                    </p>
                    <p className="text-xs text-gray-500">{member.email}</p>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      {member.roles.length === 0 && <span className="text-xs text-gray-500">No role</span>}
                      {member.roles.map((role) => (
                        <span
                          key={role.slug}
                          className="rounded-full bg-brand-100 px-2 py-0.5 text-xs text-brand-800"
                        >
                          {role.name}
                        </span>
                      ))}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-gray-600">
                    {member.branch_scopes.length === 0
                      ? 'All branches'
                      : member.branch_scopes.map((scope) => scope.name).join(', ')}
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(member.status)}>{member.status}</Badge>
                  </td>
                  <td className="px-4 py-3 text-gray-500">
                    {member.joined_at ? formatDateTime(member.joined_at) : '—'}
                  </td>
                  <td className="px-4 py-3 text-right">
                    {canManage && (
                      <Button
                        variant="secondary"
                        onClick={() => setEditingId(editingId === member.id ? null : member.id)}
                      >
                        {editingId === member.id ? 'Close' : 'Manage access'}
                      </Button>
                    )}
                  </td>
                </tr>
                {editingId === member.id && canManage && (
                  <tr>
                    <td colSpan={6} className="bg-gray-50 px-4 py-3">
                      <MemberEditor
                        tenantId={tenantId}
                        member={member}
                        roles={roles.data ?? []}
                        branchOptions={branchOptions}
                        onDone={() => setEditingId(null)}
                        onError={(message) => setError(message)}
                      />
                    </td>
                  </tr>
                )}
              </Fragment>
            ))}
          </Table>
        </Card>
      )}

      <InvitationsSection
        tenantId={tenantId}
        invitations={invitations.data ?? []}
        roles={(roles.data ?? []).filter((role) => role.slug !== 'business-owner')}
        branchOptions={branchOptions}
        canManage={canManage}
      />
    </div>
  )
}

export function formatOperatingMode(mode: string): string {
  if (mode === 'brathamatu_pos') return 'BRATHAMATU POS'
  if (mode === 'external') return 'External POS / ERP'
  if (mode === 'manual') return 'Manual / spreadsheets'
  return mode.replaceAll('_', ' ')
}

const kesFormatter = new Intl.NumberFormat('en-KE', {
  style: 'currency',
  currency: 'KES',
  minimumFractionDigits: 2,
})

export function formatKes(amount: number | string): string {
  const value = typeof amount === 'string' ? Number(amount) : amount
  if (!Number.isFinite(value)) return '—'
  return kesFormatter.format(value)
}

export function formatDate(iso: string, timeZone = 'Africa/Nairobi'): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '—'
  return new Intl.DateTimeFormat('en-KE', {
    dateStyle: 'medium',
    timeZone,
  }).format(date)
}

export function formatDateTime(iso: string, timeZone = 'Africa/Nairobi'): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return '—'
  return new Intl.DateTimeFormat('en-KE', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone,
  }).format(date)
}

export function formatMoney(amount: number | string, currency = 'KES'): string {
  const value = typeof amount === 'string' ? Number(amount) : amount
  if (!Number.isFinite(value)) return '—'
  try {
    return new Intl.NumberFormat('en-KE', {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
    }).format(value)
  } catch {
    return `${currency} ${value.toFixed(2)}`
  }
}

export function formatStatusLabel(status: string): string {
  return status
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
}
const MINUTE = 60
const HOUR = 60 * MINUTE
const DAY = 24 * HOUR
const MONTH = 30 * DAY

export function formatRelativeTime(input: string | Date | null | undefined): string {
  if (!input) return ''
  const date = typeof input === 'string' ? new Date(input) : input
  const seconds = Math.max(0, (Date.now() - date.getTime()) / 1000)

  if (seconds < 45)         return 'just now'
  if (seconds < 90)         return '1m ago'
  if (seconds < HOUR)       return `${Math.round(seconds / MINUTE)}m ago`
  if (seconds < 2 * HOUR)   return '1h ago'
  if (seconds < DAY)        return `${Math.round(seconds / HOUR)}h ago`
  if (seconds < 2 * DAY)    return 'yesterday'
  if (seconds < MONTH)      return `${Math.round(seconds / DAY)}d ago`

  return date.toLocaleDateString()
}

export function formatAbsoluteTime(input: string | Date | null | undefined): string {
  if (!input) return ''
  const date = typeof input === 'string' ? new Date(input) : input
  return date.toLocaleString()
}

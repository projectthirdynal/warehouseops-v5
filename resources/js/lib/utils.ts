import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

export function formatCurrency(amount: number): string {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
  }).format(amount);
}

/**
 * Parse a datetime string from the DB (stored in Asia/Manila time, no tz suffix).
 * Appending +08:00 tells the browser it's already PH local time — no offset applied.
 */
function parsePhDate(date: string | Date): Date {
  if (date instanceof Date) return date;
  // If already has timezone info, parse as-is
  if (date.includes('Z') || date.includes('+') || date.includes('T')) return new Date(date);
  // DB stores Asia/Manila time without suffix — tag it so browser doesn't shift by 8h
  return new Date(date.replace(' ', 'T') + '+08:00');
}

export function formatDate(date: string | Date): string {
  return new Intl.DateTimeFormat('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  }).format(parsePhDate(date));
}

export function formatDateTime(date: string | Date): string {
  return new Intl.DateTimeFormat('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(parsePhDate(date));
}

export function formatRelativeTime(date: string | Date): string {
  const now = new Date();
  const then = parsePhDate(date);
  const diffMs = now.getTime() - then.getTime();
  const diffMins = Math.floor(diffMs / 60000);
  const diffHours = Math.floor(diffMins / 60);
  const diffDays = Math.floor(diffHours / 24);

  if (diffMins < 1) return 'Just now';
  if (diffMins < 60) return `${diffMins}m ago`;
  if (diffHours < 24) return `${diffHours}h ago`;
  if (diffDays < 7) return `${diffDays}d ago`;
  return formatDate(date);
}

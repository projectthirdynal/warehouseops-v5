export interface User {
  id: number
  name: string
  email: string
  role: string
  is_active: boolean
  theme?: string
}

export interface DashboardData {
  stats: {
    pending_dispatch: number
    in_transit: number
    delivered_today: number
    returned_today: number
    new_leads: number
    sales_today: number
    qc_pending: number
    agents_online: number
  }
  hourly_activity: Array<{
    hour: string
    waybills: number
    leads: number
  }>
  recent_activity: Array<{
    id: number
    type: string
    description: string
    time: string
  }>
}

export type WaybillStatus =
  | 'PENDING'
  | 'DISPATCHED'
  | 'PICKED_UP'
  | 'IN_TRANSIT'
  | 'OUT_FOR_DELIVERY'
  | 'AT_WAREHOUSE'
  | 'DELIVERED'
  | 'RETURNED'
  | 'CANCELLED'

export interface ScannedItem {
  id: string
  waybill_number: string
  status: 'valid' | 'invalid' | 'duplicate'
  message?: string
  receiver_name?: string
  timestamp: Date
}

export interface UploadRecord {
  id: number
  filename: string
  original_filename: string
  total_rows: number
  processed_rows: number
  success_rows: number
  error_rows: number
  status: 'pending' | 'processing' | 'completed' | 'failed'
  errors: Array<{ row: number; error: string }> | null
  uploaded_by: { name: string } | null
  created_at: string
}

export interface MonitoringMetrics {
  leads: {
    total: number
    new_today: number
    converted: number
    conversion_rate: number
    trend: number
  }
  waybills: {
    total: number
    dispatched_today: number
    delivered_today: number
    returned_today: number
    delivery_rate: number
  }
  agents: {
    total: number
    online: number
    avg_performance: number
    top_performer: string
  }
  revenue: {
    today: number
    this_week: number
    this_month: number
    trend: number
  }
  hourly_activity: Array<{
    hour: string
    leads: number
    sales: number
  }>
  top_agents: Array<{
    name: string
    leads: number
    sales: number
    conversion_rate: number
  }>
}

export interface ElectronAPI {
  getVersion: () => Promise<string>
  checkUpdate: () => Promise<void>
  installUpdate: () => Promise<void>
  quit: () => Promise<void>
  onUpdaterChecking: (cb: () => void) => void
  onUpdaterAvailable: (cb: (_e: unknown, info: unknown) => void) => void
  onUpdaterNotAvailable: (cb: () => void) => void
  onUpdaterProgress: (cb: (_e: unknown, progress: unknown) => void) => void
  onUpdaterDownloaded: (cb: (_e: unknown, info: unknown) => void) => void
  onUpdaterError: (cb: (_e: unknown, msg: string) => void) => void
}

declare global {
  interface Window {
    electronAPI?: ElectronAPI
  }
}

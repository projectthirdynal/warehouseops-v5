export interface User {
  id: number;
  name: string;
  email: string;
  role: 'superadmin' | 'admin' | 'teamleader' | 'agent' | 'checker' | 'encoder' | 'claims_officer';
  is_active: boolean;
  theme?: 'light' | 'dark' | 'system';
  avatar_url?: string;
  created_at: string;
  updated_at: string;
}

export interface Waybill {
  id: number;
  waybill_number: string;
  status: WaybillStatus;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  city: string;
  state: string;
  barangay?: string;
  street?: string;
  item_name?: string;
  item_qty: number;
  amount: number;
  cod_amount?: number;
  shipping_cost?: number;
  courier_provider: string;
  remarks?: string;
  rts_reason?: string;
  lead_id?: number;
  created_at: string;
  updated_at: string;
  dispatched_at?: string;
  delivered_at?: string;
  returned_at?: string;
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
  | 'CANCELLED';

export type ClaimStatus = 'DRAFT' | 'FILED' | 'UNDER_REVIEW' | 'APPROVED' | 'REJECTED' | 'SETTLED';
export type ClaimType = 'LOST' | 'DAMAGED' | 'BEYOND_SLA';

export interface Claim {
  id: number;
  claim_number: string;
  waybill_id: number;
  waybill?: Waybill;
  type: ClaimType;
  status: ClaimStatus;
  description: string | null;
  claim_amount: number;
  approved_amount: number | null;
  jnt_reference_number: string | null;
  filed_by: number;
  filed_by_user?: User;
  filed_at: string | null;
  reviewed_by: number | null;
  reviewed_by_user?: User;
  reviewed_at: string | null;
  resolved_at: string | null;
  resolution_notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface ReturnReceipt {
  id: number;
  waybill_id: number;
  waybill?: Waybill;
  scanned_by: number;
  scanned_by_user?: User;
  scanned_at: string;
  condition: 'GOOD' | 'DAMAGED';
  notes: string | null;
  created_at: string;
}

export interface ScanResults {
  scanned: string[];
  already_received: string[];
  not_found: string[];
  wrong_status: string[];
}

export interface Lead {
  id: number;
  name: string;
  phone: string;
  address: string;
  city: string;
  state: string;
  status: LeadStatus;
  sales_status: SalesStatus;
  source: string;
  assigned_to?: number;
  assigned_agent?: User;
  customer_id?: number;
  customer?: Customer;
  product_name?: string;
  product_brand?: string;
  amount?: number;
  total_cycles: number;
  quality_score?: number;
  created_at: string;
  updated_at: string;
}

export type LeadStatus =
  | 'NEW'
  | 'CALLING'
  | 'NO_ANSWER'
  | 'REJECT'
  | 'CALLBACK'
  | 'SALE'
  | 'REORDER'
  | 'DELIVERED'
  | 'RETURNED'
  | 'CANCELLED'
  | 'ARCHIVED';

export type SalesStatus =
  | 'NEW'
  | 'CONTACTED'
  | 'AGENT_CONFIRMED'
  | 'QA_PENDING'
  | 'QA_APPROVED'
  | 'QA_REJECTED'
  | 'OPS_APPROVED'
  | 'CANCELLED'
  | 'WAYBILL_CREATED';

export interface Customer {
  id: number;
  phone: string;
  name: string;
  canonical_address?: string;
  total_orders: number;
  successful_orders: number;
  returned_orders: number;
  success_rate: number;
  total_revenue: number;
  risk_level: 'LOW' | 'MEDIUM' | 'HIGH' | 'BLACKLISTED';
  is_blacklisted: boolean;
  created_at: string;
  updated_at: string;
}

export interface AgentProfile {
  id: number;
  user_id: number;
  user?: User;
  max_active_cycles: number;
  active_cycles_count: number;
  product_skills: string[];
  regions: string[];
  priority_weight: number;
  is_available: boolean;
  performance_score: number;
}

export interface LeadCycle {
  id: number;
  lead_id: number;
  cycle_number: number;
  assigned_agent_id: number;
  assigned_agent?: User;
  status: 'ACTIVE' | 'COMPLETED' | 'RECYCLED' | 'EXHAUSTED';
  outcome?: string;
  opened_at: string;
  closed_at?: string;
}

export interface CourierProvider {
  id: number;
  code: string;
  name: string;
  is_active: boolean;
  api_endpoint: string | null;
  config: Record<string, unknown>;
  webhook_secret: string | null;
  total_api_calls?: number;
  failed_api_calls?: number;
  last_api_call_at?: string;
  active_waybills?: number;
  created_at: string;
  updated_at: string;
}

export interface CourierApiLog {
  id: number;
  courier_provider_id: number | null;
  courier_code: string;
  action: string;
  direction: 'outbound' | 'inbound';
  endpoint?: string;
  request_data?: Record<string, unknown>;
  response_data?: Record<string, unknown>;
  http_status?: number;
  is_success: boolean;
  error_message?: string;
  response_time_ms?: number;
  waybill_id?: number;
  created_at: string;
}

export interface Product {
  id: number;
  sku: string;
  name: string;
  brand: string | null;
  category: string | null;
  selling_price: number;
  cost_price: number;
  weight_grams: number;
  description: string | null;
  image_url: string | null;
  is_active: boolean;
  requires_qa: boolean;
  margin?: number;
  available_stock?: number;
  is_low_stock?: boolean;
  stock?: ProductStock;
  variants?: ProductVariant[];
  created_at: string;
  updated_at: string;
}

export interface ProductVariant {
  id: number;
  product_id: number;
  sku: string;
  variant_name: string;
  selling_price: number | null;
  cost_price: number | null;
  weight_grams: number | null;
  is_active: boolean;
  stock?: ProductStock;
}

export interface ProductStock {
  id: number;
  product_id: number;
  variant_id: number | null;
  current_stock: number;
  reserved_stock: number;
  available_stock: number;
  reorder_point: number;
  is_low_stock: boolean;
  last_restock_at: string | null;
}

export interface InventoryMovement {
  id: number;
  product_id: number;
  variant_id: number | null;
  type: 'STOCK_IN' | 'STOCK_OUT' | 'ADJUSTMENT' | 'RETURN' | 'RESERVATION' | 'RELEASE';
  quantity: number;
  notes: string | null;
  performer?: User;
  created_at: string;
}

export type OrderStatus =
  | 'PENDING'
  | 'CONFIRMED'
  | 'QA_PENDING'
  | 'QA_APPROVED'
  | 'QA_REJECTED'
  | 'PROCESSING'
  | 'DISPATCHED'
  | 'DELIVERED'
  | 'RETURNED'
  | 'CANCELLED';

export interface Order {
  id: number;
  order_number: string;
  lead_id: number | null;
  customer_id: number | null;
  product_id: number | null;
  variant_id: number | null;
  assigned_agent_id: number | null;
  status: OrderStatus;
  courier_code: string | null;
  waybill_id: number | null;
  quantity: number;
  unit_price: number;
  total_amount: number;
  cod_amount: number;
  shipping_cost: number;
  receiver_name: string;
  receiver_phone: string;
  receiver_address: string;
  city: string | null;
  state: string | null;
  barangay: string | null;
  postal_code: string | null;
  notes: string | null;
  rejection_reason: string | null;
  confirmed_at: string | null;
  dispatched_at: string | null;
  delivered_at: string | null;
  returned_at: string | null;
  created_at: string;
  product?: Product;
  agent?: User;
  customer?: Customer;
  lead?: Lead;
  waybill?: Waybill;
}

export interface DashboardStats {
  total_waybills: number;
  pending_dispatch: number;
  in_transit: number;
  delivered_today: number;
  returned_today: number;
  total_leads: number;
  new_leads: number;
  sales_today: number;
  conversion_rate: number;
  qc_pending: number;
  agents_online: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
}

export interface PageProps {
  auth: {
    user: User;
  };
  flash: {
    success?: string;
    error?: string;
  };
  ziggy: {
    location: string;
    url: string;
  };
  [key: string]: unknown;
}

export * from './lead-pool';

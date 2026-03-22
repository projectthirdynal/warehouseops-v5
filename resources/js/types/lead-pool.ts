// resources/js/types/lead-pool.ts

export type PoolStatus = 'AVAILABLE' | 'ASSIGNED' | 'COOLDOWN' | 'EXHAUSTED';

export type LeadOutcome =
  | 'NO_ANSWER'
  | 'CALLBACK'
  | 'INTERESTED'
  | 'ORDERED'
  | 'NOT_INTERESTED'
  | 'WRONG_NUMBER';

export interface LeadCycle {
  id: number;
  cycle_number: number;
  status: 'ACTIVE' | 'CLOSED';
  outcome: LeadOutcome | null;
  call_count: number;
  last_call_at: string | null;
  callback_at: string | null;
  callback_notes: string | null;
  opened_at: string;
  closed_at: string | null;
}

export interface AgentLead {
  id: number;
  name: string;
  city: string | null;
  state: string | null;
  barangay: string | null;
  product_name: string | null;
  product_brand: string | null;
  amount: number | null;
  status: string;
  sales_status: string;
  pool_status: PoolStatus;
  total_cycles: number;
  call_attempts: number;
  last_called_at: string | null;
  assigned_at: string | null;
  created_at: string;
  customer?: {
    id: number;
    name: string;
    total_orders: number;
    successful_orders: number;
    success_rate: number;
  };
  cycles?: LeadCycle[];
}

export interface PoolStats {
  available: number;
  assigned: number;
  cooldown: number;
  exhausted: number;
}

export interface AgentPerformance {
  id: number;
  name: string;
  active_leads: number;
  called_today: number;
  sold_today: number;
  no_answer_today: number;
  conversion_rate: number;
  status: 'ONLINE' | 'IDLE' | 'OFFLINE';
}

export interface FraudFlag {
  id: number;
  agent_id: number;
  agent_name: string;
  flag_type: 'SUSPICIOUS_VELOCITY' | 'NO_CALL_INITIATED' | 'OUTCOME_TAMPERING' | 'LEAD_HOARDING';
  severity: 'WARNING' | 'CRITICAL';
  details: Record<string, unknown>;
  is_reviewed: boolean;
  created_at: string;
}

export interface OutcomeFormData {
  outcome: LeadOutcome;
  remarks?: string;
  callback_at?: string;
}

// Agent ticket system types (for self-service portal)
export type TicketType = 'WRONG_NUMBER' | 'DUPLICATE_LEAD' | 'SYSTEM_ISSUE' | 'REASSIGNMENT_REQUEST' | 'OTHER';
export type TicketStatus = 'OPEN' | 'IN_PROGRESS' | 'RESOLVED' | 'CLOSED';
export type TicketPriority = 'LOW' | 'MEDIUM' | 'HIGH';

export interface AgentTicket {
  id: number;
  type: TicketType;
  status: TicketStatus;
  priority: TicketPriority;
  subject: string;
  description: string;
  lead_id?: number;
  created_at: string;
  updated_at: string;
  resolved_at?: string;
  resolution?: string;
}

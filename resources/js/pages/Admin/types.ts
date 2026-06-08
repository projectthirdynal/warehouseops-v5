export interface UserRecord {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  role: string;
  is_active: boolean;
  last_login_at: string | null;
  created_at: string;
}

export interface ModuleDefinition {
  key: string;
  label: string;
  section: string;
  roles: string[];
}

export interface PermissionItem {
  id: number;
  key: string;
  label: string;
  section: string;
  description: string | null;
}

export interface ActivityItem {
  id: number;
  user_id: number | null;
  user: { name: string; email: string } | null;
  action: string;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

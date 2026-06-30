export const ROLE_COLORS: Record<string, string> = {
  superadmin: 'bg-destructive/10 text-destructive border-destructive/20',
  admin: 'bg-info/10 text-info border-info/20',
  supervisor: 'bg-primary/10 text-primary border-primary/20',
  finance: 'bg-success/10 text-success border-success/20',
  accounting: 'bg-warning/10 text-warning border-warning/20',
  warehouse: 'bg-warning/10 text-warning border-warning/20',
  agent: 'bg-muted text-muted-foreground border-border',
};

export const ACTION_ICONS: Record<string, string> = {
  'user.activated': 'UserCheck',
  'user.deactivated': 'UserX',
  'user.role_changed': 'Shield',
  'user.created': 'UserCheck',
  'user.updated': 'UserCog',
  'user.deleted': 'UserX',
  'permissions.updated': 'Lock',
};

export const ACTION_LABELS: Record<string, string> = {
  'user.activated': 'Activated user',
  'user.deactivated': 'Deactivated user',
  'user.role_changed': 'Changed user role',
  'user.created': 'Created user',
  'user.updated': 'Updated user',
  'user.deleted': 'Deleted user',
  'permissions.updated': 'Updated role permissions',
};

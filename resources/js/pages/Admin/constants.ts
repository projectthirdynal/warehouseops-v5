export const ROLE_COLORS: Record<string, string> = {
  superadmin: 'bg-red-100 text-red-700 border-red-200',
  admin: 'bg-blue-100 text-blue-700 border-blue-200',
  supervisor: 'bg-purple-100 text-purple-700 border-purple-200',
  finance: 'bg-emerald-100 text-emerald-700 border-emerald-200',
  accounting: 'bg-amber-100 text-amber-700 border-amber-200',
  warehouse: 'bg-orange-100 text-orange-700 border-orange-200',
  agent: 'bg-gray-100 text-gray-700 border-gray-200',
};

export const ACTION_ICONS: Record<string, string> = {
  'user.activated': 'UserCheck',
  'user.deactivated': 'UserX',
  'user.role_changed': 'Shield',
  'user.created': 'UserCheck',
  'user.deleted': 'UserX',
  'permissions.updated': 'Lock',
};

export const ACTION_LABELS: Record<string, string> = {
  'user.activated': 'Activated user',
  'user.deactivated': 'Deactivated user',
  'user.role_changed': 'Changed user role',
  'user.created': 'Created user',
  'user.deleted': 'Deleted user',
  'permissions.updated': 'Updated role permissions',
};

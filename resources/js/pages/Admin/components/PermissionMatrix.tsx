import { useState, useEffect, useMemo } from 'react';
import { router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Loader2, ShieldCheck } from 'lucide-react';

import type { PermissionItem } from '../types';

interface Props {
  permissions: Record<string, PermissionItem[]>;
  roles: string[];
  rolePermissions: Record<string, number[]>;
}

export default function PermissionMatrix({ permissions, roles, rolePermissions }: Props) {
  const [selectedRole, setSelectedRole] = useState<string>(roles[0] ?? 'superadmin');
  const [selectedPermissionIds, setSelectedPermissionIds] = useState<Record<string, number[]>>(rolePermissions);
  const [savingPermissions, setSavingPermissions] = useState(false);

  useEffect(() => {
    setSelectedPermissionIds(rolePermissions);
  }, [rolePermissions]);

  const isSuperadminSelected = selectedRole === 'superadmin';

  // Track which roles have unsaved changes
  const dirtyRoles = useMemo(() => {
    return roles.filter(role => {
      const original = new Set(rolePermissions[role] ?? []);
      const current = new Set(selectedPermissionIds[role] ?? []);
      if (original.size !== current.size) return true;
      for (const id of original) if (!current.has(id)) return true;
      return false;
    });
  }, [roles, rolePermissions, selectedPermissionIds]);

  const isDirty = dirtyRoles.includes(selectedRole);

  const togglePermission = (role: string, permissionId: number) => {
    if (role === 'superadmin') return; // superadmin is always full access
    setSelectedPermissionIds(prev => {
      const current = new Set(prev[role] ?? []);
      if (current.has(permissionId)) {
        current.delete(permissionId);
      } else {
        current.add(permissionId);
      }
      return { ...prev, [role]: Array.from(current) };
    });
  };

  const saveRolePermissions = (role: string) => {
    if (role === 'superadmin') return;
    setSavingPermissions(true);
    router.post('/admin/roles/permissions', {
      role,
      permissions: selectedPermissionIds[role] ?? [],
    }, {
      onFinish: () => setSavingPermissions(false),
      preserveScroll: true,
    });
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <label className="text-sm font-medium">Select Role:</label>
        <Select value={selectedRole} onValueChange={setSelectedRole}>
          <SelectTrigger className="w-48 capitalize">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {roles.map(r => (
              <SelectItem key={r} value={r} className="capitalize">
                <span>{r.replace('_', ' ')}</span>
                {dirtyRoles.includes(r) && r !== 'superadmin' && (
                  <span className="ml-2 inline-block h-2 w-2 rounded-full bg-amber-500" />
                )}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {isSuperadminSelected ? (
          <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
            <ShieldCheck className="h-4 w-4 text-green-600" />
            Superadmin always has full access — permissions cannot be modified.
          </div>
        ) : (
          <Button
            size="sm"
            onClick={() => saveRolePermissions(selectedRole)}
            disabled={savingPermissions || !isDirty}
            variant={isDirty ? 'default' : 'outline'}
          >
            {savingPermissions ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
            {isDirty ? 'Save Changes' : 'Saved'}
          </Button>
        )}

        {isDirty && !isSuperadminSelected && (
          <Badge variant="outline" className="border-amber-400 text-amber-600 text-xs">
            Unsaved changes
          </Badge>
        )}
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {Object.entries(permissions).map(([section, perms]) => (
          <Card key={section} className={isSuperadminSelected ? 'opacity-60' : ''}>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                {section}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              {perms.map(perm => {
                const isChecked = isSuperadminSelected
                  ? true
                  : (selectedPermissionIds[selectedRole] ?? []).includes(perm.id);
                return (
                  <div key={perm.id} className="flex items-start gap-2.5">
                    <Checkbox
                      id={`perm-${selectedRole}-${perm.id}`}
                      checked={isChecked}
                      onCheckedChange={() => togglePermission(selectedRole, perm.id)}
                      disabled={isSuperadminSelected}
                      className="mt-0.5"
                    />
                    <label
                      htmlFor={`perm-${selectedRole}-${perm.id}`}
                      className={isSuperadminSelected ? 'text-sm leading-tight opacity-50' : 'cursor-pointer text-sm leading-tight'}
                    >
                      <span className="font-medium">{perm.label}</span>
                      {perm.description && (
                        <p className="text-xs text-muted-foreground mt-0.5">{perm.description}</p>
                      )}
                    </label>
                  </div>
                );
              })}
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}

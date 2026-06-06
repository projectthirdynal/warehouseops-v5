import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Loader2 } from 'lucide-react';

interface PermissionItem {
  id: number;
  key: string;
  label: string;
  section: string;
  description: string | null;
}

interface Props {
  permissions: Record<string, PermissionItem[]>;
  roles: string[];
  rolePermissions: Record<string, number[]>;
}

export default function PermissionMatrix({ permissions, roles, rolePermissions }: Props) {
  const [selectedRole, setSelectedRole] = useState<string>(roles[0] ?? 'superadmin');
  const [selectedPermissionIds, setSelectedPermissionIds] = useState<Record<string, number[]>>(rolePermissions);
  const [savingPermissions, setSavingPermissions] = useState(false);

  const togglePermission = (role: string, permissionId: number) => {
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
      <div className="flex items-center gap-3">
        <label className="text-sm font-medium">Select Role:</label>
        <Select value={selectedRole} onValueChange={setSelectedRole}>
          <SelectTrigger className="w-48 capitalize">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {roles.map(r => (
              <SelectItem key={r} value={r} className="capitalize">{r.replace('_', ' ')}</SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Button
          size="sm"
          onClick={() => saveRolePermissions(selectedRole)}
          disabled={savingPermissions}
        >
          {savingPermissions ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
          Save Permissions
        </Button>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {Object.entries(permissions).map(([section, perms]) => (
          <Card key={section}>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                {section}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              {perms.map(perm => {
                const isChecked = (selectedPermissionIds[selectedRole] ?? []).includes(perm.id);
                return (
                  <div key={perm.id} className="flex items-start gap-2.5">
                    <Checkbox
                      id={`perm-${selectedRole}-${perm.id}`}
                      checked={isChecked}
                      onCheckedChange={() => togglePermission(selectedRole, perm.id)}
                      className="mt-0.5"
                    />
                    <label
                      htmlFor={`perm-${selectedRole}-${perm.id}`}
                      className="cursor-pointer text-sm leading-tight"
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

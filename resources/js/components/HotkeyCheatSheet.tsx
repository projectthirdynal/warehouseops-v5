import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { HOTKEY_REGISTRY } from '@/hooks/use-hotkeys';

interface HotkeyCheatSheetProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function HotkeyCheatSheet({ open, onOpenChange }: HotkeyCheatSheetProps) {
  const groups = Array.from(new Set(HOTKEY_REGISTRY.map((h) => h.group)));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg p-0 overflow-hidden">
        <div className="flex items-center gap-3 border-b px-5 py-4">
          <DialogTitle className="text-base font-semibold">Keyboard Shortcuts</DialogTitle>
          <kbd className="ml-auto inline-flex h-5 items-center gap-1 rounded border bg-muted px-1.5 font-mono text-[10px] font-medium text-muted-foreground">
            ?
          </kbd>
        </div>
        <div className="max-h-[60vh] overflow-y-auto px-5 py-4 space-y-6">
          {groups.map((group) => (
            <div key={group}>
              <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                {group}
              </p>
              <div className="space-y-1">
                {HOTKEY_REGISTRY.filter((h) => h.group === group).map((hotkey) => (
                  <div
                    key={hotkey.key}
                    className="flex items-center justify-between rounded-md px-2 py-1.5 text-sm hover:bg-muted/50"
                  >
                    <span className="text-foreground">{hotkey.description}</span>
                    <kbd
                      className={cn(
                        'inline-flex h-6 items-center rounded border bg-muted px-2 font-mono text-xs font-medium text-muted-foreground',
                        'ml-4 shrink-0'
                      )}
                    >
                      {hotkey.label}
                    </kbd>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
        <div className="border-t px-5 py-3 text-xs text-muted-foreground">
          Shortcuts are disabled when focus is inside an input field.
        </div>
      </DialogContent>
    </Dialog>
  );
}

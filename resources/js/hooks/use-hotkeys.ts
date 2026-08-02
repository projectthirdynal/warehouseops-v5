import { useHotkeys } from 'react-hotkeys-hook';
import { router } from '@inertiajs/react';

export interface HotkeyEntry {
  key: string;
  label: string;
  description: string;
  group: string;
}

/* Global hotkey registry — powers the cheat sheet automatically */
export const HOTKEY_REGISTRY: HotkeyEntry[] = [
  { key: '?', label: '?', description: 'Open keyboard shortcuts', group: 'General' },
  { key: 'mod+k', label: '⌘K', description: 'Open command palette', group: 'General' },
  { key: 'Escape', label: 'Esc', description: 'Close modal / drawer / palette', group: 'General' },
  { key: '/', label: '/', description: 'Focus search on current page', group: 'General' },
  { key: 'g d', label: 'G then D', description: 'Go to Dashboard', group: 'Navigation' },
  { key: 'g w', label: 'G then W', description: 'Go to Waybills', group: 'Navigation' },
  { key: 'g u', label: 'G then U', description: 'Go to Admin / Users', group: 'Navigation' },
  { key: 'g i', label: 'G then I', description: 'Go to Inventory', group: 'Navigation' },
  { key: 'g f', label: 'G then F', description: 'Go to Finance', group: 'Navigation' },
  { key: 'g s', label: 'G then S', description: 'Go to Settings', group: 'Navigation' },
  { key: 'n', label: 'N', description: 'New record (context-aware)', group: 'Actions' },
  { key: 'j', label: 'J', description: 'Move down in table', group: 'Table' },
  { key: 'k', label: 'K', description: 'Move up in table', group: 'Table' },
  { key: 'Enter', label: '↵', description: 'Open selected row', group: 'Table' },
  { key: 'Space', label: 'Space', description: 'Toggle row selection', group: 'Table' },
  { key: 'mod+Enter', label: '⌘↵', description: 'Confirm / submit form', group: 'Actions' },
];

/* Hook: register navigation hotkeys globally */
export function useGlobalHotkeys(onOpenCheatSheet: () => void, onOpenPalette: () => void) {
  useHotkeys('mod+k', () => onOpenPalette(), { preventDefault: true });
  useHotkeys('g+d', () => router.visit('/'), { preventDefault: true });
  useHotkeys('g+w', () => router.visit('/waybills'), { preventDefault: true });
  useHotkeys('g+u', () => router.visit('/admin'), { preventDefault: true });
  useHotkeys('g+i', () => router.visit('/inventory'), { preventDefault: true });
  useHotkeys('g+f', () => router.visit('/finance'), { preventDefault: true });
  useHotkeys('g+s', () => router.visit('/settings'), { preventDefault: true });
  useHotkeys('?', () => onOpenCheatSheet(), { preventDefault: true });
}

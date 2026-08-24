import { useState, useEffect, useRef, useCallback } from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
  Command,
  CommandInput,
  CommandList,
  CommandEmpty,
  CommandGroup,
  CommandItem,
} from '@/components/ui/command';
import { Check, ChevronsUpDown, Loader2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';

interface PriceRemark {
  id: number;
  price_key: string;
  remarks: string;
}

interface PriceRemarkComboboxProps {
  value: string;
  onSelect: (remark: PriceRemark) => void;
  onClear?: () => void;
  placeholder?: string;
  disabled?: boolean;
}

export function PriceRemarkCombobox({
  value,
  onSelect,
  onClear,
  placeholder = 'Select bundle...',
  disabled = false,
}: PriceRemarkComboboxProps) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [options, setOptions] = useState<PriceRemark[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const abortRef = useRef<AbortController | null>(null);

  const fetchAll = useCallback(() => {
    if (abortRef.current) abortRef.current.abort();
    const controller = new AbortController();
    abortRef.current = controller;
    setIsLoading(true);
    fetch('/api/agent/price-remarks/all', { signal: controller.signal })
      .then((r) => r.json())
      .then((data) => setOptions(data.remarks || []))
      .catch(() => setOptions([]))
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    if (open) fetchAll();
    return () => abortRef.current?.abort();
  }, [open, fetchAll]);

  const handleSelect = (row: PriceRemark) => {
    onSelect(row);
    setOpen(false);
    setSearch('');
  };

  const filtered = options.filter(
    (row) =>
      row.remarks.toLowerCase().includes(search.toLowerCase()) ||
      row.price_key.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className="justify-between font-normal"
          disabled={disabled}
        >
          <span className={cn('truncate', !value && 'text-muted-foreground')}>
            {value || placeholder}
          </span>
          <span className="flex items-center gap-1 shrink-0">
            {value && onClear && !disabled && (
              <X
                className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground"
                onClick={(e) => {
                  e.stopPropagation();
                  onClear();
                }}
              />
            )}
            <ChevronsUpDown className="h-3.5 w-3.5 opacity-50" />
          </span>
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="w-[var(--radix-popover-trigger-width)] min-w-[360px] p-0"
        align="start"
      >
        <Command shouldFilter={false} className="h-auto">
          <CommandInput placeholder="Search bundles..." value={search} onValueChange={setSearch} />
          <CommandList className="max-h-[300px] overflow-y-auto overflow-x-hidden">
            {isLoading && (
              <div className="flex items-center justify-center py-6">
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              </div>
            )}
            {!isLoading && filtered.length === 0 && <CommandEmpty>No bundles found.</CommandEmpty>}
            {!isLoading && filtered.length > 0 && (
              <CommandGroup>
                {filtered.map((row) => (
                  <CommandItem
                    key={row.id}
                    value={`${row.price_key}-${row.remarks}`}
                    onSelect={() => handleSelect(row)}
                    className="flex flex-col items-start gap-1 py-2"
                  >
                    <span className="flex items-center gap-2 w-full">
                      <Check
                        className={cn(
                          'h-3.5 w-3.5 shrink-0',
                          value === row.remarks ? 'opacity-100' : 'opacity-0'
                        )}
                      />
                      <span className="font-medium text-xs">{row.remarks}</span>
                    </span>
                    <span className="text-[10px] text-muted-foreground ml-5">{row.price_key}</span>
                  </CommandItem>
                ))}
              </CommandGroup>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}

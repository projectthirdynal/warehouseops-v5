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

interface AddressComboboxProps {
  value: string;
  onChange: (value: string, extra?: unknown) => void;
  placeholder?: string;
  disabled?: boolean;
  /** API endpoint to fetch options from. Should return { items: [{name, ...}] } or { provinces: [...] } / { cities: [...] } / { barangays: [...] } */
  endpoint: string;
  /** Query param name for the search term (default: 'q') */
  searchParam?: string;
  /** Extra params to send with the request (e.g. { province: 'Cebu' }) */
  extraParams?: Record<string, string>;
  /** Whether to reset options when extraParams change (default: true) */
  resetOnParamChange?: boolean;
  /** Data key in the response (default: auto-detect) */
  dataKey?: string;
  /** Whether to clear the value when extraParams change */
  clearOnParamChange?: boolean;
  /** Optional: show shipping days badge next to barangay items */
  showShippingDays?: boolean;
}

interface OptionItem {
  name: string;
  shipping_days?: number;
}

export function AddressCombobox({
  value,
  onChange,
  placeholder = 'Select...',
  disabled = false,
  endpoint,
  searchParam = 'q',
  extraParams,
  resetOnParamChange = true,
  dataKey,
  clearOnParamChange = false,
  showShippingDays = false,
}: AddressComboboxProps) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [options, setOptions] = useState<OptionItem[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const abortRef = useRef<AbortController | null>(null);

  const fetchOptions = useCallback(
    (query: string) => {
      if (abortRef.current) {
        abortRef.current.abort();
      }

      const controller = new AbortController();
      abortRef.current = controller;

      setIsLoading(true);
      const params = new URLSearchParams();
      if (query) params.set(searchParam, query);
      if (extraParams) {
        Object.entries(extraParams).forEach(([k, v]) => {
          if (v) params.set(k, v);
        });
      }

      fetch(`${endpoint}?${params.toString()}`, { signal: controller.signal })
        .then((r) => r.json())
        .then((data) => {
          const key =
            dataKey ||
            (data.items
              ? 'items'
              : data.provinces
                ? 'provinces'
                : data.cities
                  ? 'cities'
                  : data.barangays
                    ? 'barangays'
                    : 'items');

          const raw = data[key] || [];
          const items: OptionItem[] = raw.map((item: unknown) =>
            typeof item === 'string' ? { name: item } : (item as OptionItem)
          );
          setOptions(items);
        })
        .catch((err) => {
          if (err.name !== 'AbortError') {
            setOptions([]);
          }
        })
        .finally(() => setIsLoading(false));
    },
    [endpoint, searchParam, extraParams, dataKey]
  );

  // Fetch initial options when opened or params change
  useEffect(() => {
    if (open) {
      fetchOptions(search);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, extraParams]);

  // Clear value when params change (if enabled)
  useEffect(() => {
    if (clearOnParamChange && value) {
      onChange('');
    }
    if (resetOnParamChange) {
      setOptions([]);
      setSearch('');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [extraParams]);

  // Debounced search
  const handleSearchChange = (val: string) => {
    setSearch(val);
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => fetchOptions(val), 200);
  };

  const handleSelect = (option: OptionItem) => {
    onChange(option.name, option);
    setOpen(false);
    setSearch('');
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    onChange('');
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          role="combobox"
          aria-expanded={open}
          className="w-full justify-between font-normal"
          disabled={disabled}
        >
          <span className={cn('truncate', !value && 'text-muted-foreground')}>
            {value || placeholder}
          </span>
          <span className="flex items-center gap-1 shrink-0">
            {value && !disabled && (
              <X
                className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground"
                onClick={handleClear}
              />
            )}
            <ChevronsUpDown className="h-3.5 w-3.5 opacity-50" />
          </span>
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="w-[var(--radix-popover-trigger-width)] min-w-[260px] p-0"
        align="start"
      >
        <Command shouldFilter={false} className="h-auto">
          <CommandInput placeholder="Search..." value={search} onValueChange={handleSearchChange} />
          <CommandList className="max-h-[300px] overflow-y-auto overflow-x-hidden">
            {isLoading && (
              <div className="flex items-center justify-center py-6">
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              </div>
            )}
            {!isLoading && options.length === 0 && <CommandEmpty>No results found.</CommandEmpty>}
            {!isLoading && options.length > 0 && (
              <CommandGroup>
                {options.map((option) => (
                  <CommandItem
                    key={option.name}
                    value={option.name}
                    onSelect={() => handleSelect(option)}
                    className="flex items-center justify-between"
                  >
                    <span className="flex items-center gap-2">
                      <Check
                        className={cn(
                          'h-3.5 w-3.5',
                          value.toLowerCase() === option.name.toLowerCase()
                            ? 'opacity-100'
                            : 'opacity-0'
                        )}
                      />
                      {option.name}
                    </span>
                    {showShippingDays && option.shipping_days && (
                      <span className="text-xs text-muted-foreground ml-2">
                        {option.shipping_days}d
                      </span>
                    )}
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

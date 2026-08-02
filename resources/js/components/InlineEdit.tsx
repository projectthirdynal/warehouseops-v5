import { useState, useRef, useCallback, KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';
import { Check, X } from 'lucide-react';

interface InlineEditProps {
  value: string;
  onSave: (value: string) => void | Promise<void>;
  className?: string;
  inputClassName?: string;
  validate?: (value: string) => string | undefined;
  placeholder?: string;
  type?: 'text' | 'number' | 'email';
}

export function InlineEdit({
  value: initialValue,
  onSave,
  className,
  inputClassName,
  validate,
  placeholder,
  type = 'text',
}: InlineEditProps) {
  const [isEditing, setIsEditing] = useState(false);
  const [value, setValue] = useState(initialValue);
  const [error, setError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const handleSave = useCallback(async () => {
    if (error) return;
    if (value === initialValue) {
      setIsEditing(false);
      return;
    }
    setIsSaving(true);
    try {
      await onSave(value);
      setIsEditing(false);
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Save failed');
    } finally {
      setIsSaving(false);
    }
  }, [value, initialValue, onSave, error]);

  const handleCancel = useCallback(() => {
    setValue(initialValue);
    setError(null);
    setIsEditing(false);
  }, [initialValue]);

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      handleSave();
    } else if (e.key === 'Escape') {
      handleCancel();
    }
  };

  const handleChange = (v: string) => {
    setValue(v);
    if (validate) {
      const err = validate(v);
      setError(err ?? null);
    }
  };

  if (isEditing) {
    return (
      <div className={cn('flex items-center gap-1', className)}>
        <input
          ref={inputRef}
          type={type}
          value={value}
          onChange={(e) => handleChange(e.target.value)}
          onKeyDown={handleKeyDown}
          onBlur={handleSave}
          disabled={isSaving}
          placeholder={placeholder}
          autoFocus
          aria-invalid={error ? 'true' : 'false'}
          aria-describedby={error ? 'inline-edit-error' : undefined}
          className={cn(
            'h-8 rounded-md border bg-background px-2 py-1 text-sm outline-none transition-colors',
            error
              ? 'border-destructive focus:ring-1 focus:ring-destructive'
              : 'border-input focus:ring-1 focus:ring-ring',
            inputClassName
          )}
        />
        {error && (
          <>
            <X className="h-3.5 w-3.5 shrink-0 text-destructive" aria-hidden="true" />
            <span id="inline-edit-error" className="sr-only">
              {error}
            </span>
          </>
        )}
        {!error && <Check className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />}
      </div>
    );
  }

  return (
    <button
      type="button"
      onClick={() => {
        setIsEditing(true);
        setTimeout(() => inputRef.current?.focus(), 0);
      }}
      className={cn(
        'text-left text-sm hover:bg-muted/50 rounded px-1.5 py-0.5 transition-colors cursor-text',
        !value && 'text-muted-foreground italic',
        className
      )}
      title="Click to edit"
    >
      {value || placeholder || '—'}
    </button>
  );
}

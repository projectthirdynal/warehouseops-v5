import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface ExportButtonProps extends Omit<React.ButtonHTMLAttributes<HTMLButtonElement>, 'variant'> {
  label?: string;
}

export function ExportButton({ label = 'Export', ...props }: ExportButtonProps) {
  return (
    <Button variant="ink" size="pill" {...props}>
      <Download className="mr-1.5 h-4 w-4" />
      {label}
    </Button>
  );
}

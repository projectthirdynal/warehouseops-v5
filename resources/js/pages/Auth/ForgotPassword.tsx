import { Head, useForm } from '@inertiajs/react';
import { Mail, ArrowLeft, CheckCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Props {
  status?: string;
}

export default function ForgotPassword({ status }: Props) {
  const { data, setData, post, processing, errors } = useForm({ email: '' });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    post('/forgot-password');
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Head title="Forgot Password" />
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          <div className="flex justify-center mb-4">
            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
              <Mail className="h-8 w-8 text-primary" />
            </div>
          </div>
          <CardTitle className="text-2xl">Forgot password?</CardTitle>
          <CardDescription>
            Enter your email address and we'll send you a link to reset your password.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {status && (
            <div className="flex items-center gap-2 rounded-md border border-success/20 bg-success/5 px-4 py-3 text-sm text-success dark:bg-success/20 dark:border-success/30 dark:text-success">
              <CheckCircle className="h-4 w-4 shrink-0" />
              {status}
            </div>
          )}

          <form onSubmit={submit} className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="email">Email address</Label>
              <Input
                id="email"
                type="email"
                value={data.email}
                onChange={(e) => setData('email', e.target.value)}
                placeholder="you@example.com"
                autoComplete="email"
                autoFocus
              />
              {errors.email && <p className="text-xs text-destructive">{errors.email}</p>}
            </div>
            <Button type="submit" className="w-full" disabled={processing}>
              {processing ? 'Sending…' : 'Send Reset Link'}
            </Button>
          </form>

          <a
            href="/login"
            className="flex items-center justify-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
          >
            <ArrowLeft className="h-3.5 w-3.5" />
            Back to login
          </a>
        </CardContent>
      </Card>
    </div>
  );
}

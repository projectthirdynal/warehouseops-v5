import { Head, useForm, router } from '@inertiajs/react';
import { Mail, RefreshCw, LogOut, CheckCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';

interface Props {
  status?: string;
  email: string;
}

export default function VerifyEmail({ status, email }: Props) {
  const { post, processing } = useForm({});

  const resend = (e: React.FormEvent) => {
    e.preventDefault();
    post('/email/verification-notification');
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <Head title="Verify Your Email" />
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          <div className="flex justify-center mb-4">
            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
              <Mail className="h-8 w-8 text-primary" />
            </div>
          </div>
          <CardTitle className="text-2xl">Check your inbox</CardTitle>
          <CardDescription>
            We sent a verification link to <strong>{email}</strong>. Click the link in that email to
            activate your account.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {status && (
            <div className="flex items-center gap-2 rounded-md border border-success/20 bg-success/5 px-4 py-3 text-sm text-success dark:bg-success/20 dark:border-success/30 dark:text-success">
              <CheckCircle className="h-4 w-4 shrink-0" />
              {status}
            </div>
          )}

          <p className="text-sm text-muted-foreground text-center">
            Didn't receive the email? Check your spam folder, or resend below.
          </p>

          <form onSubmit={resend}>
            <Button type="submit" className="w-full" disabled={processing}>
              <RefreshCw className={`mr-2 h-4 w-4 ${processing ? 'animate-spin' : ''}`} />
              {processing ? 'Sending…' : 'Resend Verification Email'}
            </Button>
          </form>

          <div className="relative flex items-center">
            <div className="flex-1 border-t" />
            <span className="mx-3 text-xs text-muted-foreground">or</span>
            <div className="flex-1 border-t" />
          </div>

          <Button variant="outline" className="w-full" onClick={() => router.post('/logout')}>
            <LogOut className="mr-2 h-4 w-4" />
            Sign in with a different account
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}

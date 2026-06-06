import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Toaster } from '@/components/ui/sonner';

const appName = import.meta.env.VITE_APP_NAME || 'TECC';
const clientBuild = 'shop-queue-2026-06-03-v4';

createInertiaApp({
  title: (title) => `${title} - ${appName}`,
  resolve: (name) =>
    resolvePageComponent(
      `./pages/${name}.tsx`,
      import.meta.glob('./pages/**/*.tsx')
    ),
  setup({ el, App, props }) {
    el.dataset.clientBuild = clientBuild;
    const root = createRoot(el);
    root.render(
      <>
        <App {...props} />
        <Toaster />
      </>
    );
  },
  progress: {
    color: '#3b82f6',
    showSpinner: true,
  },
});

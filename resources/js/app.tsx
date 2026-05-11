import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

const appName = import.meta.env.VITE_APP_NAME || 'TECC';
const clientBuild = 'shop-queue-2026-05-11';

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
    root.render(<App {...props} />);
  },
  progress: {
    color: '#3b82f6',
    showSpinner: true,
  },
});

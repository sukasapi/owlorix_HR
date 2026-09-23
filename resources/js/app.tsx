import '@fontsource-variable/bricolage-grotesque';
import '@fontsource-variable/instrument-sans';

import { createInertiaApp } from '@inertiajs/react';

// App name from Pengaturan aplikasi, rendered by app.blade.php; a rename shows on the next full page load
const appName = document.querySelector<HTMLMetaElement>('meta[name="application-name"]')?.content || 'Owlorix HR';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    pages: './pages',
    progress: { color: '#C99A33' },
});

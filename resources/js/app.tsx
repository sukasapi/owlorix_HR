import '@fontsource-variable/bricolage-grotesque';
import '@fontsource-variable/instrument-sans';

import { createInertiaApp } from '@inertiajs/react';

createInertiaApp({
    title: (title) => (title ? `${title} · Owlorix HR` : 'Owlorix HR'),
    pages: './pages',
    progress: { color: '#C99A33' },
});

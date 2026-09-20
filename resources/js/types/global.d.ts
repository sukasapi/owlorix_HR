import type { route as ziggyRoute } from 'ziggy-js';

declare global {
    // Provided by the @routes Blade directive (tightenco/ziggy).
    const route: typeof ziggyRoute;
}

export {};

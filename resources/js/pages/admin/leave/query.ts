import type { AdminFilters } from '../../leave/types';

/** Only chosen values go into the query string, so the plain page stays /admin/cuti. */
export function adminQuery(filters: AdminFilters, quotaYear: number, thisYear: number): Record<string, string> {
    const query: Record<string, string> = {};
    if (filters.status !== 'all') query.status = filters.status;
    if (filters.orang !== null) query.orang = String(filters.orang);
    if (filters.bulan) query.bulan = filters.bulan;
    if (quotaYear !== thisYear) query.tahun = String(quotaYear);
    return query;
}

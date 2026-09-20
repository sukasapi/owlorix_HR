import { Dialog } from '@/components/ui/Dialog';
import { Notice } from '@/components/ui/Notice';
import { formatLongDate } from '@/lib/format';
import { useLocale, useT } from '@/lib/i18n';
import type { SharedProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { CalendarCheck, CalendarX, X } from '@phosphor-icons/react';
import { useId, useRef } from 'react';
import { EntrySection } from './EntrySection';
import { OpenedSection } from './OpenedSection';
import type { CalendarDate, CalendarPageProps } from './types';

interface Props {
    day: CalendarDate | null;
    today: string;
    can: CalendarPageProps['can'];
    scopes: CalendarPageProps['scopes'];
    onClose: () => void;
    onSelect: (date: string) => void;
}

/** Everything about one date: its studio status, the calendar entry, and the teams or people it is opened for. */
export function DayDialog({ day, today, can, scopes, onClose, onSelect }: Props) {
    const titleId = useId();

    return (
        <Dialog open={day !== null} onClose={onClose} labelledBy={titleId}>
            {day && <DayDetails key={day.date} day={day} today={today} can={can} scopes={scopes} titleId={titleId} onClose={onClose} onSelect={onSelect} />}
        </Dialog>
    );
}

function DayDetails({ day, today, can, scopes, titleId, onClose, onSelect }: Omit<Props, 'day'> & { day: CalendarDate; titleId: string }) {
    const t = useT();
    const locale = useLocale();
    const { flash } = usePage<SharedProps>().props;
    // Only a message that arrived while this date is open belongs in the dialog.
    const flashOnOpen = useRef(flash);
    const status = flash !== flashOnOpen.current ? flash.status : undefined;

    const statusLine = (() => {
        if (day.entry) {
            return day.entry.type === 'workday'
                ? t('calendar.day.status_entry_workday', { name: day.entry.name })
                : t('calendar.day.status_entry_off', { type: t(`calendar.types.${day.entry.type}`), name: day.entry.name });
        }
        return day.week_workday ? t('calendar.day.status_week_workday') : t('calendar.day.status_week_off');
    })();

    return (
        <div className="max-h-[calc(100dvh-2.5rem)] overflow-y-auto">
            <header className="flex flex-col gap-3 bg-panel px-5 pt-5 pb-5 sm:px-7">
                <div className="flex items-start justify-between gap-3">
                    <h2 id={titleId} className="h2 pt-1.5 text-[22px] sm:text-[24px]">
                        {formatLongDate(day.date, locale)}
                    </h2>
                    <button type="button" onClick={onClose} className="btn btn-secondary btn-sm flex-none bg-surface" aria-label={t('calendar.day.close')}>
                        <X weight="bold" size={16} aria-hidden />
                    </button>
                </div>
                <p className="m-0 flex items-start gap-2 font-medium">
                    {day.is_studio_workday ? (
                        <CalendarCheck weight="bold" size={20} className="mt-0.5 flex-none" aria-hidden />
                    ) : (
                        <CalendarX weight="bold" size={20} className="mt-0.5 flex-none" aria-hidden />
                    )}
                    <span>{statusLine}</span>
                </p>
            </header>

            {status && (
                <div className="px-5 pt-4 sm:px-7">
                    <Notice tone="success">{status}</Notice>
                </div>
            )}

            <EntrySection day={day} today={today} canManage={can.manage_calendar} onSaved={onSelect} />
            <OpenedSection day={day} canOpen={can.open_workdays} scopes={scopes} />
        </div>
    );
}

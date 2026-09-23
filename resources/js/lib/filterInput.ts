import { type ChangeEvent, type FocusEvent, type KeyboardEvent, useEffect, useRef, useState } from 'react';

/** A year typed digit by digit passes through 0002, 0020 and 0202 first; no studio record is that old. */
const MIN_YEAR = 2000;

export function isFilterMonth(text: string): boolean {
    const match = /^(\d{4})-(0[1-9]|1[0-2])$/.exec(text);
    return match !== null && Number(match[1]) >= MIN_YEAR;
}

export function isFilterDate(text: string): boolean {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
    if (match === null || Number(match[1]) < MIN_YEAR) return false;
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    return date.getUTCMonth() === Number(match[2]) - 1 && date.getUTCDate() === Number(match[3]);
}

/**
 * Props for a date or month filter box that reloads the list. The box keeps what is typed: a complete value is sent
 * right away, an empty box on blur or Enter, and a half-typed one goes back to the applied filter on blur. While the
 * box has focus, a response never overwrites it.
 */
export function useFilterInput(applied: string | null, isComplete: (text: string) => boolean, commit: (next: string | null) => void) {
    const [text, setText] = useState(applied ?? '');
    const focused = useRef(false);
    const sent = useRef(applied);

    useEffect(() => {
        sent.current = applied;
        if (!focused.current) setText(applied ?? '');
    }, [applied]);

    const send = (next: string) => {
        const value = next === '' ? null : next;
        if (value === sent.current) return;
        sent.current = value;
        commit(value);
    };

    const settle = (input: HTMLInputElement) => {
        // A date box with some parts filled reports '' and badInput; that is not a request to clear the filter
        if ((text === '' && !input.validity.badInput) || isComplete(text)) {
            send(text);
            return;
        }
        input.value = sent.current ?? '';
        setText(sent.current ?? '');
    };

    return {
        value: text,
        onChange: (event: ChangeEvent<HTMLInputElement>) => {
            setText(event.target.value);
            if (isComplete(event.target.value)) send(event.target.value);
        },
        onFocus: () => {
            focused.current = true;
        },
        onBlur: (event: FocusEvent<HTMLInputElement>) => {
            focused.current = false;
            settle(event.currentTarget);
        },
        onKeyDown: (event: KeyboardEvent<HTMLInputElement>) => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            settle(event.currentTarget);
        },
    };
}

/** Filter choices shown at once while the visit runs, then taken from the page props the response brings. */
export function useChosenFilters<T>(applied: T) {
    const [chosen, setChosen] = useState(applied);
    useEffect(() => setChosen(applied), [applied]);
    return [chosen, setChosen] as const;
}

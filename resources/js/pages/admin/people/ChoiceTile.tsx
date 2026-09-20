import type { InputHTMLAttributes, ReactNode } from 'react';

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'type' | 'children'> & {
    type: 'checkbox' | 'radio';
    label: ReactNode;
    help?: ReactNode;
};

/**
 * A checkbox or radio inside a 44px-tall bordered tile, so the whole tile is the tap target.
 * The checked tile takes the selected surface and the focus color border; the native control keeps its focus ring.
 */
export function ChoiceTile({ type, label, help, disabled, className = '', ...input }: Props) {
    return (
        <label
            className={`flex min-h-11 items-start gap-2.5 rounded-sm border-[1.5px] border-line-strong px-3 py-2.5 has-checked:border-[var(--focus)] has-checked:bg-[var(--selected)] ${
                disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-[color-mix(in_srgb,var(--selected)_60%,transparent)]'
            } ${className}`}
        >
            <input type={type} disabled={disabled} className="mt-0.5 size-[18px] flex-none accent-[var(--primary-bg)]" {...input} />
            <span className="min-w-0">
                <span className="block text-[15px] font-semibold leading-snug">{label}</span>
                {help && <span className="mt-0.5 block text-[13px] leading-snug text-muted">{help}</span>}
            </span>
        </label>
    );
}

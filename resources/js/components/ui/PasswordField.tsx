import { Eye, EyeSlash } from '@phosphor-icons/react';
import { type InputHTMLAttributes, type ReactNode, useState } from 'react';
import { Field } from './Field';

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & {
    label: string;
    error?: string;
    help?: ReactNode;
    showLabel: string;
    hideLabel: string;
};

export function PasswordField({ label, error, help, showLabel, hideLabel, className, ...input }: Props) {
    const [visible, setVisible] = useState(false);

    return (
        <Field label={label} error={error} help={help} className={className}>
            {({ id, describedBy, invalid }) => (
                <div className="relative">
                    <input
                        id={id}
                        type={visible ? 'text' : 'password'}
                        className="input pr-28"
                        aria-describedby={describedBy}
                        aria-invalid={invalid}
                        {...input}
                    />
                    <button
                        type="button"
                        onClick={() => setVisible((v) => !v)}
                        aria-pressed={visible}
                        aria-controls={id}
                        className="absolute inset-y-1 right-1 inline-flex items-center gap-1 rounded-sm px-3 text-[13px] font-semibold text-muted hover:text-ink"
                    >
                        {visible ? <EyeSlash weight="bold" size={15} aria-hidden /> : <Eye weight="bold" size={15} aria-hidden />}
                        {visible ? hideLabel : showLabel}
                    </button>
                </div>
            )}
        </Field>
    );
}

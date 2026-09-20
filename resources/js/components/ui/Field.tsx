import { WarningCircle } from '@phosphor-icons/react';
import { type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes, useId } from 'react';

interface FieldProps {
    label: string;
    error?: string;
    help?: ReactNode;
    children: (ids: { id: string; describedBy?: string; invalid: boolean }) => ReactNode;
    className?: string;
}

/** Label, control, help, and error wired together for screen readers. */
export function Field({ label, error, help, children, className = '' }: FieldProps) {
    const id = useId();
    const helpId = help ? `${id}-help` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy = [helpId, errorId].filter(Boolean).join(' ') || undefined;

    return (
        <div className={`field ${className}`}>
            <label htmlFor={id} className="label">
                {label}
            </label>
            {children({ id, describedBy, invalid: Boolean(error) })}
            {help && (
                <p id={helpId} className="help m-0">
                    {help}
                </p>
            )}
            {error && (
                <p id={errorId} className="error-text m-0 flex items-center gap-1.5" role="alert">
                    <WarningCircle weight="bold" size={15} aria-hidden />
                    {error}
                </p>
            )}
        </div>
    );
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string; help?: ReactNode };

export function TextField({ label, error, help, className, ...input }: InputProps) {
    return (
        <Field label={label} error={error} help={help} className={className}>
            {({ id, describedBy, invalid }) => (
                <input id={id} className="input" aria-describedby={describedBy} aria-invalid={invalid} {...input} />
            )}
        </Field>
    );
}

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; error?: string; help?: ReactNode };

export function TextAreaField({ label, error, help, className, ...textarea }: TextareaProps) {
    return (
        <Field label={label} error={error} help={help} className={className}>
            {({ id, describedBy, invalid }) => (
                <textarea id={id} className="input min-h-[92px]" aria-describedby={describedBy} aria-invalid={invalid} {...textarea} />
            )}
        </Field>
    );
}

type SelectProps = SelectHTMLAttributes<HTMLSelectElement> & { label: string; error?: string; help?: ReactNode; children: ReactNode };

export function SelectField({ label, error, help, className, children, ...select }: SelectProps) {
    return (
        <Field label={label} error={error} help={help} className={className}>
            {({ id, describedBy, invalid }) => (
                <select id={id} className="input" aria-describedby={describedBy} aria-invalid={invalid} {...select}>
                    {children}
                </select>
            )}
        </Field>
    );
}

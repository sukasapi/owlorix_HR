interface Props {
    initials: string;
    photoUrl?: string | null;
    /** Tailwind size classes; the default matches the `.avatar` class. */
    className?: string;
}

/** Profile photo when the person uploaded one (DESIGN.md: no generated faces), initials otherwise. Decorative: the name is always next to it. */
export function Avatar({ initials, photoUrl, className = '' }: Props) {
    if (photoUrl) {
        return <img src={photoUrl} alt="" aria-hidden className={`avatar object-cover p-0 ${className}`} loading="lazy" decoding="async" />;
    }

    return (
        <span className={`avatar ${className}`} aria-hidden>
            {initials}
        </span>
    );
}

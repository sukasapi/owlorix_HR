import type { EyeState } from '@/components/owl/OwlEyes';
import { OwlHero } from '@/components/owl/OwlHero';
import { useThemeSync } from '@/lib/theme';
import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

interface Props {
    title: string;
    greeting: string;
    lead: string;
    eyes?: EyeState;
    children: ReactNode;
}

/**
 * Two halves as in the desktop sign-in mockup: a brow-cut greeting panel with the owl
 * (3D where it can run, SVG eyes otherwise) and the form. Stacks on phones.
 */
export default function AuthLayout({ title, greeting, lead, eyes = 'closed', children }: Props) {
    useThemeSync();

    return (
        <>
            <Head title={title} />
            <main className="mx-auto grid min-h-dvh max-w-[1040px] items-center gap-6 px-4 py-6 sm:px-8 md:grid-cols-2 md:gap-10">
                <section className="brow flex flex-col px-7 py-8 sm:px-9 sm:py-10 md:min-h-[520px]">
                    {/* The logo file has black lettering on white, so it keeps its white ground in both themes. */}
                    <img src="/owlorix-logo.png" alt="Owlorix Creative Lab" width={96} height={96} className="size-24 self-start rounded-md bg-white" />
                    <p className="display m-0 mt-8 text-[40px] text-heading sm:text-[46px]">{greeting}</p>
                    <p className="m-0 mt-3 max-w-[40ch] text-base text-muted">{lead}</p>
                    <div className="mt-8 flex flex-1 items-end justify-center md:justify-start">
                        <OwlHero state={eyes} />
                    </div>
                </section>
                <section className="px-1 sm:px-4">{children}</section>
            </main>
        </>
    );
}

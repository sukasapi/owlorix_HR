import { OwlEyes } from '@/components/owl/OwlEyes';
import { Head } from '@inertiajs/react';

interface Action {
    label: string;
    href: string;
}

interface Props {
    page: {
        status: number;
        title: string;
        body: string;
        code: string;
        note: string | null;
        primary: Action;
        secondary: Action | null;
    };
}

/**
 * An error during a visit inside the app (App\Http\ErrorPage::inertia). It gets no shared props, since those read the
 * database, so every string arrives translated in `page`. Links are plain anchors: a full page load is the surest way
 * back, and while the server is still failing it lands on the Blade version of this page.
 */
export default function Show({ page }: Props) {
    return (
        <>
            <Head title={page.title} />
            <main className="mx-auto grid min-h-dvh w-full max-w-[34rem] content-center px-4 py-6">
                <section className="brow px-6 pt-7 pb-6 sm:px-8">
                    <div className="mb-6">
                        <OwlEyes state="half" size={88} />
                    </div>
                    <h1 className="display m-0 text-[30px] text-heading sm:text-[36px]">{page.title}</h1>
                    <p className="m-0 mt-3 max-w-[42ch]">{page.body}</p>
                    <div className="mt-6 flex flex-wrap gap-3">
                        <a className="btn btn-primary max-[420px]:flex-[1_1_100%]" href={page.primary.href}>
                            {page.primary.label}
                        </a>
                        {page.secondary && (
                            <a className="btn btn-secondary max-[420px]:flex-[1_1_100%]" href={page.secondary.href}>
                                {page.secondary.label}
                            </a>
                        )}
                    </div>
                </section>
                {page.note && <p className="m-0 mx-1 mt-5 text-[15px] text-muted">{page.note}</p>}
                <p className="m-0 mx-1 mt-3 text-[13px] text-muted">{page.code}</p>
            </main>
        </>
    );
}

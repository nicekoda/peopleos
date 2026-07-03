import { Link, usePage } from '@inertiajs/react';
import { PageProps } from '@/types';

export default function Topbar({ onToggleSidebar }: { onToggleSidebar: () => void }) {
    const { auth, tenant } = usePage<PageProps>().props;

    return (
        <header className="flex h-16 shrink-0 items-center gap-4 border-b border-slate-200 bg-white px-4 sm:px-6">
            <button
                type="button"
                onClick={onToggleSidebar}
                className="rounded-md p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
                aria-label="Toggle navigation menu"
            >
                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                </svg>
            </button>

            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-slate-900">
                    {tenant ? tenant.name : 'PeopleOS Platform'}
                </p>
            </div>

            {auth.user && (
                <div className="flex items-center gap-3">
                    <div className="hidden text-right sm:block">
                        <p className="text-sm font-medium text-slate-900">{auth.user.name}</p>
                        <p className="text-xs text-slate-500">{auth.user.email}</p>
                    </div>
                    <div className="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700">
                        {auth.user.name.charAt(0).toUpperCase()}
                    </div>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900"
                    >
                        Log out
                    </Link>
                </div>
            )}
        </header>
    );
}

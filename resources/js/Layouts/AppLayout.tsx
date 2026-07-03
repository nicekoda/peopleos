import { ReactNode, useState } from 'react';
import { Link } from '@inertiajs/react';
import Sidebar from '@/Components/Sidebar';
import Topbar from '@/Components/Topbar';

export default function AppLayout({ children }: { children: ReactNode }) {
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <div className="min-h-screen bg-slate-50">
            {/* Desktop sidebar */}
            <div className="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col">
                <div className="flex grow flex-col overflow-y-auto border-r border-slate-200 bg-white">
                    <div className="flex h-16 shrink-0 items-center border-b border-slate-200 px-6">
                        <Link href="/dashboard" className="text-lg font-bold tracking-tight text-slate-900">
                            PeopleOS
                        </Link>
                    </div>
                    <Sidebar />
                </div>
            </div>

            {/* Mobile sidebar */}
            {mobileOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="fixed inset-0 bg-slate-900/50" onClick={() => setMobileOpen(false)} aria-hidden="true" />
                    <div className="relative flex h-full w-64 flex-col bg-white shadow-xl">
                        <div className="flex h-16 shrink-0 items-center justify-between border-b border-slate-200 px-6">
                            <span className="text-lg font-bold tracking-tight text-slate-900">PeopleOS</span>
                            <button
                                type="button"
                                onClick={() => setMobileOpen(false)}
                                className="rounded-md p-1 text-slate-500 hover:bg-slate-100"
                                aria-label="Close navigation menu"
                            >
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <Sidebar onNavigate={() => setMobileOpen(false)} />
                    </div>
                </div>
            )}

            <div className="lg:pl-64">
                <Topbar onToggleSidebar={() => setMobileOpen(true)} />
                <main className="px-4 py-8 sm:px-6 lg:px-8">
                    <div className="mx-auto max-w-6xl">{children}</div>
                </main>
            </div>
        </div>
    );
}

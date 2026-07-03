import { Link } from '@inertiajs/react';
import { ReactNode } from 'react';

interface NavItemProps {
    href: string;
    active: boolean;
    icon: ReactNode;
    children: ReactNode;
    onNavigate?: () => void;
}

export default function NavItem({ href, active, icon, children, onNavigate }: NavItemProps) {
    return (
        <Link
            href={href}
            onClick={onNavigate}
            className={`flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                active ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
            }`}
            aria-current={active ? 'page' : undefined}
        >
            <span className={`h-5 w-5 shrink-0 ${active ? 'text-indigo-600' : 'text-slate-400'}`}>{icon}</span>
            {children}
        </Link>
    );
}

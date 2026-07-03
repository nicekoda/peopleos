import { ReactNode } from 'react';

export default function Card({ title, children, className = '' }: { title?: string; children: ReactNode; className?: string }) {
    return (
        <div className={`rounded-lg border border-slate-200 bg-white p-5 shadow-sm ${className}`}>
            {title && <h3 className="mb-3 text-sm font-semibold text-slate-900">{title}</h3>}
            {children}
        </div>
    );
}

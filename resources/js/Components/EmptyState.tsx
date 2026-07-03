import { ReactNode } from 'react';

interface EmptyStateProps {
    title: string;
    description?: string;
    action?: ReactNode;
}

export default function EmptyState({ title, description, action }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100">
                <svg className="h-6 w-6 text-slate-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 13h6m-6-4h6m2 5.5V6a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v13l3-1.5 3 1.5 3-1.5Z" />
                </svg>
            </div>
            <h3 className="mt-4 text-sm font-semibold text-slate-900">{title}</h3>
            {description && <p className="mt-1 max-w-sm text-sm text-slate-500">{description}</p>}
            {action && <div className="mt-6">{action}</div>}
        </div>
    );
}

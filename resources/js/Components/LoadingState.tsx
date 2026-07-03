export default function LoadingState({ label = 'Loading…' }: { label?: string }) {
    return (
        <div className="flex items-center justify-center gap-3 py-16 text-sm text-slate-500" role="status">
            <svg className="h-5 w-5 animate-spin text-slate-400" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4Z" />
            </svg>
            {label}
        </div>
    );
}

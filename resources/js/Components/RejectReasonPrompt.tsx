import { useState } from 'react';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';

interface RejectReasonPromptProps {
    onConfirm: (reason: string) => void;
    onCancel: () => void;
    submitting: boolean;
    error?: string;
}

/**
 * Rejection requires a reason (Refinement 5) — this is a UI convenience
 * that reveals a required textarea before calling the reject endpoint;
 * RejectLeaveRequestRequest on the backend independently requires
 * rejection_reason regardless of what this component does.
 */
export default function RejectReasonPrompt({ onConfirm, onCancel, submitting, error }: RejectReasonPromptProps) {
    const [reason, setReason] = useState('');

    return (
        <div className="rounded-md border border-slate-200 bg-slate-50 p-4">
            <label htmlFor="rejection_reason" className="block text-sm font-medium text-slate-700">
                Rejection reason <span className="text-red-500">*</span>
            </label>
            <textarea
                id="rejection_reason"
                required
                rows={3}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
            />
            <ErrorMessage message={error} />

            <div className="mt-3 flex gap-3">
                <Button
                    type="button"
                    variant="danger"
                    disabled={submitting || reason.trim() === ''}
                    onClick={() => onConfirm(reason)}
                >
                    {submitting ? 'Rejecting…' : 'Confirm rejection'}
                </Button>
                <Button type="button" variant="secondary" disabled={submitting} onClick={onCancel}>
                    Cancel
                </Button>
            </div>
        </div>
    );
}

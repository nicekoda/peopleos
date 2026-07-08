import { Head, Link, usePage } from '@inertiajs/react';
import { FormEventHandler, useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import ErrorMessage from '@/Components/ErrorMessage';
import PermissionGate from '@/Components/PermissionGate';
import { SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { ApplicationStage, JobApplication } from '@/types/recruitment';
import { PageProps } from '@/types';

interface ShowProps extends PageProps {
    applicationId: string;
}

const stageTone: Record<ApplicationStage, 'neutral' | 'success' | 'warning' | 'danger'> = {
    applied: 'neutral',
    screening: 'neutral',
    interview: 'warning',
    offer: 'warning',
    rejected: 'danger',
    hired: 'success',
    withdrawn: 'danger',
};

/**
 * Mirrors ApplicationStage::allowedNextStates() (backend, single source
 * of truth) purely so the dropdown doesn't offer an obviously illegal
 * choice — the backend re-validates every transition regardless.
 */
const allowedNextStages: Record<ApplicationStage, ApplicationStage[]> = {
    applied: ['applied', 'screening', 'rejected', 'withdrawn'],
    screening: ['screening', 'interview', 'rejected', 'withdrawn'],
    interview: ['interview', 'offer', 'rejected', 'withdrawn'],
    offer: ['offer', 'hired', 'rejected', 'withdrawn'],
    rejected: ['rejected'],
    hired: ['hired'],
    withdrawn: ['withdrawn'],
};

export default function RecruitmentApplicationShow() {
    const { applicationId } = usePage<ShowProps>().props;

    const [application, setApplication] = useState<JobApplication | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [stageDraft, setStageDraft] = useState<ApplicationStage | ''>('');
    const [updatingStage, setUpdatingStage] = useState(false);
    const [noteText, setNoteText] = useState('');
    const [notingError, setNotingError] = useState<string | null>(null);
    const [addingNote, setAddingNote] = useState(false);
    const [updatingReadiness, setUpdatingReadiness] = useState(false);

    const load = useCallback(() => {
        setError(null);
        api.get<{ data: JobApplication }>(`/job-applications/${applicationId}`)
            .then((response) => {
                setApplication(response.data.data);
                setStageDraft(response.data.data.stage);
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, [applicationId]);

    useEffect(() => {
        load();
    }, [load]);

    const submitStage: FormEventHandler = (e) => {
        e.preventDefault();
        if (!application || !stageDraft || stageDraft === application.stage) return;

        setUpdatingStage(true);
        setError(null);

        api.patch<{ data: JobApplication }>(`/job-applications/${applicationId}/stage`, { stage: stageDraft })
            .then((response) => setApplication(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            })
            .finally(() => setUpdatingStage(false));
    };

    const submitNote: FormEventHandler = (e) => {
        e.preventDefault();
        if (!noteText.trim()) return;

        setAddingNote(true);
        setNotingError(null);

        api.post(`/job-applications/${applicationId}/notes`, { note: noteText })
            .then(() => {
                setNoteText('');
                load();
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setNotingError(apiError.message);
                }
            })
            .finally(() => setAddingNote(false));
    };

    const toggleReadyForConversion = () => {
        if (!application) return;

        setUpdatingReadiness(true);
        setError(null);

        api.patch<{ data: JobApplication }>(`/job-applications/${applicationId}/ready-for-conversion`, {
            ready_for_conversion: !application.ready_for_conversion,
        })
            .then((response) => setApplication(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            })
            .finally(() => setUpdatingReadiness(false));
    };

    if (error) {
        return (
            <AppLayout>
                <Head title="Application" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            </AppLayout>
        );
    }

    if (!application) {
        return (
            <AppLayout>
                <Head title="Application" />
                <LoadingState label="Loading application…" />
            </AppLayout>
        );
    }

    const applicantName = application.applicant
        ? `${application.applicant.first_name} ${application.applicant.last_name}`
        : 'Unknown applicant';
    const isTerminalStage = application.stage === 'rejected' || application.stage === 'hired' || application.stage === 'withdrawn';

    return (
        <AppLayout>
            <Head title={applicantName} />

            <PageHeader
                title={applicantName}
                description={`Applying for: ${application.job?.title ?? 'Unknown job opening'}`}
                actions={
                    <Link href="/recruitment/applications" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Applications
                    </Link>
                }
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Applicant details">
                    <dl className="divide-y divide-slate-100">
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Email</dt>
                            <dd className="font-medium text-slate-900">{application.applicant?.email ?? '—'}</dd>
                        </div>
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Phone</dt>
                            <dd className="font-medium text-slate-900">{application.applicant?.phone ?? '—'}</dd>
                        </div>
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Source</dt>
                            <dd className="font-medium text-slate-900">{application.applicant?.source ?? '—'}</dd>
                        </div>
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Stage</dt>
                            <dd>
                                <Badge tone={stageTone[application.stage]}>{application.stage}</Badge>
                            </dd>
                        </div>
                    </dl>
                    {application.cover_letter && (
                        <div className="mt-4">
                            <h4 className="text-sm font-semibold text-slate-900">Cover letter</h4>
                            <p className="mt-1 whitespace-pre-wrap text-sm text-slate-600">{application.cover_letter}</p>
                        </div>
                    )}
                </Card>

                <Card title="Pipeline">
                    <PermissionGate
                        permission="job_applications.update_stage"
                        fallback={<p className="text-sm text-slate-500">You don't have permission to change this application's stage.</p>}
                    >
                        {isTerminalStage ? (
                            <p className="text-sm text-slate-600">This application is {application.stage} and cannot move further.</p>
                        ) : (
                            <form onSubmit={submitStage} className="flex items-end gap-3">
                                <div className="flex-1">
                                    <SelectField
                                        label="Move to stage"
                                        name="stage"
                                        value={stageDraft}
                                        onChange={(e) => setStageDraft(e.target.value as ApplicationStage)}
                                    >
                                        {allowedNextStages[application.stage].map((stage) => (
                                            <option key={stage} value={stage}>
                                                {stage}
                                            </option>
                                        ))}
                                    </SelectField>
                                </div>
                                <Button type="submit" disabled={updatingStage || stageDraft === application.stage}>
                                    {updatingStage ? 'Saving…' : 'Update stage'}
                                </Button>
                            </form>
                        )}
                    </PermissionGate>

                    <div className="mt-6 border-t border-slate-100 pt-4">
                        <div className="flex items-center justify-between">
                            <div>
                                <h4 className="text-sm font-semibold text-slate-900">Ready for conversion</h4>
                                <p className="mt-1 text-sm text-slate-500">
                                    A milestone flag only — no employee record is created automatically. See the roadmap for the
                                    planned candidate-to-employee conversion flow.
                                </p>
                            </div>
                            <Badge tone={application.ready_for_conversion ? 'success' : 'neutral'}>
                                {application.ready_for_conversion ? 'Ready' : 'Not ready'}
                            </Badge>
                        </div>
                        <PermissionGate permission="job_applications.mark_ready_for_conversion">
                            <div className="mt-3 flex items-center gap-3">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={toggleReadyForConversion}
                                    disabled={updatingReadiness || application.stage === 'rejected' || application.stage === 'withdrawn'}
                                >
                                    {updatingReadiness
                                        ? 'Saving…'
                                        : application.ready_for_conversion
                                          ? 'Mark not ready'
                                          : 'Mark ready for conversion'}
                                </Button>
                                <span
                                    title="Candidate-to-employee conversion is not built yet — see docs/architecture.md for the planned flow."
                                >
                                    <Button type="button" variant="secondary" disabled>
                                        Convert to Employee (coming soon)
                                    </Button>
                                </span>
                            </div>
                        </PermissionGate>
                    </div>
                </Card>
            </div>

            <div className="mt-4">
                <Card title="Internal notes">
                    <p className="mb-3 text-xs text-slate-500">Internal only — never visible to the applicant.</p>
                    {(application.notes ?? []).length === 0 && (
                        <p className="text-sm text-slate-500">No notes yet.</p>
                    )}
                    <ul className="divide-y divide-slate-100">
                        {(application.notes ?? []).map((note) => (
                            <li key={note.id} className="py-2 text-sm">
                                <p className="text-slate-700">{note.note}</p>
                                <p className="mt-1 text-xs text-slate-400">
                                    {note.author ?? 'Unknown'} · {note.created_at?.slice(0, 10) ?? ''}
                                </p>
                            </li>
                        ))}
                    </ul>

                    <PermissionGate permission="job_applications.add_note">
                        <form onSubmit={submitNote} className="mt-4">
                            <label htmlFor="note" className="block text-sm font-medium text-slate-700">
                                Add a note
                            </label>
                            <textarea
                                id="note"
                                rows={3}
                                value={noteText}
                                onChange={(e) => setNoteText(e.target.value)}
                                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            />
                            <ErrorMessage message={notingError ?? undefined} />
                            <div className="mt-2 flex justify-end">
                                <Button type="submit" disabled={addingNote || !noteText.trim()}>
                                    {addingNote ? 'Adding…' : 'Add note'}
                                </Button>
                            </div>
                        </form>
                    </PermissionGate>
                </Card>
            </div>
        </AppLayout>
    );
}

import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { JobApplication, ApplicationStage, PaginatedResponse } from '@/types/recruitment';

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
 * Application list, fetched client-side from /api/v1/job-applications
 * (Checkpoint 39) — same pattern as every other module's index page.
 */
export default function RecruitmentApplicationsIndex() {
    const [applications, setApplications] = useState<JobApplication[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<JobApplication>>('/job-applications')
            .then((response) => setApplications(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    return (
        <AppLayout>
            <Head title="Applications" />

            <PageHeader
                title="Applications"
                description="Applicants moving through the hiring pipeline."
                actions={
                    <PermissionGate permission="job_applications.create">
                        <Link
                            href="/recruitment/applications/create"
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        >
                            New application
                        </Link>
                    </PermissionGate>
                }
            />

            {error && <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>}

            {applications === null && !error && <LoadingState label="Loading applications…" />}

            {applications !== null && applications.length === 0 && (
                <EmptyState title="No applications yet" description="Applications created for this tenant will appear here." />
            )}

            {applications !== null && applications.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Applicant</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Job opening</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Stage</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Ready for conversion</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {applications.map((application) => (
                                <tr key={application.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                                        <Link
                                            href={`/recruitment/applications/${application.id}`}
                                            className="text-indigo-600 hover:text-indigo-500"
                                        >
                                            {application.applicant
                                                ? `${application.applicant.first_name} ${application.applicant.last_name}`
                                                : '—'}
                                        </Link>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-700">{application.job?.title ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={stageTone[application.stage]}>{application.stage}</Badge>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        {application.ready_for_conversion ? (
                                            <Badge tone="success">Ready</Badge>
                                        ) : (
                                            <span className="text-slate-400">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </AppLayout>
    );
}

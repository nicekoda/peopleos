import { Head, Link } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { JobOpening, RecruitmentJobStatus, PaginatedResponse } from '@/types/recruitment';

const statusTone: Record<RecruitmentJobStatus, 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    open: 'success',
    on_hold: 'warning',
    closed: 'neutral',
    cancelled: 'danger',
};

/**
 * Job opening list, fetched client-side from /api/v1/job-openings
 * (Checkpoint 39) — same pattern as every other module's index page.
 */
export default function RecruitmentJobsIndex() {
    const [jobs, setJobs] = useState<JobOpening[] | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<PaginatedResponse<JobOpening>>('/job-openings')
            .then((response) => setJobs(response.data.data))
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
            <Head title="Job Openings" />

            <PageHeader
                title="Job Openings"
                description="Roles open for recruitment."
                actions={
                    <PermissionGate permission="job_openings.create">
                        <Link
                            href="/recruitment/jobs/create"
                            className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        >
                            New job opening
                        </Link>
                    </PermissionGate>
                }
            />

            {error && <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>}

            {jobs === null && !error && <LoadingState label="Loading job openings…" />}

            {jobs !== null && jobs.length === 0 && (
                <EmptyState title="No job openings yet" description="Job openings created for this tenant will appear here." />
            )}

            {jobs !== null && jobs.length > 0 && (
                <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Title</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Department</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Location</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-600">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {jobs.map((job) => (
                                <tr key={job.id}>
                                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                                        <PermissionGate
                                            permission="job_openings.update"
                                            fallback={<span>{job.title}</span>}
                                        >
                                            <Link
                                                href={`/recruitment/jobs/${job.id}/edit`}
                                                className="text-indigo-600 hover:text-indigo-500"
                                            >
                                                {job.title}
                                            </Link>
                                        </PermissionGate>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-700">{job.department?.name ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3 text-slate-700">{job.location?.name ?? '—'}</td>
                                    <td className="whitespace-nowrap px-4 py-3">
                                        <Badge tone={statusTone[job.status]}>{job.status.replace('_', ' ')}</Badge>
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

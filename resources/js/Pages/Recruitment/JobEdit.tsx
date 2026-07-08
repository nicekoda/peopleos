import { Head, Link, router, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import Badge from '@/Components/Badge';
import LoadingState from '@/Components/LoadingState';
import ErrorMessage from '@/Components/ErrorMessage';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Department } from '@/types/department';
import { Position } from '@/types/position';
import { Location } from '@/types/location';
import { JobOpening, JobOpeningEditPayload, RecruitmentJobStatus, PaginatedResponse } from '@/types/recruitment';
import { PageProps } from '@/types';

interface EditProps extends PageProps {
    jobId: string;
}

/**
 * Mirrors RecruitmentJobStatus::allowedNextStates() (backend, single
 * source of truth) purely so the dropdown doesn't offer an obviously
 * illegal choice — the backend re-validates every transition regardless.
 */
const allowedNextStates: Record<RecruitmentJobStatus, RecruitmentJobStatus[]> = {
    draft: ['draft', 'open', 'cancelled'],
    open: ['open', 'on_hold', 'closed', 'cancelled'],
    on_hold: ['on_hold', 'open', 'closed', 'cancelled'],
    closed: ['closed'],
    cancelled: ['cancelled'],
};

export default function RecruitmentJobEdit() {
    const { jobId } = usePage<EditProps>().props;

    const [job, setJob] = useState<JobOpening | null>(null);
    const [form, setForm] = useState<JobOpeningEditPayload | null>(null);
    const [departments, setDepartments] = useState<Department[] | null>(null);
    const [positions, setPositions] = useState<Position[] | null>(null);
    const [locations, setLocations] = useState<Location[] | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        Promise.all([
            api.get<{ data: JobOpening }>(`/job-openings/${jobId}`),
            api.get<PaginatedResponse<Department>>('/departments'),
            api.get<PaginatedResponse<Position>>('/positions'),
            api.get<PaginatedResponse<Location>>('/locations'),
        ])
            .then(([jobRes, departmentsRes, positionsRes, locationsRes]) => {
                const data = jobRes.data.data;
                setJob(data);
                setForm({
                    title: data.title,
                    department_id: data.department_id ?? '',
                    position_id: data.position_id ?? '',
                    location_id: data.location_id ?? '',
                    employment_type: data.employment_type ?? '',
                    description: data.description ?? '',
                    status: data.status,
                });
                setDepartments(departmentsRes.data.data);
                setPositions(positionsRes.data.data);
                setLocations(locationsRes.data.data);
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, [jobId]);

    const set = <K extends keyof JobOpeningEditPayload>(key: K, value: JobOpeningEditPayload[K]) => {
        setForm((prev) => (prev ? { ...prev, [key]: value } : prev));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!form) return;

        setSubmitting(true);
        setErrors({});
        setGeneralError(null);
        setSuccessMessage(null);

        const payload = {
            title: form.title,
            department_id: form.department_id || null,
            position_id: form.position_id || null,
            location_id: form.location_id || null,
            employment_type: form.employment_type || null,
            description: form.description || null,
            ...(form.status ? { status: form.status } : {}),
        };

        api.patch<{ data: JobOpening }>(`/job-openings/${jobId}`, payload)
            .then((response) => {
                setSuccessMessage('Job opening updated.');
                const data = response.data.data;
                setJob(data);
                setForm((prev) => (prev ? { ...prev, status: data.status } : prev));
            })
            .catch((err) => {
                const apiError: ApiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) {
                    return;
                }
                if (apiError.errors) {
                    setErrors(apiError.errors);
                }
                setGeneralError(apiError.message);
            })
            .finally(() => setSubmitting(false));
    };

    if (loadError) {
        return (
            <AppLayout>
                <Head title="Edit job opening" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            </AppLayout>
        );
    }

    if (!job || !form || departments === null || positions === null || locations === null) {
        return (
            <AppLayout>
                <Head title="Edit job opening" />
                <LoadingState label="Loading job opening…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title={`Edit ${job.title}`} />

            <PageHeader
                title={job.title}
                description={<Badge tone="neutral">{job.status.replace('_', ' ')}</Badge>}
                actions={
                    <Link href="/recruitment/jobs" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Job Openings
                    </Link>
                }
            />

            {successMessage && (
                <div className="mb-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{successMessage}</div>
            )}
            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <InputField
                                label="Title"
                                name="title"
                                required
                                value={form.title}
                                onChange={(e) => set('title', e.target.value)}
                                error={fieldError('title')}
                            />
                        </div>
                        <SelectField
                            label="Department"
                            name="department_id"
                            value={form.department_id}
                            onChange={(e) => set('department_id', e.target.value)}
                            error={fieldError('department_id')}
                        >
                            <option value="">— None —</option>
                            {departments.map((department) => (
                                <option key={department.id} value={department.id}>
                                    {department.name}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField
                            label="Position"
                            name="position_id"
                            value={form.position_id}
                            onChange={(e) => set('position_id', e.target.value)}
                            error={fieldError('position_id')}
                        >
                            <option value="">— None —</option>
                            {positions.map((position) => (
                                <option key={position.id} value={position.id}>
                                    {position.name}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField
                            label="Location"
                            name="location_id"
                            value={form.location_id}
                            onChange={(e) => set('location_id', e.target.value)}
                            error={fieldError('location_id')}
                        >
                            <option value="">— None —</option>
                            {locations.map((location) => (
                                <option key={location.id} value={location.id}>
                                    {location.name}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField
                            label="Employment type"
                            name="employment_type"
                            value={form.employment_type}
                            onChange={(e) => set('employment_type', e.target.value as JobOpeningEditPayload['employment_type'])}
                            error={fieldError('employment_type')}
                        >
                            <option value="">— None —</option>
                            <option value="full_time">Full time</option>
                            <option value="part_time">Part time</option>
                            <option value="contractor">Contractor</option>
                            <option value="intern">Intern</option>
                            <option value="consultant">Consultant</option>
                        </SelectField>
                        <SelectField
                            label="Status"
                            name="status"
                            value={form.status}
                            onChange={(e) => set('status', e.target.value as JobOpeningEditPayload['status'])}
                            error={fieldError('status')}
                        >
                            {allowedNextStates[job.status].map((status) => (
                                <option key={status} value={status}>
                                    {status.replace('_', ' ')}
                                </option>
                            ))}
                        </SelectField>
                        <div className="sm:col-span-2">
                            <label htmlFor="description" className="block text-sm font-medium text-slate-700">
                                Description
                            </label>
                            <textarea
                                id="description"
                                rows={4}
                                value={form.description}
                                onChange={(e) => set('description', e.target.value)}
                                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            />
                            <ErrorMessage message={fieldError('description')} />
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="button" variant="secondary" onClick={() => router.visit('/recruitment/jobs')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Saving…' : 'Save changes'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}

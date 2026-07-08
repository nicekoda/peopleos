import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Department } from '@/types/department';
import { Position } from '@/types/position';
import { Location } from '@/types/location';
import { JobOpening, JobOpeningFormPayload, PaginatedResponse } from '@/types/recruitment';

/**
 * status is never a Create field — a new job opening always starts as
 * draft, set by the backend. Same rule as LifecycleProcess Create.
 */
export default function RecruitmentJobCreate() {
    const [form, setForm] = useState<JobOpeningFormPayload>({
        title: '',
        department_id: '',
        position_id: '',
        location_id: '',
        employment_type: '',
        description: '',
    });
    const [departments, setDepartments] = useState<Department[] | null>(null);
    const [positions, setPositions] = useState<Position[] | null>(null);
    const [locations, setLocations] = useState<Location[] | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        Promise.all([
            api.get<PaginatedResponse<Department>>('/departments'),
            api.get<PaginatedResponse<Position>>('/positions'),
            api.get<PaginatedResponse<Location>>('/locations'),
        ])
            .then(([departmentsRes, positionsRes, locationsRes]) => {
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
    }, []);

    const set = <K extends keyof JobOpeningFormPayload>(key: K, value: JobOpeningFormPayload[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = {
            title: form.title,
            ...(form.department_id ? { department_id: form.department_id } : {}),
            ...(form.position_id ? { position_id: form.position_id } : {}),
            ...(form.location_id ? { location_id: form.location_id } : {}),
            ...(form.employment_type ? { employment_type: form.employment_type } : {}),
            ...(form.description ? { description: form.description } : {}),
        };

        api.post<{ data: JobOpening }>('/job-openings', payload)
            .then((response) => {
                router.visit(`/recruitment/jobs/${response.data.data.id}/edit`);
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

    const ready = departments !== null && positions !== null && locations !== null;

    return (
        <AppLayout>
            <Head title="New job opening" />

            <PageHeader
                title="New job opening"
                description="Every job opening starts as a draft. Move it to open once you're ready to receive applications."
                actions={
                    <Link href="/recruitment/jobs" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Job Openings
                    </Link>
                }
            />

            {loadError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
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
                            {(departments ?? []).map((department) => (
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
                            {(positions ?? []).map((position) => (
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
                            {(locations ?? []).map((location) => (
                                <option key={location.id} value={location.id}>
                                    {location.name}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField
                            label="Employment type"
                            name="employment_type"
                            value={form.employment_type}
                            onChange={(e) => set('employment_type', e.target.value as JobOpeningFormPayload['employment_type'])}
                            error={fieldError('employment_type')}
                        >
                            <option value="">— None —</option>
                            <option value="full_time">Full time</option>
                            <option value="part_time">Part time</option>
                            <option value="contractor">Contractor</option>
                            <option value="intern">Intern</option>
                            <option value="consultant">Consultant</option>
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
                        <Button type="submit" disabled={submitting || !ready}>
                            {submitting ? 'Creating…' : 'Create job opening'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}

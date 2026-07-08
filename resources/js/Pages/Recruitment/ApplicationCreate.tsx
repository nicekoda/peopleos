import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { JobApplication, JobApplicationFormPayload, JobOpening, PaginatedResponse } from '@/types/recruitment';

/**
 * One-step create: the applicant identity and the application to a
 * specific job opening are submitted together in one request (mirrors
 * the backend's single-step create — see JobApplicationController::store()).
 * stage/status/ready_for_conversion are never form fields; a new
 * application always starts at stage=applied.
 */
export default function RecruitmentApplicationCreate() {
    const [form, setForm] = useState<JobApplicationFormPayload>({
        recruitment_job_id: '',
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        source: '',
        cover_letter: '',
    });
    const [jobs, setJobs] = useState<JobOpening[] | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<PaginatedResponse<JobOpening>>('/job-openings')
            .then((response) => setJobs(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, []);

    const set = <K extends keyof JobApplicationFormPayload>(key: K, value: JobApplicationFormPayload[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = {
            recruitment_job_id: form.recruitment_job_id,
            first_name: form.first_name,
            last_name: form.last_name,
            email: form.email,
            ...(form.phone ? { phone: form.phone } : {}),
            ...(form.source ? { source: form.source } : {}),
            ...(form.cover_letter ? { cover_letter: form.cover_letter } : {}),
        };

        api.post<{ data: JobApplication }>('/job-applications', payload)
            .then((response) => {
                router.visit(`/recruitment/applications/${response.data.data.id}`);
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

    return (
        <AppLayout>
            <Head title="New application" />

            <PageHeader
                title="New application"
                description="Record an applicant's details and their application to a job opening."
                actions={
                    <Link href="/recruitment/applications" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Applications
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
                            <SelectField
                                label="Job opening"
                                name="recruitment_job_id"
                                required
                                value={form.recruitment_job_id}
                                onChange={(e) => set('recruitment_job_id', e.target.value)}
                                error={fieldError('recruitment_job_id')}
                            >
                                <option value="">— Select a job opening —</option>
                                {(jobs ?? []).map((job) => (
                                    <option key={job.id} value={job.id}>
                                        {job.title}
                                    </option>
                                ))}
                            </SelectField>
                        </div>
                        <InputField
                            label="First name"
                            name="first_name"
                            required
                            value={form.first_name}
                            onChange={(e) => set('first_name', e.target.value)}
                            error={fieldError('first_name')}
                        />
                        <InputField
                            label="Last name"
                            name="last_name"
                            required
                            value={form.last_name}
                            onChange={(e) => set('last_name', e.target.value)}
                            error={fieldError('last_name')}
                        />
                        <InputField
                            label="Email"
                            name="email"
                            type="email"
                            required
                            value={form.email}
                            onChange={(e) => set('email', e.target.value)}
                            error={fieldError('email')}
                        />
                        <InputField
                            label="Phone"
                            name="phone"
                            value={form.phone}
                            onChange={(e) => set('phone', e.target.value)}
                            error={fieldError('phone')}
                        />
                        <InputField
                            label="Source"
                            name="source"
                            placeholder="e.g. referral, job board, LinkedIn"
                            value={form.source}
                            onChange={(e) => set('source', e.target.value)}
                            error={fieldError('source')}
                        />
                        <div className="sm:col-span-2">
                            <label htmlFor="cover_letter" className="block text-sm font-medium text-slate-700">
                                Cover letter
                            </label>
                            <textarea
                                id="cover_letter"
                                rows={5}
                                value={form.cover_letter}
                                onChange={(e) => set('cover_letter', e.target.value)}
                                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            />
                            <ErrorMessage message={fieldError('cover_letter')} />
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting || jobs === null}>
                            {submitting ? 'Creating…' : 'Create application'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}

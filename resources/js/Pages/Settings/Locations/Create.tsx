import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { InputField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Location } from '@/types/location';

interface CreateForm {
    name: string;
    description: string;
}

/**
 * Checkpoint 32 — name/description only. Slug is always server-
 * generated from name; a new location always starts active via the
 * backend's own default.
 */
export default function SettingsLocationCreate() {
    const [form, setForm] = useState<CreateForm>({ name: '', description: '' });
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const set = <K extends keyof CreateForm>(key: K, value: CreateForm[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = {
            name: form.name,
            ...(form.description ? { description: form.description } : {}),
        };

        api.post<{ data: Location }>('/locations', payload)
            .then(() => {
                router.visit('/settings/locations');
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
            <Head title="Create location" />

            <PageHeader
                title="Create location"
                description="New locations start active."
                actions={
                    <Link href="/settings/locations" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Locations
                    </Link>
                }
            />

            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <div className="grid grid-cols-1 gap-4">
                        <InputField
                            label="Name"
                            name="name"
                            required
                            value={form.name}
                            onChange={(e) => set('name', e.target.value)}
                            error={fieldError('name')}
                        />

                        <div>
                            <label htmlFor="description" className="block text-sm font-medium text-slate-700">
                                Description
                            </label>
                            <textarea
                                id="description"
                                rows={3}
                                value={form.description}
                                onChange={(e) => set('description', e.target.value)}
                                className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                            />
                            <ErrorMessage message={fieldError('description')} />
                        </div>
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting}>
                            {submitting ? 'Creating…' : 'Create location'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}

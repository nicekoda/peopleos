import { Head, router, usePage } from '@inertiajs/react';
import { ChangeEventHandler, FormEventHandler, useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { DocumentCategory, EmployeeDocument, EmployeeDocumentFormPayload, PaginatedResponse } from '@/types/document';
import { PageProps } from '@/types';

interface UploadProps extends PageProps {
    employeeId: string;
}

/**
 * Upload payload is built field-by-field into a FormData from exactly
 * the fields in EmployeeDocumentFormPayload, plus the file itself —
 * tenant_id/employee_id/storage_path/stored_filename/uploaded_by/
 * approved_by/approved_at/status are never fields on this form at all,
 * matching what StoreEmployeeDocumentRequest actually accepts (see
 * docs/security.md). Backend validation (file type/size/content,
 * expiry-after-issue, category active+tenant-scoped) remains the sole
 * authority; the client-side checks here (Refinements 3/4) are UX only.
 */
export default function EmployeeDocumentUpload() {
    const { employeeId } = usePage<UploadProps>().props;

    const [categories, setCategories] = useState<DocumentCategory[] | null>(null);
    const [categoriesError, setCategoriesError] = useState<ApiError | null>(null);

    const [form, setForm] = useState<EmployeeDocumentFormPayload>({
        title: '',
        description: '',
        document_category_id: '',
        issue_date: '',
        expiry_date: '',
    });
    const [file, setFile] = useState<File | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<PaginatedResponse<DocumentCategory>>('/document-categories')
            .then((response) => setCategories(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    // Refinement 2: a category-list failure blocks upload
                    // with a clear error, rather than silently letting the
                    // user submit an uncategorised document — categorising
                    // correctly (sensitivity, expiry requirement) depends
                    // on this list being available.
                    setCategoriesError(apiError);
                }
            });
    }, []);

    // Refinement 2: active only, employee-scoped categories preferred;
    // if none apply specifically to employees, fall back to any active
    // category rather than leaving the dropdown empty.
    const selectableCategories = useMemo(() => {
        const active = categories?.filter((category) => category.status === 'active') ?? [];
        const employeeScoped = active.filter((category) => category.applies_to === 'employee');

        return employeeScoped.length > 0 ? employeeScoped : active;
    }, [categories]);

    const selectedCategory = selectableCategories.find((category) => category.id === form.document_category_id) ?? null;

    const set = <K extends keyof EmployeeDocumentFormPayload>(key: K, value: EmployeeDocumentFormPayload[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const handleFileChange: ChangeEventHandler<HTMLInputElement> = (e) => {
        setFile(e.target.files?.[0] ?? null);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setErrors({});
        setGeneralError(null);

        if (!file) {
            setErrors({ file: ['Please choose a file to upload.'] });
            return;
        }

        // Refinement 3: client-side enforcement only — the backend
        // (StoreEmployeeDocumentRequest::withValidator()) independently
        // rejects a missing expiry date for a category that requires
        // one, regardless of what happens here.
        if (selectedCategory?.requires_expiry_date && !form.expiry_date) {
            setErrors({ expiry_date: ['This document category requires an expiry date.'] });
            return;
        }

        setSubmitting(true);

        const data = new FormData();
        data.append('file', file);
        data.append('title', form.title);
        if (form.description) data.append('description', form.description);
        if (form.document_category_id) data.append('document_category_id', form.document_category_id);
        if (form.issue_date) data.append('issue_date', form.issue_date);
        if (form.expiry_date) data.append('expiry_date', form.expiry_date);

        api.post<{ data: EmployeeDocument }>(`/employees/${employeeId}/documents`, data)
            .then((response) => {
                router.visit(`/employees/${employeeId}/documents/${response.data.data.id}`);
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

    if (categoriesError) {
        return (
            <AppLayout>
                <Head title="Upload document" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{categoriesError.message}</div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Upload document" />

            <PageHeader title="Upload document" description="Add a new document to this employee's record." />

            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <InputField
                            label="Title"
                            name="title"
                            required
                            value={form.title}
                            onChange={(e) => set('title', e.target.value)}
                            error={fieldError('title')}
                        />

                        <SelectField
                            label="Category"
                            name="document_category_id"
                            value={form.document_category_id}
                            onChange={(e) => set('document_category_id', e.target.value)}
                            error={fieldError('document_category_id')}
                        >
                            <option value="">No category</option>
                            {selectableCategories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </SelectField>

                        <div className="sm:col-span-2">
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

                        <div className="sm:col-span-2">
                            <label htmlFor="file" className="block text-sm font-medium text-slate-700">
                                File <span className="text-red-500">*</span>
                            </label>
                            <input
                                id="file"
                                name="file"
                                type="file"
                                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                onChange={handleFileChange}
                                className="mt-1.5 block w-full text-sm text-slate-700 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3.5 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100"
                            />
                            <p className="mt-1 text-xs text-slate-500">PDF, Word, or image files up to 10MB.</p>
                            <ErrorMessage message={fieldError('file')} />
                        </div>

                        <InputField
                            label="Issue date"
                            name="issue_date"
                            type="date"
                            value={form.issue_date}
                            onChange={(e) => set('issue_date', e.target.value)}
                            error={fieldError('issue_date')}
                        />
                        <InputField
                            label="Expiry date"
                            name="expiry_date"
                            type="date"
                            required={Boolean(selectedCategory?.requires_expiry_date)}
                            value={form.expiry_date}
                            onChange={(e) => set('expiry_date', e.target.value)}
                            error={fieldError('expiry_date')}
                        />
                    </div>

                    {/* Refinement 4 — cosmetic only; the backend independently decides
                        and enforces the real sensitivity/access rule regardless of
                        whether this warning is shown. */}
                    {selectedCategory?.is_sensitive && (
                        <p className="mt-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            This document category is marked as sensitive. Access will be restricted.
                        </p>
                    )}

                    <div className="mt-6 flex justify-end gap-3">
                        <Button type="submit" disabled={submitting || categories === null}>
                            {submitting ? 'Uploading…' : 'Upload document'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}

import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useEffect, useMemo, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import { InputField, SelectField } from '@/Components/FormField';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Employee } from '@/types/employee';
import { PaginatedResponse as EmployeePaginatedResponse } from '@/types/department';
import { HrDocumentTemplate, HrGeneratedDocument, HrGeneratedDocumentFormPayload, PaginatedResponse } from '@/types/hrDocument';

/**
 * Checkpoint 34 — generate a new HR document from an active template for
 * a same-tenant employee. employeeId query-string param (from an
 * Employee detail page's "HR Documents" link) only pre-fills the form;
 * the actual employee_id sent is always whatever this form currently
 * holds, never trusted from the URL alone — same pattern as
 * Lifecycle/Create.tsx. Only active templates *with a published version*
 * are offered (Checkpoint 36) — an inactive template, or one with no
 * current_version_id yet, is rejected server-side (GenerateHrDocumentRequest)
 * even if somehow submitted.
 */
export default function HrDocumentCreate() {
    const params = useMemo(() => new URLSearchParams(window.location.search), []);

    const [form, setForm] = useState<HrGeneratedDocumentFormPayload>({
        employee_id: params.get('employeeId') ?? '',
        hr_document_template_id: '',
        title: '',
    });
    const [employees, setEmployees] = useState<Employee[] | null>(null);
    const [templates, setTemplates] = useState<HrDocumentTemplate[] | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);
    const [loadError, setLoadError] = useState<ApiError | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        api.get<EmployeePaginatedResponse<Employee>>('/employees')
            .then((response) => setEmployees(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });

        api.get<PaginatedResponse<HrDocumentTemplate>>('/hr-document-templates')
            .then((response) => setTemplates(
                response.data.data.filter((template) => template.status === 'active' && template.current_version_id !== null),
            ))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLoadError(apiError);
                }
            });
    }, []);

    const set = <K extends keyof HrGeneratedDocumentFormPayload>(key: K, value: HrGeneratedDocumentFormPayload[K]) => {
        setForm((prev) => ({ ...prev, [key]: value }));
    };

    const fieldError = (name: string) => errors[name]?.[0];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGeneralError(null);

        const payload = {
            employee_id: form.employee_id,
            hr_document_template_id: form.hr_document_template_id,
            ...(form.title ? { title: form.title } : {}),
        };

        api.post<{ data: HrGeneratedDocument }>('/hr-generated-documents', payload)
            .then((response) => {
                router.visit(`/hr-documents/${response.data.data.id}`);
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
            <Head title="Generate HR document" />

            <PageHeader
                title="Generate HR document"
                description="Renders a letter from an active template using the selected employee's details."
                actions={
                    <Link href="/hr-documents" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to HR Documents
                    </Link>
                }
            />

            {loadError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{loadError.message}</div>
            )}
            {generalError && (
                <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{generalError}</div>
            )}

            {(employees === null || templates === null) && !loadError ? (
                <LoadingState label="Loading employees and templates…" />
            ) : (
                <form onSubmit={submit}>
                    <Card>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <SelectField
                                label="Employee"
                                name="employee_id"
                                required
                                value={form.employee_id}
                                onChange={(e) => set('employee_id', e.target.value)}
                                error={fieldError('employee_id')}
                            >
                                <option value="">— Select an employee —</option>
                                {(employees ?? []).map((employee) => (
                                    <option key={employee.id} value={employee.id}>
                                        {employee.full_name}
                                    </option>
                                ))}
                            </SelectField>
                            <SelectField
                                label="Template"
                                name="hr_document_template_id"
                                required
                                value={form.hr_document_template_id}
                                onChange={(e) => set('hr_document_template_id', e.target.value)}
                                error={fieldError('hr_document_template_id')}
                            >
                                <option value="">— Select a template —</option>
                                {(templates ?? []).map((template) => (
                                    <option key={template.id} value={template.id}>
                                        {template.title}
                                    </option>
                                ))}
                            </SelectField>
                            <div className="sm:col-span-2">
                                <InputField
                                    label="Title override (optional)"
                                    name="title"
                                    value={form.title}
                                    onChange={(e) => set('title', e.target.value)}
                                    error={fieldError('title')}
                                />
                                <p className="mt-1 text-xs text-slate-500">Defaults to the template's own title if left blank.</p>
                            </div>
                        </div>

                        <div className="mt-6 flex justify-end gap-3">
                            <Button type="submit" disabled={submitting || employees === null || templates === null}>
                                {submitting ? 'Generating…' : 'Generate document'}
                            </Button>
                        </div>
                    </Card>
                </form>
            )}
        </AppLayout>
    );
}

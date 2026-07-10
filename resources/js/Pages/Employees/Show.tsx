import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Badge from '@/Components/Badge';
import LoadingState from '@/Components/LoadingState';
import PermissionGate from '@/Components/PermissionGate';
import { useCan } from '@/hooks/useCan';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { Employee } from '@/types/employee';
import { PageProps } from '@/types';

interface ShowProps extends PageProps {
    employeeId: string;
}

const statusTone: Record<Employee['status'], 'neutral' | 'success' | 'warning' | 'danger'> = {
    draft: 'neutral',
    active: 'success',
    inactive: 'warning',
    terminated: 'danger',
};

/**
 * Sensitive-field microcopy (Refinement 5): personal_email/phone come
 * back `null` from the API both when genuinely empty and when the
 * viewer lacks employees.view_sensitive — the frontend can't tell those
 * apart from the value alone, so it uses the viewer's own permission
 * list (already shared safely via HandleInertiaRequests) purely to
 * choose which explanatory microcopy to show for an already-null value.
 * This never re-exposes anything; the backend already decided the real
 * value before this component ever saw it.
 */
function SensitiveField({ label, value, canView }: { label: string; value: string | null; canView: boolean }) {
    return (
        <div className="flex justify-between py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-900">
                {value ?? (canView ? 'Not provided' : 'Not visible')}
            </dd>
        </div>
    );
}

function Field({ label, value }: { label: string; value: string | null | undefined }) {
    return (
        <div className="flex justify-between py-2 text-sm">
            <dt className="text-slate-500">{label}</dt>
            <dd className="font-medium text-slate-900">{value ?? '—'}</dd>
        </div>
    );
}

export default function EmployeesShow() {
    const { employeeId } = usePage<ShowProps>().props;
    const canViewSensitive = useCan('employees.view_sensitive');
    const canViewUsers = useCan('users.view');

    const [employee, setEmployee] = useState<Employee | null>(null);
    const [error, setError] = useState<ApiError | null>(null);

    useEffect(() => {
        api.get<{ data: Employee }>(`/employees/${employeeId}`)
            .then((response) => setEmployee(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, [employeeId]);

    if (error) {
        return (
            <AppLayout>
                <Head title="Employee" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            </AppLayout>
        );
    }

    if (!employee) {
        return (
            <AppLayout>
                <Head title="Employee" />
                <LoadingState label="Loading employee…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title={employee.full_name} />

            <PageHeader
                title={employee.full_name}
                description={`Employee #${employee.employee_number}`}
                actions={
                    <div className="flex items-center gap-3">
                        <PermissionGate permission="documents.view">
                            <Link
                                href={`/employees/${employee.id}/documents`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                Documents
                            </Link>
                        </PermissionGate>
                        <PermissionGate permission="lifecycle.view">
                            <Link
                                href={`/lifecycle?employeeId=${employee.id}`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                View Lifecycle
                            </Link>
                        </PermissionGate>
                        <PermissionGate permission="hr_generated_documents.view">
                            <Link
                                href={`/hr-documents?employeeId=${employee.id}`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                HR Documents
                            </Link>
                        </PermissionGate>
                        <PermissionGate permission="lifecycle.create">
                            <Link
                                href={`/lifecycle/create?employeeId=${employee.id}&type=onboarding`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                Start Onboarding
                            </Link>
                        </PermissionGate>
                        <PermissionGate permission="lifecycle.create">
                            <Link
                                href={`/lifecycle/create?employeeId=${employee.id}&type=offboarding`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                Start Offboarding
                            </Link>
                        </PermissionGate>
                        <PermissionGate permission="employees.update">
                            <Link
                                href={`/employees/${employee.id}/edit`}
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                            >
                                Edit
                            </Link>
                        </PermissionGate>
                    </div>
                }
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card title="Overview">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Preferred name" value={employee.preferred_name} />
                        <Field label="Work email" value={employee.work_email} />
                        <Field label="Employment type" value={employee.employment_type.replace('_', ' ')} />
                        <div className="flex justify-between py-2 text-sm">
                            <dt className="text-slate-500">Status</dt>
                            <dd>
                                <Badge tone={statusTone[employee.status]}>{employee.status}</Badge>
                            </dd>
                        </div>
                    </dl>
                </Card>

                <Card title="Organisation">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Department" value={employee.department?.name ?? null} />
                        <Field label="Position" value={employee.position?.name ?? null} />
                        <Field label="Location" value={employee.location?.name ?? null} />
                    </dl>
                </Card>

                <Card title="Dates">
                    <dl className="divide-y divide-slate-100">
                        <Field label="Start date" value={employee.start_date} />
                        <Field label="Probation end date" value={employee.probation_end_date} />
                        <Field label="Confirmation date" value={employee.confirmation_date} />
                    </dl>
                </Card>

                <Card title="Contact (sensitive)" className="sm:col-span-2">
                    <dl className="divide-y divide-slate-100">
                        <SensitiveField label="Personal email" value={employee.personal_email} canView={canViewSensitive} />
                        <SensitiveField label="Phone" value={employee.phone} canView={canViewSensitive} />
                    </dl>
                </Card>

                <Card title="User account" className="sm:col-span-2">
                    {employee.linked_user ? (
                        canViewUsers ? (
                            <Link
                                href={`/settings/access/users/${employee.linked_user.id}`}
                                className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                {employee.linked_user.name}
                            </Link>
                        ) : (
                            <p className="text-sm text-slate-500">Linked to a user account.</p>
                        )
                    ) : (
                        <PermissionGate
                            permission="users.create"
                            fallback={<p className="text-sm text-slate-500">No user account linked.</p>}
                        >
                            <div className="flex items-center justify-between">
                                <p className="text-sm text-slate-500">No user account linked.</p>
                                <Link
                                    href={`/settings/access/users/create?employeeId=${employee.id}`}
                                    className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                                >
                                    Create user account
                                </Link>
                            </div>
                        </PermissionGate>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}

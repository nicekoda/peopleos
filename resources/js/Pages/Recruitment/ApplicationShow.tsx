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
import { InputField, SelectField } from '@/Components/FormField';
import CustomFieldsCard from '@/Components/CustomFieldsCard';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { ApplicationStage, ConversionFormPayload, JobApplication, PaginatedResponse } from '@/types/recruitment';
import { Department } from '@/types/department';
import { Position } from '@/types/position';
import { Location } from '@/types/location';
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
    const { applicationId, tenant } = usePage<ShowProps>().props;
    const lifecycleEnabled = tenant?.modules?.lifecycle !== false;

    const [application, setApplication] = useState<JobApplication | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [stageDraft, setStageDraft] = useState<ApplicationStage | ''>('');
    const [updatingStage, setUpdatingStage] = useState(false);
    const [noteText, setNoteText] = useState('');
    const [notingError, setNotingError] = useState<string | null>(null);
    const [addingNote, setAddingNote] = useState(false);
    const [updatingReadiness, setUpdatingReadiness] = useState(false);
    const [departments, setDepartments] = useState<Department[] | null>(null);
    const [positions, setPositions] = useState<Position[] | null>(null);
    const [locations, setLocations] = useState<Location[] | null>(null);
    const [conversionForm, setConversionForm] = useState<ConversionFormPayload>({
        employee_number: '',
        work_email: '',
        start_date: '',
        employment_type: '',
        department_id: '',
        position_id: '',
        location_id: '',
    });
    const [conversionErrors, setConversionErrors] = useState<Record<string, string[]>>({});
    const [conversionGeneralError, setConversionGeneralError] = useState<string | null>(null);
    const [converting, setConverting] = useState(false);
    const [startingOnboarding, setStartingOnboarding] = useState(false);
    const [onboardingError, setOnboardingError] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<{ data: JobApplication }>(`/job-applications/${applicationId}`)
            .then((response) => {
                const data = response.data.data;
                setApplication(data);
                setStageDraft(data.stage);
                setConversionForm((prev) => ({
                    ...prev,
                    work_email: prev.work_email || data.applicant?.email || '',
                    employment_type: prev.employment_type || data.job?.employment_type || '',
                    department_id: prev.department_id || data.job?.department_id || '',
                    position_id: prev.position_id || data.job?.position_id || '',
                    location_id: prev.location_id || data.job?.location_id || '',
                }));
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
            .catch(() => {
                // Non-fatal — the conversion form still works with manual
                // entry if these reference lists fail to load; the picker
                // dropdowns just render empty besides "— None —".
            });
    }, []);

    const setConversionField = <K extends keyof ConversionFormPayload>(key: K, value: ConversionFormPayload[K]) => {
        setConversionForm((prev) => ({ ...prev, [key]: value }));
    };

    const conversionFieldError = (name: string) => conversionErrors[name]?.[0];

    const submitConversion: FormEventHandler = (e) => {
        e.preventDefault();
        setConverting(true);
        setConversionErrors({});
        setConversionGeneralError(null);

        const payload = {
            employee_number: conversionForm.employee_number,
            work_email: conversionForm.work_email || null,
            start_date: conversionForm.start_date || null,
            employment_type: conversionForm.employment_type,
            department_id: conversionForm.department_id || null,
            position_id: conversionForm.position_id || null,
            location_id: conversionForm.location_id || null,
        };

        api.post<{ data: JobApplication }>(`/job-applications/${applicationId}/convert-to-employee`, payload)
            .then((response) => setApplication(response.data.data))
            .catch((err) => {
                const apiError: ApiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) {
                    return;
                }
                if (apiError.errors) {
                    setConversionErrors(apiError.errors);
                }
                setConversionGeneralError(apiError.message);
            })
            .finally(() => setConverting(false));
    };

    const startOnboarding = () => {
        setStartingOnboarding(true);
        setOnboardingError(null);

        api.post<{ data: JobApplication }>(`/job-applications/${applicationId}/start-onboarding`)
            .then((response) => setApplication(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setOnboardingError(apiError.message);
                }
            })
            .finally(() => setStartingOnboarding(false));
    };

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

                <CustomFieldsCard
                    title="Custom fields"
                    entityTypeUrl="recruitment_applicant"
                    endpointUrl={`/job-applications/${applicationId}`}
                    payloadKey="custom_field_values"
                    values={application.applicant?.custom_field_values}
                    onSaved={load}
                />

                <CustomFieldsCard
                    title="Application custom fields"
                    entityTypeUrl="job_application"
                    endpointUrl={`/job-applications/${applicationId}`}
                    payloadKey="application_custom_field_values"
                    values={application.custom_field_values}
                    onSaved={load}
                />

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
                            </div>
                        </PermissionGate>
                    </div>
                </Card>
            </div>

            <div className="mt-4">
                <Card title="Candidate-to-Employee Conversion">
                    {application.converted_employee ? (
                        <div className="space-y-3">
                            <p className="text-sm text-slate-700">
                                Converted to employee{' '}
                                <Link
                                    href={`/employees/${application.converted_employee.id}`}
                                    className="font-medium text-indigo-600 hover:text-indigo-500"
                                >
                                    {application.converted_employee.full_name} (#{application.converted_employee.employee_number})
                                </Link>{' '}
                                on {application.converted_at?.slice(0, 10)}.
                            </p>
                            {onboardingError && (
                                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{onboardingError}</div>
                            )}
                            {application.onboarding_process ? (
                                <p className="text-sm text-slate-500">
                                    Onboarding has started —{' '}
                                    <Link
                                        href={`/lifecycle/${application.onboarding_process.id}`}
                                        className="font-medium text-indigo-600 hover:text-indigo-500"
                                    >
                                        view onboarding process
                                    </Link>{' '}
                                    (status: {application.onboarding_process.status.replace('_', ' ')}).
                                </p>
                            ) : lifecycleEnabled ? (
                                <div>
                                    <p className="mb-2 text-sm text-slate-500">
                                        No onboarding process has been started. Starting onboarding only creates a draft process — no
                                        tasks, user account, or role assignment happens automatically.
                                    </p>
                                    <PermissionGate
                                        permission="lifecycle.create"
                                        fallback={<p className="text-sm text-slate-500">You don't have permission to start onboarding.</p>}
                                    >
                                        <Button type="button" variant="secondary" onClick={startOnboarding} disabled={startingOnboarding}>
                                            {startingOnboarding ? 'Starting…' : 'Start Onboarding'}
                                        </Button>
                                    </PermissionGate>
                                </div>
                            ) : null}
                        </div>
                    ) : (
                        <PermissionGate
                            permission="job_applications.convert_to_employee"
                            fallback={<p className="text-sm text-slate-500">You don't have permission to convert this application to an employee.</p>}
                        >
                            {application.stage !== 'hired' || !application.ready_for_conversion ? (
                                <p className="text-sm text-slate-600">
                                    This application must be at the <strong>hired</strong> stage and marked{' '}
                                    <strong>ready for conversion</strong> before it can be converted.
                                </p>
                            ) : (
                                <form onSubmit={submitConversion}>
                                    <p className="mb-4 text-sm text-slate-500">
                                        Creates a new employee record. No user account, role assignment, or onboarding process is
                                        started automatically.
                                    </p>
                                    {conversionGeneralError && (
                                        <div className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
                                            {conversionGeneralError}
                                        </div>
                                    )}
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <InputField
                                            label="Employee number"
                                            name="employee_number"
                                            required
                                            value={conversionForm.employee_number}
                                            onChange={(e) => setConversionField('employee_number', e.target.value)}
                                            error={conversionFieldError('employee_number')}
                                        />
                                        <InputField
                                            label="Work email"
                                            name="work_email"
                                            type="email"
                                            value={conversionForm.work_email}
                                            onChange={(e) => setConversionField('work_email', e.target.value)}
                                            error={conversionFieldError('work_email')}
                                        />
                                        <InputField
                                            label="Start date"
                                            name="start_date"
                                            type="date"
                                            value={conversionForm.start_date}
                                            onChange={(e) => setConversionField('start_date', e.target.value)}
                                            error={conversionFieldError('start_date')}
                                        />
                                        <SelectField
                                            label="Employment type"
                                            name="employment_type"
                                            required
                                            value={conversionForm.employment_type}
                                            onChange={(e) =>
                                                setConversionField('employment_type', e.target.value as ConversionFormPayload['employment_type'])
                                            }
                                            error={conversionFieldError('employment_type')}
                                        >
                                            <option value="">— Select —</option>
                                            <option value="full_time">Full time</option>
                                            <option value="part_time">Part time</option>
                                            <option value="contractor">Contractor</option>
                                            <option value="intern">Intern</option>
                                            <option value="consultant">Consultant</option>
                                        </SelectField>
                                        <SelectField
                                            label="Department"
                                            name="department_id"
                                            value={conversionForm.department_id}
                                            onChange={(e) => setConversionField('department_id', e.target.value)}
                                            error={conversionFieldError('department_id')}
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
                                            value={conversionForm.position_id}
                                            onChange={(e) => setConversionField('position_id', e.target.value)}
                                            error={conversionFieldError('position_id')}
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
                                            value={conversionForm.location_id}
                                            onChange={(e) => setConversionField('location_id', e.target.value)}
                                            error={conversionFieldError('location_id')}
                                        >
                                            <option value="">— None —</option>
                                            {(locations ?? []).map((location) => (
                                                <option key={location.id} value={location.id}>
                                                    {location.name}
                                                </option>
                                            ))}
                                        </SelectField>
                                    </div>
                                    <div className="mt-6 flex justify-end">
                                        <Button type="submit" disabled={converting}>
                                            {converting ? 'Converting…' : 'Convert to Employee'}
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </PermissionGate>
                    )}
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

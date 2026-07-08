// Mirrors JobOpeningResource/JobApplicationResource/
// RecruitmentApplicationNoteResource exactly (Checkpoint 39) — no
// tenant_id/created_by/updated_by/deleted_at.
export type RecruitmentJobStatus = 'draft' | 'open' | 'on_hold' | 'closed' | 'cancelled';
export type ApplicationStage = 'applied' | 'screening' | 'interview' | 'offer' | 'rejected' | 'hired' | 'withdrawn';
export type ApplicationStatus = 'active' | 'archived';
export type EmploymentType = 'full_time' | 'part_time' | 'contractor' | 'intern' | 'consultant';

export interface JobOpening {
    id: string;
    title: string;
    department_id: string | null;
    department?: { id: string; name: string } | null;
    position_id: string | null;
    position?: { id: string; name: string } | null;
    location_id: string | null;
    location?: { id: string; name: string } | null;
    employment_type: EmploymentType | null;
    description: string | null;
    status: RecruitmentJobStatus;
    opened_at: string | null;
    closed_at: string | null;
    created_at: string | null;
    updated_at: string | null;
}

export interface ApplicationNote {
    id: string;
    note: string;
    visibility: string;
    created_by: number | null;
    author?: string | null;
    created_at: string | null;
}

export interface JobApplication {
    id: string;
    recruitment_job_id: string;
    job?: {
        id: string;
        title: string;
        status: RecruitmentJobStatus;
        department_id: string | null;
        position_id: string | null;
        location_id: string | null;
        employment_type: EmploymentType | null;
    } | null;
    applicant?: {
        id: string;
        first_name: string;
        last_name: string;
        email: string;
        phone: string | null;
        source: string | null;
    } | null;
    stage: ApplicationStage;
    status: ApplicationStatus;
    resume_document_id: string | null;
    cover_letter: string | null;
    ready_for_conversion: boolean;
    converted_employee_id: string | null;
    converted_employee?: { id: string; full_name: string; employee_number: string } | null;
    converted_at: string | null;
    notes?: ApplicationNote[];
    created_at: string | null;
    updated_at: string | null;
}

export interface PaginatedResponse<T> {
    data: T[];
    meta?: {
        current_page: number;
        last_page: number;
        total: number;
    };
}

/**
 * Allowlisted for the job opening Create form — status is never a Create
 * field, a new opening always starts as draft (set by the backend).
 */
export interface JobOpeningFormPayload {
    title: string;
    department_id: string;
    position_id: string;
    location_id: string;
    employment_type: EmploymentType | '';
    description: string;
}

/**
 * Allowlisted for the job opening Edit form.
 */
export interface JobOpeningEditPayload {
    title: string;
    department_id: string;
    position_id: string;
    location_id: string;
    employment_type: EmploymentType | '';
    description: string;
    status: RecruitmentJobStatus | '';
}

/**
 * Allowlisted for the application Create form — stage/status/
 * ready_for_conversion are absent, a new application always starts at
 * stage=applied/status=active/ready_for_conversion=false (set by the
 * backend).
 */
export interface JobApplicationFormPayload {
    recruitment_job_id: string;
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
    source: string;
    cover_letter: string;
}

/**
 * Allowlisted for the candidate-to-employee conversion form (Checkpoint
 * 40) — manager_employee_id is deliberately absent; assigning a manager
 * stays a separate action via the existing Employee edit page, same as
 * every other employee-creation path in this app.
 */
export interface ConversionFormPayload {
    employee_number: string;
    work_email: string;
    start_date: string;
    employment_type: EmploymentType | '';
    department_id: string;
    position_id: string;
    location_id: string;
}

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
    job?: { id: string; title: string; status: RecruitmentJobStatus } | null;
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

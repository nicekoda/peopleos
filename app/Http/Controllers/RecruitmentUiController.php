<?php

namespace App\Http\Controllers;

use App\Models\RecruitmentApplication;
use App\Models\RecruitmentJob;
use App\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recruitment & Applicant Tracking Foundation UI (Checkpoint 39) — same
 * thin-page-route pattern as every other module: job/application data is
 * fetched client-side from /api/v1/job-openings and /api/v1/job-applications,
 * never passed through as an Inertia prop beyond IDs.
 */
class RecruitmentUiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Recruitment/Index');
    }

    public function jobsIndex(): Response
    {
        return Inertia::render('Recruitment/JobsIndex');
    }

    public function jobsCreate(): Response
    {
        return Inertia::render('Recruitment/JobCreate');
    }

    public function jobsEdit(RecruitmentJob $jobOpening): Response
    {
        $this->ensureJobBelongsToCurrentTenant($jobOpening);

        return Inertia::render('Recruitment/JobEdit', ['jobId' => $jobOpening->id]);
    }

    public function applicationsIndex(): Response
    {
        return Inertia::render('Recruitment/ApplicationsIndex');
    }

    public function applicationsCreate(): Response
    {
        return Inertia::render('Recruitment/ApplicationCreate');
    }

    public function applicationsShow(RecruitmentApplication $jobApplication): Response
    {
        $this->ensureApplicationBelongsToCurrentTenant($jobApplication);

        return Inertia::render('Recruitment/ApplicationShow', ['applicationId' => $jobApplication->id]);
    }

    private function ensureJobBelongsToCurrentTenant(RecruitmentJob $job): void
    {
        abort_unless($job->tenant_id === app(Tenant::class)->id, 404);
    }

    private function ensureApplicationBelongsToCurrentTenant(RecruitmentApplication $application): void
    {
        abort_unless($application->tenant_id === app(Tenant::class)->id, 404);
    }
}

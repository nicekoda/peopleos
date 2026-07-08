import { Head, Link, usePage } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import { PageProps } from '@/types';

/**
 * Landing page (Checkpoint 39) — "access, not data" design, same as
 * Settings' index page: no blanket permission on this route itself, each
 * card below is separately gated by its own module permission. A user
 * with neither job_openings.view nor job_applications.view reaches this
 * page but sees no cards (their sidebar link would already be hidden,
 * but this page doesn't assume that — see Sidebar.tsx's own comment on
 * hiding a link never being the security boundary).
 */
export default function RecruitmentIndex() {
    const permissions = usePage<PageProps>().props.auth.user?.permissions ?? [];
    const canViewJobs = permissions.includes('job_openings.view');
    const canViewApplications = permissions.includes('job_applications.view');

    return (
        <AppLayout>
            <Head title="Recruitment" />

            <PageHeader
                title="Recruitment"
                description="A simple applicant tracking foundation: job openings, applications, and their pipeline stage."
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {canViewJobs && (
                    <Card title="Job Openings">
                        <p className="text-sm text-slate-500">Create and manage open roles.</p>
                        <Link
                            href="/recruitment/jobs"
                            className="mt-4 inline-flex text-sm font-medium text-indigo-600 hover:text-indigo-500"
                        >
                            View job openings →
                        </Link>
                    </Card>
                )}
                {canViewApplications && (
                    <Card title="Applications">
                        <p className="text-sm text-slate-500">Track applicants through the hiring pipeline.</p>
                        <Link
                            href="/recruitment/applications"
                            className="mt-4 inline-flex text-sm font-medium text-indigo-600 hover:text-indigo-500"
                        >
                            View applications →
                        </Link>
                    </Card>
                )}
                {!canViewJobs && !canViewApplications && (
                    <p className="text-sm text-slate-500">You don't have access to any recruitment features yet.</p>
                )}
            </div>
        </AppLayout>
    );
}

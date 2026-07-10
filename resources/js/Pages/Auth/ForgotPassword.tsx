import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';
import { PageProps } from '@/types';

/**
 * Checkpoint 44 — guest-only (see routes/auth.php). Submits via
 * Inertia's useForm(), same pattern Login.tsx already uses, so the
 * shared 'status' prop (HandleInertiaRequests) carries the one-time
 * success message back after the redirect. The message is always the
 * same generic text regardless of what actually happened server-side
 * (PasswordResetLinkController::store() never reveals whether the email
 * exists or belongs to another tenant) — this page has no way to know
 * the difference either, by design.
 */
export default function ForgotPassword() {
    const { status } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/forgot-password');
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
            <Head title="Forgot password" />

            <div className="w-full max-w-sm">
                <div className="mb-8 text-center">
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">PeopleOS</h1>
                    <p className="mt-1 text-sm text-slate-500">Reset your password</p>
                </div>

                <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    {status && (
                        <p className="mb-4 rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{status}</p>
                    )}

                    <p className="mb-4 text-sm text-slate-500">
                        Enter your account's email address and we'll send you a password reset link, if one exists.
                    </p>

                    <div>
                        <label htmlFor="email" className="block text-sm font-medium text-slate-700">
                            Email
                        </label>
                        <input
                            id="email"
                            type="email"
                            autoComplete="username"
                            required
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        />
                        <ErrorMessage message={errors.email} />
                    </div>

                    <Button type="submit" disabled={processing} className="mt-6 w-full">
                        {processing ? 'Sending…' : 'Email password reset link'}
                    </Button>

                    <p className="mt-4 text-center text-sm text-slate-500">
                        <Link href="/login" className="font-medium text-indigo-600 hover:text-indigo-500">
                            Back to sign in
                        </Link>
                    </p>
                </form>
            </div>
        </div>
    );
}

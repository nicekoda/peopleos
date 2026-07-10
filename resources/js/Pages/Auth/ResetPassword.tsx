import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';

interface ResetPasswordProps {
    token: string;
    email: string;
}

/**
 * Checkpoint 44 — guest-only. token/email are page props passed through
 * exactly as NewPasswordController::create() received them from the
 * emailed link's URL — never independently validated here; email stays
 * editable in case the caller mistyped it when following the link. On
 * success, redirects to /login with a flashed 'status' message (shown
 * there); on failure (invalid/expired token, or any other rejection),
 * shows the same single generic error every time — this page has no way
 * to distinguish "wrong tenant" from "expired" from "no such user", by
 * design (see ResetPasswordRequest::reset()).
 */
export default function ResetPassword({ token, email }: ResetPasswordProps) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/reset-password');
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
            <Head title="Reset password" />

            <div className="w-full max-w-sm">
                <div className="mb-8 text-center">
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">PeopleOS</h1>
                    <p className="mt-1 text-sm text-slate-500">Choose a new password</p>
                </div>

                <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
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

                    <div className="mt-4">
                        <label htmlFor="password" className="block text-sm font-medium text-slate-700">
                            New password
                        </label>
                        <input
                            id="password"
                            type="password"
                            autoComplete="new-password"
                            required
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        />
                        <ErrorMessage message={errors.password} />
                    </div>

                    <div className="mt-4">
                        <label htmlFor="password_confirmation" className="block text-sm font-medium text-slate-700">
                            Confirm new password
                        </label>
                        <input
                            id="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            required
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        />
                    </div>

                    <Button type="submit" disabled={processing} className="mt-6 w-full">
                        {processing ? 'Resetting…' : 'Reset password'}
                    </Button>
                </form>
            </div>
        </div>
    );
}

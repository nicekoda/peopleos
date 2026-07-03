import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import Button from '@/Components/Button';
import ErrorMessage from '@/Components/ErrorMessage';

/**
 * Guest-only (see routes/auth.php + AuthenticatedSessionController::create()
 * — an authenticated user is redirected to /dashboard before this page
 * ever renders). Submits via Inertia's useForm(), which carries CSRF
 * protection automatically (Laravel's XSRF-TOKEN cookie + axios
 * interceptor Inertia wires up) and surfaces validation errors from the
 * same LoginRequest used by the JSON API — no separate/weaker
 * validation path. Errors are the same safe, generic messages
 * LoginRequest has always thrown (never reveals whether an email
 * exists, no stack traces — see docs/security.md).
 */
export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/login');
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
            <Head title="Log in" />

            <div className="w-full max-w-sm">
                <div className="mb-8 text-center">
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900">PeopleOS</h1>
                    <p className="mt-1 text-sm text-slate-500">Sign in to your account</p>
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
                            Password
                        </label>
                        <input
                            id="password"
                            type="password"
                            autoComplete="current-password"
                            required
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="mt-1.5 block w-full rounded-md border-0 px-3 py-2 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm"
                        />
                        <ErrorMessage message={errors.password} />
                    </div>

                    <Button type="submit" disabled={processing} className="mt-6 w-full">
                        {processing ? 'Signing in…' : 'Sign in'}
                    </Button>
                </form>
            </div>
        </div>
    );
}

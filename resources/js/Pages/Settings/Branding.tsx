import { Head, Link } from '@inertiajs/react';
import { FormEventHandler, useCallback, useEffect, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import Card from '@/Components/Card';
import Button from '@/Components/Button';
import LoadingState from '@/Components/LoadingState';
import { InputField } from '@/Components/FormField';
import { useCan } from '@/hooks/useCan';
import { api, toApiError, redirectIfUnauthenticated, ApiError } from '@/lib/api';
import { TenantBrandingState } from '@/types/tenantBranding';

const HEX_PATTERN = /^#[0-9a-fA-F]{6}$/;

/**
 * Checkpoint 47 — logo upload (PNG/JPG/JPEG only, server-validated size
 * and dimensions) and strict 6-digit hex colors. No custom CSS/HTML/JS
 * field exists here at all. tenant.branding.manage gates the whole
 * form; a view-only caller (none currently granted, but the permission
 * split is deliberate — see docs/security.md) would see the read-only
 * summary instead.
 */
export default function SettingsBranding() {
    const canManage = useCan('tenant.branding.manage');

    const [branding, setBranding] = useState<TenantBrandingState | null>(null);
    const [error, setError] = useState<ApiError | null>(null);
    const [primaryColor, setPrimaryColor] = useState('');
    const [secondaryColor, setSecondaryColor] = useState('');
    const [colorErrors, setColorErrors] = useState<Record<string, string[]>>({});
    const [savingColors, setSavingColors] = useState(false);
    const [uploadingLogo, setUploadingLogo] = useState(false);
    const [removingLogo, setRemovingLogo] = useState(false);
    const [logoError, setLogoError] = useState<string | null>(null);

    const load = useCallback(() => {
        setError(null);
        api.get<{ data: TenantBrandingState }>('/tenant/branding')
            .then((response) => {
                setBranding(response.data.data);
                setPrimaryColor(response.data.data.primary_color ?? '');
                setSecondaryColor(response.data.data.secondary_color ?? '');
            })
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setError(apiError);
                }
            });
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const submitColors: FormEventHandler = (e) => {
        e.preventDefault();
        setSavingColors(true);
        setColorErrors({});

        api.patch<{ data: TenantBrandingState }>('/tenant/branding', {
            primary_color: primaryColor || null,
            secondary_color: secondaryColor || null,
        })
            .then((response) => setBranding(response.data.data))
            .catch((err) => {
                const apiError: ApiError = toApiError(err);
                if (redirectIfUnauthenticated(apiError)) {
                    return;
                }
                if (apiError.errors) {
                    setColorErrors(apiError.errors);
                }
            })
            .finally(() => setSavingColors(false));
    };

    const uploadLogo = (file: File) => {
        setUploadingLogo(true);
        setLogoError(null);

        const formData = new FormData();
        formData.append('logo', file);

        api.post<{ data: TenantBrandingState }>('/tenant/branding/logo', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
            .then((response) => setBranding(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLogoError(apiError.message);
                }
            })
            .finally(() => setUploadingLogo(false));
    };

    const removeLogo = () => {
        if (!window.confirm('Remove the current logo?')) {
            return;
        }

        setRemovingLogo(true);
        setLogoError(null);

        api.delete<{ data: TenantBrandingState }>('/tenant/branding/logo')
            .then((response) => setBranding(response.data.data))
            .catch((err) => {
                const apiError = toApiError(err);
                if (!redirectIfUnauthenticated(apiError)) {
                    setLogoError(apiError.message);
                }
            })
            .finally(() => setRemovingLogo(false));
    };

    if (error) {
        return (
            <AppLayout>
                <Head title="Branding" />
                <div className="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error.message}</div>
            </AppLayout>
        );
    }

    if (!branding) {
        return (
            <AppLayout>
                <Head title="Branding" />
                <LoadingState label="Loading branding…" />
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Branding" />

            <PageHeader
                title="Branding"
                description="Logo and brand colors used across the app."
                actions={
                    <Link href="/settings" className="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                        Back to Settings
                    </Link>
                }
            />

            <Card title="Logo" className="mb-4">
                {logoError && <div className="mb-3 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{logoError}</div>}
                <div className="flex items-center gap-4">
                    {branding.logo_url ? (
                        <img src={branding.logo_url} alt="Tenant logo" className="h-16 w-16 rounded object-contain ring-1 ring-slate-200" />
                    ) : (
                        <div className="flex h-16 w-16 items-center justify-center rounded bg-slate-100 text-xs text-slate-400 ring-1 ring-slate-200">
                            No logo
                        </div>
                    )}
                    {canManage && (
                        <div className="flex items-center gap-3">
                            <label className="inline-flex cursor-pointer items-center rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                                {uploadingLogo ? 'Uploading…' : 'Upload logo'}
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    className="hidden"
                                    disabled={uploadingLogo}
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        if (file) uploadLogo(file);
                                        e.target.value = '';
                                    }}
                                />
                            </label>
                            {branding.logo_url && (
                                <Button type="button" variant="secondary" onClick={removeLogo} disabled={removingLogo}>
                                    {removingLogo ? 'Removing…' : 'Remove'}
                                </Button>
                            )}
                        </div>
                    )}
                </div>
                <p className="mt-2 text-xs text-slate-500">PNG or JPEG only, up to 2MB.</p>
            </Card>

            <Card title="Colors">
                {canManage ? (
                    <form onSubmit={submitColors}>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <InputField
                                    label="Primary color"
                                    name="primary_color"
                                    placeholder="#4F46E5"
                                    value={primaryColor}
                                    onChange={(e) => setPrimaryColor(e.target.value)}
                                    error={colorErrors.primary_color?.[0]}
                                />
                                {HEX_PATTERN.test(primaryColor) && (
                                    <span
                                        className="mt-1.5 inline-block h-5 w-5 rounded ring-1 ring-slate-200"
                                        style={{ backgroundColor: primaryColor }}
                                    />
                                )}
                            </div>
                            <div>
                                <InputField
                                    label="Secondary color"
                                    name="secondary_color"
                                    placeholder="#111827"
                                    value={secondaryColor}
                                    onChange={(e) => setSecondaryColor(e.target.value)}
                                    error={colorErrors.secondary_color?.[0]}
                                />
                                {HEX_PATTERN.test(secondaryColor) && (
                                    <span
                                        className="mt-1.5 inline-block h-5 w-5 rounded ring-1 ring-slate-200"
                                        style={{ backgroundColor: secondaryColor }}
                                    />
                                )}
                            </div>
                        </div>
                        <div className="mt-6 flex justify-end">
                            <Button type="submit" disabled={savingColors}>
                                {savingColors ? 'Saving…' : 'Save colors'}
                            </Button>
                        </div>
                    </form>
                ) : (
                    <dl className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-slate-500">Primary color</dt>
                            <dd className="font-medium text-slate-900">{branding.primary_color ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Secondary color</dt>
                            <dd className="font-medium text-slate-900">{branding.secondary_color ?? '—'}</dd>
                        </div>
                    </dl>
                )}
            </Card>
        </AppLayout>
    );
}

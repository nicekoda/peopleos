import { usePage } from '@inertiajs/react';
import { PageProps } from '@/types';

/**
 * UI-visibility only (Checkpoint 16) — never the security boundary.
 * Every backend route/action independently enforces the same
 * permission (and tenant/object-level checks) regardless of what this
 * returns. See docs/security.md.
 */
export function useCan(permission: string): boolean {
    const { auth } = usePage<PageProps>().props;

    return auth.user?.permissions.includes(permission) ?? false;
}

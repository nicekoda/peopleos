import { ReactNode } from 'react';
import { useCan } from '@/hooks/useCan';

interface PermissionGateProps {
    permission: string;
    children: ReactNode;
    fallback?: ReactNode;
}

/**
 * <PermissionGate permission="employees.view">...</PermissionGate>
 *
 * UI visibility only — hides/shows content for a better experience.
 * This is NOT a security boundary: the backend route and every API
 * action behind it independently enforce the same permission (plus
 * tenant isolation and object-level checks) regardless of whether this
 * component renders its children. See docs/security.md.
 */
export default function PermissionGate({ permission, children, fallback = null }: PermissionGateProps) {
    const can = useCan(permission);

    return can ? <>{children}</> : <>{fallback}</>;
}

/**
 * Mirrors the JSON shape returned by GET /api/v1/dashboard exactly
 * (Checkpoint 21). Every field here is already safe to render as-is —
 * the backend never includes raw records, leave/rejection reasons,
 * policy content, storage paths, IP addresses, user agents, or raw
 * internal actor IDs. `permission` on a card is informational only
 * (matches the module permission the backend already checked before
 * including this card) — the frontend never re-derives visibility from
 * it, since the card's mere presence in the response already means the
 * backend decided it was safe to show.
 */
export interface DashboardCard {
    key: string;
    label: string;
    value: number;
    href: string | null;
    permission: string;
}

export interface QuickLink {
    label: string;
    href: string;
}

export interface RecentItem {
    type: string;
    label: string;
    href: string;
}

export interface DashboardSummary {
    cards: DashboardCard[];
    quick_links: QuickLink[];
    recent_items: RecentItem[];
}

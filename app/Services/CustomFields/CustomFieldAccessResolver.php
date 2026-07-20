<?php

namespace App\Services\CustomFields;

use App\Enums\CustomFieldVisibilityRuleStatus;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldVisibilityRule;
use App\Models\User;

/**
 * Checkpoint 52 — extracted from CustomFieldDefinitionResource so the
 * CustomFormResource family can reuse the exact same can_view/can_edit
 * computation without re-deriving it. Checkpoint 53 — this is now also
 * the single call CustomFieldValueService's own read/write enforcement
 * makes (replacing a second, independent tier-only implementation that
 * previously lived there) — there is exactly one place in this
 * application that decides whether a user can view or edit a custom
 * field's value, and this is it. Any future consumer (reports, exports,
 * the AI assistant, workflow conditions) must call this method too,
 * never re-derive tier or rule logic on its own.
 *
 * Checkpoint 53 — configurable visibility rules are an *override*
 * layer, never a replacement for the fixed sensitivity-tier model:
 *
 *   1. Parent-entity permission (valueViewPermission()/valueUpdatePermission())
 *      is always required, regardless of rules or tier — a rule can
 *      never grant access to a record the user cannot already reach.
 *   2. If the user holds no role with an active visibility rule for
 *      this field, the original fixed tier-permission model applies
 *      unchanged (requiredAccessPermission(), reused as-is).
 *   3. If the user holds one or more roles with an active rule for
 *      this field, those rules together *fully replace* the tier
 *      result for this user (never merged with it) — most-permissive-
 *      wins across the matching rules: can_view is true if any
 *      matching rule grants view, can_edit is true if any matching
 *      rule grants edit. This is deliberately symmetric with how
 *      hasPermission() itself already aggregates across a user's
 *      roles (any one role granting a permission is enough).
 *
 * Role-based only in this checkpoint: a permission granted directly to
 * a user (bypassing role membership entirely, via the pre-existing
 * `user_permissions` direct-grant mechanism) is unaffected by any rule
 * here either way — it simply keeps following the fixed tier model,
 * since a rule only ever matches roles the user actually holds. This
 * asymmetry is deliberate, not a gap — see docs/architecture.md.
 */
class CustomFieldAccessResolver
{
    /**
     * @return array{can_view: bool, can_edit: bool}
     */
    public static function resolve(CustomFieldDefinition $definition, ?User $user): array
    {
        if ($user === null) {
            return ['can_view' => false, 'can_edit' => false];
        }

        $canViewParent = $user->hasPermission($definition->entity_type->valueViewPermission());
        $canEditParent = $user->hasPermission($definition->entity_type->valueUpdatePermission());

        [$canView, $canEdit] = self::resolveTierOrRuleAccess($definition, $user);

        return [
            'can_view' => $canViewParent && $canView,
            'can_edit' => $canEditParent && $canEdit,
        ];
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private static function resolveTierOrRuleAccess(CustomFieldDefinition $definition, User $user): array
    {
        $roleIds = $user->roles()->pluck('roles.id');

        $matchingRules = CustomFieldVisibilityRule::query()
            ->where('custom_field_definition_id', $definition->id)
            ->where('status', CustomFieldVisibilityRuleStatus::Active->value)
            ->whereIn('role_id', $roleIds)
            ->get();

        if ($matchingRules->isNotEmpty()) {
            return [
                $matchingRules->contains(fn (CustomFieldVisibilityRule $rule) => $rule->can_view),
                $matchingRules->contains(fn (CustomFieldVisibilityRule $rule) => $rule->can_edit),
            ];
        }

        $tierPermission = $definition->sensitivity->requiredAccessPermission();
        $hasTierAccess = $tierPermission === null || $user->hasPermission($tierPermission);

        return [$hasTierAccess, $hasTierAccess];
    }
}

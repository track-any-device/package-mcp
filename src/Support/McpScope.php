<?php

declare(strict_types=1);

namespace TrackAnyDevice\Mcp\Support;

use TrackAnyDevice\Core\Enums\Role;
use TrackAnyDevice\Core\Models\Assignee;
use TrackAnyDevice\Core\Models\Device;
use TrackAnyDevice\Core\Models\Tenant;
use TrackAnyDevice\Core\Models\User;
use TrackAnyDevice\Core\Services\BeatScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves which devices and assignees the calling user can see through
 * the MCP server. Encapsulates the three rules in one place:
 *
 *   1. Central host + Role::User       → own devices only (user_id +
 *                                        user_devices pivot).
 *   2. Central host + central staff    → all devices/assignees.
 *   3. Tenant host + any user          → scoped to that tenant; for
 *                                        TenantUsers, further restricted
 *                                        to the beats they're assigned to
 *                                        (matches BeatScopedAccess
 *                                        middleware behaviour).
 *
 * All visibility decisions for MCP tools go through this class so the
 * privacy rules can't drift between tools.
 */
class McpScope
{
    public function __construct(
        public readonly User $user,
        public readonly ?Tenant $tenant,
        private readonly BeatScope $beatScope,
    ) {}

    public static function forCurrentRequest(): self
    {
        /** @var User $user */
        $user = auth()->user();

        return new self(
            user: $user,
            tenant: function_exists('tenancy') ? tenancy()->tenant : null,
            beatScope: app(BeatScope::class),
        );
    }

    public function isTenantContext(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Build a Device query that is already scoped to what the user can see.
     */
    public function devices(): Builder
    {
        $query = Device::query();

        if ($this->isTenantContext()) {
            $query->where('tenant_id', $this->tenant->getKey());

            if ($this->user->role === Role::TenantUser) {
                $this->beatScope->scopeDeviceQuery($query, $this->user);
            }

            return $query;
        }

        if ($this->user->role?->isCentralStaff()) {
            return $query;
        }

        if ($this->user->role === Role::User) {
            $followedIds = $this->user->devices()->pluck('devices.id')->all();

            return $query->where(function (Builder $q) use ($followedIds) {
                $q->where('user_id', $this->user->id);
                if ($followedIds !== []) {
                    $q->orWhereIn('id', $followedIds);
                }
            });
        }

        // TenantUser hitting the central host with no active tenant is a
        // misconfiguration — show nothing rather than leaking other tenants'
        // devices.
        return $query->whereRaw('1 = 0');
    }

    /**
     * Build an Assignee query scoped to the caller's visibility.
     *
     * Assignees are tenant-bound by design (BelongsToTenant trait) and
     * outside a tenant context the concept of "an assignee" doesn't apply
     * to an end-user. So this only returns rows on tenant hosts.
     */
    public function assignees(): Builder
    {
        if (! $this->isTenantContext()) {
            return Assignee::query()->whereRaw('1 = 0');
        }

        $query = Assignee::query();

        if ($this->user->role === Role::TenantUser) {
            $beatIds = $this->beatScope->allowedBeatIds($this->user);

            if ($beatIds !== null) {
                $query->whereHas('activeDeviceAssignment.device.activeBeatAssignment', function (Builder $q) use ($beatIds) {
                    $q->whereIn('beat_id', $beatIds);
                });
            }
        }

        return $query;
    }

    /**
     * Whether the caller is allowed to see SIM and GSM numbers.
     * Only central admins can; tenants and end-users never see them
     * (see CLAUDE.md privacy-rules).
     */
    public function canSeeSimGsmNumbers(): bool
    {
        return $this->user->role === Role::Admin && ! $this->isTenantContext();
    }
}

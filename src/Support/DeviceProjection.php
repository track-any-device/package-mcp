<?php

declare(strict_types=1);

namespace TrackAnyDevice\Mcp\Support;

use TrackAnyDevice\Core\Models\Device;

/**
 * Privacy-aware serializer for Device rows returned through MCP tools.
 *
 * Hides `sim_number` and `gsm_number` for everyone except a central Admin
 * on the central host (see CLAUDE.md privacy-rules — these fields are
 * admin-only). Tenant portal users see the assignee's phone number
 * instead, exposed through `assignee_contact`.
 */
class DeviceProjection
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Device $device, McpScope $scope): array
    {
        $device->loadMissing([
            'deviceType:id,name,slug',
            'activeDeviceAssignment.assignee:id,name,code,metadata,assignee_type_id',
            'activeDeviceAssignment.assignee.assigneeType:id,name,slug',
            'activeBeatAssignment.beat:id,name',
        ]);

        $activeAssignment = $device->activeDeviceAssignment->first();
        $activeBeat = $device->activeBeatAssignment->first();

        $out = [
            'id' => $device->id,
            'imei' => $device->imei,
            'serial_number' => $device->serial_number,
            'name' => $device->name,
            'status' => $device->status?->value,
            'device_type' => $device->deviceType ? [
                'id' => $device->deviceType->id,
                'name' => $device->deviceType->name,
                'slug' => $device->deviceType->slug,
            ] : null,
            'battery_level' => $device->battery_level,
            'last_lat' => $device->last_lat !== null ? (float) $device->last_lat : null,
            'last_lon' => $device->last_lon !== null ? (float) $device->last_lon : null,
            'last_seen_at' => $device->last_seen_at?->toIso8601ZuluString(),
            'last_signal_at' => $device->last_signal_at?->toIso8601ZuluString(),
            'assignee' => $activeAssignment?->assignee ? [
                'id' => $activeAssignment->assignee->id,
                'name' => $activeAssignment->assignee->name,
                'code' => $activeAssignment->assignee->code,
                'type' => $activeAssignment->assignee->assigneeType?->name,
                'contact' => static::assigneeContact($activeAssignment->assignee->metadata),
            ] : null,
            'beat' => $activeBeat?->beat ? [
                'id' => $activeBeat->beat->id,
                'name' => $activeBeat->beat->name,
            ] : null,
        ];

        if ($scope->canSeeSimGsmNumbers()) {
            $out['sim_number'] = $device->sim_number;
            $out['gsm_number'] = $device->gsm_number;
        }

        return $out;
    }

    /**
     * Compact, list-friendly projection — drops nested metadata.
     *
     * @return array<string, mixed>
     */
    public static function toCompactArray(Device $device, McpScope $scope): array
    {
        $row = static::toArray($device, $scope);

        return [
            'id' => $row['id'],
            'imei' => $row['imei'],
            'name' => $row['name'],
            'status' => $row['status'],
            'device_type' => $row['device_type']['name'] ?? null,
            'battery_level' => $row['battery_level'],
            'last_seen_at' => $row['last_seen_at'],
            'assignee_name' => $row['assignee']['name'] ?? null,
            'beat_name' => $row['beat']['name'] ?? null,
        ];
    }

    /**
     * Surface the assignee's phone from their type-specific metadata blob.
     * Most assignee types in the seed data carry the phone under the
     * `phone` key; falls back to common alternates if not.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    private static function assigneeContact(?array $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }

        foreach (['phone', 'contact', 'primary_contact', 'mobile'] as $key) {
            if (! empty($metadata[$key])) {
                return (string) $metadata[$key];
            }
        }

        return null;
    }
}

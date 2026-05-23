<?php

declare(strict_types=1);

namespace TrackAnyDevice\Mcp\Support;

use TrackAnyDevice\Core\Models\Device;
use TrackAnyDevice\Core\Models\Signal;
use TrackAnyDevice\Core\Services\SignalService;

/**
 * Builds the canonical Signal-shaped payload that the MCP tools return
 * for any location- or sensor-reading question.
 *
 * The shape mirrors TrackAnyDevice\Core\Models\Signal::toArray() exactly so any AI client
 * can be told once "this is a Signal object" and apply the same parsing
 * across every tool that emits one. We prefer the InfluxDB Signal row
 * when available (richer fields, includes `level`, `temperature`, etc.)
 * and fall back to the Device's snapshot columns (last_lat/lon,
 * last_seen_at, battery_level) when InfluxDB has nothing on file.
 */
class SignalProjection
{
    /**
     * Latest signal for a device, in canonical Signal-object shape.
     *
     * @return array<string, mixed>|null null when the device has never reported
     */
    public static function latestForDevice(Device $device): ?array
    {
        $signals = app(SignalService::class)->latestForDevice($device->id, 1);

        $signal = $signals->first();

        if ($signal instanceof Signal) {
            return $signal->toArray();
        }

        // InfluxDB disabled / no rows yet — synthesise from the device's
        // snapshot columns so the AI still has a usable answer.
        if ($device->last_lat === null && $device->last_lon === null && $device->last_seen_at === null) {
            return null;
        }

        return [
            'device_id' => $device->id,
            'imei' => $device->imei,
            'event_type' => 'update',
            'source' => 'snapshot',
            'server_time' => $device->last_signal_at?->toIso8601ZuluString()
                ?? $device->last_seen_at?->toIso8601ZuluString(),
            'device_time' => null,
            'latitude' => $device->last_lat !== null ? (float) $device->last_lat : null,
            'longitude' => $device->last_lon !== null ? (float) $device->last_lon : null,
            'altitude' => null,
            'speed' => null,
            'direction' => null,
            'gps_fixed' => $device->last_lat !== null,
            'satellites' => null,
            'positioning_type' => null,
            'hdop' => null,
            'battery_percent' => $device->battery_level,
            'battery_voltage' => null,
            'battery_capacity_mah' => null,
            'gsm_signal' => null,
            'network_signal' => null,
            'mcc' => null,
            'mnc' => null,
            'lac' => null,
            'cell_id' => null,
            'working_mode' => null,
            'alarm_flags' => null,
            'status_flags' => null,
            'level' => null,
            'temperature' => null,
            'raw_payload' => null,
            'extra' => [],
        ];
    }

    /**
     * Parsing notes embedded in every Signal-shaped tool response so the
     * AI client can be told what each field means.
     *
     * @return array<string, string>
     */
    public static function parsingInstructions(): array
    {
        return [
            'shape' => 'Each "signal" is a snapshot of one device at one point in time. Shape matches App\\Models\\Signal::toArray().',
            'time' => 'server_time and device_time are ISO-8601 UTC with a Z suffix. Always treat them as UTC.',
            'location' => 'latitude/longitude are decimal degrees (WGS84). gps_fixed=false means the coordinates are stale or LBS-derived.',
            'battery' => 'battery_percent is 0-100. battery_voltage in mV. battery_capacity_mah is the design capacity, not remaining.',
            'sensors' => 'Custom sensor values appear in these slots: temperature (°C), level (water/tank/material level — units defined by the device).',
            'event_type' => 'One of: update, punch_in, punch_out, sos, alarm, online, offline, registration, intercom, ack. See App\\Enums\\SignalEventType.',
            'source' => 'snapshot = derived from the device row when InfluxDB had no recent point. Otherwise an entry from App\\Enums\\SignalSource.',
            'null_fields' => 'A null value means the device did not report that sensor on this reading, not that the value is zero.',
        ];
    }
}

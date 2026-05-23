<?php

declare(strict_types=1);

namespace TrackAnyDevice\Mcp\Tools;

use TrackAnyDevice\Mcp\Support\McpScope;
use TrackAnyDevice\Mcp\Support\SignalProjection;
use TrackAnyDevice\Core\Models\Assignee;
use TrackAnyDevice\Core\Models\Sensor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('read_sensor')]
#[Title('Read sensor reading')]
#[Description('Read the latest value of a named sensor (e.g. "level", "temperature", "battery_percent") from the device currently assigned to an assignee. Use this for "What is the water level at Gate B?" — pass `assignee="Gate B"` and `sensor="level"`. Only works on tenant subdomains. Returns the value plus a full Signal object so the AI has context (timestamp, GPS fix, etc.).')]
class ReadSensorTool extends Tool
{
    public function handle(Request $request): Response
    {
        $scope = McpScope::forCurrentRequest();

        if (! $scope->isTenantContext()) {
            return Response::json([
                'error' => 'wrong_host',
                'message' => 'Sensor readings are looked up per-assignee, which requires a tenant context. Reconnect your AI tool to the tenant subdomain MCP URL.',
            ]);
        }

        $assigneeName = $request->string('assignee')->trim()->toString();
        $sensorSlug = $request->string('sensor')->trim()->toString();

        if ($assigneeName === '' || $sensorSlug === '') {
            return Response::json([
                'error' => 'validation',
                'message' => 'Both `assignee` and `sensor` are required. `sensor` should be a sensor slug — call list_sensors-style tools or try common slugs: level, temperature, battery_percent, speed, gps, gsm_signal, satellites.',
            ]);
        }

        $assignee = $this->resolveAssignee($scope, $assigneeName);
        if ($assignee === null) {
            return Response::json([
                'error' => 'assignee_not_found',
                'message' => "No assignee matches \"{$assigneeName}\". Try the locate_assignee tool first to disambiguate.",
                'query' => $assigneeName,
            ]);
        }

        $device = $assignee->activeDeviceAssignment->first()?->device;
        if ($device === null) {
            return Response::json([
                'error' => 'no_device',
                'message' => "{$assignee->name} has no active device assignment, so no sensor reading is available.",
                'assignee' => ['id' => $assignee->id, 'name' => $assignee->name, 'code' => $assignee->code],
            ]);
        }

        $sensor = Sensor::where('slug', $sensorSlug)->first();
        if ($sensor === null) {
            return Response::json([
                'error' => 'unknown_sensor',
                'message' => "Sensor \"{$sensorSlug}\" is not registered. Check the sensors catalogue (config/sensors or the admin panel).",
                'sensor' => $sensorSlug,
            ]);
        }

        $allowed = $device->effectiveSensorSlugs();
        if (! in_array($sensorSlug, $allowed, true)) {
            return Response::json([
                'error' => 'sensor_not_supported',
                'message' => "{$assignee->name}'s device ({$device->name}, type {$device->deviceType?->name}) does not advertise the \"{$sensorSlug}\" sensor. Supported sensors on this device: ".implode(', ', $allowed).'.',
                'sensor' => $sensorSlug,
                'supported_sensors' => $allowed,
            ]);
        }

        $signal = SignalProjection::latestForDevice($device);
        $value = $this->extractSensorValue($signal, $sensorSlug);

        return Response::json([
            'assignee' => [
                'id' => $assignee->id,
                'name' => $assignee->name,
                'code' => $assignee->code,
                'type' => $assignee->assigneeType?->name,
            ],
            'sensor' => [
                'slug' => $sensor->slug,
                'label' => $sensor->displayLabel(),
                'unit' => $sensor->unit,
                'data_type' => $sensor->data_type,
            ],
            'reading' => [
                'value' => $value,
                'observed_at' => $signal['server_time'] ?? null,
                'is_stale' => $signal === null,
            ],
            'signal' => $signal,
            'parsing' => SignalProjection::parsingInstructions(),
            'usage_hint' => $value === null
                ? 'The device exists and supports this sensor but the latest reading does not include a value. Either the sensor has not reported yet or this device sends it on a different cadence.'
                : "The value is in the \"{$sensor->unit}\" unit (or unitless if blank). Combine it with `reading.observed_at` to tell the user how fresh the reading is.",
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'assignee' => $schema->string()
                ->required()
                ->description('Name or code of the assignee whose device should be read. Example: "Gate B", "PV-3", "Ali".'),
            'sensor' => $schema->string()
                ->required()
                ->description('Sensor slug. Common values: level (water/tank level), temperature, battery_percent, speed, gsm_signal, satellites, gps.'),
        ];
    }

    private function resolveAssignee(McpScope $scope, string $name): ?Assignee
    {
        $base = $scope->assignees()->with([
            'assigneeType:id,name,slug',
            'activeDeviceAssignment.device.deviceType.sensors:id,slug,unit',
            'activeDeviceAssignment.device.sensors:id,slug,unit',
        ]);

        return (clone $base)->where('code', $name)->first()
            ?? (clone $base)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? (clone $base)->whereRaw('LOWER(name) like ?', ['%'.mb_strtolower($name).'%'])->first();
    }

    /**
     * Pull the slug's value out of the canonical Signal-shaped payload.
     * Sensor slugs map onto specific Signal fields; for slugs that aren't
     * a top-level field we fall through to the `extra` bag.
     *
     * @param  array<string, mixed>|null  $signal
     */
    private function extractSensorValue(?array $signal, string $slug): mixed
    {
        if ($signal === null) {
            return null;
        }

        $map = [
            'gps' => $signal['latitude'] !== null && $signal['longitude'] !== null
                ? ['lat' => $signal['latitude'], 'lon' => $signal['longitude']]
                : null,
            'altitude' => $signal['altitude'] ?? null,
            'speed' => $signal['speed'] ?? null,
            'direction' => $signal['direction'] ?? null,
            'battery_percent' => $signal['battery_percent'] ?? null,
            'battery_voltage' => $signal['battery_voltage'] ?? null,
            'battery_capacity' => $signal['battery_capacity_mah'] ?? null,
            'gsm_signal' => $signal['gsm_signal'] ?? null,
            'network_signal' => $signal['network_signal'] ?? null,
            'satellites' => $signal['satellites'] ?? null,
            'temperature' => $signal['temperature'] ?? null,
            'level' => $signal['level'] ?? null,
            'hdop' => $signal['hdop'] ?? null,
            'positioning_type' => $signal['positioning_type'] ?? null,
            'mcc' => $signal['mcc'] ?? null,
            'mnc' => $signal['mnc'] ?? null,
            'lac' => $signal['lac'] ?? null,
            'cell_id' => $signal['cell_id'] ?? null,
        ];

        if (array_key_exists($slug, $map)) {
            return $map[$slug];
        }

        return $signal['extra'][$slug] ?? null;
    }
}

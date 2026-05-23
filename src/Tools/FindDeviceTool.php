<?php

declare(strict_types=1);

namespace TrackAnyDevice\Mcp\Tools;

use TrackAnyDevice\Mcp\Support\DeviceProjection;
use TrackAnyDevice\Mcp\Support\McpScope;
use TrackAnyDevice\Mcp\Support\SignalProjection;
use TrackAnyDevice\Core\Models\Device;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('find_device')]
#[Title('Find a device')]
#[Description('Locate a single device by IMEI, serial number, name (partial match) or numeric id. Returns the device record plus the latest Signal-object reading for that device. Use this for "where is device XYZ" questions.')]
class FindDeviceTool extends Tool
{
    public function handle(Request $request): Response
    {
        $scope = McpScope::forCurrentRequest();

        $query = $request->string('query')->trim()->toString();
        if ($query === '') {
            return Response::json([
                'error' => 'validation',
                'message' => 'The `query` parameter is required (IMEI, serial, name, or numeric device id).',
            ]);
        }

        $devices = $this->matchDevices($scope, $query);

        if ($devices->isEmpty()) {
            return Response::json([
                'error' => 'not_found',
                'message' => "No device matches \"{$query}\" within the scope visible to you.",
                'query' => $query,
            ]);
        }

        $matches = $devices->map(function (Device $device) use ($scope) {
            return [
                'device' => DeviceProjection::toArray($device, $scope),
                'signal' => SignalProjection::latestForDevice($device),
            ];
        })->values()->all();

        return Response::json([
            'query' => $query,
            'match_count' => count($matches),
            'primary' => $matches[0] ?? null,
            'matches' => $matches,
            'parsing' => SignalProjection::parsingInstructions(),
            'usage_hint' => count($matches) > 1
                ? 'Multiple devices matched. Ask the user which one they meant (use the `device.name`, `device.imei`, or `device.assignee.name` to disambiguate), then re-call with a more specific query (e.g. the full IMEI).'
                : 'A single device matched. Use `primary.signal.latitude` / `primary.signal.longitude` for the device location.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->required()
                ->description('IMEI (preferred — exact match), serial number, numeric device id, or a partial name.'),
        ];
    }

    private function matchDevices(McpScope $scope, string $query): Collection
    {
        $base = $scope->devices();

        if (ctype_digit($query)) {
            $byId = (clone $base)->where('id', (int) $query)->get();
            if ($byId->isNotEmpty()) {
                return $byId;
            }
        }

        $exact = (clone $base)->where(function ($q) use ($query) {
            $q->where('imei', $query)->orWhere('serial_number', $query);
        })->get();

        if ($exact->isNotEmpty()) {
            return $exact;
        }

        $like = "%{$query}%";

        return (clone $base)->where(function ($q) use ($like) {
            $q->where('imei', 'like', $like)
                ->orWhere('serial_number', 'like', $like)
                ->orWhere('name', 'like', $like);
        })->limit(10)->get();
    }
}

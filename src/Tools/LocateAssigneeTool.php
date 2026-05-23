<?php

declare(strict_types=1);

namespace TrackAnyDevice\Mcp\Tools;

use TrackAnyDevice\Mcp\Support\DeviceProjection;
use TrackAnyDevice\Mcp\Support\McpScope;
use TrackAnyDevice\Mcp\Support\SignalProjection;
use TrackAnyDevice\Core\Models\Assignee;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('locate_assignee')]
#[Title('Locate an assignee')]
#[Description('Find an assignee (a person, vehicle, gate, or any other tracked entity) by name or code, and return the latest Signal-object location of the device currently assigned to them. Use this for "Where is Ali?" or "Where is patrol vehicle PV-3?" style questions. Only works on tenant subdomains — assignees do not exist on the central host.')]
class LocateAssigneeTool extends Tool
{
    public function handle(Request $request): Response
    {
        $scope = McpScope::forCurrentRequest();

        if (! $scope->isTenantContext()) {
            return Response::json([
                'error' => 'wrong_host',
                'message' => 'Assignees only exist inside a tenant. Reconnect your AI tool to the tenant subdomain MCP URL.',
            ]);
        }

        $name = $request->string('name')->trim()->toString();
        if ($name === '') {
            return Response::json([
                'error' => 'validation',
                'message' => 'The `name` parameter is required.',
            ]);
        }

        $assignees = $this->matchAssignees($scope, $name);

        if ($assignees->isEmpty()) {
            return Response::json([
                'error' => 'not_found',
                'message' => "No assignee matches \"{$name}\" within the scope visible to you.",
                'query' => $name,
            ]);
        }

        $matches = $assignees->map(function (Assignee $assignee) use ($scope) {
            $assignment = $assignee->activeDeviceAssignment->first();
            $device = $assignment?->device;

            return [
                'assignee' => [
                    'id' => $assignee->id,
                    'name' => $assignee->name,
                    'code' => $assignee->code,
                    'type' => $assignee->assigneeType?->name,
                    'status' => $assignee->status?->value,
                ],
                'device' => $device ? DeviceProjection::toArray($device, $scope) : null,
                'signal' => $device ? SignalProjection::latestForDevice($device) : null,
                'note' => $device === null
                    ? 'This assignee currently has no active device assignment — there is no location to report.'
                    : null,
            ];
        })->values()->all();

        return Response::json([
            'query' => $name,
            'match_count' => count($matches),
            'primary' => $matches[0] ?? null,
            'matches' => $matches,
            'parsing' => SignalProjection::parsingInstructions(),
            'usage_hint' => count($matches) > 1
                ? 'Multiple assignees matched. Ask the user to disambiguate (e.g. by code) and re-call with the assignee code instead of the name.'
                : 'Single assignee matched. Use `primary.signal.latitude` / `primary.signal.longitude` for the location, and `primary.signal.server_time` for when that reading came in.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->required()
                ->description('Assignee name, code, or partial name. Matching is case-insensitive.'),
        ];
    }

    private function matchAssignees(McpScope $scope, string $name): Collection
    {
        $base = $scope->assignees()->with([
            'assigneeType:id,name,slug',
            'activeDeviceAssignment.device',
            'activeDeviceAssignment.device.deviceType:id,name,slug',
            'activeDeviceAssignment.device.activeBeatAssignment.beat:id,name',
        ]);

        $exactCode = (clone $base)->where('code', $name)->get();
        if ($exactCode->isNotEmpty()) {
            return $exactCode;
        }

        $exactName = (clone $base)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->get();
        if ($exactName->isNotEmpty()) {
            return $exactName;
        }

        $like = '%'.mb_strtolower($name).'%';

        return (clone $base)->where(function ($q) use ($like) {
            $q->whereRaw('LOWER(name) like ?', [$like])
                ->orWhereRaw('LOWER(code) like ?', [$like]);
        })->limit(10)->get();
    }
}

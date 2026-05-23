<?php

declare(strict_types=1);

namespace TrackAnyDevice\Mcp\Tools;

use TrackAnyDevice\Mcp\Support\DeviceProjection;
use TrackAnyDevice\Mcp\Support\McpScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('list_devices')]
#[Title('List devices')]
#[Description('Lists the devices visible to the authenticated user — paginated, with id, IMEI, name, status, battery, last-seen time, current assignee and current beat. Never includes SIM or GSM numbers for non-admin callers.')]
class ListDevicesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $scope = McpScope::forCurrentRequest();

        $perPage = max(1, min(100, (int) ($request->get('per_page') ?? 25)));
        $page = max(1, (int) ($request->get('page') ?? 1));

        $query = $scope->devices()->orderByDesc('last_seen_at');

        $status = $request->string('status')->toString();
        if ($status !== '') {
            $query->where('status', $status);
        }

        $search = $request->string('search')->toString();
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = "%{$search}%";
                $q->where('name', 'like', $like)
                    ->orWhere('imei', 'like', $like)
                    ->orWhere('serial_number', 'like', $like);
            });
        }

        $total = (clone $query)->count();
        $devices = $query->forPage($page, $perPage)->get();

        return Response::json([
            'data' => $devices->map(fn ($device) => DeviceProjection::toCompactArray($device, $scope))->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($page * $perPage) < $total,
            ],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()
                ->description('Page number, 1-indexed. Default 1.'),
            'per_page' => $schema->integer()
                ->description('Items per page (1-100). Default 25.'),
            'status' => $schema->string()
                ->description('Optional. Filter by device status (e.g. "assigned", "available").'),
            'search' => $schema->string()
                ->description('Optional. Free-text match against device name, IMEI or serial number.'),
        ];
    }
}

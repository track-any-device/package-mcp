<?php

declare(strict_types=1);

namespace TrackAnyDevice\Mcp\Tools;

use TrackAnyDevice\Mcp\Support\McpScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('count_devices')]
#[Title('Count devices')]
#[Description('Returns how many devices are visible to the authenticated user. Use this for "how many devices do I have" style questions. The count respects per-user, per-tenant and beat scoping.')]
class CountDevicesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $scope = McpScope::forCurrentRequest();

        $query = $scope->devices();

        $status = $request->string('status')->toString();
        if ($status !== '') {
            $query->where('status', $status);
        }

        $count = $query->count();

        return Response::json([
            'count' => $count,
            'scope' => [
                'tenant' => $scope->tenant?->id,
                'role' => $scope->user->role?->value,
                'status_filter' => $status !== '' ? $status : null,
            ],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Optional. Limit the count to devices with this status (e.g. "assigned", "available", "offline").'),
        ];
    }
}

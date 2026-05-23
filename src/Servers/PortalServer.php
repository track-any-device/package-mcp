<?php

declare(strict_types=1);

namespace TrackAnyDevice\Mcp\Servers;

use TrackAnyDevice\Mcp\Tools\CountDevicesTool;
use TrackAnyDevice\Mcp\Tools\FindDeviceTool;
use TrackAnyDevice\Mcp\Tools\ListDevicesTool;
use TrackAnyDevice\Mcp\Tools\LocateAssigneeTool;
use TrackAnyDevice\Mcp\Tools\ReadSensorTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Fleet Tracking Portal')]
#[Version('1.0.0')]
#[Instructions(<<<'MD'
This MCP server lets your AI tool query the fleet-tracking platform on
behalf of the authenticated user. The same server class is mounted on
the central host AND on each tenant subdomain — the scoping of what you
see depends entirely on which URL you connected to and which user token
you used:

- On the central host (e.g. https://app.example.com/mcp) with an
  end-user token you see only the devices that user purchased or follows.
- On the central host with an admin token you see every device.
- On a tenant subdomain (e.g. https://tenant.example.com/mcp) you see
  that tenant's devices and assignees. If the user is a TenantUser, the
  view is further narrowed to the beats they are assigned to.

SIM and GSM phone numbers are never returned except for central admins
on the central host. For tenants, the assignee's own phone is exposed
under each device's `assignee.contact` field.

All location and sensor responses follow the canonical "Signal object"
shape (mirrors App\\Models\\Signal::toArray()). Every such response
ships with a `parsing` block describing each field so you can interpret
the payload without external documentation.
MD)]
class PortalServer extends Server
{
    /**
     * @var array<int, class-string>
     */
    protected array $tools = [
        CountDevicesTool::class,
        ListDevicesTool::class,
        FindDeviceTool::class,
        LocateAssigneeTool::class,
        ReadSensorTool::class,
    ];
}

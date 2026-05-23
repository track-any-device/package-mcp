# track-any-device/mcp

MCP (Model Context Protocol) server for the Track Any Device platform. Exposes fleet-tracking
data — devices, locations, assignees, and sensor readings — to AI assistants via
[`laravel/mcp`](https://github.com/laravel/mcp).

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.3` |
| Laravel | `^13.7` |
| `laravel/mcp` | `^0.7.0` |
| `track-any-device/core` | `^0.0.2` |
| `stancl/tenancy` | `^3.10` (host app must install) |

---

## Installation

```bash
composer require track-any-device/mcp
```

### 1 — Publish and wire the MCP route

The package does not auto-register a route. Publish the route stub and add the server:

```bash
php artisan vendor:publish --tag=mcp-routes   # from laravel/mcp
```

Then in `routes/ai.php` (created by the above command, or created manually):

```php
use Laravel\Mcp\Facades\Mcp;
use TrackAnyDevice\Mcp\Servers\PortalServer;

Mcp::web('/mcp', PortalServer::class)
    ->middleware(['web', 'auth']);
```

> **Tenant subdomains** — register a second entry inside your tenancy route group
> so the URL is the same path on each subdomain. The `McpScope` detects the active
> tenant automatically via `tenancy()->tenant`.

### 2 — Environment variables

No env vars are required by this package itself. The underlying `laravel/mcp` package
publishes its own config (`config/mcp.php`) which controls OAuth, path prefixes, etc.

---

## Auth flow

Requests to `/mcp` must arrive with a valid **Laravel session cookie** (web middleware) or
a **Sanctum / Passport bearer token** (add the appropriate auth guard middleware). The package
calls `auth()->user()` and aborts with 401 if no authenticated user is found.

The same endpoint serves multiple roles — scoping is applied automatically:

| Context | Role | What is visible |
|---|---|---|
| Central host | `Role::Admin` | All devices across all tenants; SIM/GSM numbers included |
| Central host | `Role::User` | Only devices the user purchased or follows (`user_id` + pivot) |
| Central host | `Role::TenantUser` | Nothing (misconfiguration guard — returns empty) |
| Tenant subdomain | `Role::Admin` / `Role::TenantAdmin` | All devices for that tenant |
| Tenant subdomain | `Role::TenantUser` | Devices on beats the user is assigned to |

---

## Host-app contracts

This package depends on the following from `track-any-device/core`. Any breakage in these
will cause runtime errors:

| Symbol | Type | Used in |
|---|---|---|
| `TrackAnyDevice\Core\Models\Device` | Eloquent model | All tools |
| `Device::activeDeviceAssignment` | Relationship | `DeviceProjection`, `LocateAssigneeTool`, `ReadSensorTool` |
| `Device::activeBeatAssignment` | Relationship | `DeviceProjection` |
| `Device::effectiveSensorSlugs(): array<string>` | Method | `ReadSensorTool` |
| `TrackAnyDevice\Core\Models\Assignee` | Eloquent model | `LocateAssigneeTool`, `ReadSensorTool` |
| `Assignee::activeDeviceAssignment` | Relationship | `LocateAssigneeTool`, `ReadSensorTool` |
| `TrackAnyDevice\Core\Models\Sensor` | Eloquent model | `ReadSensorTool` |
| `Sensor::displayLabel(): string` | Method | `ReadSensorTool` |
| `TrackAnyDevice\Core\Models\Signal` | Eloquent / InfluxDB model | `SignalProjection` |
| `TrackAnyDevice\Core\Services\BeatScope` | Service class | `McpScope` |
| `TrackAnyDevice\Core\Services\SignalService` | Service class | `SignalProjection` |
| `TrackAnyDevice\Core\Enums\Role` | Backed enum | `McpScope` |
| `Role::isCentralStaff(): bool` | Method | `McpScope` |
| `tenancy()` global helper | stancl/tenancy | `McpScope` |

---

## MCP server

### `PortalServer` — `src/Servers/PortalServer.php`

Single server class mounted at the configured URL. The same class is used on both the central
host and every tenant subdomain — scoping is transparent to the AI client.

**Server instructions** (sent to the AI at connection time) describe:
- URL-based scoping rules
- SIM/GSM privacy rules
- Signal object shape

---

## Tools

### Navigation: Device tools

| Tool | Name | Description |
|---|---|---|
| `CountDevicesTool` | `count_devices` | How many devices are visible. Accepts optional `status` filter. |
| `ListDevicesTool` | `list_devices` | Paginated device list. Filters: `status`, `search` (name / IMEI / serial). |
| `FindDeviceTool` | `find_device` | Find a single device by IMEI, serial, name (partial), or numeric id. Returns device + latest Signal. |

### Navigation: Assignee & sensor tools

| Tool | Name | Description |
|---|---|---|
| `LocateAssigneeTool` | `locate_assignee` | Find an assignee by name or code, return their device's latest Signal location. Tenant only. |
| `ReadSensorTool` | `read_sensor` | Read a named sensor value (`level`, `temperature`, `battery_percent`, etc.) from an assignee's device. Tenant only. |

---

## Signal object shape

Every location/sensor response includes a `signal` key and a `parsing` key. The signal shape
mirrors `TrackAnyDevice\Core\Models\Signal::toArray()`:

```
server_time      ISO-8601 UTC (Z suffix)
device_time      ISO-8601 UTC (Z suffix) — may be null
latitude         decimal degrees WGS84
longitude        decimal degrees WGS84
gps_fixed        bool — false = stale or LBS-derived coordinates
battery_percent  0–100
battery_voltage  mV
temperature      °C (custom sensor)
level            tank/water/material level (custom sensor; units defined by device type)
event_type       update | punch_in | punch_out | sos | alarm | online | offline | ...
source           snapshot | <SignalSource enum value>
extra            object — device-specific extra fields
```

A `source: "snapshot"` means InfluxDB had no recent data and the reading was synthesised from
the device row's snapshot columns (`last_lat`, `last_lon`, `last_seen_at`).

---

## Release workflow

Releases are created automatically by `.github/workflows/release.yml` on every push to `main`
and can also be triggered manually.

### Conventional commit → version bump

| Commit prefix | Bump |
|---|---|
| `fix:`, `chore:`, `refactor:`, `docs:`, `test:`, `style:`, `perf:` | **patch** |
| `feat:` | **minor** |
| `feat!:`, `fix!:`, any type with `BREAKING CHANGE` footer | **major** |

The workflow reads the latest git tag, increments the appropriate component, creates a new
annotated tag, and publishes a GitHub Release with an auto-generated changelog of commits
since the previous tag. If there are no new commits since the last tag, the job exits cleanly
without creating a release.

### Manual release

Go to **Actions → Release → Run workflow** and pick `patch`, `minor`, or `major`.

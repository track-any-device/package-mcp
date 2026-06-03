# package-mcp — AI Instructions

This is the **MCP (Model Context Protocol) server package** for the Track Any Device platform.
Packagist: `track-any-device/mcp` | Namespace: `TrackAnyDevice\Mcp\`

This package exposes fleet data to AI assistants (Claude, Cursor, etc.) through the MCP protocol.
It mounts an `/mcp` endpoint with tenant-scoped tools that allow AI agents to query devices,
locate assignees, and read sensor data — without exposing raw database access.

Read this file before making any change.

---

## Platform-Wide Rules

These three rules apply in every repository under the `track-any-device` organisation.

**Cross-repo changes: file a GitHub issue first.**
If a task in this repository requires a change in another package or server app — stop. Open a
GitHub issue in the target repository describing exactly what is needed and why. Reference that
issue number in your commit message (`ref track-any-device/{repo}#{n}`). Do not directly edit
files in another repository. When picking up a cross-repo issue, run Claude locally inside that
repository's working directory and work only within its scope.

**Release order: packages before server apps.**
This package depends on `package-core`. Release order: `package-core → package-mcp → server apps`.

**Database layer lives in `package-core` only.**
No migrations or model classes here. All data is read from models in `package-core`.

---

## Rule 1 — Plan before implementing

Before writing any code, ask clarifying questions. Present a plan and get explicit agreement.
Only begin once the approach is confirmed.

---

## Available MCP Tools

| Tool | Description | Scope |
|---|---|---|
| `count_devices` | Count devices visible to the caller | Tenant or global |
| `list_devices` | List devices with status and last signal | Tenant or global |
| `find_device` | Find a device by IMEI or name | Tenant or global |
| `locate_assignee` | Get the last known location of an assignee | Tenant |
| `read_sensor` | Read the latest sensor value for a device | Tenant |

---

## Rule 2 — All tools must be tenant-scoped for tenant users

Central staff (`Role::Admin`, `Role::Supervisor`, `Role::Staff`) see all data.
Tenant users see only devices in their assigned beat (`BeatScopedAccess`).
Never return cross-tenant data to a tenant user from any MCP tool.

---

## Rule 3 — MCP tools are read-only

No MCP tool may write to the database, dispatch commands, or modify device state.
MCP is a read interface for AI assistants — not a command channel.

---

## Rule 4 — Signal shape is stable

The signal shape returned by `read_sensor` is part of the public MCP API. Do not rename
or remove fields without versioning the tool. Add new fields additively.

---

## Authentication

The `/mcp` endpoint uses `web` middleware + `auth`. Only authenticated users reach the tools.
The caller's `Role` determines what data is visible.

---

## Dependencies

```
track-any-device/core
laravel/mcp ^0.1
```

---

## Versioning

Tags are created automatically on merge to `main`. Default bump is `patch`.

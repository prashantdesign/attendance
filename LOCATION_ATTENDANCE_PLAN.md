# Location-Based Auto Attendance (Feasibility + Implementation Plan)

## Is this possible?
Yes. It is technically possible to implement all points you asked for:

- Admin can enable/disable location attendance globally.
- Admin can set one or more office pins (latitude/longitude + radius).
- Admin can enable/disable location attendance per user.
- Auto punch-in when user enters radius.
- Auto break start when user exits radius during active shift.
- Auto break end when user re-enters radius.
- Auto half-day classification (first half / second half).
- Keep manual controls as fallback.
- Background location service support (mobile app/PWA constraints apply).

## Recommended architecture

### 1) Data model additions
Add these tables:

1. `location_attendance_settings`
   - `id`
   - `global_enabled` (bool)
   - `office_latitude`, `office_longitude`
   - `office_radius_meters`
   - `half_day_first_half_cutoff_ist` (example `14:00:00`)
   - `half_day_second_half_cutoff_ist` (example `18:30:00`)
   - `updated_by`, `updated_at`

2. `user_location_prefs`
   - `user_id` (FK)
   - `location_attendance_enabled` (bool)
   - `background_tracking_required` (bool)
   - `updated_by`, `updated_at`

3. `location_events`
   - `id`
   - `user_id`
   - `event_type` (`enter_geofence`, `exit_geofence`, `heartbeat`, `manual_override`)
   - `latitude`, `longitude`, `accuracy_m`
   - `distance_from_office_m`
   - `device_time`, `server_time`
   - `action_taken` (`auto_clock_in`, `auto_break_start`, `auto_break_end`, `auto_clock_out`, `none`)
   - `meta_json`

### 2) API layer
Add/extend endpoints:

#### Admin APIs
- `GET /admin_api.php?action=get_location_settings`
- `POST /admin_api.php?action=save_location_settings`
- `POST /admin_api.php?action=set_user_location_attendance`
- `GET /admin_api.php?action=get_user_location_attendance_list`

#### User APIs
- `GET /api.php?action=get_location_policy`
- `POST /api.php?action=location_event`

`location_event` request payload example:

```json
{
  "latitude": 23.0225,
  "longitude": 72.5714,
  "accuracy_m": 18,
  "is_background": true,
  "device_time": "2026-03-06T14:27:00+05:30"
}
```

### 3) Decision engine (server-side)
Use server-side geofence evaluation (never trust only client decision).

1. Validate user eligibility:
   - global enabled
   - user enabled
2. Compute distance by Haversine formula.
3. Determine inside/outside radius.
4. Compare with last known state (inside/outside).
5. Trigger attendance actions:
   - outside -> inside:
     - if no active attendance today: **clock in**
     - if on break: **break end (immediate)**
   - inside -> outside:
     - if logged in and not on break: **break start (immediate)**
6. Do not force auto punch-out at a fixed time.
7. Recalculate day status:
   - no sufficient work hours => half day
   - first half / second half based on configured cutoffs.

### 4) Mobile/background constraints
- Android native app: reliable with foreground/background service.
- iOS native app: must follow strict background location permissions/policies.
- Browser-only PWA: background tracking is limited and not always reliable.

For production-grade reliability, use native wrapper/app for location service.

### 5) Security + anti-spoofing
- Enforce HTTPS.
- Persist location accuracy and reject very poor readings.
- Detect mock locations (best effort, app-layer).
- Rate limit location events.
- Maintain audit trail (`location_events`) for disputes.
- Keep manual override with admin audit note.

### 6) Rollout plan

#### Phase 1
- Admin settings + per-user toggles.
- User sends location pings.
- Audit logs only (no auto attendance action).

#### Phase 2
- Enable auto clock-in and immediate break tracking from geofence transitions.
- No grace delay for break start/end.

#### Phase 3
- Auto half-day classification rules.
- Analytics dashboards for location attendance quality.

## Suggested business rules (default)
- Geofence radius: `150m`
- Break start: immediate on first valid outside geofence event
- Break end: immediate on first valid inside geofence event
- No forced auto logout / no forced punch-out
- Half-day threshold: `< 4h = Half Day`, `< 2h = Absent` (configurable)

## Manual controls to keep
- User manual clock-in/clock-out/break buttons remain enabled.
- Admin can lock manual control per user if required.
- If location service unavailable, fallback to manual attendance with reason.

## Important note
Because your current app is web + PHP, full background tracking quality will depend on mobile platform limits. If strict, always-on, reliable tracking is mandatory, a dedicated mobile app (or hybrid app with native plugins) is strongly recommended.

## Updated per latest requirement
- No force logout at 22:30 PM IST.
- No forced auto punch-out by scheduler.
- No grace delay for breaks: break start/end should be tracked immediately by geofence transitions.

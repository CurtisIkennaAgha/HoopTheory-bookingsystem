# BlockTimes Migration & Feature Plan

## Overview
This document outlines the migration plan and feature checklist for adding a `blockTimes` array to the HoopTheory booking system, parallel to the existing `blockDates` array. The goal is to allow each block session to store and use custom times for each session, with full consistency across frontend, backend, and data files.

---

## Migration Plan

### 1. Data Model & Storage
- Update all slot/session objects in `availableSlots.json` to include `blockTimes: []`.
- Update any test data or reset scripts to include `blockTimes`.

### 2. Admin UI (admin.html)
- When creating a block session, collect time values from each preview row into a `blockTimes` array.
- Save `blockTimes` alongside `blockDates` in the slot/session object.
- When displaying/editing block sessions, show `blockTimes` in the UI (if needed).

### 3. User UI (user.html)
- Display `blockTimes` alongside `blockDates` for block sessions.
- Use `blockTimes` for any time-related display or logic for block sessions.

### 4. Backend PHP Endpoints
- Update all PHP files that read/write `blockDates` to also handle `blockTimes` (e.g., `saveSlots.php`, `getSlots.php`, `editSession.php`).
- Update any logic that processes, validates, or displays `blockDates` to also process/display `blockTimes`.
- Ensure `blockTimes` is included in all relevant JSON responses and requests.

### 5. Booking/Waitlist Logic
- If `blockDates` is used for booking, cancellation, or waitlist logic, update those flows to use `blockTimes` as well (if needed).
- Ensure `blockTimes` is passed and stored wherever `blockDates` is used.

### 6. Email/Notification Logic
- Update any email templates or notification logic that includes `blockDates` to also include `blockTimes`.

### 7. Validation & Consistency
- Add validation to ensure `blockDates` and `blockTimes` arrays are always the same length and correspond to each other.
- Add fallback logic if `blockTimes` is missing (for backward compatibility).

### 8. Testing
- Test block session creation with custom times in the admin panel.
- Test display of `blockTimes` in admin and user UIs.
- Test booking, cancellation, and waitlist flows for block sessions.
- Test email/notification output for block sessions.
- Test with legacy data (without `blockTimes`) to ensure no crashes.

---

## Feature Plan
- Add `blockTimes` array to all block session data structures.
- Collect and save custom times from preview rows in admin panel.
- Display `blockTimes` wherever block session times are shown.
- Ensure all backend and frontend logic is updated for `blockTimes`.
- Validate and test for consistency and backward compatibility.

---

## Notes
- This migration is non-trivial and should be done step-by-step, with careful validation and testing.
- All places that use `blockDates` must be updated for `blockTimes` to avoid bugs.
- Consider adding automated tests or validation scripts to check for consistency.

---

Prepared by GitHub Copilot (GPT-4.1)
Date: 2026-02-15

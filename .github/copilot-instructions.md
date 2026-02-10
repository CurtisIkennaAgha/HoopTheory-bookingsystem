# AI Copilot Instructions - HoopTheory Booking System

## System Overview
HoopTheory is a vanilla JS + PHP booking system for basketball sessions with capacity management, waitlists, and payment integration. Two main interfaces: **user.html** (booking) and **admin.html** (slot management).

**Key principle:** All data is persisted to JSON files in `/data/`, not a database. PHP endpoints provide file I/O wrappers.

---

## Architecture & Data Flow

### Core Components
- **Frontend**: Single-page HTML files with embedded `<script>` (no bundler, no Node.js)
- **Backend**: PHP files in `/php/` that read/write `/data/*.json`
- **Data**: JSON files (availableSlots.json, bookings.json, bookingMappings.json, waitlist.json, playerProfiles.json, offers.json, players.json)

### Key Data Structures
```javascript
// availableSlots.json: Sessions offered
{ "2026-02-15": [{ 
  time, title, capacity, sessionType, bookedUsers: [{name, email}],
  blockId, blockDates, price, location 
}]}

// bookings.json: Legacy format (string-based), also new booking objects via saveBookings.php
{ "2026-02-15": ["HH:MM - Title (Name) (Email)"] }

// bookingMappings.json: Maps emails→booking IDs for email cancellation
{ "email@test.com": { "2026-02-15_08:00_title": "booking-uuid" } }

// players.json: Player roster
{ "email@test.com": { name, email, phones, address } }

// playerProfiles.json: Extended profile (age, experience, medical, emergency contact, consents)
{ "email@test.com": { name, email, age, experience, medical, emergency, waivers } }
```

### Booking Workflow (User)
1. User selects date → `handleDateClick()` loads slots for that date
2. User selects slot → `selectedSlot` is stored in global state
3. Form submission → `checkPlayerRegistered()` checks if profile exists
4. If new: show registration form (age, medical, emergency contact, consents)
5. Save profile → `savePlayerProfile.php` → `/data/playerProfiles.json`
6. Save booking → `saveBooking()` → POST to `saveBookings.php` (updates both availableSlots.json and bookings.json)
7. Show payment modal with 24h deadline
8. On close: `sendReservationEmail()` → `sendEmail.php` using PHPMailer

### Block Sessions (4-week courses)
- Slot has `blockId` (unique UUID) and `blockDates` array (4 dates)
- First date shows green, subsequent dates show blue
- Users confirm checkbox before booking entire block
- `saveBlockBooking()` sends all 4 dates to backend

---

## Project-Specific Patterns

### State Management (user.html)
- **Global state**: `selectedDate`, `selectedSlot`, `currentDisplayDate`, `currentBookingData`, `currentBookingId`, `pendingBooking`
- **No frameworks**: Use vanilla DOM manipulation with `document.getElementById()`, classList operations
- **Debouncing**: `handleNameEmailChange()` uses 600ms debounce for live profile validation

### Form Registration (Blocking Pattern)
- Registration form only appears if player NOT found in database
- Form is dynamically shown/hidden based on `checkPlayerRegistered()` response
- **Key fields**: name, email, age, experience, medical, emergency contact details, media consent, waiver acknowledgment
- Confirmation banners alert user if email/name conflicts with existing player

### Payment Handling
- Reserved booking (NOT yet confirmed) lives in `paymentModal`
- User has 24h to transfer payment with **reference code** (format: `FULLNAME-SESSIONNAME`)
- On close button: email confirmation sent, then page reloads
- Booking ID is captured from backend response and passed to email for cancellation link generation

### API Communication
- All endpoints: `POST /php/X.php` or `GET /php/X.php` with optional query strings
- No request auth/validation (local environment assumption)
- Cache headers explicitly disabled in PHP to force fresh data reads
- File locking used (`LOCK_EX`) in write operations

### Admin Panel (admin.html)
- Auto-refreshes every 5 seconds using `setInterval()`
- Preserves expanded booking state across refreshes using `previousBookingIds` Set tracking
- Slot creation: date picker → form fields → `saveSlots.php`
- Conflict resolution: if email/name mismatch, show inline warning banner

---

## Developer Workflows

### Testing Changes
1. **Local setup**: No build required. Open `user.html` or `admin.html` in browser.
2. **Data reset**: Delete/restore JSON files in `/data/` for clean test state.
3. **Test data**: See `/data/TEST_DATA_GUIDE.md` for expected structures and examples.
4. **Email testing**: Use `php/testEmail.php` with environment variables:
   ```powershell
   $env:MAIL_USERNAME='test@gmail.com'
   $env:MAIL_PASSWORD='app_password'
   php php/testEmail.php
   ```

### Adding New Features
- **New endpoints**: Add PHP file to `/php/`, follow pattern in `getSlots.php` (cache headers + JSON response)
- **New fields**: Update data structure in applicable JSON file AND all PHP read/write endpoints that touch it
- **UI changes**: Modify HTML directly (no JSX), use CSS classes for styling
- **Validation**: Client-side in JS, server-side in PHP (data integrity)

### Common Pitfalls
- **Stale data**: PHP must `clearstatcache(true, $file)` before reading; set no-cache headers
- **File permissions**: `/data/` must be writable by PHP process
- **JSON structure mismatch**: availableSlots and bookings have different formats; reconcile in logic
- **Booking vs. Waitlist**: Two separate JSON files; check both when validating duplicates

---

## Critical Files & Their Roles

| File | Purpose |
|------|---------|
| [user.html](user.html#L418) | Booking UI, slot selection, registration form, payment modal |
| [admin.html](admin.html) | Slot creation, booking management, auto-refresh loop |
| [php/saveBookings.php](php/saveBookings.php) | Creates booking, updates both JSON files, returns bookingId |
| [php/saveSlots.php](php/saveSlots.php) | Saves availableSlots.json |
| [php/savePlayerProfile.php](php/savePlayerProfile.php) | Saves playerProfiles.json, validates required fields |
| [php/sendEmail.php](php/sendEmail.php) | PHPMailer wrapper, supports multiple email types (reservation, confirmation, waitlist) |
| [php/getWaitlist.php](php/getWaitlist.php) | Returns waitlist.json |
| [php/isPlayerRegistered.php](php/isPlayerRegistered.php) | Checks players.json and playerProfiles.json for existing profiles |
| [styles.css](styles.css) | Responsive grid layout, dark theme, modal styles |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Detailed system design, component hierarchy, data flow diagrams |

---

## Integration Points & Dependencies

### PHPMailer (Email)
- Located at `/php/PHPMailer/`
- Requires environment variables: `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
- Supports multiple email types via `type` field in payload: `temporary_reservation`, `waitlist_confirmation`, `booking_confirmation`
- No reply-to validation; assumes Gmail App Passwords if 2FA enabled

### Browser APIs
- `fetch()` for all HTTP requests
- `localStorage` (not currently used, but available for future persistence)
- `navigator.clipboard.writeText()` for copy-to-clipboard functionality
- `Date` object for calendar/validation logic (set to 2026 in sample data)

### External Resources
- Google Fonts (Inter font family)
- No CDN dependencies; all JS is inline
- Logo: `NewLogo.png` (must exist in root)

---

## Testing Hints

1. **Calendar rendering**: Months are Monday-first; check `buildCalendarSkeleton()` for grid logic
2. **Slot availability**: A slot is "available" if `bookedUsers.length < capacity` (check both availableSlots.json and bookings.json)
3. **Waitlist logic**: Player can join waitlist if slot is fully booked; check `waitlist.json` before allowing join
4. **Registration validation**: All fields required; accept only lowercase emails with `@` and `.`
5. **Block sessions**: Icon/color indicates block vs. single; 4 dates must all exist in availableSlots for block to render

---

## Questions or Ambiguities?
Refer to [ARCHITECTURE.md](ARCHITECTURE.md) for detailed flow diagrams and component hierarchy. Check [QUICKSTART.md](QUICKSTART.md) for user-facing workflows.

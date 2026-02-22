
# AI Copilot Instructions - HoopTheory Booking System (2026)

## System Overview
HoopTheory is a vanilla JS + PHP booking system for basketball sessions. It manages session slots, bookings, waitlists, and payments, using JSON files for all persistent data. There are two main interfaces:
- **user.html**: Player booking, registration, payment
- **admin.html**: Session slot management, booking review, admin actions

**Key Principle:** All persistent data is stored in `/data/*.json` files. PHP endpoints provide all file I/O and business logic. No database is used.

---

## Architecture & Data Flow

### Core Components
- **Frontend**: Single-page HTML files with inline JS (no frameworks, no bundler)
- **Backend**: PHP files in `/php/` for all data operations
- **Data**: JSON files (availableSlots.json, bookings.json, bookingMappings.json, waitlist.json, playerProfiles.json, offers.json, players.json)

### Key Data Structures
```javascript
// availableSlots.json: Sessions offered
{ "YYYY-MM-DD": [{
  time, title, capacity, sessionType, bookedUsers: [{name, email}],
  blockId, blockDates, price, location
}]}

// bookings.json: Legacy format (string-based), also new booking objects via saveBookings.php
{ "YYYY-MM-DD": ["HH:MM - Title (Name) (Email)"] }

// bookingMappings.json: Maps emails→booking IDs for email cancellation
{ "email@test.com": { "YYYY-MM-DD_HH:MM_title": "booking-uuid" } }

// players.json: Player roster
{ "email@test.com": { name, email, phones, address } }

// playerProfiles.json: Extended profile (age, experience, medical, emergency contact, consents)
{ "email@test.com": { name, email, age, experience, medical, emergency, waivers } }
```

### Booking Workflow (User)
1. User selects date → JS loads slots for that date
2. User selects slot → `selectedSlot` is stored in global state
3. Form submission → `checkPlayerRegistered()` checks if profile exists
4. If new: show registration form (age, medical, emergency contact, consents)
5. Save profile → `savePlayerProfile.php` → `/data/playerProfiles.json`
6. Save booking → `saveBookings.php` (updates availableSlots.json and bookings.json)
7. Show payment modal with 24h deadline
8. On modal close: `sendReservationEmail()` → `sendEmail.php` (PHPMailer)

### Block Sessions (4-week courses)
- Slot has `blockId` (UUID) and `blockDates` array (4 dates)
- First date shows green, others blue
- User must confirm checkbox to book block
- `saveBlockBooking()` sends all 4 dates to backend

---

## Project-Specific Patterns

### State Management (user.html)
- **Global state**: `selectedDate`, `selectedSlot`, `currentDisplayDate`, `currentBookingData`, `currentBookingId`, `pendingBooking`
- **No frameworks**: Use vanilla DOM manipulation
- **Debouncing**: `handleNameEmailChange()` uses 600ms debounce for live profile validation

### Registration Form Logic
-  egistration form only appears if player NOT found in database
- Form is shown/hidden based on `checkPlayerRegistered()` response
- **Fields**: name, email, age, experience, medical, emergency contact, media consent, waiver
- Banner alerts for email/name conflicts

### Payment Handling
- Reserved booking (not confirmed) shown in `paymentModal`
- User has 24h to pay with reference code (`FULLNAME-SESSIONNAME`)
- On modal close: email sent, page reloads
- Booking ID from backend is used for cancellation link

### API Communication
- All endpoints: `POST /php/X.php` or `GET /php/X.php`
- No request auth/validation (local only)
- PHP disables cache headers, uses file locking (`LOCK_EX`)

### Admin Panel (admin.html)
- Auto-refresh every 5 seconds (`setInterval()`)
- Expanded booking state preserved with `previousBookingIds` Set
- Slot creation: date picker → form → `saveSlots.php`
- Inline warning banner for email/name mismatch

---

## Developer Workflows

### Testing Changes
1. **Local setup**: No build required. Open `user.html` or `admin.html` in browser.
2. **Data reset**: Delete/restore JSON files in `/data/` for clean test state.
3. **Test data**: See `/data/TEST_DATA_GUIDE.md` for expected structures
4. **Email testing**: Use `php/testEmail.php` with environment variables:
   ```powershell
   $env:MAIL_USERNAME='test@gmail.com'
   $env:MAIL_PASSWORD='app_password'
   php php/testEmail.php
   ```

### Adding New Features
- Add new PHP endpoint to `/php/` (see `getSlots.php` for pattern)
- Update JSON structure in all relevant files and endpoints
- UI changes: edit HTML directly, use CSS classes
- Validation: client-side JS and server-side PHP

### Common Pitfalls
- PHP must `clearstatcache(true, $file)` before reading; set no-cache headers
- `/data/` must be writable by PHP
- availableSlots and bookings formats differ; reconcile in logic
- Booking vs. waitlist: check both JSON files for duplicates

---

## Critical Files & Their Roles

| File | Purpose |
|------|---------|
| [user.html](user.html#L418) | Booking UI, slot selection, registration form, payment modal |
| [admin.html](admin.html) | Slot creation, booking management, auto-refresh loop |
| [php/saveBookings.php](php/saveBookings.php) | Creates booking, updates both JSON files, returns bookingId |
| [php/saveSlots.php](php/saveSlots.php) | Saves availableSlots.json |
| [php/savePlayerProfile.php](php/savePlayerProfile.php) | Saves playerProfiles.json, validates required fields |
| [php/sendEmail.php](php/sendEmail.php) | PHPMailer wrapper, supports reservation, confirmation, waitlist emails |
| [php/getWaitlist.php](php/getWaitlist.php) | Returns waitlist.json |
| [php/isPlayerRegistered.php](php/isPlayerRegistered.php) | Checks players.json and playerProfiles.json |
| [styles.css](styles.css) | Responsive grid layout, dark theme, modal styles |
| [ARCHITECTURE.md](ARCHITECTURE.md) | System design, flow diagrams |

---

## Integration Points & Dependencies

### PHPMailer (Email)
- Located at `/php/PHPMailer/`
- Requires env vars: `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
- Supports multiple email types via `type`: `temporary_reservation`, `waitlist_confirmation`, `booking_confirmation`
- Gmail App Passwords required if 2FA enabled

### Browser APIs
- `fetch()` for HTTP requests
- `localStorage` (not used, but available)
- `navigator.clipboard.writeText()` for copy-to-clipboard
- `Date` object for calendar logic (sample data uses 2026)

### External Resources
- Google Fonts (Inter)
- No CDN dependencies; all JS inline
- Logo: `NewLogo.png` in root

---

## Testing Hints

1. **Calendar rendering**: Monday-first; see `buildCalendarSkeleton()`
2. **Slot availability**: `bookedUsers.length < capacity` (check availableSlots.json and bookings.json)
3. **Waitlist logic**: Player can join waitlist if slot is full; check waitlist.json
4. **Registration validation**: All fields required; only lowercase emails with `@` and `.`
5. **Block sessions**: Icon/color indicates block vs. single; all 4 dates must exist in availableSlots

---

## Questions or Ambiguities?
See [ARCHITECTURE.md](ARCHITECTURE.md) for flow diagrams and component hierarchy. See [QUICKSTART.md](QUICKSTART.md) for user workflows.

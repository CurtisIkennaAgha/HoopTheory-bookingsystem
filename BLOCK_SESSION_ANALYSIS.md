# Block Session Booking Logic Analysis

## 1. CURRENT WORKFLOW

### A. Block Session Creation (Admin Side)
1. Admin creates a block session in admin.html with:
   - Unique `blockId` (UUID)
   - Array of 4 dates in `blockDates` field
   - Same title, time, price, location for all dates
2. Each date gets a separate slot entry in `availableSlots.json` with the same `blockId`
3. First date in `blockDates` array shows GREEN in calendar
4. Subsequent dates show BLUE

### B. Block Session Display (User Side)
**File: user.html, lines 750-900**

1. User clicks on a date → `handleDateClick()` loads slots for that date
2. System identifies block sessions by checking `slot.blockId && slot.blockDates`
3. **Deduplication logic**: Uses `processedBlockIds` Set to ensure block only renders once per day
4. **Color coding**:
   - First date (`blockDates[0] === dateStr`): GREEN card
   - Subsequent dates: BLUE card
   - Fully booked: RED card (overrides other colors)
5. **Availability check**:
   - Counts bookings from `bookings.json` matching time + title
   - Compares against `slot.numberOfSpots`
   - Shows "X / Y spots left" pill

### C. Block Session Selection & Confirmation
**File: user.html, lines 800-900, 1000-1050**

1. User clicks block card → sets `selectedSlot = slot`
2. If NOT fully booked:
   - Calls `showBlockConfirmation(slot, false)` 
   - Shows panel with all 4 dates listed
   - Displays checkbox: "I understand this is a 4-date block"
3. If fully booked:
   - Shows waitlist form instead
   - Can show block confirmation in "waitlist mode" (red theme)

### D. Block Booking Submission
**File: user.html, lines 2100-2250**

**Validation Phase:**
1. Checks if `selectedSlot` exists
2. Checks name/email fields populated
3. **Block-specific validation**: If `selectedSlot.blockId` exists, verifies checkbox is checked
4. If checkbox NOT checked → Error: "Please confirm you understand this is a 4-date block session"

**Registration Check:**
5. Calls `checkPlayerRegistered(name, email)`
6. If NOT registered → shows registration form with full profile fields
7. User must fill: age, experience, medical conditions, emergency contact, consents
8. Calls `submitRegistrationAndContinue()` → saves to `playerProfiles.json`

**Booking Creation:**
9. Calls `saveBlockBooking(slot, name, email)` which sends:
```javascript
{
  name: "John Doe",
  email: "john@example.com",
  blockId: "uuid-here",
  blockDates: ["2026-02-15", "2026-02-22", "2026-03-01", "2026-03-08"],
  slot: {
    title: "Advanced Training",
    time: "18:00",
    price: "100.00",
    location: "Main Court"
  }
}
```

### E. Backend Processing
**File: php/saveBookings.php, lines 100-200**

1. Receives payload, checks if `blockDates` array exists
2. **For each date in blockDates:**
   - Creates booking string: `"18:00 - Advanced Training (John Doe) (john@example.com)"`
   - Adds to `bookings.json[date]` array
   - Finds matching slot in `availableSlots.json[date]`
   - Appends `{name, email}` to slot's `bookedUsers` array
3. Generates unique `bookingId` (e.g., "BK-1706965234-A3F2B8C1")
4. Creates entry in `bookingMappings.json`:
```json
{
  "BK-1706965234-A3F2B8C1": {
    "name": "John Doe",
    "email": "john@example.com",
    "date": null,
    "slot": "18:00",
    "title": "Advanced Training",
    "isBlock": true,
    "blockDates": ["2026-02-15", "2026-02-22", "2026-03-01", "2026-03-08"],
    "price": "100.00",
    "location": "Main Court",
    "status": "Pending",
    "confirmedAt": null,
    "createdAt": "2026-02-04 15:30:00",
    "timestamp": 1706965800
  }
}
```
5. Calls `sync_slots_from_bookings()` to reconcile data
6. Returns `{status: 'ok', bookingId: 'BK-...'}`

### F. Payment Modal & Email
**File: user.html, lines 2230-2300**

1. `showPaymentInstructions(selectedSlot, name, email, selectedDate, bookingId)` shows modal
2. User sees:
   - Bank details
   - Payment reference (auto-generated)
   - 24-hour deadline
   - Cancellation link with `bookingId` parameter
3. User closes modal → triggers `sendReservationEmail()`
4. Email sent via `php/sendEmail.php` with:
   - All booking details
   - Cancellation link: `https://hooptheory.co.uk/cancel-session.html?bookingId=BK-...`
   - Block dates displayed if applicable
5. Page reloads → booking shows as "Pending"

### G. Admin Confirmation
**File: admin.html, lines 3920-4200 (table view)**

1. Admin sees all bookings in table/card view
2. Block bookings grouped together (same name + email + title + blockId)
3. Shows all 4 dates combined in one row
4. Admin clicks "Confirm" → calls `markBookingConfirmed(email, date, time, title, blockDatesArray)`
5. Updates `bookingMappings.json[bookingId].status = "Confirmed"`
6. Updates `bookingMappings.json[bookingId].confirmedAt = timestamp`
7. Sends confirmation email

### H. Cancellation
**File: php/cancelBooking.php, lines 1-200**

1. User clicks cancellation link with `bookingId`
2. System loads `bookingMappings.json[bookingId]`
3. Extracts: `isBlock`, `blockDates`, `email`, `time`, `title`
4. If `isBlock === true`:
   - Loops through ALL dates in `blockDates`
   - Removes booking string from `bookings.json[date]` for each date
   - Removes `{name, email}` from `availableSlots.json[date][].bookedUsers` for each slot
5. Deletes `bookingMappings.json[bookingId]` entry
6. Calls `sync_slots_from_bookings()` to reconcile

---

## 2. LOGICAL ERRORS & POTENTIAL ISSUES

### 🔴 CRITICAL ISSUES

#### Issue 1: Duplicate Bookings - Same User Can Book Multiple Times
**Location:** user.html, lines 2100-2250 (no duplicate check)

**Problem:**
- No validation prevents the same email from booking the same block session multiple times
- User can refresh page and book again with same details
- Creates multiple `bookingId` entries for same person

**Example Scenario:**
1. User books "Advanced Training" block → creates booking with all 4 dates
2. User refreshes page
3. User books same block again → system allows it
4. Now there are 2 bookings (8 total date entries) for same person

**Impact:**
- Overbooking capacity
- Confusing admin view (duplicate entries)
- User charged twice if both confirmed

**Fix Required:**
```javascript
// Before calling saveBlockBooking(), check if user already booked this block
const existingBooking = await checkExistingBlockBooking(email, slot.blockId);
if (existingBooking) {
  showBookingMessage('You have already booked this block session', 'error');
  return;
}
```

---

#### Issue 2: Partial Block Booking on Cancellation
**Location:** php/cancelBooking.php, lines 80-110

**Problem:**
- If cancellation fails mid-loop (e.g., file write error on 3rd date), only some dates are cancelled
- No transaction rollback mechanism
- Results in inconsistent state: user removed from dates 1-2 but still booked on dates 3-4

**Example Scenario:**
1. User cancels block booking
2. Loop successfully removes booking from Feb 15, Feb 22
3. File write fails on Mar 1 due to permissions issue
4. User is partially cancelled → shows as "available" on calendar for first 2 dates but still booked on last 2

**Impact:**
- Data corruption
- Ghost bookings that can't be managed
- Capacity miscalculation

**Fix Required:**
- Implement atomic operation (all-or-nothing)
- Use file locking during entire operation
- Validate all dates exist before starting deletion
- Add rollback logic if any date fails

---

#### Issue 3: Block Session Capacity Sync Issue
**Location:** saveBookings.php, lines 150-180

**Problem:**
- Each date in block gets separate `bookedUsers` entry
- If one date's slot has different `numberOfSpots` value, inconsistency occurs
- Sync function may not reconcile properly if slots have different capacities

**Example Scenario:**
1. Admin creates block with 10 spots for Feb 15
2. Admin accidentally sets 8 spots for Feb 22 (same block)
3. System books 10 users
4. Feb 22 shows as overbooked (10/8), but Feb 15 shows full (10/10)

**Impact:**
- Misleading availability display
- Can't determine true block capacity

**Fix Required:**
- Validation on slot creation: all dates in block must have identical `numberOfSpots`
- Single source of truth for block capacity
- Display warning if discrepancy detected

---

#### Issue 4: Missing Block Date Validation
**Location:** user.html, line 678 + saveBookings.php

**Problem:**
- No validation that all 4 `blockDates` exist in `availableSlots.json` before booking
- User can book a block even if admin deleted one of the dates
- Creates orphaned bookings

**Example Scenario:**
1. Admin creates block for Feb 15, 22, Mar 1, 8
2. Admin later deletes Mar 8 slot from calendar
3. User books block → system creates bookings for all 4 dates
4. Mar 8 booking exists in `bookings.json` but has no matching slot

**Impact:**
- Bookings can't be displayed properly
- Admin can't confirm/manage orphaned bookings
- Capacity calculation broken

**Fix Required:**
```javascript
// In saveBlockBooking, before creating bookings:
const slotsData = await getAvailableSlots();
for (const date of slot.blockDates) {
  const slotExists = slotsData[date]?.find(s => 
    s.time === slot.time && s.title === slot.title && s.blockId === slot.blockId
  );
  if (!slotExists) {
    throw new Error(`Block session incomplete: ${date} slot missing`);
  }
}
```

---

### ⚠️ MEDIUM PRIORITY ISSUES

#### Issue 5: Race Condition - Simultaneous Bookings
**Problem:**
- Two users can click "Book" at same millisecond for last spot
- Both read availability as "1 spot left"
- Both successfully create bookings
- Result: 11 bookings for 10-person capacity

**Location:** saveBookings.php (no locking mechanism)

**Fix:** Implement file locking with capacity re-check before writing:
```php
$fp = fopen($bookingsFile, 'c+');
if (flock($fp, LOCK_EX)) {
  // Re-read bookings, re-count, validate capacity
  // Only write if still available
  flock($fp, LOCK_UN);
}
fclose($fp);
```

---

#### Issue 6: Email Failure Leaves Booking in Limbo
**Problem:**
- Booking is created and saved
- Email sending fails (SMTP down, wrong credentials)
- User never receives cancellation link with `bookingId`
- User can't cancel booking

**Location:** user.html line 2300 (sendReservationEmail returns false but no retry)

**Fix:**
- Store `bookingId` in localStorage as fallback
- Show cancellation link on confirmation page
- Add "Resend Email" button
- Log failed email attempts with bookingId for manual resolution

---

#### Issue 7: Block Confirmation Shows Wrong Date
**Problem:**
- `showPaymentInstructions()` receives `selectedDate` parameter
- For block sessions, this is the date user clicked (first date)
- Email displays only first date, not all 4 dates
- Confusing for user

**Location:** user.html line 2160

**Fix:** Pass block dates to payment modal:
```javascript
showPaymentInstructions(selectedSlot, name, email, selectedSlot.blockDates || [selectedDate], bookingId);
```

---

### 🟡 MINOR ISSUES

#### Issue 8: No Partial Cancellation Support
**Problem:**
- User books 4-week block
- User wants to cancel only Week 3 due to vacation
- System only supports all-or-nothing cancellation
- User must cancel entire block and rebook 3 weeks individually

**Impact:** Poor user experience, lost revenue

**Fix:** Add partial cancellation UI with date selection checkboxes

---

#### Issue 9: Block Booking Shows as Single Entry in Some Views
**Location:** admin.html card view (lines 1400-1600)

**Problem:**
- Card view groups blocks by name/email/blockId
- Shows one card with multiple dates
- But underlying data has 4 separate booking entries
- Confirming the "grouped" card only confirms one entry
- Other 3 dates remain unconfirmed

**Fix:** Ensure confirmation action loops through all dates in block

---

#### Issue 10: No Block Waitlist Logic
**Problem:**
- Block sessions can be fully booked
- User can join waitlist for block
- But waitlist offer system only handles single dates
- Admin can't offer block waitlist position

**Location:** php/reserveOffer.php, php/confirmOffer.php (no block support)

**Fix:** Extend waitlist offer system to handle `isBlock` + `blockDates` fields

---

## 3. DATA CONSISTENCY RISKS

### Risk 1: bookings.json vs availableSlots.json Desync
- `bookings.json` is "string format": `"18:00 - Title (Name) (Email)"`
- `availableSlots.json[date][].bookedUsers` is object array: `[{name, email}]`
- If one updates without the other, counts don't match
- `sync_slots_from_bookings()` attempts to fix but can fail if data malformed

**Mitigation:** Use sync function after EVERY write operation

---

### Risk 2: bookingMappings.json Orphaned Entries
- If booking is deleted from `bookings.json` manually
- But `bookingMappings.json[bookingId]` entry remains
- Cancellation link still works but tries to cancel non-existent booking
- Admin sees "ghost" bookings

**Mitigation:** Add cleanup job to remove orphaned mapping entries

---

## 4. RECOMMENDED FIXES PRIORITY

### High Priority (Implement Immediately)
1. **Add duplicate booking prevention** (Issue 1)
2. **Add atomic cancellation with rollback** (Issue 2)
3. **Validate all block dates exist before booking** (Issue 4)
4. **Fix race condition with file locking** (Issue 5)

### Medium Priority (Next Sprint)
5. Implement email retry mechanism (Issue 6)
6. Fix block confirmation to show all dates (Issue 7)
7. Validate uniform capacity across block slots (Issue 3)

### Low Priority (Future Enhancement)
8. Add partial cancellation feature (Issue 8)
9. Fix admin block confirmation to update all dates (Issue 9)
10. Extend waitlist to support blocks (Issue 10)

---

## 5. TESTING SCENARIOS TO VALIDATE

1. **Double Booking Test:**
   - Open 2 browser windows, same user
   - Book same block simultaneously
   - Expected: Second booking should fail

2. **Mid-Cancellation Failure Test:**
   - Temporarily make `availableSlots.json` read-only
   - Try to cancel block booking
   - Expected: No dates should be cancelled, error shown

3. **Missing Date Test:**
   - Create block for 4 dates
   - Delete one date's slot manually
   - Try to book block
   - Expected: Booking should fail with "incomplete block" error

4. **Capacity Overflow Test:**
   - Set capacity to 1 spot
   - Have 2 users book at exact same time
   - Expected: Only 1 booking succeeds

5. **Email Failure Test:**
   - Disable SMTP temporarily
   - Complete booking
   - Expected: Booking created but user gets fallback cancellation link on-screen

---

## 6. ARCHITECTURE RECOMMENDATIONS

### Short-Term
- Add pre-flight validation function that runs before `saveBlockBooking()`
- Implement proper error handling with rollback
- Add duplicate detection query

### Long-Term
- Consider using database instead of JSON files for ACID compliance
- Add booking state machine (Draft → Pending → Confirmed → Completed/Cancelled)
- Implement event sourcing to track all booking changes
- Add idempotency keys to prevent duplicate submissions

---

**Document Version:** 1.0  
**Date:** 2026-02-04  
**Author:** AI Analysis based on codebase review

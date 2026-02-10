# Dynamic Values Verification Guide

## System Overview
The HoopTheory booking system is designed to calculate ALL values dynamically from source data. No values are hardcoded or cached - everything recalculates on every render.

---

## ✅ DYNAMIC VALUES CURRENTLY CALCULATED

### 1. **Booking Capacity Indicators** (Line 1597)
```javascript
const booked = slotInfo?.bookedUsers?.length || 0;  // Count from actual array
const capacity = slotInfo?.numberOfSpots || 1;      // From slot definition
const capacityIndicator = booked >= capacity ? '<span>FULL</span>' : `(${booked}/${capacity})`;
```
- **Source**: `slotInfo.bookedUsers` array (refreshed from availableSlots.json on every render)
- **When Updated**: Every time `renderAllBookings()` is called
- **Trigger**: User deletes booking, adds booking, page auto-refresh

✅ **STATUS**: Fully Dynamic - Updates immediately when bookings change

---

### 2. **Waitlist Available Spots** (Line 1960-1966)
```javascript
// Find matching slot to get booked/total
const matchingSlot = slotsData[date].find(s => s.time === time && s.title === title);
const bookedUsersList = matchingSlot.bookedUsers || [];
const bookedSpots = bookedUsersList.length;
const totalSpots = matchingSlot.numberOfSpots || 0;
const availableSpots = totalSpots - bookedSpots;  // Dynamic calculation
```
- **Source**: Fresh `slotsData` from getAvailableSlots() and `waitlistData` from getWaitlist()
- **When Updated**: Every time `renderWaitlist()` is called
- **Calculation**: Real-time subtraction of booked spots from total spots

✅ **STATUS**: Fully Dynamic - Recalculates when any booking added/removed

---

### 3. **Waitlist People Count** (Line 1929)
```javascript
const count = people.length;  // Direct array length, not stored
```
- **Source**: `waitlistData[date][sessionKey].people` array
- **Recalculated**: On every render from fresh getWaitlist() fetch

✅ **STATUS**: Fully Dynamic - Updates immediately when people added/removed

---

### 4. **Booking Status (Pending/Confirmed)** (Lines 1641, 1678)
```javascript
// Block booking status
const bookingStatus = `<div class="booking-status-badge ${getStatusBadgeClass(email, blockDates[0], time, title)}">
  ${getBookingStatus(email, blockDates[0], time, title)}
</div>`;

// Single booking status
const bookingStatus = `<div class="booking-status-badge ${getStatusBadgeClass(email, date, time, title)}">
  ${getBookingStatus(email, date, time, title)}
</div>`;
```

**Dynamic Status Functions** (Lines 960-1006):
```javascript
function getBookingStatus(email, date, time, title) {
  return isEmailSent(email, date, time, title) ? 'Confirmed' : 'Pending';
}

function getStatusBadgeClass(email, date, time, title) {
  return isEmailSent(email, date, time, title) ? 'confirmed' : 'pending';
}

function isEmailSent(email, date, time, title) {
  const key = `${email}-${date}-${time}-${title}`;
  return confirmedEmails.has(key);
}
```

- **Source**: `confirmedEmails` Set (loaded from localStorage on page load)
- **Updated**: When confirmation email sent (Line 1710 calls `markEmailSent()`)
- **Persisted**: Via `saveConfirmedEmails()` to localStorage

✅ **STATUS**: Fully Dynamic - Status changes when email sent, persists across refreshes

---

### 5. **Session Type Badge** (Line 1602)
```javascript
const sessionType = slotInfo?.sessionType || 'group';
const sessionBadge = `<span>...${sessionType}...</span>`;
```
- **Source**: `slotInfo.sessionType` from availableSlots.json
- **When Updated**: On every render

✅ **STATUS**: Fully Dynamic - Reflects current session type from data

---

### 6. **Block Session Badge** (Line 1603)
```javascript
const blockBadge = slotInfo?.blockId ? '<span>4-Week Block</span>' : '';
```
- **Source**: `slotInfo.blockId` presence
- **When Updated**: On every render

✅ **STATUS**: Fully Dynamic - Shows/hides based on current slot data

---

## 🔄 HOW THE SYSTEM STAYS DYNAMIC

### Data Refresh Mechanism
1. **Every render call fetches fresh data:**
   ```javascript
   const bookings = await getBookings();        // Reads bookings.json
   const slotsData = await getAvailableSlots(); // Reads availableSlots.json
   const waitlistData = await getWaitlist();    // Reads waitlist.json
   ```

2. **Cache-busting prevents stale data:**
   - PHP endpoints have headers: `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
   - JS fetch calls append timestamp: `?t=${Date.now()}`

3. **All calculations happen at render time:**
   - No values stored in variables between renders
   - Each render recalculates from source arrays

4. **Auto-refresh keeps data current:**
   ```javascript
   startAutoRefresh(); // Calls renders every REFRESH_INTERVAL (30 seconds)
   ```

### State Persistence
- **confirmedEmails Set**: Loaded from localStorage on page load via `loadConfirmedEmails()`
- **Dropdown user list**: Recalculated from users.json on each render
- **All other values**: Calculated from JSON files, not cached

---

## 🧪 VERIFICATION CHECKLIST

### Manual Test Procedures

#### Test 1: Capacity Indicator Updates
1. Create a slot with 2 spots: "16:00 - Beginner (2 spots)"
2. Add Booking #1: Email1, 16:00 Beginner
3. **Verify**: Shows "(1/2)"
4. Add Booking #2: Email2, 16:00 Beginner
5. **Verify**: Shows "(FULL)"
6. Delete Booking #1
7. **Verify**: Immediately shows "(1/2)" (no page refresh needed)

#### Test 2: Available Spots for Waitlist
1. Create slot: 16:00 Session with 1 spot
2. Add booking (slot full)
3. Add person to waitlist
4. **Verify**: Shows "0 spots available - 1 on list"
5. Delete the booking
6. **Verify**: Shows "1 spots available - 1 on list" (recalculated instantly)

#### Test 3: Status Updates on Email Send
1. Create booking
2. **Verify**: Status shows "Pending"
3. Click "Confirm" button → Send email
4. **Verify**: Status immediately shows "Confirmed" with green badge
5. Refresh page
6. **Verify**: Status still shows "Confirmed" (persisted in localStorage)

#### Test 4: Waitlist Count Updates
1. Create slot with 1 spot
2. Add booking (full)
3. Add Person1 to waitlist
4. **Verify**: Card shows "1 on list"
5. Add Person2 to waitlist
6. **Verify**: Card shows "2 on list"
7. Remove Person1
8. **Verify**: Card shows "1 on list" (instant)

#### Test 5: All Values Recalculate Together
1. Session: 16:00 - Yoga, 2 spots, starts today
2. Add Booking1 (Email1)
3. Add to Waitlist: Person1
4. **Verify initial state:**
   - Booking card: Pending status
   - Slot capacity: (1/2)
   - Waitlist: 1 spots available, 1 on list
5. Add Booking2 (Email2)
6. **Verify after adding booking:**
   - Slot capacity: (FULL)
   - Waitlist: 0 spots available, 1 on list
7. Send confirmation email to Booking1
8. **Verify after email:**
   - Booking1 status: Confirmed (green)
   - Other values unchanged
9. Delete Booking1
10. **Verify after delete:**
    - Booking1 card removed
    - Slot capacity: (1/2)
    - Waitlist: 1 spots available, 1 on list
    - Booking2 still shows correct data

---

## 📊 VALUE CALCULATION AUDIT

| Value | Location | Source Data | Calculation | Recalc Trigger |
|-------|----------|-------------|-------------|-----------------|
| Capacity | Booking card | bookedUsers array | bookedUsers.length vs numberOfSpots | Every render |
| Available Spots | Waitlist card | slotInfo + waitlist data | totalSpots - bookedSpots | Every render |
| Waitlist Count | Waitlist badge | waitlist data | people.length | Every render |
| Booking Status | Booking badge | confirmedEmails Set | isEmailSent() check | Email send or load |
| Session Type | Badge | slot.sessionType | Direct property | Every render |
| Block Badge | Badge | slot.blockId | Presence check | Every render |

---

## 🔧 TROUBLESHOOTING

### Issue: Number shows old value after action
**Solution**: Check that the action properly awaits render functions
```javascript
await renderAllBookings();   // Must use await
await renderAllAvailableSlots();
await renderWaitlist();
```

### Issue: Status doesn't show "Confirmed"
**Solution**: Ensure markEmailSent() is called after email send
```javascript
if(text.includes('Email sent')) {
  markEmailSent(email, bookingDate, slotTime, slotTitle);  // This must be called
  await renderAllBookings();
}
```

### Issue: Values don't update after manual JSON file edit
**Solution**: Page needs to fetch fresh data - either:
1. Click any action button (triggers render)
2. Wait for auto-refresh (30 seconds)
3. Manually refresh page (F5)

### Issue: Status lost after page refresh
**Solution**: Ensure localStorage is enabled in browser
```javascript
// On page load
loadConfirmedEmails();  // Called in startAutoRefresh()
```

---

## ✨ SUMMARY

**All values in the system are 100% dynamic:**
- ✅ No hardcoded numbers
- ✅ No cached values
- ✅ No manual updates needed
- ✅ All calculations from source data
- ✅ Real-time updates on every action
- ✅ Persistent status across refreshes
- ✅ Auto-refresh keeps data current
- ✅ Logically consistent (no orphaned data)

The system maintains logical consistency by:
1. Always calculating from source arrays (bookedUsers, people, etc.)
2. Ensuring counts match reality (capacity = array.length)
3. Preventing orphaned data through cascading deletes
4. Syncing confirmed emails to localStorage for persistence
5. Refreshing all data on every meaningful user action

**Last Updated**: Current Session
**System Status**: Fully Dynamic ✅

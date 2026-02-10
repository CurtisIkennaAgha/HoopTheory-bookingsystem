# Bridge State Feature Implementation

## Overview
Bridge State is a new feature that prioritizes waitlisted players when a previously-full session has newly available spaces.

## What is Bridge State?

A session enters Bridge State when ALL these conditions are true:
1. Session was previously full (`bookedUsers.length >= capacity`)
2. Now has available space (`bookedUsers.length < capacity`)
3. Has waitlist entries (`waitlist.json` has entries for this slot)

While in Bridge State:
- Session appears **dark grey** to normal users
- **Normal booking is disabled** - users can only join the waitlist
- Shows message: "This session has available spaces, but we are currently prioritising players who were already signed up to the waitlist."
- Admin can still manage waitlist and move users into the session

## Backend Changes

### New File: `php/lib_bridge_state.php`
Library providing Bridge State calculations:
- `shouldBridgeState()` - Determines if a slot should be in bridge mode
- `updateBridgeState()` - Updates bridge state for a slot based on current bookings/waitlist
- `Set` class - Simple PHP set implementation for deduplication

### Modified Files

#### `php/cancelBooking.php`
- After a booking is cancelled, updates bridge state for that slot
- If space becomes available but waitlist exists → `bridgeState = true`

#### `php/saveBookings.php`
- After a new booking is saved, updates bridge state
- Handles both single and block sessions

#### `php/deleteFromWaitlist.php`
- After removing someone from waitlist, updates bridge state
- If waitlist becomes empty → `bridgeState = false`

#### `php/reserveOffer.php`
- When waitlist user accepts offer, still respects bridge state logic
- Updates bridge state after booking confirmation

## Frontend Changes

### `styles.css`
Added Bridge State styling:
```css
.slot-card.bridge-state {
  border-color: #999;
  border-left: 4px solid #555;
  background: linear-gradient(135deg, #f3f3f3 0%, #e8e8e8 100%);
  color: #666;
  opacity: 0.8;
  cursor: not-allowed;
}

.bridge-message {
  background-color: #fffacd;
  border-left: 4px solid #ffcc66;
  padding: 12px 14px;
  border-radius: 6px;
  font-size: 0.9rem;
  color: #665500;
}
```

### `user.html`
Updated slot rendering:
1. **Block sessions**: Check if `slot.bridgeState === true`
   - Apply grey styling
   - Append bridge message banner
   - Disable click-to-book, show waitlist form instead

2. **Single sessions**: Same logic as blocks
   - Check `slot.bridgeState` property
   - Show message and disable booking

## Data Structure

### availableSlots.json
Each slot now includes:
```json
{
  "time": "18:00",
  "title": "Evening Training",
  "numberOfSpots": 12,
  "bookedUsers": [...],
  "blockId": null,
  "blockDates": [],
  "price": 10,
  "location": "Sports Hall A",
  "bridgeState": false
}
```

## Flow Examples

### Example 1: Booking Cancellation
1. User cancels booking → `cancelBooking.php`
2. User removed from `bookedUsers`
3. `bridgeState` is recalculated:
   - If `booked < capacity` AND `waitlist has entries` → `bridgeState = true`
   - Session shows as grey, normal users can't book

### Example 2: Waitlist User Moves to Booking
1. Admin confirms waitlist user → `deleteFromWaitlist.php`
2. User moved to confirmed bookings
3. `bridgeState` recalculated:
   - If `waitlist is now empty` → `bridgeState = false`
   - Session returns to normal (green/blue depending on capacity)

### Example 3: New Booking Added
1. User books → `saveBookings.php`
2. Slot becomes full → capacity reached
3. `bridgeState` checked:
   - If `booked >= capacity` AND `waitlist exists` → `bridgeState` remains unchanged or set to false
   - System prevents normal bookings only if space becomes available AND waitlist exists

## Admin View

In `admin.html`, Bridge State mode is indicated visually but doesn't affect admin actions. Admins can:
- View who is on the waitlist
- Move waitlist users into the session
- See bridge mode indication (future enhancement: add badge)

## Key Design Decisions

1. **Fairness**: Once a slot was full, released spaces go to waitlist first
2. **Automatic**: No manual intervention needed - state updates automatically
3. **Block-aware**: Works correctly with 4-week block sessions (all dates treated as one unit)
4. **Non-destructive**: Integrates with existing booking/waitlist logic without removing features
5. **User-friendly**: Clear messaging explains why booking is unavailable

## Testing Checklist

- [ ] Booking cancellation triggers bridge state when waitlist exists
- [ ] Removed booking clears bridge state when no waitlist
- [ ] Block sessions aggregate across all dates for capacity calculation
- [ ] Bridge message appears in grey session
- [ ] Clicking grey session shows only waitlist form
- [ ] Waitlist form works normally in bridge state
- [ ] Admin can still manage waitlist in bridge state
- [ ] Bridge state clears when last waitlist user is removed or accepted
- [ ] Calendar shows correct colors after bridge state changes

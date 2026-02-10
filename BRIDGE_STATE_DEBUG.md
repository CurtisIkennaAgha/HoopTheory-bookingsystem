# Bridge State - Debugging Guide

## Console Debugging

### Browser Console (F12)
Look for these log messages:

```
🌉 Calendar check 2026-02-15: Bridge Test Session bridgeState=true
  → BRIDGE STATE - showing grey
  
🌉 Block slot Block Bridge Test: bridgeState=true full=false
  → BRIDGE STATE - showing grey
  
🌉 Single slot Test Session: bridgeState=true full=false
  → Adding bridge message
  → Clicked. inBridgeState=true
    → In bridge state, showing waitlist only
```

### Server Logs (PHP error_log)
Run:
```powershell
Get-Content -Path "C:\path\to\php\error.log" -Tail 50 -Wait
```

Look for:
```
🌉 updateBridgeState START: date=2026-02-15 time=14:00 title="Bridge Test Session"
🌉 updateBridgeState: Found matching slot
🌉 Checking 1 dates for capacity calculation
🌉 Checking bookings for date: 2026-02-15 (count: 1)
🌉   ✓ Matched booking for bob@test.com
🌉 Total booked count: 1
🌉 Checking waitlist for date: 2026-02-15 (count: 1)
🌉   ✓ Found waitlist entry for charlie@test.com
🌉 Total waitlist entries: 1
🌉 shouldBridgeState: booked=1 capacity=2 space=YES waitlist=YES result=BRIDGE=TRUE
🌉 updateBridgeState RESULT: 14:00 Bridge Test Session | booked=1 capacity=2 waitlist=1 | bridgeState: false → true
```

---

## Quick Verification Checklist

### 1. Check PHP is Working
Run:
```powershell
# Check if bridge state calculations are happening
Select-String -Path "C:\path\to\error.log" -Pattern "🌉" | Select-Object -Last 20
```

**Expected**: See multiple 🌉 logs showing state transitions

### 2. Check JSON Structure
```powershell
# Check availableSlots.json for bridgeState field
Get-Content data/availableSlots.json | ConvertFrom-Json | ConvertTo-Json -Depth 10 | Select-String "bridgeState"
```

**Expected**: Should see `"bridgeState": true` or `"bridgeState": false` on slots

### 3. Check Frontend Console
Open browser DevTools (F12) → Console tab

**Expected**: Lots of 🌉 messages showing slot states

---

## Test Flow with Debugging

### Step 1: Create Test Slot
1. Admin creates: "Debug Test" session, capacity 2, on 2026-02-15
2. Check console: Should see normal slot rendering (no 🌉 logs yet)

### Step 2: Book 2 Users
1. Book Alice and Bob to fill it
2. Check `availableSlots.json`:
   ```json
   "bridgeState": false  // Not yet triggered
   ```
3. Calendar should show RED (fully booked)

### Step 3: Add to Waitlist
1. Add Charlie to waitlist
2. Check `waitlist.json`:
   ```json
   "email": "charlie@test.com",
   "title": "Debug Test"
   ```

### Step 4: Cancel Booking (THIS TRIGGERS BRIDGE STATE)
1. Cancel Alice's booking
2. Check PHP error log - should see:
   ```
   🌉 updateBridgeState START: date=2026-02-15 time=14:00 title="Debug Test"
   🌉 updateBridgeState: Found matching slot
   🌉 Total booked count: 1
   🌉 Total waitlist entries: 1
   🌉 shouldBridgeState: booked=1 capacity=2 space=YES waitlist=YES result=BRIDGE=TRUE
   🌉 updateBridgeState RESULT: ... | bridgeState: false → true
   ```

3. Check `availableSlots.json`:
   ```json
   "bridgeState": true  // ✅ NOW TRUE!
   ```

### Step 5: Verify Calendar & Slots
1. Reload user.html
2. Calendar on 2026-02-15 should be **DARK GREY** (not red)
3. Console should show:
   ```
   🌉 Calendar check 2026-02-15: Debug Test bridgeState=true
     → BRIDGE STATE - showing grey
   ```
4. Click the grey date
5. Session card should be **DARK GREY** with yellow message
6. Console should show:
   ```
   🌉 Single slot Debug Test: bridgeState=true full=false
     → Adding bridge message
     → Clicked. inBridgeState=true
       → In bridge state, showing waitlist only
   ```

### Step 6: Verify Only Waitlist Shows
1. Only waitlist form should appear, NO booking button
2. Should be able to join waitlist with new user
3. Booking form completely hidden

---

## Common Issues & Solutions

| Issue | Debug | Fix |
|-------|-------|-----|
| Bridge state never appears | No 🌉 logs in PHP error.log | Check PHP errors, ensure updateBridgeState is being called |
| Calendar shows red not grey | Check availableSlots.json `bridgeState` field | May not be set. Check if updateBridgeState ran. |
| Slot shows available even when grey | Check if slot.bridgeState is in the JSON | May need to reload PHP. |
| No yellow message appears | Check CSS loaded, console has no errors | Hard refresh (Ctrl+Shift+R) |
| Waitlist form still shows booking button | Check if `inBridgeState` is true in console | May be caching issue. Reload. |

---

## File Locations for Debugging

1. **PHP Logs**: Check your PHP error_log location
2. **JSON Files**:
   - `data/availableSlots.json` - Check `bridgeState` field
   - `data/waitlist.json` - Should have entries
   - `data/bookings.json` - Should have bookings
3. **Browser Console** (F12): Look for 🌉 messages
4. **Network Tab** (F12): Check responses from PHP endpoints

---

## Expected Log Sequence

**When user cancels booking:**
```
1. cancelBooking.php called
2. Removes from bookings.json
3. Reloads slots and waitlist
4. Calls updateBridgeState()
   → 🌉 updateBridgeState START
   → 🌉 Found matching slot
   → 🌉 Total booked count
   → 🌉 Total waitlist entries
   → 🌉 shouldBridgeState calculation
   → 🌉 updateBridgeState RESULT
5. Writes updated slots back to JSON with bridgeState=true
```

**When user loads calendar:**
```
1. Calendar refreshes
2. For each date, checks slots
   → 🌉 Calendar check [date]: [slot] bridgeState=true
   → If true: div.classList.add('bridge')
3. Calendar date shows GREY
```

**When user clicks date:**
```
1. Loads slots for that date
2. For each slot, checks bridgeState
   → 🌉 Block slot / Single slot: bridgeState=true
   → If true: colorClass='bridge-state'
   → Adds yellow message div
3. When clicked:
   → 🌉 Clicked. inBridgeState=true
   → Shows waitlist form only
```

---

## Quick Test (Without UI)

Check if bridge state is being set:

```powershell
# Look at raw JSON
$slots = Get-Content data/availableSlots.json | ConvertFrom-Json
$slots.'2026-02-15' | ForEach-Object {
  Write-Host "$($_.time) - $($_.title): bridgeState=$($_.bridgeState)"
}
```

**Expected**:
```
14:00 - Debug Test: bridgeState=True
```

If you see `bridgeState=False` or no bridgeState field at all, the PHP updateBridgeState function isn't being called or isn't working.

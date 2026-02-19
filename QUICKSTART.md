# Quick Start Guide - HoopTheory Booking System

## For Admins

### Creating Slots

1. **Open admin.html**
2. **Select a Date** using the date picker
3. **Fill in Slot Details:**
   - **Slot Title**: Name of the session (e.g., "Basketball Training", "1v1 Coaching")
   - **Number of Spots**: Maximum participants allowed
   - **Session Type**: Choose "Group" or "Solo"
4. **Select Time Slots**: Click on available times (08:00-20:00)
   - Click to toggle selection (highlighted = selected)
5. **Click "Save Slots"**
6. **View Results**: 
   - Check "All Available Slots" section below
   - Monitor "All Bookings" for real-time updates

### Managing Bookings

1. **View all bookings** in the "All Bookings" section
2. **Click any booking** to expand details and see actions
3. **Confirm Booking**: 
   - Sends confirmation email to user
   - Updates booking status
4. **Delete Booking**:
   - Removes booking
   - Updates capacity automatically
   - Reopens a spot in the slot

### Monitoring Capacity

- **Slot Cards** show: "2/5 booked" and "3 spots left"
- **Full Indicator**: Shows "(FULL)" in red when capacity reached
- **Auto-refresh**: Page updates every 5 seconds with new bookings

---

## For Users

### Finding and Booking Sessions

1. **Open index.html**
2. **Navigate Months**:
   - Use ← Previous and Next → buttons to browse dates
   - Check current month/year in the center
3. **Select a Date** from the calendar:
   - Grayed out dates = no available slots
   - Active dates = availability exists
4. **View Slot Cards**:
   - Each card shows: Title, Time, Session Type, Capacity
   - Green cards with available spots = can book
   - Gray cards = fully booked (unavailable)
5. **Select a Slot**: Click the card to highlight it (turns black)
6. **Enter Your Information**:
   - Your Name (required)
   - Your Email (required)
7. **Click "Confirm Booking"**
8. **Success**: See confirmation with your booking details

### Understanding Slot Cards

**Card Information:**
```
┌─────────────────────────┐
│ Basketball Training [GROUP]│ ← Title + Session Type
│ 08:00                   │ ← Time
│ 2/5 booked              │ ← Current/Max
│ 3 spots left            │ ← Remaining (green)
└─────────────────────────┘
```

**Session Type Badges:**
- 🔵 **GROUP** (Blue) - Multiple participants, social sessions
- 🔴 **SOLO** (Pink) - One-on-one coaching or private sessions

**Slot States:**
- ✅ **Available** (White with green accents) - Click to book
- ⭐ **Selected** (Black) - Ready to confirm
- ❌ **Full** (Gray) - Cannot book, no spots available

---

## Sample Data

The system comes with sample data in February 2026:

### February 1st
- **08:00 - Basketball Training** (Group, 2/5 booked, 3 spots available)
- **10:00 - One-on-One Coaching** (Solo, 1/1 booked, **FULL**)
- **14:00 - Group Drills** (Group, 0/8 booked, 8 spots available)
- **16:00 - Court Rental** (Group, 3/20 booked, 17 spots available)

### February 2nd
- **09:00 - Basketball Training** (Group, 0/5 booked, 5 spots available)
- **15:00 - Skills Workshop** (Group, 0/10 booked, 10 spots available)

### February 3rd
- **11:00 - One-on-One Coaching** (Solo, 0/1 booked, 1 spot available)
- **13:00 - Basketball Training** (Group, 5/5 booked, **FULL**)

---

## Troubleshooting

### Issue: "No available slots for this date"
**Solution**: This date has no open slots. Try another date using Previous/Next navigation.

### Issue: Can't select a slot
**Solution**: The slot is fully booked. Look for cards with green "spots left" text.

### Issue: Bookings not showing
**Solution**: Check that you filled in all fields (Name, Email, and selected a slot).

### Issue: Admin page not updating
**Solution**: Page auto-refreshes every 5 seconds. Wait a moment or refresh manually.

### Issue: Previous dates showing as unavailable
**Solution**: This is correct behavior - past dates are always disabled.

---

## Features Highlight

✅ **Real-time Capacity Tracking** - See exactly how many spots are left
✅ **Month Navigation** - Browse all months forward and backward
✅ **Session Types** - Different options for group and solo sessions
✅ **Responsive Design** - Works perfectly on mobile, tablet, and desktop
✅ **Auto-refresh Admin** - See bookings update live every 5 seconds
✅ **Simple Workflow** - Just select date, pick slot, enter info, confirm

---

## Pro Tips

1. **Check availability early** - Popular slots fill up quickly!
2. **Note session types** - Group sessions are for multiple people, Solo for one-on-one
3. **Admins**: Use Previous/Next to prep slots for future months
4. **Capacity matters** - Solo sessions can only have 1 booking, groups can have many

---

## Contact & Support

For issues or questions about booking:
- Check the admin dashboard for booking status
- Contact admin through the booking email confirmation

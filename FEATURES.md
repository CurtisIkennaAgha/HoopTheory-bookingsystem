# HoopTheory Booking System - Enhanced Features

## Overview
This document outlines the comprehensive enhancements made to the HoopTheory booking system to support advanced slot management with capacity tracking, session types, and reactive UI updates.

## New Features

### 1. **Admin – Slot Creation with Enhanced Metadata**

When creating slots, admins can now specify:
- **Title**: The name of the session (e.g., "Basketball Training", "One-on-One Coaching")
- **Number of Spots**: Maximum capacity for each slot
- **Session Type**: Choose between "Group" or "Solo" sessions

**Location**: Admin panel (admin.html) - New input fields above the slot selector

```
- Slot Title: [text input]
- Number of Spots: [number input]
- Session Type: [dropdown: Group/Solo]
```

### 2. **Slot Availability Logic**

The system now tracks slot capacity in real-time:
- Each slot has a `numberOfSpots` limit
- `bookedUsers` array tracks all users booked in that slot
- **Automatic Unavailability**: A slot becomes unavailable when `bookedUsers.length >= numberOfSpots`
- Users cannot book a slot once capacity is reached

**Data Structure**:
```json
{
  "2026-02-01": [
    {
      "time": "08:00",
      "title": "Basketball Training",
      "numberOfSpots": 5,
      "sessionType": "group",
      "bookedUsers": [
        {"name": "John Smith", "email": "john@example.com"},
        {"name": "Jane Doe", "email": "jane@example.com"}
      ]
    }
  ]
}
```

### 3. **User – Enhanced Slot Display**

Slots are now displayed as **interactive cards** instead of simple time strings:

**Slot Card Shows**:
- 🎯 **Title**: The session name
- ⏰ **Time Range**: Start time of the session
- 🏆 **Session Type Badge**: Visual indicator (Group/Solo)
  - Group: Blue background with "GROUP" label
  - Solo: Pink background with "SOLO" label
- 📊 **Remaining Spots**: Live capacity indicator
  - Shows "2/5 booked" and "3 spots left"
  - Green when spots available
  - Red when fully booked

**Visual States**:
- ✅ **Available**: White with green border, hover effect
- 🔒 **Fully Booked**: Grayed out, disabled interaction
- ⭐ **Selected**: Black background with white text

### 4. **Disabled Dates**

Dates with no available slots are now visually distinguished:
- **Reduced opacity** and grayed appearance
- **Click disabled** - users cannot interact
- **Smart checking**: Dates are checked for actual available capacity, not just presence

**When a date is disabled**:
- Alert message: "No available slots for this date"
- Visual feedback with hover disabled

### 5. **Admin – Enhanced Bookings View**

Each booking now displays comprehensive information:

**Per Booking Card Shows**:
- 📅 **Date & Time**: Full booking details
- 👤 **User Info**: Name and email
- 🏆 **Slot Details**: Title, session type, capacity status
- 📊 **Capacity Status**:
  - Shows current/max (e.g., "2/5 booked")
  - Red "(FULL)" indicator when at capacity
- 🎯 **Status Badge**: Pending/Confirmed status

**Expandable Details**:
- Click any booking to expand and see actions
- Confirm button: Sends confirmation email
- Delete button: Removes booking and updates capacity

### 6. **Month Navigation**

Full calendar navigation now available:

**Controls**:
- ← **Previous**: Navigate to previous month
- **Month/Year Display**: Current viewing month
- **Next** →: Navigate to next month

**Behavior**:
- ✅ Supports all 12 months
- ✅ Handles year transitions automatically
- ✅ Slot availability updates correctly when changing months
- ✅ Disabled dates update dynamically
- ✅ Previous dates remain disabled (no past bookings)

### 7. **Reactive UI Updates**

The entire system updates reactively:

**When a booking is made**:
1. User list for that slot updates immediately
2. Slot capacity display refreshes
3. Slot becomes unavailable if capacity reached
4. "Remaining spots" counter decreases

**Admin auto-refresh**:
- Admin panel auto-refreshes every 5 seconds
- All bookings and slots update without page reload
- Maintains expanded/collapsed state during refresh

## Data Flow

### Booking Process
```
User selects date
  ↓
Check if date has available slots
  ↓
Display available slot cards with capacity info
  ↓
User selects a slot
  ↓
Verify slot capacity check
  ↓
Save booking to bookings.json
  ↓
Update bookedUsers array in availableSlots.json
  ↓
Recalculate availability
  ↓
Render updated calendar and slots
```

## File Structure
 `index.html` - Slot cards, month navigation, capacity display
- `user.html` - Slot cards, month navigation, capacity display
- `styles.css` - New styles for slot cards, session badges, disabled states
- `data/availableSlots.json` - New object structure with slot metadata
- `data/bookings.json` - Remains same format for backward compatibility

### PHP Files (No changes needed)
- `php/getSlots.php` - Returns availableSlots.json
- `php/getBookings.php` - Returns bookings.json
- `php/saveSlots.php` - Saves availableSlots.json
- `php/saveBookings.php` - Saves bookings.json

## Sample Data

Sample data is included in `data/availableSlots.json` and `data/bookings.json` demonstrating:
- Multiple session types (group and solo)
- Various capacity levels
- Booked and available slots
- Fully booked slots

## Responsive Design

All new features are fully responsive:
- ✅ Mobile (< 480px): Compact slot cards, stacked layout
- ✅ Tablet (480px - 640px): Adjusted spacing
- ✅ Desktop (> 640px): Full feature display

## Styling Highlights

### Slot Cards
- Smooth hover effects with elevation
- Gradient backgrounds for visual hierarchy
- Shimmer effect on hover (available slots only)
- Clear visual distinction for fully booked state

### Session Type Badges
- Color-coded: Blue for Group, Pink for Solo
- Compact size with rounded corners
- High contrast text for readability

### Month Navigation
- Centered, accessible buttons
- Full-width display on mobile
- Intuitive previous/next flow

## Browser Compatibility

All features compatible with:
- ✅ Chrome 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+
- ✅ Mobile browsers (iOS Safari, Chrome Mobile)

## Future Enhancements

Potential improvements for future versions:
- Recurring slots/sessions
- Custom slot duration (not fixed 40 minutes)
- Waitlist functionality
- Email notifications with slot details
- Payment integration for premium slots
- Admin dashboard with booking analytics
- Slot editing/modification after creation

# System Architecture & Data Flow

## 📐 Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                   HoopTheory System                     │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  ┌──────────────┐              ┌─────────────────┐    │
│  │   user.html  │              │   admin.html    │    │
│  │   (Booking)  │              │  (Management)   │    │
│  └──────────────┘              └─────────────────┘    │
│         │                               │              │
│         └───────────────┬───────────────┘              │
│                         │                              │
│              ┌──────────▼──────────┐                   │
│              │   PHP API Layer     │                   │
│              ├─────────────────────┤                   │
│              │ • getSlots.php      │                   │
│              │ • getBookings.php   │                   │
│              │ • saveSlots.php     │                   │
│              │ • saveBookings.php  │                   │
│              └──────────────────────┘                  │
│                         │                              │
│              ┌──────────▼──────────┐                   │
│              │   JSON Data Layer   │                   │
│              ├─────────────────────┤                   │
│              │ • availableSlots    │                   │
│              │ • bookings          │                   │
│              └─────────────────────┘                   │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

## 🔄 Data Flow: User Booking

```
User Page Load
    ↓
Fetch availableSlots.json & bookings.json
    ↓
Render Calendar (Current Month)
    ↓
Calculate Disabled Dates (no available slots)
    ↓
Display Calendar & Month Navigation
    ↓
User Clicks Date
    ↓
Check: hasAvailableSlots(date)?
    ├─ YES → Load slot cards
    └─ NO → Show alert, disable click
    ↓
Display Slot Cards with:
  • Title, Time, Type Badge
  • Capacity: X/Y booked, Z spots left
  • Color-coded states
    ↓
User Selects Slot
    ↓
User Enters Name & Email
    ↓
User Clicks "Confirm Booking"
    ↓
Verify: capacity < numberOfSpots?
    ├─ NO → Show "fully booked" error
    └─ YES → Proceed
    ↓
Save Booking to bookings.json
    ↓
Update bookedUsers[] in availableSlots.json
    ↓
Refresh Calendar & Slots
    ↓
Show Success Message
    ↓
Clear Form
```

## 👨‍💼 Data Flow: Admin Slot Creation

```
Admin Page Load
    ↓
Load availableSlots.json & bookings.json
    ↓
Display Form:
  • Date picker
  • Slot title input
  • Number of spots input
  • Session type dropdown
  • Time slot selector (08:00-20:00)
    ↓
Admin Selects Date
    ↓
Admin Fills Form Fields
    ↓
Admin Selects Times
    ↓
Admin Clicks "Save Slots"
    ↓
Create slot objects:
{
  time: "08:00",
  title: "Admin Input",
  numberOfSpots: Admin Input,
  sessionType: "group" or "solo",
  bookedUsers: []
}
    ↓
Save to availableSlots.json
    ↓
Refresh Display
    ↓
Show "Slots saved" confirmation
    ↓
Update All Available Slots View
    ↓
Auto-refresh active bookings
```

## 📊 Data Structure

### availableSlots.json
```
{
  "YYYY-MM-DD": [
    {
      time: "HH:MM",              // Start time
      title: string,              // Session name
      numberOfSpots: number,      // Capacity
      sessionType: "group|solo",  // Type
      bookedUsers: [              // Bookings
        {
          name: string,
          email: string
        }
      ]
    }
  ]
}
```

### bookings.json
```
{
  "YYYY-MM-DD": [
    "HH:MM - Title (Name) (Email)"  // Legacy format
  ]
}
```

## 🎨 Component Hierarchy

### User Interface
```
Container
├── Logo
├── Title: "Book a Session"
├── Month Navigation
│   ├── Previous Button
│   ├── Month/Year Display
│   └── Next Button
├── Calendar Grid (7 columns)
│   ├── Day Cells (disabled/available/selected)
│   └── Previous Month Empty Cells
├── Slots Container
│   └── Slot Cards (1-N)
│       ├── Card Header
│       │   ├── Title
│       │   └── Session Badge
│       ├── Time Display
│       └── Capacity Info
├── Form
│   ├── Name Input
│   ├── Email Input
│   └── Confirm Button
└── Hidden Date Input
```

### Admin Interface
```
Admin Container
├── Logo
├── Title: "Admin - Manage Slots & Bookings"
├── Date Selector
├── Slot Details Form
│   ├── Title Input
│   ├── Spots Input
│   ├── Type Dropdown
│   └── Time Selector Grid (13 slots)
├── Save Button
├── Available Slots Section
│   ├── Slot List (scrollable)
│   └── Remove Buttons
└── Bookings Section
    ├── Booking List (scrollable)
    └── Booking Cards
        ├── Info Display
        ├── Expand Toggle
        └── Actions (Confirm/Delete)
```

## 🔌 State Management

### User Page State
```
selectedDate    → null or "YYYY-MM-DD"
selectedSlot    → null or {slot object}
currentDisplayDate → Date object for month navigation
```

### Admin Page State
```
selectedDate    → null or "YYYY-MM-DD"
expandedBooking → null or DOM element
previousBookingIds → Set of booking IDs for change detection
autoRefreshInterval → ID or null
```

## ⏱️ Timing & Updates

### User Page
- **Initial Load**: Fetch data once
- **Calendar**: Recalculates when month changes
- **Slots**: Load on date selection
- **Reactive**: Updates on booking confirmation

### Admin Page
- **Initial Load**: Fetch data once
- **Polling**: Auto-refresh every 5 seconds
- **Conflict Handling**: Preserves expanded states during refresh
- **Manual Refresh**: Via date selector or save button

## 🌐 API Endpoints (PHP)

### GET /php/getSlots.php
**Response**: availableSlots.json content

### GET /php/getBookings.php
**Response**: bookings.json content

### POST /php/saveSlots.php
**Input**: JSON object (availableSlots)
**Response**: {"status":"ok"}

### POST /php/saveBookings.php
**Input**: JSON object (bookings)
**Response**: {"status":"ok"}

## 🎯 Key Validation Points

### User Booking Validation
1. Date is not in the past
2. Date has at least one available slot
3. Selected slot capacity not reached
4. Name field not empty
5. Email field not empty
6. Double-check capacity before save

### Admin Slot Creation Validation
1. Date is selected
2. Slot title is provided
3. numberOfSpots is positive integer
4. sessionType is "group" or "solo"
5. At least one time slot selected

## 📱 Responsive Breakpoints

```
Desktop (> 768px)
├── Calendar: 7 column grid (full week)
├── Slots: 4-5 columns auto-fill
├── Cards: Full size with hover effects
└── Buttons: Full width

Tablet (480px - 768px)
├── Calendar: 7 column grid, compact
├── Slots: 3 columns auto-fill
├── Cards: Slightly reduced padding
└── Buttons: Full width, stacked

Mobile (< 480px)
├── Calendar: 7 column grid, minimal gap
├── Slots: 2 columns auto-fill
├── Cards: Compact with smaller text
└── Buttons: Full width, 100% font-size 16px
```

## 🔒 Data Integrity

### Constraints
- Cannot book past capacity (checked both UI and logic)
- Cannot book past dates (calendar disables them)
- Cannot have duplicate bookings (system appends only)
- Slot metadata preserved on edit/refresh

### Sync Points
- After booking: Update both files
- After delete: Remove from both files
- On admin save: Update availableSlots.json only
- On refresh: Reload both files

## 📊 Performance Considerations

- **File I/O**: Minimal - JSON files only read/written when needed
- **Network**: Fetch only happens on user action or admin refresh
- **DOM**: Re-rendered only on data changes
- **Memory**: Slot data held in memory during session only
- **Auto-refresh**: 5-second interval is configurable

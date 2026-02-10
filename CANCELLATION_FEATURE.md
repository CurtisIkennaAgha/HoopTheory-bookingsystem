# User Self-Service Cancellation Feature - Implementation Complete

## Overview
Implemented a complete self-service booking cancellation system allowing users to cancel their reservations via secure email links without requiring authentication.

## Features Implemented

### 1. Email Enhancements
**Files Modified:** `php/sendEmail.php`

- Added `bookingId` parameter extraction from email data
- Added cancellation link to **temporary_reservation** emails:
  - Link: "Can't make this session? Cancel your booking here"
  - Style: Red text with underline, inline in email
  - Format: `https://hooptheory.co.uk/cancel-session.html?bookingId=<UNIQUE_ID>`
  
- Added cancellation link to **confirmation** emails:
  - Link: "Need to cancel? Click here"
  - Same styling and format as temporary_reservation

- **New email type: `cancellation`**
  - Triggered when user confirms cancellation
  - Displays cancelled session details (Title, Date, Time)
  - Shows optional user feedback if provided
  - Includes refund contact information
  - Professional HTML template with Hoop Theory branding

### 2. Booking ID Tracking System
**Files Modified:** `php/saveBookings.php`

- Generate unique `bookingId` for every booking created
- Format: `BK-{TIMESTAMP}-{RANDOM_HEX}` (e.g., `BK-1702500000-A1B2C3D4`)
- Store mapping in `data/bookingMappings.json`:
  - bookingId → booking details (name, email, date, session info)
  - Used for secure cancellation verification
  
- Return `bookingId` in API response for frontend usage
- Include `bookingId` in email data passed to sendEmail.php

### 3. Cancellation Page
**File Created:** `cancel-session.html`

**Features:**
- Professional gradient background with card-based layout
- Loading state with spinner while fetching booking details
- Session details display:
  - Session title
  - Date
  - Time
  - Booked for: [User Name]
  
- Optional cancellation reason text area:
  - Max 500 characters with live counter
  - Optional field (user feedback)
  - Text displayed in cancellation email
  
- Security checks:
  - Verifies booking exists via bookingId
  - Shows error if booking not found
  - Shows message if already cancelled (prevents reuse)
  
- Confirmation warning:
  - Informs user about refund process
  - Links to email contact for questions
  
- Success state:
  - Confirms cancellation with message
  - Provides link back to bookings page
  
- Fully responsive mobile design
- Accessible HTML with proper semantic structure

**Error States Handled:**
- Invalid/missing bookingId
- Booking not found
- Already cancelled
- Network/processing errors

### 4. Backend Cancellation Logic
**Files Created:** 
- `php/getBookingDetails.php` - Retrieve booking info for verification
- `php/processCancellation.php` - Process cancellation and update data

**getBookingDetails.php:**
- GET endpoint: `/php/getBookingDetails.php?bookingId={ID}`
- Checks if booking already cancelled
- Returns booking details for display (name, email, date, slot, title)
- Security: Prevents viewing cancelled bookings

**processCancellation.php:**
- POST endpoint: `/php/processCancellation.php`
- Accepts booking data + optional cancellation reason
- Performs cascading updates:
  1. Remove booking from `data/bookings.json`
  2. Remove user from slot's `bookedUsers` in `data/availableSlots.json`
  3. Handle both single and block session cancellations
  4. Log cancellation to `data/cancellations.json`
  5. Remove bookingId from `data/bookingMappings.json`
  6. Re-sync slots using `lib_slot_sync.php`
  7. Send cancellation confirmation email
  
- Error handling with detailed logging
- Prevents double-cancellation via mapping check

### 5. Data Structure Updates

**New Files Created:**
- `data/bookingMappings.json` - Maps bookingId to booking details
  ```json
  {
    "BK-1702500000-A1B2C3D4": {
      "name": "Alice Johnson",
      "email": "alice@example.com",
      "date": "2026-02-03",
      "slot": "10:00",
      "title": "Beginner Fundamentals",
      "isBlock": false,
      "blockDates": [],
      "price": "£15",
      "location": "Court 1",
      "createdAt": "2026-01-20 14:30:45",
      "timestamp": 1702500000
    }
  }
  ```

- `data/cancellations.json` - Audit log of all cancellations
  ```json
  {
    "BK-1702500000-A1B2C3D4": {
      "bookingId": "BK-1702500000-A1B2C3D4",
      "name": "Alice Johnson",
      "email": "alice@example.com",
      "date": "2026-02-03",
      "slot": "10:00",
      "title": "Beginner Fundamentals",
      "isBlock": false,
      "blockDates": [],
      "cancellationReason": "Got another commitment that day",
      "cancelledAt": "2026-01-20 15:45:22",
      "timestamp": 1702504522
    }
  }
  ```

### 6. Frontend Integration
**Files Modified:** `user.html`

- Updated `saveBooking()` and `saveBlockBooking()` to return bookingId
- Modified `proceedWithBookingAfterProfile()` to capture and pass bookingId
- Updated `showPaymentInstructions(slot, name, email, date, bookingId)` signature
- Include `bookingId` in email payload for both regular and registration-path bookings
- Three different booking entry points all now properly track bookingId

## Security Measures

1. **Token-Based Access:** Bookings verified via secure bookingId (not user email/password)
2. **One-Time Use Prevention:** Cancelled bookings marked in `cancellations.json`, duplicate attempts show error
3. **Data Validation:** All user inputs validated and escaped in HTML output
4. **Email Verification:** Only original booking email can view details (bookingId is unique)
5. **Audit Logging:** All cancellations recorded with timestamp and reason for admin review
6. **No Authentication Required:** Users don't need to log in, making cancellation frictionless

## Workflow

1. **Booking Created** → bookingId generated and stored in `bookingMappings.json`
2. **Confirmation Email Sent** → Includes cancellation link with bookingId parameter
3. **User Clicks Link** → Opens `cancel-session.html?bookingId=BK-...`
4. **Page Loads** → Calls `getBookingDetails.php` to verify booking exists
5. **User Reviews Details** → Optional: enters cancellation reason
6. **User Confirms Cancellation** → Calls `processCancellation.php`
7. **Processing:**
   - Booking removed from bookings.json
   - User removed from bookedUsers
   - Cancelled logged to cancellations.json
   - Session capacity updated
   - Cancellation confirmation email sent
8. **Confirmation Page** → Shows success message with back link

## Testing Checklist

- [ ] Send test booking confirmation email, verify cancellation link is present
- [ ] Click cancellation link, verify session details load correctly
- [ ] Enter cancellation reason and submit cancellation
- [ ] Verify cancellation appears in cancellations.json
- [ ] Check that booking is removed from bookings.json
- [ ] Verify capacity updates correctly in availableSlots.json
- [ ] Check cancellation confirmation email arrives
- [ ] Try clicking cancellation link again, verify "already cancelled" message
- [ ] Test with invalid bookingId, verify error message
- [ ] Test mobile responsiveness on cancel-session.html
- [ ] Verify emails are encrypted and sent securely

## Files Modified
- `php/sendEmail.php` - Added cancellation email type and links to templates
- `php/saveBookings.php` - Added bookingId generation and tracking
- `user.html` - Updated booking flow to capture and pass bookingId

## Files Created
- `cancel-session.html` - Cancellation interface page
- `php/getBookingDetails.php` - Booking verification endpoint
- `php/processCancellation.php` - Cancellation processing endpoint
- `data/bookingMappings.json` - BookingId tracking file
- `data/cancellations.json` - Cancellation audit log

## Integration Notes

**Email Sending Flow:**
1. User closes payment modal after confirming booking
2. `showPaymentInstructions()` called with bookingId
3. Email payload includes bookingId
4. `sendEmail.php` receives bookingId and builds email with cancellation link

**Cancellation Flow:**
1. User clicks cancellation link in email
2. `cancel-session.html` loads and fetches booking details
3. User reviews details and optional reason text
4. Form submission calls `processCancellation.php`
5. Backend removes booking and logs cancellation
6. Success page confirms cancellation
7. Cancellation email sent

## Admin Considerations

**Monitoring Cancellations:**
- Check `data/cancellations.json` regularly for feedback trends
- High cancellation rates for specific sessions may indicate scheduling/pricing issues
- Cancellation reasons can inform future marketing and session planning

**Refund Processing:**
- Users directed to contact `bao@hooptheory.co.uk` for refunds
- Manual review of `cancellations.json` helps identify legitimate refund requests
- Consider implementing automated refund emails based on payment status

**Booking Analytics:**
- `bookingMappings.json` can track total bookings created
- `cancellations.json` provides cancellation rate metrics
- Consider exporting this data to analytics dashboard

## Future Enhancements

1. **Automated Refunds:** If payment API integrated, auto-refund on cancellation
2. **Reschedule Option:** Offer same cancellation page option to reschedule instead
3. **Notification Digest:** Email admin when cancellation threshold reached
4. **Analytics Dashboard:** Visual display of cancellation trends
5. **SMS Reminders:** Send reminder before session to reduce cancellations
6. **Session Reoffer:** Auto-offer cancelled slot to waitlist users


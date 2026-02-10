#!/bin/bash
# Quick Test Script for Dynamic Values System
# Run this to verify the system is working correctly

echo "================================"
echo "HoopTheory Dynamic Values Test"
echo "================================"
echo ""

# Check that all key functions are present in admin.html
echo "✓ Checking for dynamic calculation functions..."

if grep -q "function getBookingStatus" admin.html; then
  echo "  ✅ getBookingStatus() found"
else
  echo "  ❌ getBookingStatus() MISSING"
fi

if grep -q "function getStatusBadgeClass" admin.html; then
  echo "  ✅ getStatusBadgeClass() found"
else
  echo "  ❌ getStatusBadgeClass() MISSING"
fi

if grep -q "function markEmailSent" admin.html; then
  echo "  ✅ markEmailSent() found"
else
  echo "  ❌ markEmailSent() MISSING"
fi

if grep -q "function loadConfirmedEmails" admin.html; then
  echo "  ✅ loadConfirmedEmails() found"
else
  echo "  ❌ loadConfirmedEmails() MISSING"
fi

echo ""
echo "✓ Checking for capacity calculation..."

if grep -q "const booked=slotInfo?.bookedUsers?.length || 0;" admin.html; then
  echo "  ✅ Capacity calculation from bookedUsers array found"
else
  echo "  ⚠️  Check manual - capacity calculation pattern"
fi

echo ""
echo "✓ Checking for waitlist available spots calculation..."

if grep -q "const availableSpots = totalSpots - bookedSpots" admin.html; then
  echo "  ✅ Available spots calculation found"
else
  echo "  ⚠️  Check manual - available spots calculation pattern"
fi

echo ""
echo "✓ Checking for dynamic status in booking cards..."

if grep -q 'getStatusBadgeClass(email' admin.html; then
  echo "  ✅ Dynamic status display found in booking cards"
else
  echo "  ❌ Dynamic status display MISSING from cards"
fi

echo ""
echo "✓ Checking for markEmailSent call after email send..."

if grep -q 'markEmailSent(email, bookingDate, slotTime, slotTitle);' admin.html; then
  echo "  ✅ markEmailSent call found in confirm button handler"
else
  echo "  ❌ markEmailSent call MISSING from button handler"
fi

echo ""
echo "✓ Checking for loadConfirmedEmails initialization..."

if grep -q 'loadConfirmedEmails();' admin.html; then
  echo "  ✅ loadConfirmedEmails call found in page initialization"
else
  echo "  ❌ loadConfirmedEmails call MISSING from initialization"
fi

echo ""
echo "================================"
echo "Test Complete"
echo "================================"
echo ""
echo "Next steps:"
echo "1. Open admin.html in browser"
echo "2. Create a test slot with 2 spots"
echo "3. Add a booking"
echo "4. Verify capacity shows (1/2)"
echo "5. Click Confirm to send email"
echo "6. Verify status changes to Confirmed (green)"
echo "7. Delete the booking"
echo "8. Verify capacity updates to show slot empty"
echo ""

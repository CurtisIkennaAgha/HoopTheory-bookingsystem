<?php
header('Content-Type: text/html; charset=utf-8');

$token = $_GET['token'] ?? '';
$message = '';
$isSuccess = false;
$sessionInfo = '';

if (!$token) {
    $message = 'Invalid token';
    goto render;
}

// Load offers
$offersFile = __DIR__ . '/../data/offers.json';
if (!file_exists($offersFile)) {
    $message = 'Offer not found';
    goto render;
}

$offers = json_decode(file_get_contents($offersFile), true);
if (!isset($offers[$token])) {
    $message = 'Offer not found';
    goto render;
}

$offer = $offers[$token];

// Only allow acting on pending offers
if ($offer['status'] !== 'pending') {
    $message = 'This offer has already been ' . $offer['status'];
    goto render;
}

if (strtotime($offer['expiresAt']) < time()) {
    $offer['status'] = 'expired';
    file_put_contents($offersFile, json_encode($offers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $message = 'Offer has expired';
    goto render;
}

// Load current bookings and slots
$bookingsFile = __DIR__ . '/../data/bookings.json';
$slotsFile = __DIR__ . '/../data/availableSlots.json';
$waitlistFile = __DIR__ . '/../data/waitlist.json';

$bookings = file_exists($bookingsFile) ? json_decode(file_get_contents($bookingsFile), true) : [];
$slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
$waitlist = file_exists($waitlistFile) ? json_decode(file_get_contents($waitlistFile), true) : [];

// Verify a spot is still available
$date = $offer['date'];
$time = $offer['time'];
$title = $offer['title'];
$blockId = $offer['blockId'] ?? null;
$blockDates = $offer['blockDates'] ?? [];

if (!isset($slots[$date])) {
    $message = 'Session not found';
    goto render;
}

// Find matching slot — be robust: prefer sessionKey when available, fall back to time+title
$slotFound = false;
$slotKey = null;
$slotPrice = null;
$slotLocation = null;
foreach ($slots[$date] as $k => $slot) {
    $matches = false;

    // Prefer an exact sessionKey match if present (safer when titles/times were edited)
    if (!empty($offer['sessionKey']) && !empty($slot['sessionKey']) && $slot['sessionKey'] === $offer['sessionKey']) {
        $matches = true;
    }

    // Fallback to legacy time+title match
    if (!$matches && ($slot['time'] === $time && $slot['title'] === $title)) {
        $matches = true;
    }

    if ($matches) {
        $slotFound = true;
        $slotKey = $k;
        $slotPrice = $slot['price'] ?? null;
        $slotLocation = $slot['location'] ?? null;

        // Infer block metadata for legacy offers
        if (empty($blockId) && !empty($slot['blockId'])) {
            $blockId = $slot['blockId'];
        }
        if (empty($blockDates) && !empty($slot['blockDates'])) {
            $blockDates = $slot['blockDates'];
        }

        // Build a de-duplicated set of booked emails using both slot.bookedUsers and bookings.json
        $bookedEmails = [];
        if (!empty($slot['bookedUsers']) && is_array($slot['bookedUsers'])) {
            foreach ($slot['bookedUsers'] as $bu) {
                if (is_array($bu) && !empty($bu['email'])) {
                    $bookedEmails[] = strtolower(trim($bu['email']));
                } elseif (is_string($bu)) {
                    // legacy string entry in some states: try to extract email
                    if (preg_match('/\(([^\)]+@[^\)]+)\)$/', $bu, $m)) {
                        $bookedEmails[] = strtolower(trim($m[1]));
                    }
                }
            }
        }

        // For block sessions: aggregate booked emails across ALL dates in the block
        // Check capacity only once - if one date is full, the block is full
        if ($blockId && !empty($blockDates)) {
            error_log("reserveOffer: Block session detected - aggregating capacity across " . count($blockDates) . " dates");
            foreach ($blockDates as $blockDate) {
                if (!isset($slots[$blockDate])) {
                    error_log("reserveOffer: Block date $blockDate not found in slots");
                    continue;
                }
                // Find matching slot for this block date
                foreach ($slots[$blockDate] as $blockSlot) {
                    if (!empty($blockSlot['blockId']) && $blockSlot['blockId'] === $blockId &&
                        $blockSlot['time'] === $time && $blockSlot['title'] === $title) {
                        // Add booked emails from this block date
                        if (!empty($blockSlot['bookedUsers']) && is_array($blockSlot['bookedUsers'])) {
                            foreach ($blockSlot['bookedUsers'] as $bu) {
                                if (is_array($bu) && !empty($bu['email'])) {
                                    $bookedEmails[] = strtolower(trim($bu['email']));
                                } elseif (is_string($bu)) {
                                    if (preg_match('/\(([^\)]+@[^\)]+)\)$/', $bu, $m)) {
                                        $bookedEmails[] = strtolower(trim($m[1]));
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Derive canonical booked emails from bookings.json (this is the source of truth)
        $bookedFromBookings = [];
        
        // For block sessions: check capacity across all dates in the block
        $datesToCheck = [$date];
        if ($blockId && !empty($blockDates)) {
            $datesToCheck = $blockDates;
        }
        
        foreach ($datesToCheck as $dateToCheck) {
            if (!empty($bookings[$dateToCheck]) && is_array($bookings[$dateToCheck])) {
                foreach ($bookings[$dateToCheck] as $bstr) {
                    if (!is_string($bstr)) continue;
                    // Match booking strings that reference this slot's time and title
                    if (strpos($bstr, $time) !== false && strpos($bstr, $title) !== false) {
                        if (preg_match('/\(([^\)]+@[^^\)]+)\)$/', $bstr, $m)) {
                            $bookedFromBookings[] = strtolower(trim($m[1]));
                        }
                    }
                }
            }
        }

        // Normalize and dedupe both sources; we will *use* bookings.json for capacity checks and
        // report any orphaned entries that appear only in slot.bookedUsers (they won't count towards capacity).
        $bookedFromBookings = array_values(array_filter(array_unique($bookedFromBookings)));
        $bookedFromSlots = array_values(array_filter(array_unique($bookedEmails ?? [])));
        $orphanedSlotEmails = array_values(array_diff($bookedFromSlots, $bookedFromBookings));

        // Use bookings.json as authoritative for capacity
        $bookedEmails = $bookedFromBookings;
        $bookedCount = count($bookedEmails);
        $capacity = intval($slot['numberOfSpots'] ?? 0);

        // Diagnostic logging to help track down mismatch reports
        $blockInfo = $blockId ? " [BLOCK: $blockId, " . count($blockDates) . " dates]" : "";
        error_log(sprintf("reserveOffer: date=%s time=%s title=%s capacity=%d booked=%d slotIndex=%s sessionKey=%s%s", $date, $time, $title, $capacity, $bookedCount, $slotKey ?? 'null', $slot['sessionKey'] ?? '', $blockInfo)); 

        // If the offer email is already in the booked list, treat this as an idempotent flow:
        // - do not reject for "no spots" (the user already occupies a seat)
        // - do not create duplicate bookings
        // - still send temporary_reservation email and remove waitlist entry
        $lowerEmail = strtolower(trim($offer['email']));
        $alreadyBooked = in_array($lowerEmail, $bookedEmails, true);
        if ($alreadyBooked) {
            error_log("reserveOffer: offer email already booked (idempotent path): $lowerEmail");
        }

        if (!$alreadyBooked && $bookedCount >= $capacity) {
            // Provide clearer diagnostics in logs and a friendly message to the user
            error_log('reserveOffer: no spots — bookedEmails=' . json_encode($bookedEmails));
            $message = 'No spots available';
            goto render;
        }

        // Add user to slots.bookedUsers (prevent race) unless already present
        // For block sessions: add to ALL dates in the block
        if (!$alreadyBooked) {
            if ($blockId && !empty($blockDates)) {
                // Add to all block dates
                foreach ($blockDates as $blockDate) {
                    if (isset($slots[$blockDate])) {
                        foreach ($slots[$blockDate] as $bIdx => $bSlot) {
                            if (!empty($bSlot['blockId']) && $bSlot['blockId'] === $blockId &&
                                $bSlot['time'] === $time && $bSlot['title'] === $title) {
                                if (!isset($slots[$blockDate][$bIdx]['bookedUsers']) || !is_array($slots[$blockDate][$bIdx]['bookedUsers'])) {
                                    $slots[$blockDate][$bIdx]['bookedUsers'] = [];
                                }
                                $slots[$blockDate][$bIdx]['bookedUsers'][] = ['name' => $offer['name'], 'email' => $offer['email']];
                                error_log("reserveOffer: Added user to block date $blockDate");
                            }
                        }
                    }
                }
            } else {
                // Single session: add only to the offered date
                if (!isset($slots[$date][$slotKey]['bookedUsers']) || !is_array($slots[$date][$slotKey]['bookedUsers'])) {
                    $slots[$date][$slotKey]['bookedUsers'] = [];
                }
                $slots[$date][$slotKey]['bookedUsers'][] = ['name' => $offer['name'], 'email' => $offer['email']];
            }
        }

        break;
    }
}

if (!$slotFound) {
    error_log(sprintf("reserveOffer: session not found for token=%s date=%s time=%s title=%s", $token, $date, $time, $title));
    $message = 'Session not found';
    goto render;
}

// Add booking entry (same as confirm flow) — this reserves the spot server-side
// For block sessions: add bookings for ALL dates in the block
$bookingString = $time . ' - ' . $title . ' (' . $offer['name'] . ') (' . $offer['email'] . ')';
$datesToBook = [$date];
if ($blockId && !empty($blockDates)) {
    $datesToBook = $blockDates;
    error_log("reserveOffer: Block session booking - adding to " . count($blockDates) . " dates");
}

foreach ($datesToBook as $bookingDate) {
    if (!isset($bookings[$bookingDate])) {
        $bookings[$bookingDate] = [];
    }
    // Avoid duplicating booking entries if one already exists (idempotent)
    $exists = false;
    foreach ($bookings[$bookingDate] as $b) {
        if (is_string($b) && trim($b) === $bookingString) { $exists = true; break; }
    }
    if (!$exists) {
        $bookings[$bookingDate][] = $bookingString;
        error_log("reserveOffer: Added booking for $bookingDate");
    } else {
        error_log('reserveOffer: booking string already present for ' . $offer['email'] . ' on ' . $bookingDate);
    }
}

// Remove from waitlist
// For block sessions: remove from ALL dates in the block
$datesToRemoveFromWaitlist = [$date];
if ($blockId && !empty($blockDates)) {
    $datesToRemoveFromWaitlist = $blockDates;
}

foreach ($datesToRemoveFromWaitlist as $wlDate) {
    if (isset($waitlist[$wlDate])) {
        $waitlist[$wlDate] = array_filter($waitlist[$wlDate], function($entry) use ($offer) {
            return $entry['email'] !== $offer['email'] || 
                   $entry['time'] !== $offer['time'] || 
                   $entry['title'] !== $offer['title'];
        });
        $waitlist[$wlDate] = array_values($waitlist[$wlDate]);
        if (empty($waitlist[$wlDate])) {
            unset($waitlist[$wlDate]);
        }
        error_log("reserveOffer: Removed from waitlist for $wlDate");
    }
}

// Mark offer as reserved / pending payment
$offer['status'] = 'reserved';
$offer['reservedAt'] = date('Y-m-d H:i:s');
$offers[$token] = $offer;

// Persist files
file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
file_put_contents($bookingsFile, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
file_put_contents($waitlistFile, json_encode($waitlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
file_put_contents($offersFile, json_encode($offers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

// Ensure availableSlots.json is rebuilt from canonical bookings.json (prevents orphaned bookedUsers)
require_once __DIR__ . '/lib_slot_sync.php';
$syncRes = sync_slots_from_bookings(__DIR__ . '/../data/bookings.json', __DIR__ . '/../data/availableSlots.json', true, true);
error_log('sync_slots_from_bookings (reserveOffer) result: ' . json_encode($syncRes));

// Update Bridge State for this slot
require_once __DIR__ . '/lib_bridge_state.php';
$slots = file_exists($slotsFile) ? json_decode(file_get_contents($slotsFile), true) : [];
$primaryDate = (!empty($blockDates) ? $blockDates[0] : $date);
if ($primaryDate) {
    updateBridgeState($slots, $primaryDate, $time, $title, $bookings, $waitlist, $blockId);
    file_put_contents($slotsFile, json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// Prepare payment details and send temporary_reservation email (same template used by normal reserve flow)
$paymentRef = 'HT-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6));
$deadline = date('D, j M Y H:i', strtotime('+24 hours'));

// Send reservation email via internal endpoint
$emailPayload = [
    'type' => 'temporary_reservation',
    'email' => $offer['email'],
    'name' => $offer['name'],
    'slot' => $time,
    'title' => $title,
    'date' => $date,
    'blockDates' => $offer['blockDates'] ?? [],
    'paymentRef' => $paymentRef,
    'deadline' => $deadline
];
if ($slotPrice) $emailPayload['price'] = $slotPrice;
if ($slotLocation) $emailPayload['location'] = $slotLocation;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/sendEmail.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    error_log('reserveOffer.php - CURL error sending temporary_reservation: ' . $curlError);
}
if ($httpCode !== 200) {
    error_log('reserveOffer.php - sendEmail returned http code ' . $httpCode . ' response: ' . $response);
}

$isSuccess = true;
$message = 'Spot reserved — payment required';
// Format date as "23rd March 2026"
[$year, $month, $day] = explode('-', $date);
$monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$dayNum = (int)$day;
$monthName = $monthNames[(int)$month - 1];
$suffix = 'th';
if ($dayNum % 10 === 1 && $dayNum !== 11) $suffix = 'st';
elseif ($dayNum % 10 === 2 && $dayNum !== 12) $suffix = 'nd';
elseif ($dayNum % 10 === 3 && $dayNum !== 13) $suffix = 'rd';
$formattedDate = "{$dayNum}{$suffix} {$monthName} {$year}";
$sessionInfo = htmlspecialchars($title) . ' on ' . $formattedDate . ' at ' . htmlspecialchars($time);

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isSuccess ? 'Booking Reserved' : 'Offer Status'; ?> - Hoop Theory</title>
    <link rel="stylesheet" href="/styles.css">
    <style>
      /* Improved reserved-page overrides: better spacing, contrast and mobile behaviour */
      :root{--accent:#10b981;--muted:#6b7280;--bg:#f5f7fa}
      body{background:linear-gradient(135deg,var(--bg) 0%,#eef2f7 100%);font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial;padding:env(safe-area-inset-top,28px) env(safe-area-inset-right,20px) env(safe-area-inset-bottom,32px) env(safe-area-inset-left,20px)}
      .card{max-width:760px;margin:0 auto;background:#fff;border-radius:16px;box-shadow:0 24px 48px rgba(15,23,42,0.08);overflow:hidden}

      /* Header: increased vertical rhythm and clearer type */
      .reserved-header{display:flex;flex-direction:column;gap:8px;padding:32px 28px;background:linear-gradient(135deg,var(--accent) 0%,#059669 100%);color:#fff}
      .reserved-header h2{margin:0;font-size:20px;line-height:1.05}
      .reserved-header p{margin:0;opacity:0.95;font-size:14px}

      .reserved-body{padding:24px 28px}

      /* Payment blocks: more space, consistent alignment */
      .payment-section{padding:18px 0;border-bottom:1px solid #f3f4f6;display:flex;flex-direction:column;gap:14px}
      .session-details .detail-row,.bank-details .detail-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-radius:6px}
      .bank-details .value{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,monospace;background:#f8faf9;padding:6px 10px;border-radius:6px;border:1px solid #f1f5f9}

      /* Copy button: larger tap target and clearer state */
      .copy-btn{background:#111827;color:#fff;border:0;padding:8px 12px;border-radius:8px;cursor:pointer;transition:background .12s,transform .08s;font-weight:600}
      .copy-btn:active{transform:translateY(1px)}
      .copy-btn.copied{background:var(--accent)}

      .next-steps{margin-top:18px;background:#fbfdfb;padding:16px;border-radius:10px;color:var(--muted)}
      .buttons{display:flex;flex-wrap:wrap;gap:12px;margin-top:18px}
      .btn{padding:12px 16px;border-radius:10px;text-decoration:none;display:inline-block}
      .btn-primary{background:#111827;color:#fff}
      .btn-secondary{background:transparent;border:1px solid #e5e7eb;color:#374151}

      /* Small screens */
      @media (max-width:520px){
        .card{margin:12px;border-radius:12px}
        .reserved-header{padding:20px}
        .reserved-body{padding:16px}
        .payment-section{gap:12px}
        .bank-details .detail-row{padding:8px 0}
      }
    </style>
</head>
<body>
  <div class="card">
    <div class="reserved-header">
      <h2 style="margin:0; font-size:22px;">Booking Reserved</h2>
      <p style="margin:6px 0 0 0; opacity:0.95;">Complete your payment to confirm your spot</p>
    </div>

    <div class="reserved-body">
      <?php if ($isSuccess): ?>
        <div class="payment-section">
          <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
            <div>
              <strong style="display:block; font-size:13px; color:#065f46; text-transform:uppercase;">Session Details</strong>
              <div style="margin-top:8px; font-weight:700; color:#111827; font-size:16px;"><?php echo htmlspecialchars($title); ?></div>
              <div style="color:#6b7280; margin-top:6px;"><?php echo htmlspecialchars($formattedDate); ?> — <?php echo htmlspecialchars($time); ?></div>
            </div>
            <div style="text-align:right; min-width:120px;">
              <div style="font-size:12px; color:#6b7280;">Price</div>
              <div style="font-weight:700; font-size:18px; color:#111827;"><?php echo $slotPrice ? htmlspecialchars($slotPrice) : '—'; ?></div>
            </div>
          </div>
        </div>

        <div class="payment-section">
          <strong style="display:block; font-size:13px; color:#374151; margin-bottom:8px;">Bank Transfer Details</strong>
          <div class="bank-details">
            <div class="detail-row"><span class="label">Account Name:</span><span class="value">Hoop Theory</span></div>
            <div class="detail-row"><span class="label">Account Number:</span><span class="value" id="accountNumber">12345678</span><button class="copy-btn" id="copyAccountBtn">Copy</button></div>
            <div class="detail-row"><span class="label">Sort Code:</span><span class="value" id="sortCode">12-34-56</span><button class="copy-btn" id="copySortBtn">Copy</button></div>
            <div class="detail-row"><span class="label">Reference:</span><span class="value" id="paymentReference"><?php echo htmlspecialchars($paymentRef); ?></span><button class="copy-btn" id="copyRefBtn">Copy</button></div>
            <div style="font-size:13px; color:#6b7280; margin-top:8px;">Please pay within 24 hours. Deadline: <strong id="deadlineText"><?php echo htmlspecialchars($deadline); ?></strong></div>
          </div>
        </div>

        <div class="next-steps">
          <strong style="display:block; margin-bottom:8px; color:#065f46;">What's next</strong>
          <ol style="margin:0 0 0 18px; color:#374151;">
            <li>Transfer the payment using the details above</li>
            <li>Use the payment reference exactly as shown</li>
            <li>We will confirm your spot once payment is received</li>
          </ol>
          <p style="margin-top:10px; font-weight:600; color:#b91c1c;">Important: please note that refunds will not be issued after your spot has been confirmed.</p>
        </div>

        <div class="buttons">
          <a class="btn btn-primary" href="/">Back to Hoop Theory</a>
          <a class="btn btn-secondary" href="mailto:bao@hooptheory.co.uk">Contact support</a>
        </div>

      <?php else: ?>
        <div style="padding:20px; color:#991b1b; background:#fff5f5; border-radius:8px;"><?php echo htmlspecialchars($message); ?></div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    // Small copy helpers to match in-app modal
    function flash(btn) {
      btn.classList.add('copied');
      const orig = btn.innerText;
      btn.innerText = 'Copied';
      setTimeout(()=>{ btn.classList.remove('copied'); btn.innerText = orig; }, 1800);
    }
    document.getElementById('copyAccountBtn')?.addEventListener('click', ()=>{ navigator.clipboard.writeText(document.getElementById('accountNumber').innerText).then(()=>flash(document.getElementById('copyAccountBtn'))); });
    document.getElementById('copySortBtn')?.addEventListener('click', ()=>{ navigator.clipboard.writeText(document.getElementById('sortCode').innerText).then(()=>flash(document.getElementById('copySortBtn'))); });
    document.getElementById('copyRefBtn')?.addEventListener('click', ()=>{ navigator.clipboard.writeText(document.getElementById('paymentReference').innerText).then(()=>flash(document.getElementById('copyRefBtn'))); });
  </script>
</body>
</html>

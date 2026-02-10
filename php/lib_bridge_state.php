<?php
/**
 * Bridge State Management Library
 * Handles the "Bridge State" feature where a previously-full session with waitlist entries
 * prioritizes waitlisted players before allowing new bookings.
 */

/**
 * Check if a session should be in Bridge State
 * @param array $slot The slot object
 * @param array $waitlistEntries Array of waitlist entries for this slot from waitlist.json
 * @param int $bookedCount Current number of unique booked users
 * @return bool True if session should be in Bridge State
 */
function shouldBridgeState($slot, $waitlistEntries, $bookedCount) {
    $capacity = intval($slot['numberOfSpots'] ?? $slot['capacity'] ?? 0);
    
    // Bridge State is active if:
    // 1. Session has available space (booked < capacity)
    // 2. Session has waitlist entries
    
    $hasAvailableSpace = $bookedCount < $capacity;
    $hasWaitlist = !empty($waitlistEntries) && count($waitlistEntries) > 0;
    
    error_log(sprintf('🌉 shouldBridgeState: booked=%d capacity=%d space=%s waitlist=%s result=%s',
        $bookedCount, $capacity,
        $hasAvailableSpace ? 'YES' : 'NO',
        $hasWaitlist ? 'YES' : 'NO',
        ($hasAvailableSpace && $hasWaitlist) ? 'BRIDGE=TRUE' : 'BRIDGE=FALSE'
    ));
    
    // Enter bridge state if there's space AND waitlist entries exist
    return $hasAvailableSpace && $hasWaitlist;
}

/**
 * Update Bridge State for a slot across all its dates (handles blocks)
 * @param array &$slots Reference to entire slots structure
 * @param string $date Primary date of the slot
 * @param string $time Slot time
 * @param string $title Slot title
 * @param array $bookingsData Bookings data to calculate capacity
 * @param array $waitlistData Waitlist data
 */
function updateBridgeState(&$slots, $date, $time, $title, $bookingsData, $waitlistData, $blockId = null) {
    error_log("🌉 updateBridgeState START: date=$date time=$time title=$title");
    
    if (!isset($slots[$date])) {
        error_log("🌉 updateBridgeState: NO SLOTS FOR DATE $date");
        return;
    }
    
    $slotIndex = null;
    foreach ($slots[$date] as $idx => &$slot) {
        $timeMatch = $slot['time'] === $time;
        $titleMatch = $slot['title'] === $title;
        $blockMatch = ($blockId === null) || (!empty($slot['blockId']) && $slot['blockId'] === $blockId);
        if ($timeMatch && $titleMatch && $blockMatch) {
            $slotIndex = $idx;
            break;
        }
    }

    if ($slotIndex === null) {
        error_log("🌉 updateBridgeState: NO MATCHING SLOT FOR $time $title ON $date");
        return;
    }

    $slotRef = &$slots[$date][$slotIndex];
    error_log("🌉 updateBridgeState: Found matching slot");

    $isBlock = !empty($slotRef['blockId']) && !empty($slotRef['blockDates']);
    $datesToCheck = $isBlock ? $slotRef['blockDates'] : [$date];

    // Calculate booked count using BridgeSet
    $uniqueEmails = new BridgeSet();
    error_log("🌉 Checking " . count($datesToCheck) . " dates for capacity calculation");

    foreach ($datesToCheck as $checkDate) {
        if (isset($bookingsData[$checkDate])) {
            error_log("🌉 Checking bookings for date: $checkDate (count: " . count($bookingsData[$checkDate]) . ")");
            foreach ($bookingsData[$checkDate] as $booking) {
                if (preg_match('/^(.+?)\s*-\s*(.+?)\s*\(([^)]+)\)\s*\(([^)]+@[^)]+)\)$/', $booking, $m)) {
                    $bTime = trim($m[1]);
                    $bTitle = trim($m[2]);
                    $bEmail = strtolower(trim($m[4]));
                    if ($bTime === $time && $bTitle === $title) {
                        error_log("🌉   ✓ Matched booking for $bEmail");
                        $uniqueEmails->add($bEmail);
                    }
                }
            }
        }
    }
    $bookedCount = $uniqueEmails->count();
    error_log("🌉 Total booked count: $bookedCount");

    // Get waitlist entries for this slot
    $waitlistEntries = [];
    foreach ($datesToCheck as $checkDate) {
        if (isset($waitlistData[$checkDate])) {
            error_log("🌉 Checking waitlist for date: $checkDate (count: " . count($waitlistData[$checkDate]) . ")");
            foreach ($waitlistData[$checkDate] as $wl) {
                if ($wl['time'] === $time && $wl['title'] === $title) {
                    error_log("🌉   ✓ Found waitlist entry for " . $wl['email']);
                    $waitlistEntries[] = $wl;
                }
            }
        }
    }
    error_log("🌉 Total waitlist entries: " . count($waitlistEntries));

    $oldBridgeState = $slotRef['bridgeState'] ?? false;
    $newBridgeState = shouldBridgeState($slotRef, $waitlistEntries, $bookedCount);

    if ($isBlock) {
        $targetBlockId = $blockId ?? $slotRef['blockId'];
        foreach ($slotRef['blockDates'] as $blockDate) {
            if (!isset($slots[$blockDate])) {
                continue;
            }
            foreach ($slots[$blockDate] as &$blockSlot) {
                if (!empty($blockSlot['blockId']) && $blockSlot['blockId'] === $targetBlockId &&
                    $blockSlot['time'] === $time && $blockSlot['title'] === $title) {
                    $blockSlot['bridgeState'] = $newBridgeState;
                }
            }
        }
    } else {
        $slotRef['bridgeState'] = $newBridgeState;
    }

    error_log(sprintf(
        '🌉 updateBridgeState RESULT: %s %s | booked=%d capacity=%d waitlist=%d | bridgeState: %s → %s',
        $time, $title, $bookedCount,
        intval($slotRef['numberOfSpots'] ?? $slotRef['capacity'] ?? 0),
        count($waitlistEntries),
        $oldBridgeState ? 'true' : 'false',
        $newBridgeState ? 'true' : 'false'
    ));
}

/**
 * Simple set implementation for PHP deduplication
 */
class BridgeSet {
    private $items = [];
    
    public function add($item) {
        $key = strtolower($item);
        $this->items[$key] = true;
    }
    
    public function count() {
        return count($this->items);
    }
}
?>

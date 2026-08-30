<?php
require_once 'db.php';

$message = '';
$booking = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['lookup'])) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = 'Invalid security token. Please try again.';
        } else {
            $reference = sanitizeInput($_POST['reference'] ?? '');
            $country_code = $_POST['country_code'] ?? '+233';
            $phone_number = preg_replace('/[^0-9]/', '', $_POST['phone_number'] ?? '');
            $phone = $country_code . $phone_number;

            if (empty($reference) || empty($phone_number)) {
                $error = 'Please provide both reference number and phone number';
            } elseif (!validatePhone($phone)) {
                $error = 'Please provide a valid phone number';
            } else {
                $reference = str_replace('#', '', $reference);

                $stmt = $db->prepare("
                    SELECT b.*, b.booking_reference, br.name as barber_name, s.name as service_name
                    FROM bookings b
                    JOIN barbers br ON b.barber_id = br.id
                    JOIN services s ON b.service_id = s.id
                    WHERE REPLACE(b.booking_reference, '#', '') = :reference AND b.client_phone LIKE :phone
                    AND b.status IN ('pending', 'confirmed')
                ");
                $stmt->execute([
                    ':reference' => $reference,
                    ':phone' => "%$phone%"
                ]);
                $booking = $stmt->fetch();

                if (!$booking) {
                    $error = 'No active booking found with that reference number and phone number';
                } else {
                    $booking['confirmation_token'] = ensureBookingConfirmationToken($booking['id']);
                }
            }
        }
    } elseif (isset($_POST['cancel'])) {
        // Cancel the booking.
        //
        // The booking id is sequential and therefore NOT proof of ownership.
        // Require the CSRF token plus the booking's confirmation_token, which
        // is only exposed after a successful reference + phone lookup above.
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = 'Invalid security token. Please look up your booking again.';
        } elseif (!checkRateLimit('client_cancel_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 300)) {
            $error = 'Too many cancellation attempts. Please try again later.';
        } else {
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $booking_data = getBookingForCancellation($booking_id, $_POST['confirmation_token'] ?? '');

            if (!$booking_data) {
                $error = 'We could not verify that booking. Please look it up again using your reference number and phone number.';
            } else {
                // Check if booking was paid and needs refund
                $needs_refund = $booking_data['payment_status'] === 'success' && $booking_data['refund_status'] === 'none';

                if ($needs_refund) {
                    // Mark as refund requested
                    $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW(), refund_status = 'requested' WHERE id = :id");
                    $stmt->execute([':id' => $booking_id]);
                    logAdminActivity('refund_requested', 'System', "Client requested cancellation with refund for booking #{$booking_data['booking_reference']} - ₵" . number_format($booking_data['paid_amount'], 2), $booking_id);
                    $message = 'Your booking has been cancelled. A refund of ₵' . number_format($booking_data['paid_amount'], 2) . ' has been requested and will be processed within 3-10 business days.';

                    // Send refund requested email
                    if (!empty($booking_data['client_email'])) {
                        sendRefundRequestedEmail($booking_data, $booking_data['paid_amount']);
                    }
                } else {
                    // Normal cancellation
                    $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW() WHERE id = :id");
                    $stmt->execute([':id' => $booking_id]);
                    $message = 'Your booking has been cancelled successfully.';
                }

                // Check waitlist for auto-fill
                ensureWaitlistTableExists();
                $waitlist_client = getNextWaitlistClient($booking_data['booking_date'], $booking_data['booking_time'], $booking_data['barber_id']);

                if ($waitlist_client) {
                    updateWaitlistStatus($waitlist_client['id'], 'notified');

                    // Send notification to waitlist client
                    $to = $waitlist_client['client_email'] ?: '';
                    if (!empty($to)) {
                        $subject = "Good news! Your preferred time slot is now available - icut";
                        $waitlist_body = "<html><body style='font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; padding: 20px;'><div style='max-width: 600px; margin: auto; background: #2d2d2d; padding: 30px; border-radius: 10px;'><h2 style='color: #c9a96e;'>icut</h2><h3>🎉 Time Slot Available!</h3><p>Hi " . htmlspecialchars($waitlist_client['client_name']) . ",</p><p>Great news! A time slot has just opened up for your preferred barber:</p><table style='width: 100%; border-collapse: collapse; margin: 20px 0;'><tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Date:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>" . date('F j, Y', strtotime($booking_data['booking_date'])) . "</td></tr><tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Time:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>" . date('g:i A', strtotime($booking_data['booking_time'])) . "</td></tr><tr><td style='padding: 10px; border: 1px solid #404040;'><strong>Barber:</strong></td><td style='padding: 10px; border: 1px solid #404040;'>" . htmlspecialchars($booking_data['barber_name']) . "</td></tr></table><p>Book now before it's taken! <a href='" . htmlspecialchars(env('SITE_URL', 'http://localhost/icut')) . "/' style='color: #c9a96e;'>Click here to book</a></p><p style='color: #c9a96e;'>See you soon!</p></div></body></html>";
                        sendEmailNotification($to, $subject, $waitlist_body);
                    }

                    // Also send WhatsApp if available
                    $site_phone = getSiteSetting('phone', '');
                    if (!empty($site_phone) && !empty($waitlist_client['client_phone'])) {
                        logAdminActivity('waitlist_notified', 'System', "Notified waitlist client {$waitlist_client['client_name']} for cancelled slot");
                    }

                    $message .= ' We have notified the next person on the waitlist.';
                }

                $booking = null;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancel Booking - Barbershop</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        if (typeof tailwind !== 'undefined') {
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            barber: {
                                900: '#0f0f0f',
                                800: '#1a1a1a',
                                700: '#2d2d2d',
                                gold: '#c9a96e',
                                'gold-light': '#d4b87a',
                            }
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-barber-900 min-h-screen">
    <div class="max-w-2xl mx-auto px-4 py-12">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-white mb-2">Cancel Booking</h1>
            <p class="text-gray-400">Enter your booking reference and phone number to cancel</p>
        </div>
        
        <?php if ($message): ?>
            <div class="bg-green-900/50 border border-green-700 text-green-300 px-6 py-4 rounded-lg mb-6">
                <?php echo htmlspecialchars($message); ?>
                <a href="index.php" class="block mt-2 text-barber-gold hover:text-barber-gold-light">← Book New Appointment</a>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="bg-red-900/50 border border-red-700 text-red-300 px-6 py-4 rounded-lg mb-6">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!$message): ?>
            <?php if ($booking): ?>
                <!-- Show booking details and confirm cancellation -->
                <div class="bg-barber-800 rounded-2xl p-8 border border-barber-700">
                    <h2 class="text-xl font-semibold text-white mb-4">Booking Details</h2>
                    <div class="space-y-3 mb-6 text-gray-300">
                        <p><span class="text-gray-400">Reference:</span> <?php echo htmlspecialchars($booking['booking_reference'] ?? '#' . $booking['id']); ?></p>
                        <p><span class="text-gray-400">Name:</span> <?php echo htmlspecialchars($booking['client_name']); ?></p>
                        <p><span class="text-gray-400">Service:</span> <?php echo htmlspecialchars($booking['service_name']); ?></p>
                        <p><span class="text-gray-400">Barber:</span> <?php echo htmlspecialchars($booking['barber_name']); ?></p>
                        <p><span class="text-gray-400">Date:</span> <?php echo date('F j, Y', strtotime($booking['booking_date'])); ?></p>
                        <p><span class="text-gray-400">Time:</span> <?php echo date('g:i A', strtotime($booking['booking_time'])); ?></p>
                        <p><span class="text-gray-400">Status:</span> 
                            <span class="text-yellow-300"><?php echo ucfirst($booking['status']); ?></span>
                        </p>
                    </div>
                    <form id="cancel-form" method="POST" action="">
                        <input type="hidden" name="action" value="cancel_booking">
                        <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
                        <input type="hidden" name="confirmation_token" value="<?php echo htmlspecialchars($booking['confirmation_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                        <button type="submit" name="cancel" 
                                class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-lg transition mb-3">
                            Confirm Cancellation
                        </button>
                        <button type="button" onclick="history.back()" 
                                class="w-full bg-barber-700 hover:bg-barber-600 text-white font-bold py-3 rounded-lg transition">
                            Go Back
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <!-- Lookup form -->
                <div class="bg-barber-800 rounded-2xl p-8 border border-barber-700">
                    <form id="lookup-form" method="POST" action="" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                        <div>
                            <label class="block text-gray-300 text-sm mb-2">Booking Reference Number</label>
                            <input type="text" name="reference" required 
                                   placeholder="e.g., #57368"
                                   class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                            <p class="text-gray-500 text-xs mt-1">You can find this in your confirmation message</p>
                        </div>
                        <div>
                            <label class="block text-gray-300 text-sm mb-2">Phone Number</label>
                            <div class="flex space-x-2">
                                <select name="country_code" class="bg-barber-700 border border-barber-600 rounded-lg px-3 py-3 text-white w-28">
                                    <option value="+233">🇬🇭 +233</option>
                                    <option value="+1">🇺🇸 +1</option>
                                    <option value="+44">🇬🇧 +44</option>
                                    <option value="+234">🇳🇬 +234</option>
                                    <option value="+27">🇿🇦 +27</option>
                                    <option value="+254">🇰🇪 +254</option>
                                    <option value="+91">🇮🇳 +91</option>
                                    <option value="+86">🇨🇳 +86</option>
                                    <option value="+81">🇯🇵 +81</option>
                                    <option value="+82">🇰🇷 +82</option>
                                    <option value="+61">🇦🇺 +61</option>
                                    <option value="+65">🇸🇬 +65</option>
                                    <option value="+60">🇲🇾 +60</option>
                                    <option value="+971">🇦🇪 +971</option>
                                    <option value="+20">🇪🇬 +20</option>
                                    <option value="+212">🇲🇦 +212</option>
                                    <option value="+63">🇵🇭 +63</option>
                                    <option value="+62">🇮🇩 +62</option>
                                    <option value="+66">🇹🇭 +66</option>
                                    <option value="+49">🇩🇪 +49</option>
                                    <option value="+33">🇫🇷 +33</option>
                                    <option value="+39">🇮🇹 +39</option>
                                    <option value="+34">🇪🇸 +34</option>
                                    <option value="+31">🇳🇱 +31</option>
                                    <option value="+46">🇸🇪 +46</option>
                                    <option value="+47">🇳🇴 +47</option>
                                    <option value="+45">🇩🇰 +45</option>
                                    <option value="+353">🇮🇪 +353</option>
                                    <option value="+64">🇳🇿 +64</option>
                                    <option value="+358">🇫🇮 +358</option>
                                    <option value="+48">🇵🇱 +48</option>
                                    <option value="+52">🇲🇽 +52</option>
                                    <option value="+55">🇧🇷 +55</option>
                                    <option value="+54">🇦🇷 +54</option>
                                    <option value="+56">🇨🇱 +56</option>
                                    <option value="+57">🇨🇴 +57</option>
                                    <option value="+58">🇻🇪 +58</option>
                                    <option value="+351">🇵🇹 +351</option>
                                    <option value="+41">🇨🇭 +41</option>
                                    <option value="+43">🇦🇹 +43</option>
                                    <option value="+30">🇬🇷 +30</option>
                                    <option value="+90">🇹🇷 +90</option>
                                    <option value="+7">🇷🇺 +7</option>
                                    <option value="+84">🇻🇳 +84</option>
                                    <option value="+95">🇲🇲 +95</option>
                                    <option value="+880">🇧🇩 +880</option>
                                    <option value="+92">🇵🇰 +92</option>
                                    <option value="+94">🇱🇰 +94</option>
                                </select>
                                <input type="tel" name="phone_number" required 
                                       placeholder="241 234 567"
                                       class="flex-1 bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white">
                        </div>
                        <button type="submit" name="lookup" 
                                class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition">
                            Find Booking
                        </button>
                    </form>
                    <div class="text-center mt-4">
                        <a href="index.php" class="text-barber-gold hover:text-barber-gold-light text-sm">← Back to Booking</a>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
    // AJAX form handling for Vercel compatibility
    (function() {
        // Lookup form
        const lookupForm = document.getElementById('lookup-form');
        if (lookupForm) {
            lookupForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const btn = this.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Searching...';

                try {
                    const response = await fetch('/api/cancel-booking', {
                        method: 'POST',
                        body: formData,
                        headers: { 'Accept': 'application/json' }
                    });
                    const result = await response.json();

                    if (result.success && result.booking) {
                        location.reload();
                    } else {
                        alert(result.error || 'Booking not found');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                } catch (error) {
                    alert('Network error. Please try again.');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            });
        }

        // Cancel form
        const cancelForm = document.getElementById('cancel-form');
        if (cancelForm) {
            cancelForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const btn = this.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Cancelling...';

                try {
                    const response = await fetch('/api/cancel-booking', {
                        method: 'POST',
                        body: formData,
                        headers: { 'Accept': 'application/json' }
                    });
                    const result = await response.json();

                    if (result.success) {
                        alert(result.message || 'Booking cancelled successfully');
                        window.location.href = '/';
                    } else {
                        alert(result.error || 'Cancellation failed');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                } catch (error) {
                    alert('Network error. Please try again.');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            });
        }
    })();
    </script>
</body>
</html>

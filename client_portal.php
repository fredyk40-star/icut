<?php
require_once 'db.php';

$message = '';
$error = '';
$booking = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['lookup'])) {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = 'Invalid security token. Please try again.';
        } elseif (!checkRateLimit('client_lookup_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 300)) {
            $error = 'Too many lookup attempts. Please try again later.';
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
                // Compare with '#' stripped from BOTH sides: references are stored
                // as '#12345' but clients often type them without the hash.
                $reference = str_replace('#', '', $reference);
                $stmt = $db->prepare("
                    SELECT b.*, br.name as barber_name, s.name as service_name, s.price,
                           b.payment_status, b.paid_amount, b.refund_status, b.refund_amount, b.refunded_at
                    FROM bookings b
                    JOIN barbers br ON b.barber_id = br.id
                    JOIN services s ON b.service_id = s.id
                    WHERE REPLACE(b.booking_reference, '#', '') = :reference AND b.client_phone LIKE :phone
                    AND b.status IN ('pending', 'confirmed', 'cancelled')
                ");
                $stmt->execute([
                    ':reference' => $reference,
                    ':phone' => "%$phone%"
                ]);
                $booking = $stmt->fetch();
                
                if (!$booking) {
                    $error = 'No active booking found with that reference number and phone number';
                } else {
                    // Ownership proven via reference + phone, so release the
                    // token that authorises cancellation.
                    $booking['confirmation_token'] = ensureBookingConfirmationToken($booking['id']);
                }
            }
        }
    } elseif (isset($_POST['cancel'])) {
        // A sequential booking id is not proof of ownership: require the CSRF
        // token and the booking's confirmation_token, which is only released
        // after a successful reference + phone lookup.
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = 'Invalid security token. Please look up your booking again.';
        } elseif (!checkRateLimit('client_cancel_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 3, 300)) {
            $error = 'Too many cancellation attempts. Please try again later.';
        } else {
            $booking_id = (int)($_POST['booking_id'] ?? 0);
            $booking_data = getBookingForCancellation($booking_id, $_POST['confirmation_token'] ?? '');

            if (!$booking_data) {
                $error = 'We could not verify that booking. Please look it up again using your reference number and phone number.';
            } elseif ($booking_data['payment_status'] === 'success' && $booking_data['refund_status'] === 'none') {
                // Paid booking - mark as refund requested
                $stmt = $db->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW(), refund_status = 'requested' WHERE id = :id");
                $stmt->execute([':id' => $booking_id]);
                logAdminActivity('refund_requested', 'System', "Client requested cancellation with refund for booking #{$booking_data['booking_reference']}", $booking_id);
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
        }
        $booking = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Portal - icut</title>
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
                                600: '#404040',
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
            <h1 class="text-3xl font-bold text-white mb-2">Client Portal</h1>
            <p class="text-gray-400">View or cancel your booking</p>
        </div>
        
        <?php if ($message): ?>
            <div class="mb-6 bg-green-900/50 border border-green-700 text-green-300 px-6 py-4 rounded-lg">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="mb-6 bg-red-900/50 border border-red-700 text-red-300 px-6 py-4 rounded-lg">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
            <div class="bg-barber-800 rounded-2xl p-8 border border-barber-700">
                <?php if (!$booking): ?>
                    <form id="lookup-form" method="POST" action="" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Booking Reference *</label>
                        <input type="text" name="reference" required 
                               class="w-full bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white"
                               placeholder="e.g., #57368">
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Phone Number *</label>
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
                                   class="flex-1 bg-barber-700 border border-barber-600 rounded-lg px-4 py-3 text-white"
                                   placeholder="241 234 567">
                        </div>
                    </div>
                    <button type="submit" name="lookup" value="1"
                            class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 px-6 rounded-lg transition">
                        Find My Booking
                    </button>
                </form>
            <?php else: ?>
                    <div class="space-y-6">
                        <div class="bg-barber-700/50 rounded-xl p-6 border border-barber-700">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <p class="text-gray-400 text-sm">Booking Reference</p>
                                    <p class="text-barber-gold font-bold text-xl"><?php echo htmlspecialchars($booking['booking_reference'] ?? '#' . $booking['id']); ?></p>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-green-900/50 text-green-300 border border-green-700">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-gray-400 text-sm">Service</p>
                                    <p class="text-white font-semibold"><?php echo htmlspecialchars($booking['service_name']); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Barber</p>
                                    <p class="text-white font-semibold"><?php echo htmlspecialchars($booking['barber_name']); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Date</p>
                                    <p class="text-white font-semibold"><?php echo date('F j, Y', strtotime($booking['booking_date'])); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Time</p>
                                    <p class="text-white font-semibold"><?php echo date('g:i A', strtotime($booking['booking_time'])); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-400 text-sm">Price</p>
                                    <p class="text-barber-gold font-bold">₵<?php echo number_format($booking['price'], 2); ?></p>
                                </div>
                                <?php if ($booking['payment_status'] === 'success'): ?>
                                    <div>
                                        <p class="text-gray-400 text-sm">Payment</p>
                                        <p class="text-green-300 font-semibold">✅ Paid</p>
                                    </div>
                                <?php endif; ?>
                                <?php if ($booking['refund_status'] === 'requested'): ?>
                                    <div class="col-span-2">
                                        <div class="bg-orange-900/30 border border-orange-700 rounded-lg p-3">
                                            <p class="text-orange-300 text-sm font-semibold">💸 Refund Requested</p>
                                            <p class="text-gray-400 text-xs mt-1">Your refund of ₵<?php echo number_format($booking['paid_amount'], 2); ?> is being processed and will be completed within 3-10 business days.</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($booking['refund_status'] === 'processed'): ?>
                                    <div class="col-span-2">
                                        <div class="bg-green-900/30 border border-green-700 rounded-lg p-3">
                                            <p class="text-green-300 text-sm font-semibold">✅ Refund Processed</p>
                                            <p class="text-gray-400 text-xs mt-1">Your refund of ₵<?php echo number_format($booking['refund_amount'], 2); ?> has been processed on <?php echo date('F j, Y', strtotime($booking['refunded_at'])); ?>.</p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if ($booking['payment_status'] !== 'success' || $booking['refund_status'] === 'none'): ?>
                            <form id="cancel-form" method="POST" action="" onsubmit="return confirm('Are you sure you want to cancel this booking?');">
                                <input type="hidden" name="action" value="cancel_booking">
                                <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
                                <input type="hidden" name="confirmation_token" value="<?php echo htmlspecialchars($booking['confirmation_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken()); ?>">
                                <button type="submit" class="w-full bg-red-700 hover:bg-red-600 text-white font-bold py-3 px-6 rounded-lg transition">
                                    Cancel This Booking
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="bg-barber-700/50 border border-barber-700 rounded-lg p-4 text-center">
                                <p class="text-gray-300 text-sm">This booking has been paid. Cancellation requires a refund. Please contact the shop to process your cancellation.</p>
                            </div>
                        <?php endif; ?>
                    
                    <div class="text-center">
                        <a href="client_portal.php" class="text-barber-gold hover:text-barber-gold-light text-sm transition">
                            ← Look up another booking
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="text-center mt-8">
            <a href="index.php" class="text-gray-400 hover:text-white text-sm transition">
                ← Back to Booking Page
            </a>
        </div>
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
                    const response = await fetch('/api/client-portal', {
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
                        alert(result.message);
                        location.reload();
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

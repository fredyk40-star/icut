<?php
require_once 'admin_auth.php';

// Create table if not exists (and seed defaults)
ensureBusinessHoursTableExists();

// Update hours
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        for ($day = 0; $day < 7; $day++) {
            $is_closed = isset($_POST["closed_$day"]) ? 1 : 0;
            $open_time = $_POST["open_$day"] ?? '09:00';
            $close_time = $_POST["close_$day"] ?? '19:00';

            $stmt = $db->prepare("UPDATE business_hours SET open_time = ?, close_time = ?, is_closed = ? WHERE day_of_week = ?");
            $stmt->execute([$open_time, $close_time, $is_closed, $day]);
        }

        logAdminActivity('business_hours', $_SESSION['admin_name'] ?? 'Admin', 'Updated business hours');
        $success = "Business hours updated successfully!";
    }
}

$hours = $db->query("SELECT * FROM business_hours ORDER BY day_of_week")->fetchAll();
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Hours - Barbershop</title>
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
    <div class="max-w-2xl mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white">Business Hours</h1>
            <a href="admin.php" class="bg-barber-700 hover:bg-barber-600 text-white px-4 py-2 rounded-lg transition">
                Back to Dashboard
            </a>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg mb-6">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="bg-green-900/50 border border-green-700 text-green-300 px-4 py-3 rounded-lg mb-6">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <div class="bg-barber-800 rounded-xl p-4 md:p-6 border border-barber-700">
            <form method="POST" action="" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <?php foreach ($hours as $hour): ?>
                    <div class="flex flex-col md:flex-row md:items-center gap-3 md:gap-4 p-3 md:p-4 bg-barber-700 rounded-lg">
                        <div class="md:w-32">
                            <span class="text-white font-semibold text-sm md:text-base"><?php echo $days[$hour['day_of_week']]; ?></span>
                        </div>
                        
                        <label class="flex items-center space-x-2 text-gray-300 text-sm">
                            <input type="checkbox" name="closed_<?php echo $hour['day_of_week']; ?>" 
                                   <?php echo $hour['is_closed'] ? 'checked' : ''; ?>
                                   onchange="toggleHours(<?php echo $hour['day_of_week']; ?>)">
                            <span>Closed</span>
                        </label>
                        
                        <div id="hours_<?php echo $hour['day_of_week']; ?>" 
                             class="flex flex-col sm:flex-row sm:items-center gap-2 <?php echo $hour['is_closed'] ? 'hidden' : ''; ?>">
                            <input type="time" name="open_<?php echo $hour['day_of_week']; ?>" 
                                   value="<?php echo $hour['open_time']; ?>"
                                   class="bg-barber-600 border border-barber-600 rounded px-3 py-2 text-white text-sm w-full sm:w-auto">
                            <span class="text-gray-400 text-sm hidden sm:inline">to</span>
                            <input type="time" name="close_<?php echo $hour['day_of_week']; ?>" 
                                   value="<?php echo $hour['close_time']; ?>"
                                   class="bg-barber-600 border border-barber-600 rounded px-3 py-2 text-white text-sm w-full sm:w-auto">
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <button type="submit" 
                        class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 font-bold py-3 rounded-lg transition mt-4">
                    Save Changes
                </button>
            </form>
        </div>
    </div>
    
    <script>
        function toggleHours(day) {
            const hoursDiv = document.getElementById('hours_' + day);
            hoursDiv.classList.toggle('hidden');
        }
    </script>
</body>
</html>
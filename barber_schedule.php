<?php
require_once 'admin_auth.php';

// Handle schedule updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token.');
    }
    
    $barber_id = (int)$_POST['barber_id'];
    $day_of_week = (int)$_POST['day_of_week'];
    $start_time = $_POST['start_time'] ?? null;
    $end_time = $_POST['end_time'] ?? null;
    $is_working = isset($_POST['is_working']) ? 1 : 0;
    
    // First, check if schedule exists
    $check = $db->prepare("SELECT id FROM barber_schedules WHERE barber_id = :barber_id AND day_of_week = :day_of_week");
    $check->execute([':barber_id' => $barber_id, ':day_of_week' => $day_of_week]);
    
    if ($check->fetch()) {
        // Update existing
        $stmt = $db->prepare("
            UPDATE barber_schedules 
            SET start_time = :start_time, end_time = :end_time, is_working = :is_working 
            WHERE barber_id = :barber_id AND day_of_week = :day_of_week
        ");
    } else {
        // Insert new
        $stmt = $db->prepare("
            INSERT INTO barber_schedules (barber_id, day_of_week, start_time, end_time, is_working)
            VALUES (:barber_id, :day_of_week, :start_time, :end_time, :is_working)
        ");
    }
    
    $stmt->execute([
        ':barber_id' => $barber_id,
        ':day_of_week' => $day_of_week,
        ':start_time' => $is_working ? $start_time : null,
        ':end_time' => $is_working ? $end_time : null,
        ':is_working' => $is_working
    ]);
}

// Create table if it doesn't exist
$db->exec("
    CREATE TABLE IF NOT EXISTS barber_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barber_id INT NOT NULL,
        day_of_week INT NOT NULL,
        start_time TIME,
        end_time TIME,
        is_working INT DEFAULT 1,
        FOREIGN KEY (barber_id) REFERENCES barbers(id) ON DELETE CASCADE,
        UNIQUE(barber_id, day_of_week)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Get all barbers
$barbers = $db->query("SELECT * FROM barbers WHERE is_active = 1")->fetchAll();

// Get schedules
$schedules = [];
$schedule_query = $db->query("
    SELECT bs.*, b.name as barber_name 
    FROM barber_schedules bs 
    JOIN barbers b ON bs.barber_id = b.id
");
while ($row = $schedule_query->fetch()) {
    $schedules[$row['barber_id']][$row['day_of_week']] = $row;
}

$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barber Schedules - Barbershop</title>
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
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white">Barber Schedules</h1>
            <a href="admin.php" class="bg-barber-700 hover:bg-barber-600 text-white px-4 py-2 rounded-lg transition">
                Back to Dashboard
            </a>
        </div>
        
        <?php foreach ($barbers as $barber): ?>
            <div class="bg-barber-800 rounded-xl p-6 mb-6 border border-barber-700">
                <h2 class="text-xl font-semibold text-white mb-4"><?php echo htmlspecialchars($barber['name']); ?></h2>
                
                <div class="grid grid-cols-1 md:grid-cols-7 gap-4">
                    <?php for ($day = 0; $day < 7; $day++): 
                        $schedule = $schedules[$barber['id']][$day] ?? null;
                        $is_working = $schedule ? $schedule['is_working'] : ($day != 0); // Default off on Sunday
                    ?>
                        <div class="bg-barber-700 rounded-lg p-4">
                            <h3 class="text-white font-semibold mb-2"><?php echo $days[$day]; ?></h3>
                    <form method="POST" action="" class="space-y-2">
                        <input type="hidden" name="barber_id" value="<?php echo $barber['id']; ?>">
                        <input type="hidden" name="day_of_week" value="<?php echo $day; ?>">
                        <input type="hidden" name="update_schedule" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                
                                <label class="flex items-center space-x-2 text-gray-300 text-sm">
                                    <input type="checkbox" name="is_working" <?php echo $is_working ? 'checked' : ''; ?> 
                                           onchange="this.form.submit()" class="text-barber-gold">
                                    <span>Working</span>
                                </label>
                                
                                <?php if ($is_working): ?>
                                    <input type="time" name="start_time" 
                                           value="<?php echo $schedule['start_time'] ?? '09:00'; ?>"
                                           class="w-full bg-barber-600 border border-barber-600 rounded px-2 py-1 text-red text-sm">
                                    <span class="text-gray-400 text-xs">to</span>
                                    <input type="time" name="end_time" 
                                           value="<?php echo $schedule['end_time'] ?? '17:00'; ?>"
                                           class="w-full bg-barber-600 border border-barber-600 rounded px-2 py-1 text-red text-sm">
                                    <button type="submit" class="w-full bg-barber-gold hover:bg-barber-gold-light text-barber-900 text-xs font-bold py-1 rounded">
                                        Save
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
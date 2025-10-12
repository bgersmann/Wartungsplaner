<?php

declare(strict_types=1);

// Hole die Instanz-ID aus der URL
$instanceId = (int)$_GET['InstanceID'];

// Lade Wartungsaufgaben
$taskListJson = WP_GetTaskList($instanceId);
$tasks = json_decode($taskListJson, true);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wartungsplaner</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h1 {
            color: white;
            text-align: center;
            margin-bottom: 30px;
            font-size: 2.5em;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .task-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .task-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .task-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.3);
        }

        .task-card.due {
            border-left: 5px solid #e74c3c;
        }

        .task-card.upcoming {
            border-left: 5px solid #f39c12;
        }

        .task-card.future {
            border-left: 5px solid #27ae60;
        }

        .task-status {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: bold;
            color: white;
        }

        .status-due {
            background: #e74c3c;
        }

        .status-upcoming {
            background: #f39c12;
        }

        .status-future {
            background: #27ae60;
        }

        .task-title {
            font-size: 1.5em;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-right: 100px;
        }

        .task-description {
            color: #7f8c8d;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .task-info {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .info-label {
            font-weight: bold;
            color: #34495e;
        }

        .info-value {
            color: #7f8c8d;
        }

        .complete-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }

        .complete-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }

        .complete-btn:active {
            transform: scale(0.98);
        }

        .no-tasks {
            text-align: center;
            color: white;
            font-size: 1.5em;
            padding: 50px;
            background: rgba(255,255,255,0.1);
            border-radius: 15px;
        }

        .refresh-info {
            text-align: center;
            color: white;
            margin-top: 20px;
            font-size: 0.9em;
            opacity: 0.8;
        }

        @media (max-width: 768px) {
            .task-grid {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 2em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Wartungsplaner</h1>
        
        <?php if (empty($tasks)): ?>
            <div class="no-tasks">
                Keine Wartungsaufgaben vorhanden.<br>
                Bitte fügen Sie Aufgaben in der Modulkonfiguration hinzu.
            </div>
        <?php else: ?>
            <div class="task-grid">
                <?php foreach ($tasks as $task): 
                    $daysUntilDue = $task['daysUntilDue'];
                    
                    if ($task['isDue']) {
                        $statusClass = 'due';
                        $statusLabel = 'Fällig';
                        $statusBadge = 'status-due';
                    } elseif ($daysUntilDue <= 7) {
                        $statusClass = 'upcoming';
                        $statusLabel = 'Bald fällig';
                        $statusBadge = 'status-upcoming';
                    } else {
                        $statusClass = 'future';
                        $statusLabel = 'Geplant';
                        $statusBadge = 'status-future';
                    }
                    
                    $dueDateFormatted = date('d.m.Y', $task['nextDueDate']);
                ?>
                    <div class="task-card <?php echo $statusClass; ?>">
                        <div class="task-status <?php echo $statusBadge; ?>">
                            <?php echo $statusLabel; ?>
                        </div>
                        
                        <div class="task-title">
                            <?php echo htmlspecialchars($task['title']); ?>
                        </div>
                        
                        <div class="task-description">
                            <?php echo htmlspecialchars($task['description']); ?>
                        </div>
                        
                        <div class="task-info">
                            <div class="info-row">
                                <span class="info-label">📅 Fälligkeitsdatum:</span>
                                <span class="info-value"><?php echo $dueDateFormatted; ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">🔄 Intervall:</span>
                                <span class="info-value"><?php echo htmlspecialchars($task['interval']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">⏱️ Verbleibende Tage:</span>
                                <span class="info-value">
                                    <?php 
                                    if ($task['isDue']) {
                                        echo '<strong style="color: #e74c3c;">ÜBERFÄLLIG</strong>';
                                    } else {
                                        echo $daysUntilDue . ' Tage';
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($task['isDue']): ?>
                            <button class="complete-btn" onclick="completeTask('<?php echo $task['id']; ?>')">
                                ✓ Erledigt
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="refresh-info">
            Die Seite wird automatisch aktualisiert nach dem Abschließen einer Aufgabe
        </div>
    </div>

    <script>
        function completeTask(taskId) {
            // Rufe die PHP-Funktion auf, um die Aufgabe als erledigt zu markieren
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=complete&taskId=' + encodeURIComponent(taskId) + '&instanceId=<?php echo $instanceId; ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Erfolgsmeldung anzeigen
                    alert('Aufgabe wurde erfolgreich als erledigt markiert!\nNeues Fälligkeitsdatum wurde berechnet.');
                    // Seite neu laden
                    window.location.reload();
                } else {
                    alert('Fehler beim Abschließen der Aufgabe.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Fehler bei der Kommunikation mit dem Server.');
            });
        }
    </script>
</body>
</html>

<?php
// Behandle POST-Requests zum Abschließen von Aufgaben
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete') {
    $taskId = $_POST['taskId'];
    $instanceId = (int)$_POST['instanceId'];
    
    $result = WP_CompleteTask($instanceId, $taskId);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => $result]);
    exit;
}
?>

<?php

declare(strict_types=1);

class Wartungsplaner extends IPSModule
{
    public function Create()
    {
        // Diese Zeile nicht löschen
        parent::Create();

        // Eigenschaften
        $this->RegisterPropertyString('Tasks', '[]');
        $this->RegisterPropertyInteger('NotificationTime', 9); // Uhrzeit für Benachrichtigung (9 Uhr)
        $this->RegisterPropertyInteger('WebFrontID', 0); // WebFront ID für Benachrichtigungen
        
        // Timer für tägliche Benachrichtigung
        $this->RegisterTimer('DailyNotification', 0, 'WP_CheckDueTasks($_IPS[\'TARGET\']);');
        
        // Attribute für persistente Daten
        $this->RegisterAttributeString('TaskStates', '[]');
        
        // HTML SDK aktivieren
        $this->SetVisualizationType(1); // 1 = HTML
    }

    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        // Timer für tägliche Benachrichtigung einrichten
        $this->SetDailyTimer();
        
        // Task-States neu berechnen wenn sich Aufgaben geändert haben
        $this->RecalculateTaskStates();
        
        // Visualisierung aktualisieren
        $this->UpdateVisualizationValue(json_encode(['action' => 'refresh']));
    }
    
    private function RecalculateTaskStates()
    {
        $tasks = json_decode($this->ReadPropertyString('Tasks'), true);
        $taskStates = json_decode($this->ReadAttributeString('TaskStates'), true);
        
        if (empty($tasks)) {
            return;
        }
        
        $today = time();
        $updated = false;
        
        foreach ($tasks as $index => $task) {
            $taskId = 'task_' . $index;
            
            // Unterstütze beide Formate: dd.mm.yyyy und yyyy-mm-dd
            if (isset($task['StartDate']) && !empty($task['StartDate'])) {
                $startDateStr = $task['StartDate'];
                // Prüfe Format und konvertiere
                if (strpos($startDateStr, '.') !== false) {
                    // Deutsches Format: dd.mm.yyyy
                    $parts = explode('.', $startDateStr);
                    if (count($parts) == 3) {
                        $newStartDate = mktime(0, 0, 0, (int)$parts[1], (int)$parts[0], (int)$parts[2]);
                    } else {
                        $newStartDate = $today;
                    }
                } else {
                    // ISO Format: yyyy-mm-dd
                    $newStartDate = strtotime($startDateStr);
                    if ($newStartDate === false) {
                        $newStartDate = $today;
                    }
                }
            } else {
                $newStartDate = $today;
            }
            
            // Wenn Task neu ist oder Startdatum sich geändert hat, neu berechnen
            if (!isset($taskStates[$taskId]) || $taskStates[$taskId]['startDate'] != $newStartDate) {
                $taskStates[$taskId] = [
                    'startDate' => $newStartDate,
                    'nextDueDate' => $this->CalculateNextDueDate($newStartDate, $task['Interval']),
                    'completed' => false
                ];
                $updated = true;
            }
        }
        
        if ($updated) {
            $this->WriteAttributeString('TaskStates', json_encode($taskStates));
        }
    }

    private function SetDailyTimer()
    {
        $hour = $this->ReadPropertyInteger('NotificationTime');
        
        // Berechne Zeit bis zur nächsten Benachrichtigung
        $now = time();
        $notificationTime = mktime($hour, 0, 0);
        
        if ($notificationTime <= $now) {
            // Wenn die Zeit heute schon vorbei ist, plane für morgen
            $notificationTime = strtotime('+1 day', $notificationTime);
        }
        
        $interval = $notificationTime - $now;
        $this->SetTimerInterval('DailyNotification', $interval * 1000);
    }

    public function CheckDueTasks()
    {
        $tasks = json_decode($this->ReadPropertyString('Tasks'), true);
        $taskStates = json_decode($this->ReadAttributeString('TaskStates'), true);
        
        if (empty($tasks)) {
            return;
        }

        $today = time();
        $notificationsSent = 0;

        foreach ($tasks as $index => $task) {
            $taskId = 'task_' . $index;
            
            // Prüfe ob Task-Status existiert, sonst initialisiere
            if (!isset($taskStates[$taskId])) {
                $taskStates[$taskId] = [
                    'startDate' => isset($task['StartDate']) ? strtotime($task['StartDate']) : $today,
                    'nextDueDate' => $this->CalculateNextDueDate(
                        isset($task['StartDate']) ? strtotime($task['StartDate']) : $today,
                        $task['Interval']
                    ),
                    'completed' => false
                ];
            }

            // Prüfe ob Aufgabe fällig ist - sende täglich Benachrichtigung bis erledigt
            if ($taskStates[$taskId]['nextDueDate'] <= $today && !$taskStates[$taskId]['completed']) {
                // Sende Benachrichtigung
                $this->SendNotification($task['Title'], $task['Description'], $taskId);
                $notificationsSent++;
            }
        }

        // Speichere aktualisierte Task-States
        $this->WriteAttributeString('TaskStates', json_encode($taskStates));

        // Setze Timer für morgen neu
        $this->SetTimerInterval('DailyNotification', 24 * 60 * 60 * 1000);

        // Visualisierung aktualisieren wenn Benachrichtigungen gesendet wurden
        if ($notificationsSent > 0) {
            $this->UpdateVisualizationValue(json_encode(['action' => 'notificationSent']));
        }

        return $notificationsSent;
    }

    private function SendNotification(string $title, string $description, string $taskId)
    {
        $webFrontID = $this->ReadPropertyInteger('WebFrontID');
        
        // Sende Benachrichtigung über WebFront
        if (IPS_FunctionExists('VISU_PostNotificationEx') && $webFrontID > 0) {
            VISU_PostNotificationEx(
                $webFrontID,
                $title,
                $description,
                'Hammer',
                'buzzer',
                0
            );
        }
    }

    private function CalculateNextDueDate(int $startDate, string $interval): int
    {
        $now = time();
        $current = $startDate;

        // Wenn Startdatum in der Zukunft liegt, verwende es
        if ($startDate > $now) {
            return $startDate;
        }

        // Berechne nächstes Fälligkeitsdatum basierend auf Intervall
        switch ($interval) {
            case 'Wöchentlich':
            case 'Weekly':
                $intervalDays = 7;
                break;
            case 'Monatlich':
            case 'Monthly':
                // Monatlich: addiere Monate bis das Datum in der Zukunft liegt
                while ($current <= $now) {
                    $current = strtotime('+1 month', $current);
                }
                return $current;
            case 'Quartalsweise':
            case 'Quarterly':
                // Quartalsweise: addiere 3 Monate
                while ($current <= $now) {
                    $current = strtotime('+3 months', $current);
                }
                return $current;
            case 'Halbjährlich':
            case 'Semi-annually':
                // Halbjährlich: addiere 6 Monate
                while ($current <= $now) {
                    $current = strtotime('+6 months', $current);
                }
                return $current;
            case 'Jährlich':
            case 'Annually':
                // Jährlich: addiere Jahre
                while ($current <= $now) {
                    $current = strtotime('+1 year', $current);
                }
                return $current;
            default:
                // Standard: Monatlich falls unbekanntes Intervall
                while ($current <= $now) {
                    $current = strtotime('+1 month', $current);
                }
                return $current;
        }

        // Für tages-basierte Intervalle
        if (isset($intervalDays)) {
            while ($current <= $now) {
                $current = strtotime('+' . $intervalDays . ' days', $current);
            }
        }

        return $current;
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'CompleteTask':
                $this->CompleteTaskAction($Value);
                break;
            default:
                throw new Exception('Invalid ident');
        }
    }

    private function CompleteTaskAction(string $taskId)
    {
        $tasks = json_decode($this->ReadPropertyString('Tasks'), true);
        $taskStates = json_decode($this->ReadAttributeString('TaskStates'), true);

        if (!isset($taskStates[$taskId])) {
            return;
        }

        // Hole Task-Index aus ID
        $index = (int)str_replace('task_', '', $taskId);
        
        if (!isset($tasks[$index])) {
            return;
        }

        $task = $tasks[$index];
        
        // Berechne neues Fälligkeitsdatum vom Startdatum aus
        $taskStates[$taskId]['nextDueDate'] = $this->CalculateNextDueDate(
            $taskStates[$taskId]['startDate'],
            $task['Interval']
        );
        $taskStates[$taskId]['completed'] = false;

        // Speichere aktualisierte Task-States
        $this->WriteAttributeString('TaskStates', json_encode($taskStates));

        // Visualisierung aktualisieren
        $this->UpdateVisualizationValue(json_encode([
            'action' => 'taskCompleted',
            'taskId' => $taskId
        ]));
    }

    public function CompleteTask(string $taskId)
    {
        $this->CompleteTaskAction($taskId);
        return true;
    }

    public function GetTaskList()
    {
        $tasks = json_decode($this->ReadPropertyString('Tasks'), true);
        $taskStates = json_decode($this->ReadAttributeString('TaskStates'), true);
        
        if (empty($tasks)) {
            return json_encode([]);
        }

        $taskList = [];
        $today = time();

        foreach ($tasks as $index => $task) {
            $taskId = 'task_' . $index;
            
            // Initialisiere Task-Status falls nicht vorhanden
            if (!isset($taskStates[$taskId])) {
                $taskStates[$taskId] = [
                    'startDate' => isset($task['StartDate']) ? strtotime($task['StartDate']) : $today,
                    'nextDueDate' => $this->CalculateNextDueDate(
                        isset($task['StartDate']) ? strtotime($task['StartDate']) : $today,
                        $task['Interval']
                    ),
                    'completed' => false
                ];
            }

            $taskList[] = [
                'id' => $taskId,
                'title' => $task['Title'],
                'description' => $task['Description'],
                'interval' => $task['Interval'],
                'nextDueDate' => $taskStates[$taskId]['nextDueDate'],
                'isDue' => $taskStates[$taskId]['nextDueDate'] <= $today,
                'daysUntilDue' => (int)(($taskStates[$taskId]['nextDueDate'] - $today) / 86400)
            ];
        }

        // Sortiere nach nächster Fälligkeit (fällige zuerst, dann nach Datum)
        usort($taskList, function($a, $b) {
            // Fällige Aufgaben haben Priorität
            if ($a['isDue'] && !$b['isDue']) {
                return -1;
            }
            if (!$a['isDue'] && $b['isDue']) {
                return 1;
            }
            
            // Wenn beide fällig oder beide nicht fällig, sortiere nach Datum
            return $a['nextDueDate'] - $b['nextDueDate'];
        });

        return json_encode($taskList);
    }

    public function GetVisualizationTile()
    {
        $data = $this->GetTaskData();
        
        return $this->GenerateHTML($data);
    }

    private function GetTaskData(): array
    {
        $tasks = json_decode($this->ReadPropertyString('Tasks'), true);
        $taskStates = json_decode($this->ReadAttributeString('TaskStates'), true);
        
        if (empty($tasks)) {
            return [];
        }

        $today = time();
        $taskList = [];

        foreach ($tasks as $index => $task) {
            $taskId = 'task_' . $index;
            
            // Initialisiere Task-Status falls nicht vorhanden
            if (!isset($taskStates[$taskId])) {
                $taskStates[$taskId] = [
                    'startDate' => isset($task['StartDate']) ? strtotime($task['StartDate']) : $today,
                    'nextDueDate' => $this->CalculateNextDueDate(
                        isset($task['StartDate']) ? strtotime($task['StartDate']) : $today,
                        $task['Interval']
                    ),
                    'completed' => false
                ];
                // Speichere initialisierte States
                $this->WriteAttributeString('TaskStates', json_encode($taskStates));
            }

            $daysUntilDue = (int)(($taskStates[$taskId]['nextDueDate'] - $today) / 86400);
            $isDue = $taskStates[$taskId]['nextDueDate'] <= $today;
            
            $taskList[] = [
                'id' => $taskId,
                'title' => $task['Title'],
                'description' => $task['Description'],
                'interval' => $task['Interval'],
                'nextDueDate' => $taskStates[$taskId]['nextDueDate'],
                'isDue' => $isDue,
                'daysUntilDue' => $daysUntilDue
            ];
        }

        // Sortiere nach nächster Fälligkeit (fällige zuerst, dann nach Datum)
        usort($taskList, function($a, $b) {
            // Fällige Aufgaben haben Priorität
            if ($a['isDue'] && !$b['isDue']) {
                return -1;
            }
            if (!$a['isDue'] && $b['isDue']) {
                return 1;
            }
            
            // Wenn beide fällig oder beide nicht fällig, sortiere nach Datum
            return $a['nextDueDate'] - $b['nextDueDate'];
        });

        return $taskList;
    }

    private function GenerateHTML(array $tasks): string
    {
        if (empty($tasks)) {
            return $this->GenerateEmptyHTML();
        }

        $taskRows = '';
        
        foreach ($tasks as $task) {
            $daysUntilDue = $task['daysUntilDue'];
            $isDue = $task['isDue'];
            
            $dueDateFormatted = date('d.m.Y', $task['nextDueDate']);
            $title = htmlspecialchars($task['title']);
            $description = htmlspecialchars($task['description']);
            $interval = htmlspecialchars($task['interval']);
            $taskId = $task['id'];
            
            // Berechne Badge-Farbe basierend auf Tagen
            if ($isDue || $daysUntilDue < 0) {
                $badgeClass = 'badge-red';
                $daysText = abs($daysUntilDue) . ' <script>document.write(translate(\'Days\'));</script>';
            } elseif ($daysUntilDue <= 7) {
                $badgeClass = 'badge-orange';
                $daysText = $daysUntilDue . ' <script>document.write(translate(\'Days\'));</script>';
            } else {
                $badgeClass = 'badge-green';
                $daysText = $daysUntilDue . ' <script>document.write(translate(\'Days\'));</script>';
            }
            
            // Icon nur für fällige Aufgaben
            $actionIcon = $isDue ? 
                "<i class='fas fa-check action-icon' onclick=\"completeTask('$taskId')\"></i>" : 
                '';
            
            $taskRows .= <<<HTML
                <tr>
                    <td class="task-title-cell">$title</td>
                    <td class="task-description-cell">$description</td>
                    <td class="task-interval-cell">$interval</td>
                    <td class="task-date-cell">$dueDateFormatted</td>
                    <td class="task-badge-cell"><span class="badge $badgeClass">$daysText</span></td>
                    <td class="task-action-cell">$actionIcon</td>
                </tr>
HTML;
        }
        
        return <<<HTML
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        background: #2b2d31;
        padding: 0;
        color: #dcddde;
    }
    .container { 
        max-width: 100%; 
        margin: 0 auto;
        background: #2b2d31;
    }
    .header {
        display: flex;
        align-items: center;
        padding: 16px 20px;
        background: #1e1f22;
        border-bottom: 1px solid #3f4147;
        gap: 12px;
    }
    .header-icon {
        font-size: 24px;
        color: #dcddde;
    }
    .header-title {
        font-size: 18px;
        font-weight: 600;
        color: #dcddde;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        background: #2b2d31;
    }
    thead {
        background: #1e1f22;
        border-bottom: 1px solid #3f4147;
    }
    th {
        padding: 12px 20px;
        text-align: left;
        font-size: 13px;
        font-weight: 600;
        color: #b5bac1;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    tbody tr {
        border-bottom: 1px solid #3f4147;
    }
    tbody tr:last-child {
        border-bottom: none;
    }
    td {
        padding: 14px 20px;
        font-size: 15px;
    }
    tr:hover td {
        background: #32353b;
    }
    .task-title-cell {
        color: #dcddde;
        align-items: center;
        gap: 12px;
        min-width: 200px;
    }
    .task-title-cell i {
        display: inline-block;
        vertical-align: middle;
    }
    .task-description-cell {
        color: #b5bac1;
        min-width: 250px;
    }
    .task-interval-cell {
        color: #b5bac1;
        min-width: 120px;
    }
    .task-date-cell {
        color: #b5bac1;
        min-width: 120px;
    }
    .task-badge-cell {
        text-align: right;
        min-width: 100px;
    }
    .task-action-cell {
        text-align: center;
        min-width: 60px;
        width: 60px;
    }
    .badge {
        display: inline-block;
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        color: white;
    }
    .badge-green {
        background: #43b581;
    }
    .badge-orange {
        background: #faa61a;
    }
    .badge-red {
        background: #f04747;
    }
    .action-icon {
        font-size: 16px;
        cursor: pointer;
        transition: all 0.2s ease;
        padding: 6px;
        border-radius: 4px;
    }
    .action-icon:hover {
        background: #3f4147;
    }
    .fa-check {
        color: #43b581;
    }
</style>

<div class="container">
    <table id="taskTable">
        <thead>
            <tr>
                <th><script>document.write(translate('Title'));</script></th>
                <th><script>document.write(translate('Description'));</script></th>
                <th><script>document.write(translate('Interval'));</script></th>
                <th><script>document.write(translate('Due Date'));</script></th>
                <th><script>document.write(translate('Status'));</script></th>
                <th><script>document.write(translate('Action'));</script></th>
            </tr>
        </thead>
        <tbody>
            $taskRows
        </tbody>
    </table>
</div>

<script>
    function handleMessage(data) {
        // Nachricht vom Modul empfangen - Visualisierung aktualisieren
        try {
            var message = JSON.parse(data);
            console.log('Wartungsplaner: Nachricht empfangen', message);
            
            // Bei jeder Änderung die Seite neu laden
            if (message.action === 'refresh' || 
                message.action === 'taskCompleted' || 
                message.action === 'notificationSent') {
                
                // Kleine Verzögerung für bessere UX
                setTimeout(function() {
                    location.reload();
                }, 300);
            }
        } catch (e) {
            console.error('Wartungsplaner: Fehler beim Parsen der Nachricht', e);
            location.reload();
        }
    }
    
    function completeTask(taskId) {
        // Sende Aktion an Modul
        requestAction('CompleteTask', taskId);
        
        // Optional: Visuelles Feedback
        var icon = event.target;
        if (icon) {
            icon.style.color = '#43b581';
            icon.style.transform = 'scale(1.2)';
        }
    }
</script>
HTML;
    }

    private function GenerateEmptyHTML(): string
    {
        return <<<HTML
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #2b2d31;
        padding: 0;
        color: #dcddde;
    }
    .no-tasks {
        text-align: center;
        color: #b5bac1;
        font-size: 15px;
        padding: 60px 20px;
        background: #2b2d31;
    }
</style>

<div class="no-tasks">
    <script>
        document.write(translate('No maintenance tasks available.') + '<br>');
        document.write(translate('Please add tasks in the module configuration.'));
    </script>
</div>
HTML;
    }

    public function GetConfigurationForm()
    {
        $form = [
            'elements' => [
                [
                    'type' => 'Label',
                    'label' => 'Maintenance Planner - Management of maintenance tasks'
                ],
                [
                    'type' => 'NumberSpinner',
                    'name' => 'NotificationTime',
                    'caption' => 'Notification Time (Hour)',
                    'minimum' => 0,
                    'maximum' => 23,
                    'suffix' => 'hour'
                ],
                [
                    'type' => 'SelectInstance',
                    'name' => 'WebFrontID',
                    'caption' => 'WebFront for Notifications',
                    'moduleID' => '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}' // WebFront Module GUID
                ],
                [
                    'type' => 'List',
                    'name' => 'Tasks',
                    'caption' => 'Maintenance Tasks',
                    'add' => true,
                    'delete' => true,
                    'columns' => [
                        [
                            'name' => 'Title',
                            'caption' => 'Title',
                            'width' => '200px',
                            'add' => 'New Maintenance Task',
                            'edit' => [
                                'type' => 'ValidationTextBox'
                            ]
                        ],
                        [
                            'name' => 'Description',
                            'caption' => 'Description',
                            'width' => '300px',
                            'add' => 'Description of the maintenance task',
                            'edit' => [
                                'type' => 'ValidationTextBox'
                            ]
                        ],
                        [
                            'name' => 'Interval',
                            'caption' => 'Interval',
                            'width' => '150px',
                            'add' => 'Monthly',
                            'edit' => [
                                'type' => 'Select',
                                'options' => [
                                    [
                                        'caption' => 'Weekly',
                                        'value' => 'Weekly'
                                    ],
                                    [
                                        'caption' => 'Monthly',
                                        'value' => 'Monthly'
                                    ],
                                    [
                                        'caption' => 'Quarterly',
                                        'value' => 'Quarterly'
                                    ],
                                    [
                                        'caption' => 'Semi-annually',
                                        'value' => 'Semi-annually'
                                    ],
                                    [
                                        'caption' => 'Annually',
                                        'value' => 'Annually'
                                    ]
                                ]
                            ]
                        ],
                        [
                            'name' => 'StartDate',
                            'caption' => 'Start Date',
                            'width' => '150px',
                            'add' => date('d.m.Y'),
                            'edit' => [
                                'type' => 'ValidationTextBox'
                            ]
                        ]
                    ]
                ]
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'label' => 'Check due tasks now',
                    'onClick' => 'WP_CheckDueTasks($id);'
                ],
                [
                    'type' => 'Label',
                    'label' => 'Note: Enter start date in format DD.MM.YYYY (e.g. 10.10.2025)'
                ]
            ]
        ];

        return json_encode($form);
    }
}

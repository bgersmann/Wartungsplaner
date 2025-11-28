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
                'screwdriver-wrench',
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

        $enrichedTasks = [];
        foreach ($tasks as $task) {
            $daysUntilDue = $task['daysUntilDue'];
            $isDue = $task['isDue'];
            $dueDateFormatted = date('d.m.Y', $task['nextDueDate']);
            $title = htmlspecialchars($task['title']);
            $description = htmlspecialchars($task['description']);
            $interval = htmlspecialchars($task['interval']);

            if ($isDue || $daysUntilDue < 0) {
                $iconClass = 'icon--red';
                $badgeClass = 'red';
                $statusLabel = 'Überfällig';
                $badgeText = $daysUntilDue < 0
                    ? 'vor ' . abs($daysUntilDue) . ' Tagen'
                    : 'Heute fällig';
            } elseif ($daysUntilDue <= 7) {
                $iconClass = 'icon--yellow';
                $badgeClass = 'yellow';
                $statusLabel = 'Bald fällig';
                $badgeText = 'in ' . $daysUntilDue . ' Tagen';
            } else {
                $iconClass = 'icon--green';
                $badgeClass = 'green';
                $statusLabel = 'Geplant';
                $badgeText = 'in ' . $daysUntilDue . ' Tagen';
            }

            if (!$isDue && $daysUntilDue >= 30) {
                $iconClass = 'icon--blue';
            }

            $enrichedTasks[] = array_merge($task, [
                'titleSafe' => $title,
                'descriptionSafe' => $description,
                'intervalSafe' => $interval,
                'dueDateFormatted' => $dueDateFormatted,
                'iconClass' => $iconClass,
                'badgeClass' => $badgeClass,
                'statusLabel' => $statusLabel,
                'badgeText' => $badgeText
            ]);
        }

        $tasks = $enrichedTasks;
        $primaryTask = $tasks[0];
        $primaryDaysText = $primaryTask['isDue']
            ? ($primaryTask['daysUntilDue'] < 0
                ? 'Überfällig seit ' . abs($primaryTask['daysUntilDue']) . ' Tagen'
                : 'Heute fällig')
            : 'In ' . $primaryTask['daysUntilDue'] . ' Tagen';
        $primaryButton = $primaryTask['isDue']
            ? "<button class=\"complete-btn\" onclick=\"completeTask('{$primaryTask['id']}')\">✓ Erledigt</button>"
            : '';

        $iconPreview = '';
        $iconLimit = min(4, count($tasks));
        for ($i = 0; $i < $iconLimit; $i++) {
            $iconPreview .= "<svg class=\"icon {$tasks[$i]['iconClass']}\" aria-hidden=\"true\"><use xlink:href=\"#icon-maintenance\" href=\"#icon-maintenance\" /></svg>";
        }

        $cardMList = '';
        foreach ($tasks as $task) {
            $cardMList .= <<<HTML
                <div class="task-pill {$task['badgeClass']}">
                    <span class="pill-title">{$task['titleSafe']}</span>
                    <span class="pill-date">{$task['dueDateFormatted']}</span>
                </div>
HTML;
        }

        $tableRows = '';
        foreach ($tasks as $task) {
            $badgeClass = $task['badgeClass'];
            $badgeText = $task['badgeText'];
            $badgeAttr = $task['isDue']
                ? "class=\"badge $badgeClass badge-action\" onclick=\"completeTask('{$task['id']}')\""
                : "class=\"badge $badgeClass\"";

            $tableRows .= <<<HTML
                <tr>
                    <td>
                        <div class="task-name">{$task['titleSafe']}</div>
                        <div class="task-description">{$task['descriptionSafe']}</div>
                    </td>
                    <td>
                        <div class="date-line">{$task['dueDateFormatted']}</div>
                        <div class="interval-line">{$task['intervalSafe']}</div>
                    </td>
                    <td><div $badgeAttr>$badgeText</div></td>
                </tr>
HTML;
        }

        return <<<HTML
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
    .hidden { width: 0; height: 0; position: absolute; }
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: transparent; border-radius: 20px; }
    ::-webkit-scrollbar-thumb:hover { background: #555; }
    .cardM { display: block; padding: 16px; }
    .cardL { display: none; padding: 16px; }
    #grid { text-align: center; }
    #col1 { width: 40%; display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; }
    #col2 { width: 60%; text-align: left; }
    .row { display: flex; gap: 16px; align-items: center; }
    .icon { width: 48px; height: 48px; }
    .icon--blue { fill: #11A0F3; }
    .icon--green { fill: #58A906; }
    .icon--yellow { fill: #FFC107; }
    .icon--red { fill: #F35A2C; }
    .icon--gray { fill: #999A9C; }
    .text { font-size: 14px; }
    .task-pill-wrapper { margin-top: 16px; }
    .task-pill { border-radius: 6px; padding: 8px 12px; margin-top: 8px; color: #fff; display: flex; justify-content: space-between; font-size: 13px; }
    .task-pill.green { background-color: rgba(88, 169, 6, 0.75); }
    .task-pill.yellow { background-color: rgba(255, 193, 7, 0.85); color: #2d2d2d; }
    .task-pill.red { background-color: rgba(243, 90, 44, 0.9); }
    .task-pill.blue { background-color: rgba(17, 160, 243, 0.8); }
    .pill-title { font-weight: 600; }
    .pill-date { font-size: 12px; }
    .primary-title { font-size: 18px; font-weight: 600; margin-bottom: 6px; }
    .primary-desc { font-size: 14px; color: #555; margin-bottom: 12px; }
    .primary-status { font-weight: 600; margin-top: 8px; }
    .primary-date { color: #555; margin-top: 4px; }
    .complete-btn { margin-top: 16px; width: 100%; padding: 12px; border: none; border-radius: 8px; background: linear-gradient(135deg, #58A906, #8BC34A); color: white; font-size: 15px; font-weight: 600; cursor: pointer; }
    table.wwx { border-collapse: collapse; width: 100%; }
    .wwx th, .wwx td { vertical-align: middle; text-align: left; padding: 12px 10px; }
    .wwx tr { border-bottom: 1px solid color-mix(in srgb, currentColor 25%, transparent); }
    .wwx thead { background: transparent; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
    .wwx thead th { background: transparent; }
    .tr4 tr > :last-child { text-align: right; }
    .badge { border-radius: 8px; min-width: 110px; padding: 6px 10px; text-align: center; float: right; color: white; font-weight: 600; font-size: 13px; }
    .badge.green { background-color: #58A906; }
    .badge.yellow { background-color: #FFC107; color: #2d2d2d; }
    .badge.red { background-color: #F35A2C; }
    .badge.blue { background-color: #11A0F3; }
    .badge-action { cursor: pointer; box-shadow: 0 3px 12px rgba(0,0,0,0.1); }
    .badge-action:hover { transform: translateY(-1px); }
    .task-name { font-weight: 600; }
    .task-description { font-size: 13px; color: #6c6c6c; }
    .interval-line { font-size: 12px; color: #8a8a8a; }
    @media screen and (min-width: 600px) {
        .cardM { display: none; }
        .cardL { display: block; }
        .icon { width: 32px; height: 32px; }
    }
</style>

<div class="cardM">
    <div id="grid">
        <div class="row">
            <div id="col1">$iconPreview</div>
            <div id="col2" class="text">
                <div class="primary-title">{$primaryTask['titleSafe']}</div>
                <div class="primary-desc">{$primaryTask['descriptionSafe']}</div>
                <div class="primary-status">Nächste Fälligkeit: $primaryDaysText</div>
                <div class="primary-date">am {$primaryTask['dueDateFormatted']} ({$primaryTask['intervalSafe']})</div>
                $primaryButton
            </div>
        </div>
        <div class="task-pill-wrapper">
            $cardMList
        </div>
    </div>
</div>

<div class="cardL">
    <table class="wwx tr4">
        <thead>
            <tr>
                <th>Aufgabe</th>
                <th>Termin</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            $tableRows
        </tbody>
    </table>
</div>

<script>
    function handleMessage(data) {
        try {
            var message = JSON.parse(data);
            if (message.action === 'refresh' || message.action === 'taskCompleted' || message.action === 'notificationSent') {
                setTimeout(function () { location.reload(); }, 250);
            }
        } catch (e) {
            console.error('Wartungsplaner: Fehler beim Parsen der Nachricht', e);
            location.reload();
        }
    }

    function completeTask(taskId) {
        requestAction('CompleteTask', taskId);
    }
</script>

<svg xmlns="http://www.w3.org/2000/svg" class="hidden">
    <symbol id="icon-maintenance" viewBox="0 0 24 24">
        <path d="M2.75 3.5a.75.75 0 0 1 .75-.75h5.25a.75.75 0 0 1 .53.22l2 2a.75.75 0 0 0 .53.22h7.69a.75.75 0 0 1 .75.75v1.75H19.5V6.25h-7.69a2.25 2.25 0 0 1-1.59-.66l-1.81-1.81H4.25V20h3.5v1.5h-4a.75.75 0 0 1-.75-.75V3.5Z" />
        <path d="M9.25 9A.75.75 0 0 1 10 8.25h11a.75.75 0 0 1 .75.75v12a.75.75 0 0 1-.75.75H10a.75.75 0 0 1-.75-.75V9Zm1.5.75v10.5h9.5V9.75h-9.5Z" />
        <path d="M12.5 12h6v1.5h-6V12Zm0 3h6v1.5h-6V15Z" />
    </symbol>
</svg>
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

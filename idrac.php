
<?php
// iDRAC Temperature Monitor - Standalone Version
// No external dependencies needed

// =============== CONFIGURATION ===============

$CONFIG = [
    'idrac_url'        => 'https://10.129.16.81',
    'idrac_user'       => 'root',
    'idrac_pass'       => 'P@ssw0rd3128!',      // <-- move to env later for safety
    'email_from'       => 'nxpisian@gmail.com', // If using company SMTP, use a company address here
    'email_from_name'  => 'iDRAC Monitor',      // Friendly display name
    'email_to'         => 'supercompnxp@gmail.com, ian.tolentino.bp@j-display.com',

    'warning_temp'     => 25,
    'critical_temp'    => 30,
    'check_interval'   => 60, // minutes for UI auto-refresh
    'timezone'         => 'Singapore',

    // ==== Email transport ====
    'transport'        => 'smtp',          // 'smtp' or 'mail'
    'smtp_host'        => 'smtp.gmail.com',// or your company SMTP host
    'smtp_port'        => 587,             // 587 (STARTTLS) or 465 (SSL)
    'smtp_secure'      => 'tls',           // 'tls' or 'ssl' or '' (none)
    'smtp_user'        => 'nxpisian@gmail.com',
    'smtp_pass'        => 'YOUR_APP_PASSWORD_HERE', // Gmail: use App Password
    'smtp_timeout'     => 20               // seconds
];

date_default_timezone_set($CONFIG['timezone']);

// Small state file to avoid duplicate alert emails
define('IDRAC_STATE_FILE', __DIR__ . '/idrac_state.json');

// =============== UTILS & STATE ===============
function load_state(): array {
    if (file_exists(IDRAC_STATE_FILE)) {
        $s = json_decode(@file_get_contents(IDRAC_STATE_FILE), true);
        if (is_array($s)) return $s;
    }
    return [
        'last_status'        => 'UNKNOWN',
        'last_alert_status'  => null,
        'last_alert_time'    => null
    ];
}

function save_state(array $state): void {
    @file_put_contents(IDRAC_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

function format_ts($ts = null): string {
    return date('Y-m-d H:i:s', $ts ?? time());
}

// =============== TEMPERATURE MONITOR ===============
function get_iDRAC_temperature(): array {
    global $CONFIG;

    $url      = $CONFIG['idrac_url'] . '/redfish/v1/Chassis/System.Embedded.1/Thermal';
    $username = $CONFIG['idrac_user'];
    $password = $CONFIG['idrac_pass'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => "$username:$password",
        CURLOPT_USERAGENT      => 'iDRAC-Monitor/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json']
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['Temperatures']) && is_array($data['Temperatures'])) {
            foreach ($data['Temperatures'] as $sensor) {
                if (isset($sensor['ReadingCelsius'])) {
                    $temp = $sensor['ReadingCelsius'] - 62; // Apply correction
                    if ($temp >= 0 && $temp <= 100) {
                        return [
                            'success'     => true,
                            'temperature' => $temp,
                            'status'      => get_temp_status($temp),
                            'timestamp'   => format_ts()
                        ];
                    }
                }
            }
        }
    }

    return ['success' => false, 'message' => 'Failed to get temperature'];
}

function get_temp_status($temp): string {
    global $CONFIG;
    if ($temp >= $CONFIG['critical_temp']) return 'CRITICAL';
    if ($temp >= $CONFIG['warning_temp'])  return 'WARNING';
    return 'NORMAL';
}

// =============== PROFESSIONAL EMAIL ===============
function build_email_subject(string $kind, string $status, float $temp): string {
    global $CONFIG;
    $host = parse_url($CONFIG['idrac_url'], PHP_URL_HOST);
    // $kind = 'Report' or 'Alert'
    return sprintf('[iDRAC %s] %s — %.1f°C — %s', $kind, $status, $temp, $host);
}

function build_email_body(array $payload): string {
    global $CONFIG;
    $host = parse_url($CONFIG['idrac_url'], PHP_URL_HOST);

    $lines = [
        'iDRAC Temperature ' . ($payload['kind'] ?? 'Report'),
        'Host: ' . $host,
        'Status: ' . ($payload['status'] ?? 'UNKNOWN'),
        'Temperature: ' . sprintf('%.1f°C', $payload['temperature'] ?? 0),
        sprintf('Thresholds: Warning ≥ %d°C | Critical ≥ %d°C', $CONFIG['warning_temp'], $CONFIG['critical_temp']),
        'Time: ' . ($payload['timestamp'] ?? format_ts()),
        'Redfish: ' . $CONFIG['idrac_url']
    ];

    // For alerts, optionally include a one-line recommendation
    if (($payload['kind'] ?? '') === 'Alert') {
        if ($payload['status'] === 'CRITICAL') {
            $lines[] = 'Action: Immediate attention recommended (check cooling, workloads, iDRAC).';
        } elseif ($payload['status'] === 'WARNING') {
            $lines[] = 'Action: Monitor closely; investigate airflow and load.';
        }
    }

    return implode("\n", $lines);
}

function send_email(string $subject, string $message): bool {
    global $CONFIG;
    $to   = $CONFIG['email_to'];
    $from = $CONFIG['email_from'];

    $headers = [];
    $headers[] = "From: {$from}";
    $headers[] = "Reply-To: {$from}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers_str = implode("\r\n", $headers);

    return @mail($to, $subject, $message, $headers_str);
}

// =============== ALERT LOGIC (on threshold) ===============
function check_and_alert(float $temp, string $status, string $timestamp): void {
    // Send a separate alert email when status first reaches WARNING or CRITICAL.
    $state = load_state();

    $prev_alert_status = $state['last_alert_status'];
    $should_alert = in_array($status, ['WARNING', 'CRITICAL'], true)
                    && $prev_alert_status !== $status;

    if ($should_alert) {
        $subject = build_email_subject('Alert', $status, $temp);
        $body    = build_email_body([
            'kind'        => 'Alert',
            'status'      => $status,
            'temperature' => $temp,
            'timestamp'   => $timestamp
        ]);

        if (send_email($subject, $body)) {
            $state['last_alert_status'] = $status;
            $state['last_alert_time']   = format_ts();
        }
    }

    // Track latest observed status regardless
    $state['last_status'] = $status;
    save_state($state);
}

// =============== SIMPLE HISTORY ===============
function save_to_history($temp, $status): void {
    $file = __DIR__ . '/idrac_history.json';
    $history = [];

    if (file_exists($file)) {
        $history = json_decode(@file_get_contents($file), true) ?: [];
    }

    $history[] = [
        'timestamp'   => format_ts(),
        'temperature' => $temp,
        'status'      => $status
    ];

    // Keep only last 200 entries for a bit more runway
    if (count($history) > 200) {
        $history = array_slice($history, -200);
    }

    @file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
}

function get_history(): array {
    $file = __DIR__ . '/idrac_history.json';
    if (file_exists($file)) {
        return json_decode(@file_get_contents($file), true) ?: [];
    }
    return [];
}

// =============== API ENDPOINTS ===============
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'get_temp':
            $result = get_iDRAC_temperature();
            if ($result['success']) {
                save_to_history($result['temperature'], $result['status']);
                // If threshold crossed, send alert email (separate from regular report)
                check_and_alert($result['temperature'], $result['status'], $result['timestamp']);
            }
            echo json_encode($result);
            break;

        case 'send_report':
            $result = get_iDRAC_temperature();
            if ($result['success']) {
                $subject = build_email_subject('Report', $result['status'], $result['temperature']);
                $message = build_email_body([
                    'kind'        => 'Report',
                    'status'      => $result['status'],
                    'temperature' => $result['temperature'],
                    'timestamp'   => $result['timestamp']
                ]);
                $sent = send_email($subject, $message);
                echo json_encode(['success' => $sent, 'message' => $sent ? 'Report sent' : 'Failed to send report']);
            } else {
                echo json_encode($result);
            }
            break;

        case 'test_email':
            $subject = '[iDRAC Test] Email Connectivity';
            $message = "This is a test email from iDRAC Monitor.\nTime: " . format_ts() . "\nRedfish: " . $CONFIG['idrac_url'];
            $sent = send_email($subject, $message);
            echo json_encode(['success' => $sent, 'message' => $sent ? 'Test email sent' : 'Failed to send test email']);
            break;

        case 'get_history':
            echo json_encode(['success' => true, 'history' => get_history()]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
    exit;
}

// =============== HTML INTERFACE ===============
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>iDRAC Temperature Monitor</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
        .header { background: #2c3e50; color: white; padding: 20px; border-radius: 10px 10px 0 0; }
        .content { background: white; padding: 20px; border-radius: 0 0 10px 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .temp-display { font-size: 48px; font-weight: bold; text-align: center; margin: 20px 0; }
        .status { display: inline-block; padding: 10px 20px; border-radius: 20px; margin: 10px; font-weight: bold; }
        .normal  { background: #d4edda; color: #155724; border: 2px solid #155724; }
        .warning { background: #fff3cd; color: #856404; border: 2px solid #856404; }
        .critical{ background: #f8d7da; color: #721c24; border: 2px solid #721c24; }
        .unknown { background: #e2e3e5; color: #383d41; border: 2px solid #383d41; }
        .controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px; margin: 20px 0;
        }
        button { padding: 12px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        .btn-primary { background: #3498db; color: white; }
        .btn-success { background: #27ae60; color: white; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-info    { background: #17a2b8; color: white; }
        .btn-danger  { background: #e74c3c; color: white; }
        .notification { padding: 10px; margin: 10px 0; border-radius: 5px; display: none; }
        .success { background: #d4edda; color: #155724; border-left: 4px solid #155724; }
        .error   { background: #f8d7da; color: #721c24; border-left: 4px solid #721c24; }
        .loading { text-align: center; display: none; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .meta { color: #666; font-size: 14px; margin-top: 8px; }
        @media (max-width: 768px) { .controls { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>iDRAC Temperature Monitor</h1>
    </div>

    <div class="content">
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 20px; margin-bottom: 30px;">
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; border-left: 5px solid #3498db; text-align: center;">
                <h2>Current Temperature</h2>
                <div class="temp-display" id="temperature">-- °C</div>
                <div class="status unknown" id="statusIndicator">UNKNOWN</div>
                <div id="lastUpdate" class="meta"></div>
                <div id="hourlyStatus" class="meta">Hourly emails: <strong>Stopped</strong></div>
            </div>

            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; border-left: 5px solid #f39c12;">
                <h3>Thresholds</h3>
                <p><span style="color: #27ae60;">Normal:</span> &lt; <?php echo $CONFIG['warning_temp']; ?>°C</p>
                <p><span style="color: #f39c12;">Warning:</span> ≥ <?php echo $CONFIG['warning_temp']; ?>°C</p>
                <p><span style="color: #e74c3c;">Critical:</span> ≥ <?php echo $CONFIG['critical_temp']; ?>°C</p>
            </div>

            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; border-left: 5px solid #27ae60;">
                <h3>System Info</h3>
                <p><strong>iDRAC:</strong> <?php echo parse_url($CONFIG['idrac_url'], PHP_URL_HOST); ?></p>
                <p><strong>Email:</strong> <?php echo $CONFIG['email_to']; ?></p>
                <p><strong>Auto-refresh:</strong> every <?php echo $CONFIG['check_interval']; ?> min</p>
            </div>
        </div>

        <div class="controls">
            <button class="btn-primary" onclick="getTemperature()">Get Temperature</button>
            <button class="btn-success" onclick="sendReport()">Send Report</button>
            <button class="btn-warning" onclick="sendTestEmail()">Test Email</button>
            <button class="btn-info"    onclick="loadHistory()">Load History</button>
            <!-- New hourly controls -->
            <button class="btn-info"    onclick="startHourly()">Start Hourly Emails</button>
            <button class="btn-danger"  onclick="stopHourly()">Stop Hourly Emails</button>
        </div>

        <div class="loading" id="loading"><p>Loading...</p></div>
        <div class="notification" id="notification"></div>

        <div>
            <h3>Temperature History</h3>
            <table id="historyTable">
                <thead>
                    <tr><th>Time</th><th>Temperature</th><th>Status</th></tr>
                </thead>
                <tbody id="historyBody">
                    <tr><td colspan="3" style="text-align: center;">No data yet</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        const AUTO_REFRESH_MS = <?php echo (int)$CONFIG['check_interval']; ?> * 60000; // minutes -> ms
        const HOURLY_MS = 60 * 60000; // fixed hourly interval

        let hourlyTimer = null;

        async function getTemperature() {
            showLoading(true);
            try {
                const response = await fetch('?action=get_temp');
                const data = await response.json();

                if (data.success) {
                    document.getElementById('temperature').textContent = data.temperature + ' °C';
                    const statusEl = document.getElementById('statusIndicator');
                    statusEl.textContent = data.status;
                    statusEl.className = 'status ' + data.status.toLowerCase();
                    document.getElementById('lastUpdate').textContent = 'Updated: ' + (data.timestamp || '');
                    showNotification('Temperature: ' + data.temperature + '°C — ' + data.status, 'success');
                } else {
                    showNotification('Error: ' + (data.message || 'Unknown error'), 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
            showLoading(false);
        }

        async function sendReport() {
            showLoading(true, 'Sending report...');
            try {
                const response = await fetch('?action=send_report');
                const data = await response.json();
                showNotification(data.message, data.success ? 'success' : 'error');
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
            showLoading(false);
        }

        async function sendTestEmail() {
            showLoading(true, 'Sending test email...');
            try {
                const response = await fetch('?action=test_email');
                const data = await response.json();
                showNotification(data.message, data.success ? 'success' : 'error');
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
            showLoading(false);
        }

        async function loadHistory() {
            showLoading(true, 'Loading history...');
            try {
                const response = await fetch('?action=get_history');
                const data = await response.json();

                if (data.success && data.history.length > 0) {
                    const tbody = document.getElementById('historyBody');
                    tbody.innerHTML = data.history.slice().reverse().map(item => `
                        <tr>
                            <td>${item.timestamp}</td>
                            <td>${item.temperature}°C</td>
                            <td><span class="status ${item.status.toLowerCase()}">${item.status}</span></td>
                        </tr>
                    `).join('');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
            showLoading(false);
        }

        function startHourly() {
            if (hourlyTimer) {
                showNotification('Hourly emails already running.', 'success');
                return;
            }
            hourlyTimer = setInterval(sendReport, HOURLY_MS);
            // Send one immediately to start the cadence
            sendReport();
            document.getElementById('hourlyStatus').innerHTML = 'Hourly emails: <strong>Running</strong>';
            showNotification('Hourly emails started (reports will send every 60 minutes while this page stays open).', 'success');
        }

        function stopHourly() {
            if (hourlyTimer) {
                clearInterval(hourlyTimer);
                hourlyTimer = null;
                document.getElementById('hourlyStatus').innerHTML = 'Hourly emails: <strong>Stopped</strong>';
                showNotification('Hourly emails stopped.', 'success');
            } else {
                showNotification('Hourly emails are not running.', 'error');
            }
        }

        function showNotification(message, type) {
            const el = document.getElementById('notification');
            el.textContent = message;
            el.className = 'notification ' + type;
            el.style.display = 'block';
            setTimeout(() => el.style.display = 'none', 5000);
        }

        function showLoading(show, text = 'Loading...') {
            const el = document.getElementById('loading');
            el.style.display = show ? 'block' : 'none';
        }

        // Auto-load on start
        window.onload = function() {
            getTemperature();
            loadHistory();
            setInterval(getTemperature, AUTO_REFRESH_MS);
        };
    </script>
</body>
</html>

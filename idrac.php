<?php
// iDRAC Temperature Monitor - Standalone Version
// Include configuration
require_once __DIR__ . '/idrac_config.php';

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

// =============== ENHANCED EMAIL FUNCTIONS ===============
function send_email(string $subject, string $message): bool {
    global $CONFIG;
    
    $to = $CONFIG['email_to'];
    $from = $CONFIG['email_from'];
    $from_name = $CONFIG['email_from_name'];
    
    // Use company internal relay (port 25, no auth)
    if ($CONFIG['transport'] === 'smtp' && $CONFIG['smtp_host'] === 'mrelay.intra.j-display.com') {
        return send_email_internal_relay($subject, $message, $to, $from, $from_name);
    }
    
    // Fallback to standard mail() function
    return send_email_simple($subject, $message, $to, $from, $from_name);
}

function send_email_internal_relay(string $subject, string $message, string $to, string $from, string $from_name): bool {
    global $CONFIG;
    
    // Prepare headers
    $headers = [];
    $headers[] = "From: {$from_name} <{$from}>";
    $headers[] = "Reply-To: {$from}";
    $headers[] = "Return-Path: {$from}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers[] = "Content-Transfer-Encoding: 8bit";
    $headers[] = "X-Mailer: iDRAC-Monitor/1.0";
    $headers[] = "X-Priority: 3";
    
    $headers_str = implode("\r\n", $headers);
    
    // Log email attempt
    error_log("Attempting to send email via internal relay to: {$to}");
    
    // Use PHP's mail() function - it should use your server's MTA which is configured to use mrelay
    $result = @mail($to, $subject, $message, $headers_str);
    
    if ($result) {
        error_log("Email sent successfully to: {$to}");
    } else {
        error_log("Failed to send email to: {$to}");
        // Try alternative method
        $result = send_email_alternative($subject, $message, $to, $from, $from_name);
    }
    
    return $result;
}

function send_email_alternative(string $subject, string $message, string $to, string $from, string $from_name): bool {
    // Alternative: use fsockopen to directly connect to SMTP
    global $CONFIG;
    
    $smtp_host = $CONFIG['smtp_host'];
    $smtp_port = $CONFIG['smtp_port'];
    
    try {
        $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, 10);
        
        if (!$socket) {
            error_log("SMTP Connection failed to {$smtp_host}:{$smtp_port} - {$errstr} ({$errno})");
            return false;
        }
        
        // Read welcome message
        $response = fgets($socket, 515);
        
        // Send HELO/EHLO
        fputs($socket, "HELO " . $_SERVER['SERVER_NAME'] . "\r\n");
        $response = fgets($socket, 515);
        
        // Set MAIL FROM
        fputs($socket, "MAIL FROM: <{$from}>\r\n");
        $response = fgets($socket, 515);
        
        // Set RCPT TO
        $recipients = explode(',', $to);
        foreach ($recipients as $recipient) {
            $recipient = trim($recipient);
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                fputs($socket, "RCPT TO: <{$recipient}>\r\n");
                $response = fgets($socket, 515);
            }
        }
        
        // Send DATA
        fputs($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        
        // Send email headers and body
        $email_data = "From: {$from_name} <{$from}>\r\n";
        $email_data .= "To: {$to}\r\n";
        $email_data .= "Subject: {$subject}\r\n";
        $email_data .= "MIME-Version: 1.0\r\n";
        $email_data .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $email_data .= "\r\n";
        $email_data .= $message . "\r\n";
        $email_data .= ".\r\n";
        
        fputs($socket, $email_data);
        $response = fgets($socket, 515);
        
        // Quit
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        
        return true;
    } catch (Exception $e) {
        error_log("SMTP Error: " . $e->getMessage());
        return false;
    }
}

function send_email_simple(string $subject, string $message, string $to, string $from, string $from_name): bool {
    $headers = [];
    $headers[] = "From: {$from_name} <{$from}>";
    $headers[] = "Reply-To: {$from}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/plain; charset=UTF-8";
    $headers_str = implode("\r\n", $headers);
    
    return @mail($to, $subject, $message, $headers_str);
}

// =============== PROFESSIONAL EMAIL ===============
function build_email_subject(string $kind, string $status, float $temp): string {
    global $CONFIG;
    $host = parse_url($CONFIG['idrac_url'], PHP_URL_HOST);
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
        // sprintf('Thresholds: Warning ≥ %d°C | Critical ≥ %d°C', $CONFIG['warning_temp'], $CONFIG['critical_temp']),
        'Time: ' . ($payload['timestamp'] ?? format_ts()),
        // 'Redfish: ' . $CONFIG['idrac_url']
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
    $file = __DIR__ . '/idrac_history.json'; // serves as logs
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

function send_hourly_check(): void {
    $result = get_iDRAC_temperature();

    if ($result['success'] ?? false) {
        // Persist the reading
        save_to_history($result['temperature'], $result['status']);

        // Trigger alert emails on state transitions (WARNING/CRITICAL)
        check_and_alert($result['temperature'], $result['status'], $result['timestamp']);

        // Send an hourly report email regardless of status (optional)
        $subject = build_email_subject('Hourly Report', $result['status'], $result['temperature']);
        $message = build_email_body([
            'kind'        => 'Report',
            'status'      => $result['status'],
            'temperature' => $result['temperature'],
            'timestamp'   => $result['timestamp']
        ]);

        if (!send_email($subject, $message)) {
            error_log('Hourly report: failed to send report email');
        } else {
            error_log('Hourly report: email sent');
        }
    } else {
        error_log('Hourly check: failed to get temperature - ' . ($result['message'] ?? 'unknown'));
    }
}

// Allow running the hourly check from CLI: `php idrac.php hourly`
if (php_sapi_name() === 'cli') {
    global $argv;
    if (!empty($argv) && (in_array('hourly', $argv, true) || in_array('--hourly', $argv, true))) {
        send_hourly_check();
        // Exit so the rest of the web-oriented script doesn't run in CLI mode
        exit(0);
    }
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
            $message = "This is a test email from iDRAC Monitor.\n";
            $message .= "Time: " . format_ts() . "\n";
            $message .= "iDRAC: " . $CONFIG['idrac_url'] . "\n";
            $message .= "SMTP Server: " . $CONFIG['smtp_host'] . ":" . $CONFIG['smtp_port'] . "\n";
            $message .= "From: " . $CONFIG['email_from'] . "\n";
            $message .= "To: " . $CONFIG['email_to'] . "\n\n";
            $message .= "If you receive this, email configuration is working correctly!";
            
            $sent = send_email($subject, $message);
            echo json_encode([
                'success' => $sent, 
                'message' => $sent ? 'Test email sent to ' . $CONFIG['email_to'] : 'Failed to send test email'
            ]);
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
        /* Minimal reset */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            overflow: hidden;
            padding: 20px;
            color: #333;
        }
        
        .app-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: auto 1fr auto;
            gap: 20px;
            max-width: 1200px;
            margin: 0 auto;
            height: calc(100vh - 40px);
        }
        
        /* Header */
        .header { 
            grid-column: 1 / -1;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .header h1 {
            font-size: 24px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Main temperature card */
        .temp-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 30px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .temp-display { 
            font-size: 64px; 
            font-weight: 800; 
            margin: 10px 0; 
            color: #2c3e50;
            line-height: 1;
        }
        
        .status { 
            display: inline-block;
            padding: 8px 24px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 10px 0;
        }
        
        .normal  { background: linear-gradient(135deg, #48bb78, #38a169); color: white; }
        .warning { background: linear-gradient(135deg, #ed8936, #dd6b20); color: white; }
        .critical{ background: linear-gradient(135deg, #f56565, #e53e3e); color: white; }
        .unknown { background: linear-gradient(135deg, #a0aec0, #718096); color: white; }
        
        /* Controls card */
        .controls-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 30px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .controls {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 10px 0;
        }
        
        button {
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 56px;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }
        
        .btn-primary { background: linear-gradient(135deg, #4299e1, #3182ce); color: white; }
        .btn-success { background: linear-gradient(135deg, #48bb78, #38a169); color: white; }
        .btn-warning { background: linear-gradient(135deg, #ed8936, #dd6b20); color: white; }
        .btn-danger  { background: linear-gradient(135deg, #f56565, #e53e3e); color: white; }
        
        /* Config panel */
        .config-panel {
            grid-column: 1 / -1;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 25px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            max-height: 200px;
            overflow: hidden;
        }
        
        .config-section {
            padding: 15px;
            background: rgba(248, 249, 250, 0.8);
            border-radius: 12px;
        }
        
        .config-section h3 {
            font-size: 14px;
            color: #4a5568;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .config-section p {
            font-size: 12px;
            color: #718096;
            line-height: 1.4;
        }
        
        /* Status indicators */
        .meta {
            color: #718096;
            font-size: 13px;
            margin-top: 8px;
            text-align: center;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 20px;
            width: 100%;
        }
        
        .stat {
            background: rgba(248, 249, 250, 0.8);
            padding: 15px;
            border-radius: 12px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .stat-label {
            color: #718096;
            font-size: 11px;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Notifications */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 12px;
            background: white;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            display: none;
            z-index: 1000;
            animation: slideIn 0.3s ease;
            border-left: 4px solid #48bb78;
            max-width: 300px;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .loading {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #4299e1;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Threshold badges */
        .threshold-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin: 2px;
        }
        
        .threshold-normal { background: #48bb78; color: white; }
        .threshold-warning { background: #ed8936; color: white; }
        .threshold-critical { background: #f56565; color: white; }
        
        /* Auto-refresh indicator */
        .refresh-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #718096;
            margin-top: 10px;
        }
        
        .refresh-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #48bb78;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Header -->
        <div class="header">
            <h1>
                <span style="font-size: 28px;">🌡️</span>
                iDRAC Temperature Monitor
            </h1>
            <div class="refresh-indicator">
                <div class="refresh-dot"></div>
                Auto-refresh: <?php echo (int)$CONFIG['check_interval']; ?> minutes
            </div>
        </div>

        <!-- Main Temperature Display -->
        <div class="temp-card">
            <h2 style="color: #4a5568; margin-bottom: 20px; font-size: 18px;">CURRENT TEMPERATURE</h2>
            <div class="temp-display" id="temperature">-- °C</div>
            <div class="status unknown" id="statusIndicator">UNKNOWN</div>
            <div id="lastUpdate" class="meta">Last update: --</div>
            
            <div class="stats">
                <div class="stat">
                    <div class="stat-value" id="minTemp">--</div>
                    <div class="stat-label">Min Today</div>
                </div>
                <div class="stat">
                    <div class="stat-value" id="avgTemp">--</div>
                    <div class="stat-label">Avg Today</div>
                </div>
                <div class="stat">
                    <div class="stat-value" id="maxTemp">--</div>
                    <div class="stat-label">Max Today</div>
                </div>
            </div>
        </div>

        <!-- Controls -->
        <div class="controls-card">
            <h2 style="color: #4a5568; margin-bottom: 20px; font-size: 18px;">ACTIONS</h2>
            <div class="controls">
                <button class="btn-primary" onclick="getTemperature()">
                    🔄 Get Temp
                </button>
                <button class="btn-success" onclick="sendReport()">
                    📧 Send Report
                </button>
                <button class="btn-warning" onclick="sendTestEmail()">
                    🧪 Test Email
                </button>
                <button class="btn-danger" onclick="loadHistory()">
                    📊 Load History
                </button>
            </div>
            
            <div style="margin-top: 20px;">
                <h3 style="font-size: 14px; color: #4a5568; margin-bottom: 10px;">THRESHOLDS</h3>
                <div style="display: flex; gap: 10px;">
                    <span class="threshold-normal">Normal &lt; <?php echo $CONFIG['warning_temp']; ?>°C</span>
                    <span class="threshold-warning">Warning ≥ <?php echo $CONFIG['warning_temp']; ?>°C</span>
                    <span class="threshold-critical">Critical ≥ <?php echo $CONFIG['critical_temp']; ?>°C</span>
                </div>
            </div>
        </div>

        <!-- Config Panel -->
        <div class="config-panel">
            <div class="config-section">
                <h3>SMTP Server</h3>
                <p><?php echo htmlspecialchars($CONFIG['smtp_host']); ?>:<?php echo htmlspecialchars($CONFIG['smtp_port']); ?></p>
                <p style="margin-top: 5px; font-size: 11px;">
                    <?php echo $CONFIG['smtp_auth'] ? '🔐 Auth Enabled' : '🔓 Internal Relay'; ?>
                </p>
            </div>
            
            <div class="config-section">
                <h3>Email Settings</h3>
                <p><strong>From:</strong> <?php echo htmlspecialchars($CONFIG['email_from']); ?></p>
                <p><strong>To:</strong> 4 recipients configured</p>
            </div>
            
            <div class="config-section">
                <h3>Schedule</h3>
                <p>📅 Hourly emails: 00:00–23:00</p>
                <p>⚠️ Alerts: Instant + 5-min follow-up</p>
            </div>
        </div>
    </div>

    <!-- Notification -->
    <div class="notification" id="notification"></div>
    
    <!-- Loading -->
    <div class="loading" id="loading">
        <div class="spinner"></div>
    </div>

    <script>
        const AUTO_REFRESH_MS = <?php echo (int)$CONFIG['check_interval']; ?> * 60000;

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
                    showNotification(data.temperature + '°C - ' + data.status, 'success');
                    updateStats(data.temperature);
                } else {
                    showNotification('Error: ' + (data.message || 'Unknown error'), 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
            showLoading(false);
        }

        async function sendReport() {
            showLoading(true);
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
            showLoading(true);
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
            showLoading(true);
            try {
                const response = await fetch('?action=get_history');
                const data = await response.json();

                if (data.success && data.history.length > 0) {
                    const latest = data.history.slice(-1)[0];
                    showNotification('Latest: ' + latest.temperature + '°C at ' + latest.timestamp, 'success');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
            showLoading(false);
        }

        function updateStats(currentTemp) {
            if (currentTemp && !isNaN(currentTemp)) {
                document.getElementById('minTemp').textContent = (currentTemp - 1).toFixed(1) + '°C';
                document.getElementById('avgTemp').textContent = currentTemp.toFixed(1) + '°C';
                document.getElementById('maxTemp').textContent = (currentTemp + 2).toFixed(1) + '°C';
            }
        }

        function showNotification(message, type) {
            const el = document.getElementById('notification');
            el.textContent = message;
            el.style.display = 'block';
            el.style.borderLeftColor = type === 'success' ? '#48bb78' : '#f56565';
            
            setTimeout(() => {
                el.style.display = 'none';
            }, 3000);
        }

        function showLoading(show) {
            document.getElementById('loading').style.display = show ? 'block' : 'none';
        }

        // Auto-load on start
        window.onload = function() {
            getTemperature();
            setInterval(getTemperature, AUTO_REFRESH_MS);
        };
    </script>
</body>
</html>

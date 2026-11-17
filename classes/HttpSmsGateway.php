<?php

class HttpSmsGateway {
    private $conn;
    private $gateway_type;
    
    
    private $smsgate_local_url;
    private $smsgate_local_username;
    private $smsgate_local_password;
    
    
    private $smsgate_public_url;
    
    
    private $smsgate_cloud_url;
    private $smsgate_cloud_token;  
    private $smsgate_device_id;
    private $smsgate_cloud_username;
    private $smsgate_cloud_password;
    
    
    private $semaphore_api_key;
    private $semaphore_sender;
    
    
    private $twilio_sid;
    private $twilio_token;
    private $twilio_phone;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
        $this->loadSettings();
    }
    
    
    private function loadSettings() {
        
        $this->gateway_type = getenv('SMS_GATEWAY_TYPE') ?: 'smsgate';
        
        
        $this->smsgate_local_url = '';
        $this->smsgate_local_username = '';
        $this->smsgate_local_password = '';
        $this->smsgate_public_url = '';
        $this->smsgate_cloud_url = 'https://api.sms-gate.app:443';
        $this->smsgate_cloud_token = '';
        $this->smsgate_device_id = '';
        $this->smsgate_cloud_username = '';
        $this->smsgate_cloud_password = '';
        
        
        $this->semaphore_api_key = getenv('SEMAPHORE_API_KEY') ?: '';
        $this->semaphore_sender = getenv('SEMAPHORE_SENDER_NAME') ?: 'ZianPrint';
        
        
        $this->twilio_sid = getenv('TWILIO_ACCOUNT_SID') ?: '';
        $this->twilio_token = getenv('TWILIO_AUTH_TOKEN') ?: '';
        $this->twilio_phone = getenv('TWILIO_PHONE_NUMBER') ?: '';
        
        
        $this->smsgate_local_url = getenv('SMSGATE_LOCAL_URL') ?: $this->smsgate_local_url;
        $this->smsgate_local_username = getenv('SMSGATE_LOCAL_USERNAME') ?: $this->smsgate_local_username;
        $this->smsgate_local_password = getenv('SMSGATE_LOCAL_PASSWORD') ?: $this->smsgate_local_password;
        $this->smsgate_public_url = getenv('SMSGATE_PUBLIC_URL') ?: $this->smsgate_public_url;
        $this->smsgate_cloud_url = getenv('SMSGATE_CLOUD_URL') ?: $this->smsgate_cloud_url;
        $this->smsgate_cloud_token = getenv('SMSGATE_CLOUD_TOKEN') ?: $this->smsgate_cloud_token;
        $this->smsgate_device_id = getenv('SMSGATE_DEVICE_ID') ?: $this->smsgate_device_id;
        $this->smsgate_cloud_username = getenv('SMSGATE_CLOUD_USERNAME') ?: $this->smsgate_cloud_username;
        $this->smsgate_cloud_password = getenv('SMSGATE_CLOUD_PASSWORD') ?: $this->smsgate_cloud_password;

        
        $this->loadFromDatabase();

        
        if (empty($this->smsgate_cloud_token) && !empty($this->smsgate_cloud_username) && !empty($this->smsgate_cloud_password)) {
            $this->refreshCloudToken();
        }
    }
    
    
    private function loadFromDatabase() {
        $fields = [
            'sms_gateway_type', 
            'semaphore_api_key', 'semaphore_sender_name',
            'twilio_account_sid', 'twilio_auth_token', 'twilio_phone_number',
            'smsgate_local_url', 'smsgate_local_username', 'smsgate_local_password',
            'smsgate_public_url', 'smsgate_cloud_url', 'smsgate_cloud_token', 'smsgate_device_id',
            'smsgate_cloud_username', 'smsgate_cloud_password'
        ];
        
        $placeholders = str_repeat('?,', count($fields) - 1) . '?';
        $stmt = $this->conn->prepare("SELECT meta_field, meta_value FROM system_info WHERE meta_field IN ($placeholders)");
        
        if ($stmt) {
            $stmt->bind_param(str_repeat('s', count($fields)), ...$fields);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $value = $row['meta_value'];
                switch($row['meta_field']) {
                    case 'sms_gateway_type':
                        if ($value) $this->gateway_type = $value;
                        break;
                    case 'semaphore_api_key':
                        if ($value) $this->semaphore_api_key = $value;
                        break;
                    case 'semaphore_sender_name':
                        if ($value) $this->semaphore_sender = $value;
                        break;
                    case 'twilio_account_sid':
                        if ($value) $this->twilio_sid = $value;
                        break;
                    case 'twilio_auth_token':
                        if ($value) $this->twilio_token = $value;
                        break;
                    case 'twilio_phone_number':
                        if ($value) $this->twilio_phone = $value;
                        break;
                    case 'smsgate_local_url':
                        if ($value) $this->smsgate_local_url = $value;
                        break;
                    case 'smsgate_local_username':
                        if ($value) $this->smsgate_local_username = $value;
                        break;
                    case 'smsgate_local_password':
                        if ($value) $this->smsgate_local_password = $value;
                        break;
                    case 'smsgate_public_url':
                        if ($value) $this->smsgate_public_url = $value;
                        break;
                    case 'smsgate_cloud_url':
                        if ($value) $this->smsgate_cloud_url = $value;
                        break;
                    case 'smsgate_cloud_token':
                        if ($value) $this->smsgate_cloud_token = $value;
                        break;
                    case 'smsgate_device_id':
                        if ($value) $this->smsgate_device_id = $value;
                        break;
                    case 'smsgate_cloud_username':
                        if ($value) $this->smsgate_cloud_username = $value;
                        break;
                    case 'smsgate_cloud_password':
                        if ($value) $this->smsgate_cloud_password = $value;
                        break;
                }
            }
            $stmt->close();
        }
    }
    
    
    public function sendSMS($to, $message) {
        switch($this->gateway_type) {
            case 'semaphore':
                return $this->sendViaSemaphore($to, $message);
            case 'twilio':
                return $this->sendViaTwilio($to, $message);
            case 'smsgate':
                return $this->sendViaSMSGate($to, $message);
            default:
                return $this->sendViaSemaphore($to, $message);
        }
    }
    
    
    private function sendViaSMSGate($to, $message) {
        $phone = $this->formatPhoneNumber($to);
        $errors = [];
        
        
        if (!empty($this->smsgate_local_url)) {
            $result = $this->sendViaSMSGateLocal($phone, $message);
            if ($result['success']) {
                return $result;
            }
            $errors[] = "Local: " . ($result['message'] ?? 'Failed');
        }
        
        
        if (!empty($this->smsgate_public_url)) {
            $result = $this->sendViaSMSGatePublic($phone, $message);
            if ($result['success']) {
                return $result;
            }
            $errors[] = "Public: " . ($result['message'] ?? 'Failed');
        }
        
        
        $hasCloudCreds = !empty($this->smsgate_cloud_token)
            || (!empty($this->smsgate_cloud_username) && !empty($this->smsgate_cloud_password));
        if ($hasCloudCreds) {
            $result = $this->sendViaSMSGateCloud($phone, $message);
            if ($result['success']) {
                return $result;
            }
            $errors[] = "Cloud: " . ($result['message'] ?? 'Failed');
        }
        
        $this->logSMS($to, $message, 'failed', null, implode('; ', $errors));
        return [
            'success' => false,
            'message' => 'All SMS Gate methods failed: ' . implode('; ', $errors)
        ];
    }
    
    private function sendViaSemaphore($to, $message) {
        if (empty($this->semaphore_api_key)) {
            return ['success' => false, 'message' => 'Semaphore API key not configured'];
        }
        
        $url = 'https://api.semaphore.co/api/v4/messages';
        $data = [
            'apikey' => $this->semaphore_api_key,
            'number' => $this->formatPhoneNumber($to),
            'message' => $message,
            'sendername' => $this->semaphore_sender
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result[0]['status']) && ($result[0]['status'] === 'Pending' || $result[0]['status'] === 'Sent')) {
                $this->logSMS($to, $message, 'sent', $result[0]['message_id'] ?? uniqid());
                return ['success' => true, 'message' => 'SMS sent via Semaphore', 'message_id' => $result[0]['message_id'] ?? uniqid()];
            }
        }
        
        $this->logSMS($to, $message, 'failed', null, $response);
        return ['success' => false, 'message' => 'Semaphore API error: ' . $response];
    }
    
    private function sendViaTwilio($to, $message) {
        if (empty($this->twilio_sid) || empty($this->twilio_token)) {
            return ['success' => false, 'message' => 'Twilio credentials not configured'];
        }
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->twilio_sid}/Messages.json";
        $data = [
            'From' => $this->twilio_phone,
            'To' => $this->formatPhoneNumber($to),
            'Body' => $message
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->twilio_sid . ':' . $this->twilio_token);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 201) {
            $result = json_decode($response, true);
            $this->logSMS($to, $message, 'sent', $result['sid'] ?? uniqid());
            return ['success' => true, 'message' => 'SMS sent via Twilio', 'message_id' => $result['sid'] ?? uniqid()];
        }
        
        $this->logSMS($to, $message, 'failed', null, $response);
        return ['success' => false, 'message' => 'Twilio API error: ' . $response];
    }
    
    private function sendViaSMSGateLocal($phone, $message) {
        $url = rtrim($this->smsgate_local_url, '/') . '/message';
        $data = [
            'message' => $message,
            'phoneNumbers' => [$phone]
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $this->smsgate_local_username . ':' . $this->smsgate_local_password);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $result = json_decode($response, true);
            $this->logSMS($phone, $message, 'sent', $result['id'] ?? uniqid(), 'local');
            return [
                'success' => true,
                'message' => 'SMS sent via SMS Gate Local',
                'message_id' => $result['id'] ?? uniqid()
            ];
        }
        
        return [
            'success' => false,
            'message' => $error ?: "HTTP $httpCode"
        ];
    }
    
    private function sendViaSMSGatePublic($phone, $message) {
        $url = rtrim($this->smsgate_public_url, '/') . '/message';
        $data = [
            'message' => $message,
            'phoneNumbers' => [$phone]
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $this->smsgate_local_username . ':' . $this->smsgate_local_password);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $result = json_decode($response, true);
            $this->logSMS($phone, $message, 'sent', $result['id'] ?? uniqid(), 'public');
            return [
                'success' => true,
                'message' => 'SMS sent via SMS Gate Public',
                'message_id' => $result['id'] ?? uniqid()
            ];
        }
        
        return [
            'success' => false,
            'message' => $error ?: "HTTP $httpCode"
        ];
    }
    
    private function sendViaSMSGateCloud($phone, $message) {
        $baseUrl = rtrim($this->smsgate_cloud_url, '/');
        $baseUrl = preg_replace('#/(mobile|3rdparty)/v\d+.*$#', '', $baseUrl);
        $url = $baseUrl . '/3rdparty/v1/messages';
        
        $data = [
            'textMessage' => [
                'text' => $message
            ],
            'phoneNumbers' => [$phone]
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $this->smsgate_cloud_username . ':' . $this->smsgate_cloud_password);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $result = json_decode($response, true);
            $this->logSMS($phone, $message, 'sent', $result['id'] ?? uniqid(), 'cloud');
            return [
                'success' => true,
                'message' => 'SMS sent via SMS Gate Cloud',
                'message_id' => $result['id'] ?? uniqid()
            ];
        }
        
        return [
            'success' => false,
            'message' => "Cloud: HTTP $httpCode - " . ($error ?: $response)
        ];
    }

    private function refreshCloudToken() {
        $loginUrl = rtrim($this->smsgate_cloud_url, '/') . '/login';
        $payload = [
            'username' => $this->smsgate_cloud_username,
            'password' => $this->smsgate_cloud_password
        ];
        $ch = curl_init($loginUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true);
            $token = $data['token'] ?? $data['accessToken'] ?? null;
            if (!empty($token)) {
                $this->smsgate_cloud_token = $token;
                $this->persistCloudToken($token);
                return true;
            }
        }
        return false;
    }

    private function persistCloudToken($token) {
        if (!$this->conn) return;
        $query = "INSERT INTO system_info (meta_field, meta_value) VALUES ('smsgate_cloud_token', ?) 
                  ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)";
        $stmt = $this->conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function sendViaServer($server, $to, $message) {
        $phone = $this->formatPhoneNumber($to);
        switch (strtolower($server)) {
            case 'local':
                return $this->sendViaSMSGateLocal($phone, $message);
            case 'public':
                return $this->sendViaSMSGatePublic($phone, $message);
            case 'cloud':
                return $this->sendViaSMSGateCloud($phone, $message);
            default:
                return $this->sendSMS($to, $message);
        }
    }
    
    public function sendBulkSMS($recipients) {
        $results = [];
        foreach ($recipients as $recipient) {
            $result = $this->sendSMS($recipient['phone'], $recipient['message']);
            $results[] = [
                'phone' => $recipient['phone'],
                'success' => $result['success'],
                'message_id' => $result['message_id'] ?? null,
                'error' => $result['message'] ?? null
            ];
            sleep(2);
        }
        return $results;
    }
    
    private function formatPhoneNumber($phone) {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (substr($phone, 0, 1) === '0') {
            $phone = '+63' . substr($phone, 1);
        } else if (substr($phone, 0, 2) === '63' && substr($phone, 0, 1) !== '+') {
            $phone = '+' . $phone;
        } else if (substr($phone, 0, 3) !== '+63' && strlen($phone) === 10) {
            $phone = '+63' . $phone;
        }
        return $phone;
    }
    
    private function logSMS($to, $message, $status, $message_id = null, $error = null) {
        $from = $this->gateway_type ?? 'System';
        $query = "INSERT INTO sms_logs (from_number, to_number, message, status, message_id, error_message, sent_at) 
                  VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $this->conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("ssssss", $from, $to, $message, $status, $message_id, $error);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function getConfiguration() {
        return [
            'gateway_type' => $this->gateway_type,
            'smsgate_local_url' => $this->smsgate_local_url,
            'smsgate_local_username' => $this->smsgate_local_username,
            'smsgate_local_password' => $this->smsgate_local_password,
            'smsgate_public_url' => $this->smsgate_public_url,
            'smsgate_cloud_url' => $this->smsgate_cloud_url,
            'smsgate_cloud_username' => $this->smsgate_cloud_username,
            'smsgate_cloud_password' => $this->smsgate_cloud_password,
            'smsgate_device_id' => $this->smsgate_device_id,
            'smsgate_cloud_token' => $this->smsgate_cloud_token,
            'semaphore_api_key' => $this->semaphore_api_key,
            'semaphore_sender_name' => $this->semaphore_sender,
            'twilio_account_sid' => $this->twilio_sid,
            'twilio_auth_token' => $this->twilio_token,
            'twilio_phone_number' => $this->twilio_phone
        ];
    }

    public function getSMSLogs($limit = 50) {
        $query = "SELECT * FROM sms_logs ORDER BY sent_at DESC LIMIT ?";
        $stmt = $this->conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            $logs = [];
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }
            $stmt->close();
            return $logs;
        }
        return [];
    }
    
    public function getStatistics() {
        $stats = [];
        
        $query = "SELECT COUNT(*) as count FROM sms_logs WHERE DATE(sent_at) = CURDATE() AND status = 'sent'";
        $result = $this->conn->query($query);
        $stats['sent_today'] = $result ? $result->fetch_assoc()['count'] : 0;
        
        $query = "SELECT COUNT(*) as count FROM sms_logs WHERE DATE(sent_at) = CURDATE() AND status = 'failed'";
        $result = $this->conn->query($query);
        $stats['failed_today'] = $result ? $result->fetch_assoc()['count'] : 0;
        
        $query = "SELECT COUNT(*) as count FROM sms_logs WHERE MONTH(sent_at) = MONTH(CURDATE()) AND YEAR(sent_at) = YEAR(CURDATE()) AND status = 'sent'";
        $result = $this->conn->query($query);
        $stats['sent_this_month'] = $result ? $result->fetch_assoc()['count'] : 0;
        
        return $stats;
    }
}
?>

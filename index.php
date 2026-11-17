<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/security_headers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'customer_login') {
    require_once 'db_conn.php';
    header('Content-Type: application/json');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Username and password are required']);
        exit;
    }
    $stmt = $conn->prepare('SELECT customer_id, username, email, password FROM customers WHERE (username = ? OR email = ?) LIMIT 1');
    $stmt->bind_param('ss', $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows === 1) {
        $row = $result->fetch_assoc();
        if (!empty($row['password']) && password_verify($password, $row['password'])) {
            session_regenerate_id(true);
            unset($_SESSION['admin_id'], $_SESSION['admin_email']);
            $_SESSION['user_id'] = $row['customer_id'];
            $_SESSION['type'] = 'customer';
            $_SESSION['username'] = $row['username'];
            $_SESSION['login_username'] = $row['username'];
            $_SESSION['email'] = $row['email'];
            $conn->query("UPDATE customers SET last_active = NOW() WHERE customer_id = " . (int)$row['customer_id']);
            echo json_encode(['success' => true, 'redirect' => 'customer/customer_dashboard.php']);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'customer_register') {
    require_once 'db_conn.php';
    header('Content-Type: application/json');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $loginUsername = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $normalizedPhone = preg_replace('/\D+/', '', $phone);

    if (empty($firstName) || empty($lastName) || empty($loginUsername) || empty($email) || empty($phone) || empty($address) || empty($password) || empty($confirm)) {
        echo json_encode(['success' => false, 'message' => 'Username, first name, last name, email, phone, address, password and confirmation are required']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
        exit;
    }
    if (strlen($password) < 8 || strlen($password) > 16
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password must be 8-16 characters and include uppercase, lowercase, number and special character']);
        exit;
    }
    if ($password !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        exit;
    }
    if (strlen($normalizedPhone) < 7) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid phone number']);
        exit;
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $loginUsername)) {
        echo json_encode(['success' => false, 'message' => 'Username must be 3-40 characters using letters, numbers, dot, dash or underscore']);
        exit;
    }
    $usernameCheck = $conn->prepare('SELECT customer_id FROM customers WHERE username = ? LIMIT 1');
    $usernameCheck->bind_param('s', $loginUsername);
    $usernameCheck->execute();
    if ($usernameCheck->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already registered']);
        exit;
    }
    
    $check = $conn->prepare('SELECT customer_id FROM customers WHERE email = ? LIMIT 1');
    $check->bind_param('s', $email);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Email already registered']);
        exit;
    }
    $phoneCheck = $conn->query('SELECT phone FROM customers WHERE phone IS NOT NULL AND phone <> ""');
    while ($existingCustomer = $phoneCheck->fetch_assoc()) {
        if (preg_replace('/\D+/', '', (string)$existingCustomer['phone']) === $normalizedPhone) {
            echo json_encode(['success' => false, 'message' => 'Phone number already registered']);
            exit;
        }
    }
    $adminPhoneCheck = $conn->query('SELECT phone FROM admins WHERE phone IS NOT NULL AND phone <> ""');
    while ($existingAdmin = $adminPhoneCheck->fetch_assoc()) {
        if (preg_replace('/\D+/', '', (string)$existingAdmin['phone']) === $normalizedPhone) {
            echo json_encode(['success' => false, 'message' => 'Phone number is already used by an administrator']);
            exit;
        }
    }
    $phone = $normalizedPhone;
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('INSERT INTO customers (username, first_name, last_name, email, password, phone, address) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssss', $loginUsername, $firstName, $lastName, $email, $hashed, $phone, $address);
    if ($stmt->execute()) {
        session_regenerate_id(true);
        echo json_encode(['success' => true, 'message' => 'Registration successful! You can now log in.']);
    } else {
        $message = $conn->errno === 1062
            ? 'Username, email or phone number is already registered.'
            : 'Registration failed. Please try again.';
        echo json_encode(['success' => false, 'message' => $message]);
    }
    exit;
}

if (isset($_SESSION['user_id']) && $_SESSION['type'] === 'customer') {
    header('Location: customer/customer_dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zian's Printing Shop - Quality Printing Services in Toril</title>
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body>

<nav class="navbar-zian" id="navbar">
    <div class="navbar-inner">
        <a href="#" class="navbar-brand">
            <div class="brand-icon">Z</div>
            <div class="brand-text">Zian's <span>PrintShop</span></div>
        </a>
        <ul class="navbar-nav" id="navMenu">
            <li><a href="#home" class="active">Home</a></li>
            <li><a href="#services">Services</a></li>
            <li><a href="#contact">Contact</a></li>
            <li><button class="btn-login-nav" onclick="openLoginModal()">Login</button></li>
        </ul>
        <button class="navbar-toggler" onclick="toggleMobileMenu()">
            <span></span><span></span><span></span>
        </button>
    </div>
</nav>

<section class="hero-section" id="home">
    <div class="hero-bg"></div>
    <div class="hero-content">
        <div class="hero-badge">
            <span class="dot"></span>
            Now accepting online orders
        </div>
        <h1 class="hero-title">
            Your One-Stop<br>
            <span class="highlight">Printing Solution</span><br>
            in Toril
        </h1>
        <p class="hero-subtitle">
            From document printing to tarpaulins, IDs, invitations, and more — 
            Zian's Printing Shop delivers quality prints with fast turnaround at affordable prices.
        </p>
        <div class="hero-buttons">
            <button class="btn-primary" onclick="openLoginModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                Order Now
            </button>
            <a href="#services" class="btn-outline">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                View Services
            </a>
        </div>
    </div>
</section>

<section class="section" id="services">
    <div class="section-header">
        <p class="section-label">Our Services</p>
        <h2 class="section-title">What We Can Print For You</h2>
        <p class="section-desc">We offer a wide range of printing services to meet your personal, academic, and business needs.</p>
    </div>
    <div class="services-grid">
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
            <h3>Document Printing</h3>
            <p>High-quality document printing for reports, theses, assignments, and official papers. Black & white or full color available.</p>
        </div>
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            </div>
            <h3>Tarpaulin Printing</h3>
            <p>Eye-catching tarpaulin banners for events, businesses, birthdays, and promotions in any size you need.</p>
        </div>
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M12 4v16"/><circle cx="8" cy="10" r="2"/><path d="M6 16l2.5-2.5"/></svg>
            </div>
            <h3>ID & Card Printing</h3>
            <p>Professional ID cards, business cards, and membership cards with custom designs and layouts.</p>
        </div>
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
            </div>
            <h3>Invitations</h3>
            <p>Beautifully designed invitations for weddings, birthdays, debuts, and any special occasion.</p>
        </div>
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 12l2 2 4-4"/></svg>
            </div>
            <h3>Stickers & Labels</h3>
            <p>Custom stickers and product labels printed on high-quality adhesive material, perfect for branding.</p>
        </div>
        <div class="service-card">
            <div class="service-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
            </div>
            <h3>Photo Printing</h3>
            <p>Glossy or matte photo prints in various sizes. ID photos, passport photos, and photo enlargements available.</p>
        </div>
    </div>
</section>

<section class="section">
    <div class="section-header">
        <p class="section-label">Why Choose Us</p>
        <h2 class="section-title">Trusted by Toril Since Day One</h2>
    </div>
    <div class="features-grid">
        <div class="feature-item">
            <div class="feature-number">500+</div>
            <h4>Happy Customers</h4>
            <p>Serving students, businesses, and the community.</p>
        </div>
        <div class="feature-item">
            <div class="feature-number">24hr</div>
            <h4>Fast Turnaround</h4>
            <p>Most orders ready within 24 hours or less.</p>
        </div>
        <div class="feature-item">
            <div class="feature-number">100%</div>
            <h4>Quality Guaranteed</h4>
            <p>Premium materials and professional equipment.</p>
        </div>
        <div class="feature-item">
            <div class="feature-number">Best</div>
            <h4>Affordable Rates</h4>
            <p>Competitive pricing for all printing needs.</p>
        </div>
    </div>
</section>

<section class="cta-section" id="contact">
    <h2>Ready to Place an Order?</h2>
    <p>Sign up or log in to start ordering online. No more long lines!</p>
    <div class="hero-buttons">
        <button class="btn-primary" onclick="openLoginModal('register')">
            Create Account
        </button>
        <button class="btn-outline" onclick="openLoginModal('login')">
            Log In
        </button>
    </div>
    <div style="margin-top: 3rem; color: var(--text-muted); font-size: 0.95rem;">
        <p>📍 Located in Toril, Davao City</p>
        <p>📞 Contact: 09XX-XXX-XXXX</p>
        <p>📧 ziansprintshop@gmail.com</p>
        <p>👋 Follow us on social media! https://www.facebook.com/zianadvertiser</p>
        
    </div>
</section>

<footer class="footer">
    <div class="footer-inner">
        <p>&copy; 2026 Zian's Printing Shop. All rights reserved.</p>
        <div class="footer-links">
            <a href="#home">Home</a>
            <a href="#services">Services</a>
            <a href="#contact">Contact</a>
        </div>
    </div>
</footer>

<div class="modal-overlay" id="authModal">
    <div class="modal-card">
        <button class="modal-close" onclick="closeLoginModal()">&times;</button>
        <div class="modal-header">
            <div class="modal-logo">Z</div>
            <h2 id="modalTitle">Welcome Back</h2>
            <p id="modalSubtitle">Log in to your account</p>
        </div>

        
        <div class="modal-tabs">
            <button class="modal-tab active" id="tabLogin" onclick="switchTab('login')">Login</button>
            <button class="modal-tab" id="tabRegister" onclick="switchTab('register')">Register</button>
        </div>

        
        <div class="alert-msg" id="authAlert"></div>

        
        <form class="modal-form" id="loginForm" onsubmit="handleLogin(event)">
            <div class="form-group">
                <label class="form-label">Username or Email</label>
                <input type="text" name="username" class="form-input" placeholder="Enter your username or email" required>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" class="form-input" placeholder="••••••••" required>
                    <button type="button" class="password-toggle" onclick="togglePassword(this)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-submit">Log In</button>
            <div class="form-footer">
                Don't have an account? <a href="#" onclick="switchTab('register'); return false;">Register here</a>
            </div>
        </form>

        
        <form class="modal-form" id="registerForm" style="display:none;" onsubmit="handleRegister(event)">
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-input" placeholder="Choose a username" required minlength="3" maxlength="40" pattern="[A-Za-z0-9_.-]{3,40}">
                <label class="form-label">First Name</label>
                <input type="text" name="first_name" class="form-input" placeholder="Juan" required>
                <label class="form-label">Last Name</label>
                <input type="text" name="last_name" class="form-input" placeholder="Dela Cruz" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-input" placeholder="email@example.com" required>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                <div class="form-group">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-input" placeholder="09XX-XXX-XXXX" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-input" placeholder="Toril, Davao City" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" class="form-input" placeholder="8-16 chars: Aa1!" required minlength="8" maxlength="16" pattern="(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,16}" title="Use 8-16 characters with uppercase, lowercase, number and special character">
                    <button type="button" class="password-toggle" onclick="togglePassword(this)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <div class="password-wrapper">
                    <input type="password" name="confirm_password" class="form-input" placeholder="Re-enter password" required minlength="8" maxlength="16">
                    <button type="button" class="password-toggle" onclick="togglePassword(this)">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-submit">Create Account</button>
            <div class="form-footer">
                Already have an account? <a href="#" onclick="switchTab('login'); return false;">Log in here</a>
            </div>
        </form>
    </div>
</div>

<div id="chatbot">
    
    <button id="chatbot-toggle" onclick="toggleChatbot()" title="Chat with us">
        <svg id="chatbot-icon-open" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        <svg id="chatbot-icon-close" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
        <span id="chatbot-badge" style="display:none;">1</span>
    </button>

    
    <div id="chatbot-window">
        <div id="chatbot-header">
            <div class="chatbot-header-info">
                <div class="chatbot-avatar">Z</div>
                <div>
                    <div class="chatbot-name">Zian's Assistant</div>
                    <div class="chatbot-status"><span class="status-dot"></span> Online</div>
                </div>
            </div>
            <button class="chatbot-close-btn" onclick="toggleChatbot()" title="Close chat">&times;</button>
        </div>
        <div id="chatbot-messages">
            
        </div>
        <div id="chatbot-quick-replies">
            
        </div>
        <form id="chatbot-input-form" onsubmit="sendChatMessage(event)">
            <input type="text" id="chatbot-input" placeholder="Type your message..." autocomplete="off">
            <button type="submit" id="chatbot-send" title="Send">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
            </button>
        </form>
    </div>
</div>

<style>

#chatbot { position: fixed; bottom: 24px; right: 24px; z-index: 9999; font-family: 'Inter', system-ui, -apple-system, sans-serif; }

#chatbot-toggle {
    width: 60px; height: 60px; border-radius: 50%; border: none;
    background: linear-gradient(135deg, #ff6b35, #e85d26);
    color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 20px rgba(255,107,53,0.4);
    transition: all 0.3s ease; position: relative;
}
#chatbot-toggle:hover { transform: scale(1.08); box-shadow: 0 6px 28px rgba(255,107,53,0.55); }
#chatbot-badge {
    position: absolute; top: -2px; right: -2px; background: #ef4444; color: #fff;
    font-size: 11px; font-weight: 700; width: 20px; height: 20px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; border: 2px solid #0a0a0a;
}

#chatbot-window {
    display: none; position: absolute; bottom: 72px; right: 0;
    width: 380px; max-height: 520px; border-radius: 16px; overflow: hidden;
    background: #ffffff; border: 1px solid #e2e8f0;
    box-shadow: 0 12px 48px rgba(0,0,0,0.12);
    flex-direction: column; animation: chatSlideUp 0.3s ease;
}
#chatbot-window.open { display: flex; }

@keyframes chatSlideUp {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}

#chatbot-header {
    background: linear-gradient(135deg, #ff6b35, #e05520);
    padding: 14px 16px; display: flex; align-items: center; justify-content: space-between;
}
.chatbot-header-info { display: flex; align-items: center; gap: 10px; }
.chatbot-avatar {
    width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,0.2);
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 18px; color: #fff;
}
.chatbot-name { font-weight: 700; font-size: 15px; color: #fff; }
.chatbot-status { font-size: 12px; color: rgba(255,255,255,0.85); display: flex; align-items: center; gap: 5px; }
.status-dot { width: 7px; height: 7px; border-radius: 50%; background: #4ade80; display: inline-block; }
.chatbot-close-btn {
    background: rgba(255,255,255,0.15); border: none; color: #fff; width: 30px; height: 30px;
    border-radius: 50%; font-size: 18px; cursor: pointer; display: flex; align-items: center;
    justify-content: center; transition: background 0.2s;
}
.chatbot-close-btn:hover { background: rgba(255,255,255,0.3); }

#chatbot-messages {
    flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px;
    min-height: 260px; max-height: 320px; scroll-behavior: smooth;
    background: #ffffff;
}
#chatbot-messages::-webkit-scrollbar { width: 5px; }
#chatbot-messages::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

.chat-msg { max-width: 85%; padding: 10px 14px; border-radius: 14px; font-size: 13.5px; line-height: 1.55; word-wrap: break-word; }
.chat-msg.bot {
    align-self: flex-start; background: #f1f5f9; color: #1e293b;
    border-bottom-left-radius: 4px;
}
.chat-msg.user {
    align-self: flex-end; background: linear-gradient(135deg, #ff6b35, #e85d26); color: #fff;
    border-bottom-right-radius: 4px;
}
.chat-msg.bot strong, .chat-msg.bot b { color: #ff6b35; }

.chat-typing { align-self: flex-start; display: flex; gap: 4px; padding: 12px 16px; background: #f1f5f9; border-radius: 14px; border-bottom-left-radius: 4px; }
.chat-typing span {
    width: 7px; height: 7px; background: #94a3b8; border-radius: 50%;
    animation: typingBounce 1.2s infinite;
}
.chat-typing span:nth-child(2) { animation-delay: 0.2s; }
.chat-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes typingBounce {
    0%, 80%, 100% { transform: translateY(0); opacity: 0.4; }
    40% { transform: translateY(-6px); opacity: 1; }
}

#chatbot-quick-replies {
    padding: 0 16px 8px; display: flex; flex-wrap: wrap; gap: 6px;
    background: #ffffff;
}
.quick-reply-btn {
    background: #ffffff; border: 1.5px solid #ff6b35; color: #ff6b35; padding: 6px 14px;
    border-radius: 20px; font-size: 12px; cursor: pointer; transition: all 0.2s; font-family: inherit;
    font-weight: 500;
}
.quick-reply-btn:hover { background: #ff6b35; color: #fff; }

#chatbot-input-form {
    display: flex; align-items: center; gap: 8px; padding: 10px 12px;
    border-top: 1px solid #f1f5f9; background: #ffffff;
}
#chatbot-input {
    flex: 1; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 20px;
    padding: 9px 16px; color: #1e293b; font-size: 13.5px; outline: none;
    font-family: inherit; transition: border-color 0.2s;
}
#chatbot-input::placeholder { color: #94a3b8; }
#chatbot-input:focus { border-color: #ff6b35; background: #ffffff; }
#chatbot-send {
    width: 38px; height: 38px; border-radius: 50%; border: none;
    background: #ff6b35; color: #fff; cursor: pointer; display: flex;
    align-items: center; justify-content: center; transition: background 0.2s; flex-shrink: 0;
}
#chatbot-send:hover { background: #e55a2b; }

@media (max-width: 480px) {
    #chatbot-window { width: calc(100vw - 32px); right: -8px; bottom: 68px; max-height: 70vh; }
    #chatbot-toggle { width: 52px; height: 52px; }
}
</style>

<script>

let chatbotOpen = false;
let chatbotReady = false;
let chatHistory = [];

function toggleChatbot() {
    const win = document.getElementById('chatbot-window');
    const iconOpen = document.getElementById('chatbot-icon-open');
    const iconClose = document.getElementById('chatbot-icon-close');
    const badge = document.getElementById('chatbot-badge');

    chatbotOpen = !chatbotOpen;
    if (chatbotOpen) {
        win.classList.add('open');
        iconOpen.style.display = 'none';
        iconClose.style.display = 'block';
        badge.style.display = 'none';
        if (!chatbotReady) {
            chatbotReady = true;
            setTimeout(() => {
                appendBotMessage("Hello! 👋 Welcome to **Zian's Printing Shop**! I'm your AI assistant powered by Gemini.\n\nI can help you with our products, pricing, how to order, and more! What would you like to know?",
                    ['Services', 'How to Order', 'Products', 'Contact']);
            }, 400);
        }
        document.getElementById('chatbot-input').focus();
    } else {
        win.classList.remove('open');
        iconOpen.style.display = 'block';
        iconClose.style.display = 'none';
    }
}

function sendChatMessage(e) {
    e.preventDefault();
    const input = document.getElementById('chatbot-input');
    const msg = input.value.trim();
    if (!msg) return;

    appendUserMessage(msg);
    chatHistory.push({ role: 'user', text: msg });
    input.value = '';
    clearQuickReplies();
    showTyping();

    fetch('chatbot_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ message: msg, history: chatHistory.slice(-10) })
    })
    .then(r => r.json())
    .then(data => {
        removeTyping();
        chatHistory.push({ role: 'assistant', text: data.reply });
        appendBotMessage(data.reply, data.quickReplies || []);
    })
    .catch(() => {
        removeTyping();
        appendBotMessage("Sorry, something went wrong. Please try again!", ['Services', 'How to Order', 'Contact']);
    });
}

function appendUserMessage(text) {
    const container = document.getElementById('chatbot-messages');
    const div = document.createElement('div');
    div.className = 'chat-msg user';
    div.textContent = text;
    container.appendChild(div);
    scrollChat();
}

function appendBotMessage(text, quickReplies) {
    const container = document.getElementById('chatbot-messages');
    const div = document.createElement('div');
    div.className = 'chat-msg bot';
    div.innerHTML = formatBotText(text);
    container.appendChild(div);
    scrollChat();

    if (quickReplies && quickReplies.length > 0) {
        showQuickReplies(quickReplies);
    }

    
    if (!chatbotOpen) {
        document.getElementById('chatbot-badge').style.display = 'flex';
    }
}

function formatBotText(text) {
    
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    
    text = text.replace(/\n/g, '<br>');
    return text;
}

function showQuickReplies(replies) {
    const container = document.getElementById('chatbot-quick-replies');
    container.innerHTML = '';
    replies.forEach(r => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'quick-reply-btn';
        btn.textContent = r;
        btn.onclick = () => {
            document.getElementById('chatbot-input').value = r;
            sendChatMessage(new Event('submit'));
        };
        container.appendChild(btn);
    });
}

function clearQuickReplies() {
    document.getElementById('chatbot-quick-replies').innerHTML = '';
}

function showTyping() {
    const container = document.getElementById('chatbot-messages');
    const typing = document.createElement('div');
    typing.className = 'chat-typing';
    typing.id = 'typing-indicator';
    typing.innerHTML = '<span></span><span></span><span></span>';
    container.appendChild(typing);
    scrollChat();
}

function removeTyping() {
    const el = document.getElementById('typing-indicator');
    if (el) el.remove();
}

function scrollChat() {
    const container = document.getElementById('chatbot-messages');
    setTimeout(() => container.scrollTop = container.scrollHeight, 50);
}

setTimeout(() => {
    if (!chatbotOpen) {
        document.getElementById('chatbot-badge').style.display = 'flex';
    }
}, 3000);
</script>

<script src="js/main.js"></script>
</body>
</html>

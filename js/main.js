

const navbar = document.getElementById('navbar');
window.addEventListener('scroll', () => {
    if (window.scrollY > 50) {
        navbar.classList.add('scrolled');
    } else {
        navbar.classList.remove('scrolled');
    }
});

function toggleMobileMenu() {
    document.getElementById('navMenu').classList.toggle('show');
}

document.querySelectorAll('.navbar-nav a').forEach(link => {
    link.addEventListener('click', () => {
        document.getElementById('navMenu').classList.remove('show');
    });
});

document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function(e) {
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

const sections = document.querySelectorAll('section[id]');
window.addEventListener('scroll', () => {
    let current = '';
    sections.forEach(section => {
        const top = section.offsetTop - 100;
        if (window.scrollY >= top) {
            current = section.getAttribute('id');
        }
    });
    document.querySelectorAll('.navbar-nav a').forEach(a => {
        a.classList.remove('active');
        if (a.getAttribute('href') === '#' + current) {
            a.classList.add('active');
        }
    });
});

function openLoginModal(tab) {
    const modal = document.getElementById('authModal');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    hideAlert();
    if (tab === 'register') {
        switchTab('register');
    } else {
        switchTab('login');
    }
}

function closeLoginModal() {
    const modal = document.getElementById('authModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

document.getElementById('authModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeLoginModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeLoginModal();
    }
});

function switchTab(tab) {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const tabLogin = document.getElementById('tabLogin');
    const tabRegister = document.getElementById('tabRegister');
    const title = document.getElementById('modalTitle');
    const subtitle = document.getElementById('modalSubtitle');

    hideAlert();

    if (tab === 'register') {
        loginForm.style.display = 'none';
        registerForm.style.display = 'flex';
        tabLogin.classList.remove('active');
        tabRegister.classList.add('active');
        title.textContent = 'Create Account';
        subtitle.textContent = 'Sign up to start ordering';
    } else {
        loginForm.style.display = 'flex';
        registerForm.style.display = 'none';
        tabLogin.classList.add('active');
        tabRegister.classList.remove('active');
        title.textContent = 'Welcome Back';
        subtitle.textContent = 'Log in to your account';
    }
}

function togglePassword(btn) {
    const input = btn.parentElement.querySelector('.form-input');
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
    } else {
        input.type = 'password';
        btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    }
}

function showAlert(message, type) {
    const alert = document.getElementById('authAlert');
    alert.textContent = message;
    alert.className = 'alert-msg ' + type;
    alert.style.display = 'block';
}

function hideAlert() {
    const alert = document.getElementById('authAlert');
    alert.style.display = 'none';
    alert.className = 'alert-msg';
}

function handleLogin(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'customer_login');

    const btn = form.querySelector('.btn-submit');
    btn.textContent = 'Logging in...';
    btn.disabled = true;

    fetch('index.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert('Login successful! Redirecting...', 'success');
            setTimeout(() => {
                window.location.href = data.redirect;
            }, 500);
        } else {
            showAlert(data.message, 'error');
            btn.textContent = 'Log In';
            btn.disabled = false;
        }
    })
    .catch(() => {
        showAlert('Something went wrong. Please try again.', 'error');
        btn.textContent = 'Log In';
        btn.disabled = false;
    });
}

function handleRegister(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    formData.append('action', 'customer_register');

    const btn = form.querySelector('.btn-submit');
    btn.textContent = 'Creating account...';
    btn.disabled = true;

    fetch('index.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert(data.message, 'success');
            form.reset();
            setTimeout(() => {
                switchTab('login');
            }, 1500);
        } else {
            showAlert(data.message, 'error');
        }
        btn.textContent = 'Create Account';
        btn.disabled = false;
    })
    .catch(() => {
        showAlert('Something went wrong. Please try again.', 'error');
        btn.textContent = 'Create Account';
        btn.disabled = false;
    });
}

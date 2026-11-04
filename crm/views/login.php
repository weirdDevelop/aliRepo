<?php
/**
 * جاکجاست - صفحه لاگین
 * Jakojast - Login Page
 *
 * اگر کاربر قبلاً لاگین کرده، index.php او را به داشبورد هدایت می‌کند.
 */
if (!defined('BASE_URL')) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    define('BASE_URL', rtrim($scriptDir, '/'));
}
$csrfToken = Helpers::generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود | جاکجاست</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='6' fill='%23e50241'/><text x='16' y='22' font-size='18' font-family='Tahoma' fill='white' text-anchor='middle' font-weight='bold'>ج</text></svg>">
    <style>
        /* استایل‌های اضافی برای صفحه لاگین */
        body {
            background: linear-gradient(135deg, #1a0a0e 0%, #2d0a18 50%, #1a0a0e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-wrapper {
            width: 100%;
            max-width: 420px;
        }
        .login-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(229, 2, 65, 0.3);
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #e50241, #c0021f);
            color: #fff;
            padding: 32px 24px;
            text-align: center;
        }
        .login-logo {
            width: 72px;
            height: 72px;
            background: rgba(255,255,255,0.15);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 16px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .login-header h1 {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .login-header p {
            font-size: 13px;
            opacity: 0.9;
        }
        .login-body {
            padding: 32px 28px;
        }
        .login-form .form-group {
            margin-bottom: 18px;
        }
        .login-form label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #555;
            margin-bottom: 6px;
        }
        .login-form .input-wrap {
            position: relative;
        }
        .login-form .input-wrap .input-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            color: #999;
            pointer-events: none;
        }
        .login-form input {
            width: 100%;
            padding: 12px 40px 12px 14px;
            border: 1.5px solid #e0e0e0;
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.2s;
            background: #fafafa;
        }
        .login-form input:focus {
            outline: none;
            border-color: #e50241;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(229, 2, 65, 0.08);
        }
        .login-form .toggle-pass {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            font-size: 16px;
            color: #999;
            background: none;
            border: none;
            padding: 4px;
        }
        .login-form .toggle-pass:hover { color: #e50241; }
        .login-form .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #e50241, #c0021f);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(229, 2, 65, 0.3);
        }
        .login-form .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(229, 2, 65, 0.4);
        }
        .login-form .btn-login:active {
            transform: translateY(0);
        }
        .login-form .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .login-error {
            background: #ffeaea;
            color: #c0021f;
            border: 1px solid #f5c2c7;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
            display: none;
            align-items: center;
            gap: 8px;
        }
        .login-error.show { display: flex; }
        .login-info {
            margin-top: 20px;
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 12px;
            color: #666;
            text-align: center;
            line-height: 1.7;
        }
        .login-info strong { color: #e50241; }
        .login-footer {
            text-align: center;
            padding: 16px 28px;
            background: #f8f9fa;
            border-top: 1px solid #eee;
            font-size: 12px;
            color: #999;
        }
        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-left: 8px;
            vertical-align: middle;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .login-bg-decoration {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: -1;
            overflow: hidden;
        }
        .login-bg-decoration::before,
        .login-bg-decoration::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            opacity: 0.1;
        }
        .login-bg-decoration::before {
            width: 400px;
            height: 400px;
            background: #e50241;
            top: -100px;
            right: -100px;
        }
        .login-bg-decoration::after {
            width: 300px;
            height: 300px;
            background: #6f42c1;
            bottom: -80px;
            left: -80px;
        }
    </style>
</head>
<body>
<div class="login-bg-decoration"></div>
<div id="toast-container"></div>

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <div class="login-logo">ج</div>
            <h1>جاکجاست</h1>
            <p>پنل مدیریت یکپارچه</p>
        </div>

        <div class="login-body">
            <div id="login-error" class="login-error">
                <span>❌</span>
                <span id="login-error-msg"></span>
            </div>

            <form class="login-form" id="login-form" onsubmit="return doLogin(event)">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="form-group">
                    <label for="username">نام کاربری</label>
                    <div class="input-wrap">
                        <span class="input-icon">👤</span>
                        <input type="text" id="username" name="username" placeholder="نام کاربری خود را وارد کنید" required autofocus autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">رمز عبور</label>
                    <div class="input-wrap">
                        <span class="input-icon">🔒</span>
                        <input type="password" id="password" name="password" placeholder="رمز عبور خود را وارد کنید" required autocomplete="current-password">
                        <button type="button" class="toggle-pass" onclick="togglePassword()">👁</button>
                    </div>
                </div>

                <div class="form-group" style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:400;font-size:13px">
                        <input type="checkbox" id="remember" name="remember" style="width:auto"> مرا به خاطر بسپار
                    </label>
                    <a href="#" onclick="forgotPassword();return false;" style="font-size:12px;color:var(--primary);text-decoration:none">رمز را فراموش کرده‌اید؟</a>
                </div>

                <button type="submit" id="login-btn" class="btn-login">
                    <span id="btn-text">ورود به سیستم</span>
                </button>
            </form>

            <div class="login-info">
                🛡️ برای اولین ورود از نام کاربری <strong>admin</strong> و رمز <strong>123</strong> استفاده کنید.<br>
                پس از اولین ورود، حتماً رمز خود را تغییر دهید.
            </div>
        </div>

        <div class="login-footer">
            نسخه ۳.۰ &mdash; &copy; ۱۴۰۳ جاکجاست
        </div>
    </div>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';

// نمایش/مخفی رمز
function togglePassword() {
    const input = document.getElementById('password');
    input.type = input.type === 'password' ? 'text' : 'password';
}

// نمایش خطا
function showError(msg) {
    const box = document.getElementById('login-error');
    document.getElementById('login-error-msg').textContent = msg;
    box.classList.add('show');
    setTimeout(() => box.classList.remove('show'), 5000);
}

// نمایش Toast
function showToast(msg, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) {
        alert(msg);
        return;
    }
    const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<span class="toast-icon">${icons[type] || 'ℹ️'}</span><span class="toast-msg">${msg}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

// فراموشی رمز
function forgotPassword() {
    showToast('برای بازیابی رمز با مدیر سیستم تماس بگیرید', 'info');
}

// ارسال فرم لاگین
async function doLogin(event) {
    event.preventDefault();

    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    const remember = document.getElementById('remember').checked;

    if (!username || !password) {
        showError('نام کاربری و رمز عبور را وارد کنید');
        return false;
    }

    // غیرفعال کردن دکمه
    const btn = document.getElementById('login-btn');
    const btnText = document.getElementById('btn-text');
    btn.disabled = true;
    btnText.innerHTML = 'در حال ورود...<span class="spinner"></span>';

    try {
        const formData = new FormData();
        formData.append('username', username);
        formData.append('password', password);

        const response = await fetch(`${BASE_URL}/api.php/auth/login`, {
            method: 'POST',
            body: formData,
        });

        const data = await response.json();

        if (data.success) {
            // ذخیره توکن در localStorage
            localStorage.setItem('jakojast_token', data.data.token);
            localStorage.setItem('jakojast_user', JSON.stringify(data.data.user));

            if (remember) {
                localStorage.setItem('jakojast_remember', username);
            } else {
                localStorage.removeItem('jakojast_remember');
            }

            showToast('ورود موفق! در حال هدایت...', 'success');

            // هدایت به داشبورد
            setTimeout(() => {
                window.location.href = `${BASE_URL}/index.php?page=dashboard`;
            }, 800);
        } else {
            showError(data.message || 'خطا در ورود');
            btn.disabled = false;
            btnText.textContent = 'ورود به سیستم';
        }
    } catch (err) {
        showError('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
        console.error(err);
        btn.disabled = false;
        btnText.textContent = 'ورود به سیستم';
    }

    return false;
}

// پر کردن نام کاربری در صورت remember
document.addEventListener('DOMContentLoaded', function() {
    const remembered = localStorage.getItem('jakojast_remember');
    if (remembered) {
        document.getElementById('username').value = remembered;
        document.getElementById('remember').checked = true;
        document.getElementById('password').focus();
    }
});

// ورود با Enter
document.getElementById('password').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        document.getElementById('login-form').dispatchEvent(new Event('submit'));
    }
});
</script>
</body>
</html>

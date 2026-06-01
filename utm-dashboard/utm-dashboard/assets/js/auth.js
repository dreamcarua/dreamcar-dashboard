// === auth.js ===
// JavaScript для сторінки авторизації

(function() {
    'use strict';

    // Password toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);

            // Змінити іконку
            const eyeIcon = this.querySelector('.eye-icon');
            if (eyeIcon) {
                eyeIcon.textContent = type === 'password' ? '👁️' : '🙈';
            }
        });
    }

    // Форма - validation
    const loginForm = document.getElementById('loginForm');

    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;

            if (!username || !password) {
                e.preventDefault();
                alert('Будь ласка, заповніть всі поля');
                return false;
            }

            // Показати індикатор завантаження
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span>Завантаження...</span> <span class="btn-icon">⏳</span>';
            }
        });
    }

    // Автофокус на поле логіна
    const usernameInput = document.getElementById('username');
    if (usernameInput && !usernameInput.value) {
        usernameInput.focus();
    }

    console.log('🔐 Auth.js завантажено');
})();

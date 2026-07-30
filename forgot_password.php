<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit();
}

$supportMailto = 'mailto:' . MAIL_FROM_EMAIL . '?subject=' . rawurlencode('KWASU LCS Password Reset Support');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php $pageTitle = 'Forgot Password'; include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="auth-body">
    <div class="auth-theme-toggle">
        <button class="theme-toggle" role="switch" onclick="toggleTheme()" title="Dark mode"></button>
    </div>
    <div class="auth-shell auth-shell--narrow">
        <div class="auth-panel auth-panel-right auth-panel-right--solo">

            <div class="reset-progress" id="resetProgress">
                <div class="reset-progress-track"><div class="reset-progress-fill" id="resetProgressFill"></div></div>
                <span class="reset-progress-label" id="resetProgressLabel">Step 1 of 6</span>
            </div>

            <!-- Step 1 — Matric number -->
            <section class="reset-step active" data-step="1">
                <div class="auth-forgot-icon"><i class="bi bi-person-badge-fill"></i></div>
                <h2>Forgot Password</h2>
                <p class="muted">Recover access to your student account.</p>
                <p class="reset-desc">Enter your matric number to begin the password recovery process.</p>

                <div class="alert error" id="step1Error" hidden></div>

                <div class="reset-support-box" id="step1Support" hidden>
                    <p>Still having trouble? Contact Technical Support for assistance.</p>
                    <a class="btn secondary" href="<?php echo h($supportMailto); ?>"><i class="bi bi-life-preserver"></i> Contact Technical Support</a>
                </div>

                <form class="auth-form auth-form--reset" id="step1Form" autocomplete="off">
                    <label for="matricInput">Matric Number</label>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon"><i class="bi bi-person-fill"></i></span>
                        <input type="text" id="matricInput" name="matric_no" required placeholder="Enter your matric number">
                    </div>

                    <div class="reset-btn-row">
                        <a class="btn secondary" href="index.php"><i class="bi bi-arrow-left"></i> Back to Login</a>
                        <button type="submit" class="btn primary auth-submit-btn" id="step1Submit">Continue <i class="bi bi-arrow-right"></i></button>
                    </div>
                </form>
            </section>

            <!-- Step 2 — Verify email -->
            <section class="reset-step" data-step="2">
                <div class="auth-forgot-icon"><i class="bi bi-envelope-check-fill"></i></div>
                <h2>Verify Your Email</h2>
                <p class="muted">Confirm the email address linked to your student account.</p>
                <p class="reset-desc">Enter the email address associated with your matric number.</p>

                <div class="alert error" id="step2Error" hidden></div>

                <div class="reset-support-box" id="step2Support" hidden>
                    <a class="btn secondary" href="<?php echo h($supportMailto); ?>"><i class="bi bi-life-preserver"></i> Contact Technical Support</a>
                    <p class="reset-support-hint">If you're unsure which email address is linked to your account, Technical Support can help you verify it.</p>
                </div>

                <form class="auth-form auth-form--reset" id="step2Form" autocomplete="off">
                    <label for="emailInput">Email Address</label>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon"><i class="bi bi-envelope-fill"></i></span>
                        <input type="email" id="emailInput" name="email" required placeholder="Enter your registered email">
                    </div>

                    <div class="reset-btn-row">
                        <button type="button" class="btn secondary" onclick="goToStep(1)"><i class="bi bi-arrow-left"></i> Back</button>
                        <button type="submit" class="btn primary auth-submit-btn" id="step2Submit">Continue <i class="bi bi-arrow-right"></i></button>
                    </div>
                </form>
            </section>

            <!-- Step 3 — CAPTCHA -->
            <section class="reset-step" data-step="3">
                <div class="auth-forgot-icon"><i class="bi bi-shield-lock-fill"></i></div>
                <h2>Security Verification</h2>
                <p class="muted">This extra step helps us stop automated password reset requests.</p>
                <p class="reset-desc">Enter the characters shown below to continue.</p>

                <div class="alert error" id="step3Error" hidden></div>

                <form class="auth-form auth-form--reset" id="step3Form" autocomplete="off">
                    <div class="captcha-box">
                        <img src="fp_captcha_image.php" alt="CAPTCHA challenge" id="captchaImg" class="captcha-img">
                        <button type="button" class="captcha-refresh" id="captchaRefresh" title="Get a new challenge"><i class="bi bi-arrow-clockwise"></i></button>
                    </div>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon"><i class="bi bi-keyboard-fill"></i></span>
                        <input type="text" id="captchaInput" required placeholder="Enter the characters above" autocomplete="off" autocapitalize="characters">
                    </div>

                    <div class="reset-btn-row">
                        <button type="button" class="btn secondary" onclick="goToStep(2)"><i class="bi bi-arrow-left"></i> Back</button>
                        <button type="submit" class="btn primary auth-submit-btn" id="step3Submit">Verify <i class="bi bi-arrow-right"></i></button>
                    </div>
                </form>
            </section>

            <!-- Step 4 — Sending code (transient) -->
            <section class="reset-step" data-step="4">
                <div class="reset-sending" id="sendingState">
                    <div class="reset-spinner"></div>
                    <h2>Sending Verification Code&hellip;</h2>
                    <p class="muted">Please wait while we email your code.</p>
                </div>
                <div class="reset-sent" id="sentState" hidden>
                    <div class="auth-forgot-icon"><i class="bi bi-check-circle-fill"></i></div>
                    <h2>Verification Code Sent</h2>
                    <p class="muted">A six-digit verification code has been sent to your registered email address. Please check your inbox and spam folder.</p>
                </div>
                <div class="alert error" id="step4Error" hidden></div>
            </section>

            <!-- Step 5 — Enter code -->
            <section class="reset-step" data-step="5">
                <div class="auth-forgot-icon"><i class="bi bi-envelope-open-fill"></i></div>
                <h2>Verify Email</h2>
                <p class="reset-desc">Enter the six-digit verification code sent to your email.</p>

                <div class="reset-countdown" id="codeCountdown">Code expires in: <span id="countdownValue">02:00</span></div>

                <div class="alert error" id="step5Error" hidden></div>
                <div class="reset-support-box" id="step5Lockout" hidden>
                    <button type="button" class="btn primary auth-submit-btn" id="btnRecaptcha"><i class="bi bi-shield-lock-fill"></i> Verify CAPTCHA to Continue</button>
                </div>

                <form class="auth-form auth-form--reset" id="step5Form" autocomplete="off">
                    <label for="codeInput">Verification Code</label>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon"><i class="bi bi-hash"></i></span>
                        <input type="text" id="codeInput" inputmode="numeric" maxlength="6" required placeholder="6-digit code" class="reset-code-input">
                    </div>

                    <div class="reset-btn-row">
                        <button type="button" class="btn secondary" id="btnResend" disabled>Resend Code</button>
                        <button type="submit" class="btn primary auth-submit-btn" id="step5Submit">Verify Code</button>
                    </div>
                </form>
            </section>

            <!-- Step 6 — New password -->
            <section class="reset-step" data-step="6">
                <div class="auth-forgot-icon"><i class="bi bi-key-fill"></i></div>
                <h2>Create New Password</h2>
                <p class="muted">Choose a strong password for your account.</p>

                <div class="alert error" id="step6Error" hidden></div>

                <form class="auth-form auth-form--reset" id="step6Form" autocomplete="off">
                    <label for="newPassword">New Password</label>
                    <div class="auth-input-wrap password-field">
                        <span class="auth-input-icon"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" id="newPassword" required placeholder="Enter a new password">
                        <button type="button" class="password-toggle" data-target="newPassword" aria-label="Show password"><i class="bi bi-eye-fill"></i></button>
                    </div>

                    <div class="pw-strength">
                        <div class="pw-strength-track"><div class="pw-strength-fill" id="pwStrengthFill"></div></div>
                        <span class="pw-strength-label" id="pwStrengthLabel">Password strength</span>
                    </div>

                    <ul class="pw-requirements" id="pwRequirements">
                        <li data-rule="length"><i class="bi bi-circle"></i> At least 8 characters</li>
                        <li data-rule="upper"><i class="bi bi-circle"></i> One uppercase letter</li>
                        <li data-rule="lower"><i class="bi bi-circle"></i> One lowercase letter</li>
                        <li data-rule="number"><i class="bi bi-circle"></i> One number</li>
                        <li data-rule="special"><i class="bi bi-circle"></i> One special character</li>
                    </ul>

                    <label for="confirmPassword">Confirm Password</label>
                    <div class="auth-input-wrap password-field">
                        <span class="auth-input-icon"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" id="confirmPassword" required placeholder="Re-enter the new password">
                        <button type="button" class="password-toggle" data-target="confirmPassword" aria-label="Show password"><i class="bi bi-eye-fill"></i></button>
                    </div>
                    <div class="reset-inline-error" id="confirmError" hidden>Passwords do not match.</div>

                    <div class="reset-btn-row">
                        <button type="submit" class="btn primary auth-submit-btn" id="step6Submit">Reset Password</button>
                    </div>
                </form>
            </section>

            <!-- Success -->
            <section class="reset-step" data-step="success">
                <div class="auth-forgot-icon"><i class="bi bi-check-circle-fill"></i></div>
                <h2>Password Reset Successful</h2>
                <p class="muted">Your password has been successfully updated. You can now sign in using your new password.</p>
                <a class="btn primary auth-submit-btn" href="index.php"><i class="bi bi-box-arrow-in-right"></i> Return to Login</a>
            </section>

        </div>
    </div>

    <script>
        const TOTAL_STEPS = 6;
        let currentStep = 1;
        let countdownTimer = null;

        function showStep(step) {
            document.querySelectorAll('.reset-step').forEach(el => el.classList.remove('active'));
            const target = document.querySelector('.reset-step[data-step="' + step + '"]');
            if (target) target.classList.add('active');

            const progress = document.getElementById('resetProgress');
            if (step === 'success') {
                progress.hidden = true;
            } else {
                progress.hidden = false;
                document.getElementById('resetProgressFill').style.width = (step / TOTAL_STEPS * 100) + '%';
                document.getElementById('resetProgressLabel').textContent = 'Step ' + step + ' of ' + TOTAL_STEPS;
            }
        }

        function goToStep(step) {
            currentStep = step;
            if (step === 3) refreshCaptcha();
            showStep(step);
        }

        function refreshCaptcha() {
            document.getElementById('captchaImg').src = 'fp_captcha_image.php?t=' + Date.now();
            document.getElementById('captchaInput').value = '';
        }

        function showError(id, message) {
            const el = document.getElementById(id);
            el.textContent = message;
            el.hidden = false;
        }

        function hideError(id) {
            document.getElementById(id).hidden = true;
        }

        async function postJson(url, payload) {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload || {})
            });
            return res.json();
        }

        function setLoading(btn, loading, label) {
            btn.disabled = loading;
            btn.dataset.originalHtml = btn.dataset.originalHtml || btn.innerHTML;
            btn.innerHTML = loading ? '<span class="btn-spinner"></span> ' + label : btn.dataset.originalHtml;
        }

        // ── Step 1: matric number ────────────────────────────────────────────
        document.getElementById('step1Form').addEventListener('submit', async function (e) {
            e.preventDefault();
            hideError('step1Error');
            document.getElementById('step1Support').hidden = true;
            const btn = document.getElementById('step1Submit');
            setLoading(btn, true, 'Checking…');
            try {
                const data = await postJson('fp_step1_matric.php', { matric_no: document.getElementById('matricInput').value.trim() });
                if (data.ok) {
                    goToStep(2);
                } else {
                    showError('step1Error', data.message);
                    if (data.locked) document.getElementById('step1Support').hidden = false;
                }
            } finally {
                setLoading(btn, false);
            }
        });

        // ── Step 2: email ────────────────────────────────────────────────────
        document.getElementById('step2Form').addEventListener('submit', async function (e) {
            e.preventDefault();
            hideError('step2Error');
            document.getElementById('step2Support').hidden = true;
            const btn = document.getElementById('step2Submit');
            setLoading(btn, true, 'Verifying…');
            try {
                const data = await postJson('fp_step2_email.php', { email: document.getElementById('emailInput').value.trim() });
                if (data.ok) {
                    goToStep(3);
                } else if (data.restart) {
                    location.href = 'forgot_password.php';
                } else {
                    showError('step2Error', data.message);
                    document.getElementById('step2Support').hidden = false;
                }
            } finally {
                setLoading(btn, false);
            }
        });

        // ── Step 3: CAPTCHA (also triggers the code send) ───────────────────
        document.getElementById('captchaRefresh').addEventListener('click', refreshCaptcha);

        document.getElementById('step3Form').addEventListener('submit', async function (e) {
            e.preventDefault();
            hideError('step3Error');
            const btn = document.getElementById('step3Submit');
            setLoading(btn, true, 'Verifying…');
            try {
                const data = await postJson('fp_step3_captcha.php', { captcha: document.getElementById('captchaInput').value.trim() });
                if (data.ok) {
                    goToStep(4);
                    sendCodeTransition(data.expires_in);
                } else if (data.restart) {
                    location.href = 'forgot_password.php';
                } else {
                    showError('step3Error', data.message);
                    refreshCaptcha();
                }
            } finally {
                setLoading(btn, false);
            }
        });

        function sendCodeTransition(expiresIn) {
            document.getElementById('sendingState').hidden = false;
            document.getElementById('sentState').hidden = true;
            hideError('step4Error');
            setTimeout(function () {
                document.getElementById('sendingState').hidden = true;
                document.getElementById('sentState').hidden = false;
                setTimeout(function () {
                    goToStep(5);
                    startCountdown(expiresIn);
                }, 1400);
            }, 700);
        }

        // ── Step 5: verify code ──────────────────────────────────────────────
        function startCountdown(seconds) {
            clearInterval(countdownTimer);
            let remaining = seconds;
            document.getElementById('codeCountdown').classList.remove('expired');
            document.getElementById('step5Submit').disabled = false;
            document.getElementById('btnResend').disabled = true;
            document.getElementById('step5Lockout').hidden = true;
            document.getElementById('codeInput').disabled = false;
            hideError('step5Error');
            updateCountdownDisplay(remaining);

            countdownTimer = setInterval(function () {
                remaining--;
                updateCountdownDisplay(remaining);
                if (remaining <= 0) {
                    clearInterval(countdownTimer);
                    document.getElementById('codeCountdown').classList.add('expired');
                    document.getElementById('countdownValue').textContent = '00:00';
                    document.getElementById('step5Submit').disabled = true;
                    document.getElementById('codeInput').disabled = true;
                    document.getElementById('btnResend').disabled = false;
                    showError('step5Error', 'Verification code expired. Please request a new code.');
                }
            }, 1000);
        }

        function updateCountdownDisplay(remaining) {
            const m = Math.floor(Math.max(remaining, 0) / 60);
            const s = Math.max(remaining, 0) % 60;
            document.getElementById('countdownValue').textContent =
                String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        }

        document.getElementById('step5Form').addEventListener('submit', async function (e) {
            e.preventDefault();
            hideError('step5Error');
            const btn = document.getElementById('step5Submit');
            setLoading(btn, true, 'Verifying…');
            try {
                const data = await postJson('fp_verify_code.php', { code: document.getElementById('codeInput').value.trim() });
                if (data.ok) {
                    clearInterval(countdownTimer);
                    goToStep(6);
                } else if (data.restart) {
                    location.href = 'forgot_password.php';
                } else if (data.reason === 'locked') {
                    clearInterval(countdownTimer);
                    showError('step5Error', data.message);
                    document.getElementById('step5Lockout').hidden = false;
                    document.getElementById('step5Submit').disabled = true;
                    document.getElementById('btnResend').disabled = true;
                } else {
                    showError('step5Error', data.message);
                }
            } finally {
                setLoading(btn, false);
            }
        });

        document.getElementById('btnResend').addEventListener('click', async function () {
            const btn = this;
            setLoading(btn, true, 'Sending…');
            try {
                const data = await postJson('fp_resend_code.php', {});
                if (data.ok) {
                    document.getElementById('codeInput').value = '';
                    startCountdown(data.expires_in);
                } else if (data.restart) {
                    location.href = 'forgot_password.php';
                } else if (data.need_captcha) {
                    goToStep(3);
                } else {
                    showError('step5Error', data.message);
                }
            } finally {
                setLoading(btn, false);
            }
        });

        document.getElementById('btnRecaptcha').addEventListener('click', function () {
            goToStep(3);
        });

        // ── Step 6: new password ─────────────────────────────────────────────
        document.querySelectorAll('.password-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const input = document.getElementById(btn.dataset.target);
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                btn.innerHTML = isHidden ? '<i class="bi bi-eye-slash-fill"></i>' : '<i class="bi bi-eye-fill"></i>';
                btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        });

        function passwordRuleState(password) {
            return {
                length: password.length >= 8,
                upper: /[A-Z]/.test(password),
                lower: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[^A-Za-z0-9]/.test(password)
            };
        }

        function evaluatePassword() {
            const password = document.getElementById('newPassword').value;
            const rules = passwordRuleState(password);
            let met = 0;
            Object.keys(rules).forEach(function (rule) {
                const li = document.querySelector('#pwRequirements li[data-rule="' + rule + '"]');
                const icon = li.querySelector('i');
                if (rules[rule]) {
                    li.classList.add('met');
                    icon.className = 'bi bi-check-circle-fill';
                    met++;
                } else {
                    li.classList.remove('met');
                    icon.className = 'bi bi-circle';
                }
            });

            const fill = document.getElementById('pwStrengthFill');
            const label = document.getElementById('pwStrengthLabel');
            const pct = (met / 5) * 100;
            fill.style.width = pct + '%';
            if (password === '') {
                fill.style.width = '0%';
                label.textContent = 'Password strength';
                fill.className = 'pw-strength-fill';
            } else if (met <= 2) {
                label.textContent = 'Weak';
                fill.className = 'pw-strength-fill weak';
            } else if (met <= 3) {
                label.textContent = 'Fair';
                fill.className = 'pw-strength-fill fair';
            } else if (met === 4) {
                label.textContent = 'Good';
                fill.className = 'pw-strength-fill good';
            } else {
                label.textContent = 'Strong';
                fill.className = 'pw-strength-fill strong';
            }
            return Object.values(rules).every(Boolean);
        }

        function checkPasswordsMatch() {
            const pw = document.getElementById('newPassword').value;
            const confirm = document.getElementById('confirmPassword').value;
            const mismatch = confirm !== '' && pw !== confirm;
            document.getElementById('confirmError').hidden = !mismatch;
            return !mismatch && confirm !== '';
        }

        document.getElementById('newPassword').addEventListener('input', function () {
            evaluatePassword();
            checkPasswordsMatch();
        });
        document.getElementById('confirmPassword').addEventListener('input', checkPasswordsMatch);

        document.getElementById('step6Form').addEventListener('submit', async function (e) {
            e.preventDefault();
            hideError('step6Error');
            const password = document.getElementById('newPassword').value;
            const confirm = document.getElementById('confirmPassword').value;

            if (!evaluatePassword()) {
                showError('step6Error', 'Password does not meet the requirements below.');
                return;
            }
            if (password !== confirm) {
                document.getElementById('confirmError').hidden = false;
                return;
            }

            const btn = document.getElementById('step6Submit');
            setLoading(btn, true, 'Updating…');
            try {
                const data = await postJson('fp_reset_password.php', { password: password, confirm_password: confirm });
                if (data.ok) {
                    showStep('success');
                } else if (data.restart) {
                    location.href = 'forgot_password.php';
                } else {
                    showError('step6Error', data.message);
                }
            } finally {
                setLoading(btn, false);
            }
        });
    </script>
</body>

</html>

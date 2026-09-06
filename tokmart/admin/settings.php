<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'الإعدادات';
$activeNav = 'settings';

$site = getSiteSettings();
$google = getGoogleOAuthConfig();

require __DIR__ . '/includes/header.php';
?>

<!-- ===== إعدادات الموقع ===== -->
<div class="admin-settings-group">
    <div class="group-header">
        <div class="group-icon"><i class="fa-solid fa-globe"></i></div>
        <h4>إعدادات الموقع</h4>
    </div>
    <div class="form-group">
        <label>اسم الموقع</label>
        <input type="text" id="siteNameInput" value="<?php echo htmlspecialchars($site['name']); ?>">
    </div>
    <div class="form-group">
        <label>وصف الموقع</label>
        <textarea id="siteDescriptionInput" rows="2"><?php echo htmlspecialchars($site['description']); ?></textarea>
    </div>
    <div class="form-group">
        <label>الشعار (Slogan)</label>
        <input type="text" id="siteSloganInput" value="<?php echo htmlspecialchars($site['slogan']); ?>">
    </div>
    <div class="form-group">
        <label>شعار الموقع (Logo)</label>
        <input type="file" id="siteLogoInput" accept="image/*">
        <div class="img-preview" id="siteLogoPreview">
            <?php if ($site['logo_url']): ?><img src="<?php echo htmlspecialchars($site['logo_url']); ?>"><?php else: ?><i class="fa-solid fa-image"></i><?php endif; ?>
        </div>
    </div>
    <button class="btn btn-md btn-primary" onclick="saveSiteSettings()">حفظ إعدادات الموقع</button>
    <div class="settings-status" id="siteStatus"></div>
</div>

<!-- ===== سياسة الخصوصية ===== -->
<div class="admin-settings-group">
    <div class="group-header">
        <div class="group-icon"><i class="fa-solid fa-shield"></i></div>
        <h4>سياسة الخصوصية</h4>
    </div>
    <div class="form-group">
        <textarea id="privacyPolicyInput" rows="6"><?php echo htmlspecialchars($site['privacy_policy']); ?></textarea>
    </div>
    <button class="btn btn-md btn-primary" onclick="savePolicy('privacy')">حفظ سياسة الخصوصية</button>
    <div class="settings-status" id="privacyStatus"></div>
</div>

<!-- ===== الشروط والأحكام ===== -->
<div class="admin-settings-group">
    <div class="group-header">
        <div class="group-icon"><i class="fa-solid fa-file-contract"></i></div>
        <h4>الشروط والأحكام</h4>
    </div>
    <div class="form-group">
        <textarea id="termsInput" rows="6"><?php echo htmlspecialchars($site['terms_conditions']); ?></textarea>
    </div>
    <button class="btn btn-md btn-primary" onclick="savePolicy('terms')">حفظ الشروط والأحكام</button>
    <div class="settings-status" id="termsStatus"></div>
</div>

<!-- ===== Google OAuth ===== -->
<div class="admin-settings-group">
    <div class="group-header">
        <div class="group-icon"><i class="fa-brands fa-google"></i></div>
        <h4>تسجيل الدخول عبر Google</h4>
    </div>
    <div class="form-group form-check">
        <input type="checkbox" id="googleEnabled" <?php echo !empty($google['enabled']) ? 'checked' : ''; ?>>
        <label for="googleEnabled" style="margin:0;">تفعيل تسجيل الدخول عبر Google</label>
    </div>
    <div class="form-group">
        <label>Client ID</label>
        <input type="text" id="googleClientIdInput" value="<?php echo htmlspecialchars($google['client_id']); ?>" style="direction:ltr;">
    </div>
    <div class="form-group">
        <label>Client Secret</label>
        <input type="password" id="googleClientSecretInput" value="<?php echo htmlspecialchars($google['client_secret']); ?>" style="direction:ltr;">
    </div>
    <div class="settings-info-box">
        <strong>📌 كيفية الحصول على Client ID و Client Secret:</strong>
        <ol>
            <li>انتقل إلى <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a></li>
            <li>أنشئ مشروعاً جديداً أو اختر مشروعاً موجوداً</li>
            <li>أنشئ <strong>OAuth 2.0 Client ID</strong> من نوع <strong>Web application</strong></li>
            <li>أضف Authorized JavaScript origin: <code><?php echo htmlspecialchars(SITE_URL); ?></code></li>
        </ol>
    </div>
    <button class="btn btn-md btn-primary" onclick="saveGoogleSettings()">حفظ إعدادات Google</button>
    <div class="settings-status" id="googleStatus"></div>
</div>

<script>
previewImageInput($('siteLogoInput'), 'siteLogoPreview');

function setStatus(id, ok, message) {
    const el = $(id);
    el.textContent = message;
    el.className = 'settings-status ' + (ok ? 'ok' : 'err');
}

function saveSiteSettings() {
    const formData = new FormData();
    formData.append('name', $('siteNameInput').value.trim());
    formData.append('description', $('siteDescriptionInput').value.trim());
    formData.append('slogan', $('siteSloganInput').value.trim());
    const logoFile = $('siteLogoInput').files[0];
    if (logoFile) formData.append('logo', logoFile);

    adminApi('saveSiteSettings', formData, 'POST').then(function(res) {
        setStatus('siteStatus', res.success, res.success ? '✅ ' + res.message : '❌ ' + res.message);
        if (res.success) adminToast('✅ تم حفظ إعدادات الموقع');
    });
}

function savePolicy(type) {
    const content = type === 'privacy' ? $('privacyPolicyInput').value : $('termsInput').value;
    const statusId = type === 'privacy' ? 'privacyStatus' : 'termsStatus';
    adminApi('savePolicy', { type: type, content: content }, 'POST').then(function(res) {
        setStatus(statusId, res.success, res.success ? '✅ ' + res.message : '❌ ' + res.message);
    });
}

function saveGoogleSettings() {
    adminApi('saveGoogleOAuth', {
        client_id: $('googleClientIdInput').value.trim(),
        client_secret: $('googleClientSecretInput').value.trim(),
        enabled: $('googleEnabled').checked ? '1' : '0'
    }, 'POST').then(function(res) {
        setStatus('googleStatus', res.success, res.success ? '✅ ' + res.message : '❌ ' + res.message);
    });
}

</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

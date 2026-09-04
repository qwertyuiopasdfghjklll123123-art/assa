<?php
require_once __DIR__ . '/../includes/bootstrap.php';
requireAdminPage();

$site = getSiteSettings();
$google = getGoogleOAuthConfig();
$smtp = getSMTPConfig();

$pageTitle = 'الإعدادات';
$activeNav = 'settings';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="card">
    <h2><i class="fa-solid fa-globe"></i> إعدادات الموقع</h2>
    <div class="form-group"><label>اسم الموقع</label><input type="text" id="siteName" value="<?php echo htmlspecialchars($site['name']); ?>"></div>
    <div class="form-group"><label>الوصف</label><textarea id="siteDesc" rows="2"><?php echo htmlspecialchars($site['description']); ?></textarea></div>
    <div class="form-group"><label>الشعار (Logo)</label>
        <input type="file" id="siteLogo" accept="image/*" onchange="previewImageInto(this,'siteLogoPreview')">
        <div class="img-preview" id="siteLogoPreview">
            <?php if ($site['logo_url']): ?><img src="<?php echo htmlspecialchars($site['logo_url']); ?>"><?php else: ?><i class="fa-solid fa-image"></i><?php endif; ?>
        </div>
    </div>
    <button class="btn btn-primary" onclick="saveSite()"><i class="fa-solid fa-save"></i> حفظ إعدادات الموقع</button>
</div>

<div class="card">
    <h2><i class="fa-solid fa-shield-halved"></i> سياسة الخصوصية والشروط</h2>
    <div class="form-group"><label>سياسة الخصوصية</label><textarea id="privacyPolicy" rows="5"><?php echo htmlspecialchars($site['privacy_policy']); ?></textarea></div>
    <button class="btn btn-outline" onclick="savePolicyText('privacy', 'privacyPolicy')" style="margin-bottom:20px;"><i class="fa-solid fa-save"></i> حفظ سياسة الخصوصية</button>

    <div class="form-group"><label>الشروط والأحكام</label><textarea id="termsPolicy" rows="5"><?php echo htmlspecialchars($site['terms_conditions']); ?></textarea></div>
    <button class="btn btn-outline" onclick="savePolicyText('terms', 'termsPolicy')"><i class="fa-solid fa-save"></i> حفظ الشروط والأحكام</button>
</div>

<div class="card">
    <h2><i class="fa-brands fa-google"></i> تسجيل الدخول عبر Google</h2>
    <div class="form-group"><label class="form-check"><input type="checkbox" id="googleEnabled" <?php echo $google['enabled'] ? 'checked' : ''; ?>> تفعيل</label></div>
    <div class="form-row">
        <div class="form-group"><label>Client ID</label><input type="text" id="googleClientId" value="<?php echo htmlspecialchars($google['client_id']); ?>" style="direction:ltr;"></div>
        <div class="form-group"><label>Client Secret</label><input type="password" id="googleClientSecret" value="<?php echo htmlspecialchars($google['client_secret']); ?>" style="direction:ltr;"></div>
    </div>
    <p class="hint">Authorized JavaScript origin: <code><?php echo htmlspecialchars(SITE_URL); ?></code></p>
    <button class="btn btn-primary" onclick="saveGoogle()"><i class="fa-solid fa-save"></i> حفظ إعدادات Google</button>
</div>

<div class="card">
    <h2><i class="fa-solid fa-envelope"></i> إعدادات البريد (SMTP)</h2>
    <div class="form-group"><label class="form-check"><input type="checkbox" id="smtpEnabled" <?php echo !empty($smtp['enabled']) ? 'checked' : ''; ?>> تفعيل الإرسال عبر SMTP</label></div>
    <div class="form-row">
        <div class="form-group"><label>الخادم (Host)</label><input type="text" id="smtpHost" value="<?php echo htmlspecialchars($smtp['host']); ?>"></div>
        <div class="form-group"><label>المنفذ (Port)</label><input type="number" id="smtpPort" value="<?php echo (int)$smtp['port']; ?>"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>اسم المستخدم</label><input type="text" id="smtpUsername" value="<?php echo htmlspecialchars($smtp['username']); ?>"></div>
        <div class="form-group"><label>كلمة المرور</label><input type="password" id="smtpPassword" value="<?php echo htmlspecialchars($smtp['password']); ?>"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>بريد المرسل</label><input type="email" id="smtpFromEmail" value="<?php echo htmlspecialchars($smtp['from_email']); ?>"></div>
        <div class="form-group"><label>اسم المرسل</label><input type="text" id="smtpFromName" value="<?php echo htmlspecialchars($smtp['from_name']); ?>"></div>
    </div>
    <div class="form-group"><label>نوع التشفير</label>
        <select id="smtpEncryption">
            <option value="ssl" <?php echo $smtp['encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL</option>
            <option value="tls" <?php echo $smtp['encryption'] === 'tls' ? 'selected' : ''; ?>>TLS</option>
            <option value="" <?php echo $smtp['encryption'] === '' ? 'selected' : ''; ?>>بدون تشفير</option>
        </select>
    </div>
    <p class="hint"><i class="fa-solid fa-circle-info"></i> يُستخدم لإرسال رموز التحقق (OTP) وإعادة تعيين كلمة المرور. بدون بيانات SMTP صحيحة لن تصل رسائل التحقق للمستخدمين.</p>
    <button class="btn btn-primary" onclick="saveSmtp()"><i class="fa-solid fa-save"></i> حفظ إعدادات البريد</button>
</div>

<script>
function saveSite() {
    const fd = new FormData();
    fd.append('name', document.getElementById('siteName').value.trim() || 'Tokmart');
    fd.append('description', document.getElementById('siteDesc').value.trim());
    fd.append('slogan', <?php echo json_encode($site['slogan']); ?>);
    const logoFile = document.getElementById('siteLogo').files[0];
    if (logoFile) fd.append('logo', logoFile);
    adminPostAction('saveSiteSettings', fd).then(r => showAdminToast(r.message || (r.success ? 'تم الحفظ' : 'حدث خطأ')));
}

function savePolicyText(type, fieldId) {
    adminPostAction('savePolicy', { type: type, content: document.getElementById(fieldId).value })
        .then(r => showAdminToast(r.message || (r.success ? 'تم الحفظ' : 'حدث خطأ')));
}

function saveGoogle() {
    adminPostAction('saveGoogleOAuth', {
        client_id: document.getElementById('googleClientId').value.trim(),
        client_secret: document.getElementById('googleClientSecret').value.trim(),
        enabled: document.getElementById('googleEnabled').checked,
    }).then(r => showAdminToast(r.message || (r.success ? 'تم الحفظ' : 'حدث خطأ')));
}

function saveSmtp() {
    adminPostAction('saveSMTPSettings', {
        host: document.getElementById('smtpHost').value.trim(),
        port: document.getElementById('smtpPort').value,
        username: document.getElementById('smtpUsername').value.trim(),
        password: document.getElementById('smtpPassword').value,
        encryption: document.getElementById('smtpEncryption').value,
        from_email: document.getElementById('smtpFromEmail').value.trim(),
        from_name: document.getElementById('smtpFromName').value.trim(),
        enabled: document.getElementById('smtpEnabled').checked,
    }).then(r => showAdminToast(r.message || (r.success ? 'تم الحفظ' : 'حدث خطأ')));
}
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>

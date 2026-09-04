<?php
/**
 * Authentication & account actions: register/OTP, login, Google sign-in,
 * password reset, profile updates.
 */

function handleAuthAction(string $action): void {
    global $pdo;

    switch ($action) {
        case 'forgotPassword':
        case 'sendResetCode':
            $email = getVal('email');
            if (empty($email)) {
                response(false, 'الرجاء إدخال البريد الإلكتروني');
            }

            $user = queryOne("SELECT id FROM users WHERE email = :email", [':email' => $email]);
            if (!$user) {
                response(false, 'البريد الإلكتروني غير مسجل لدينا');
            }

            $code = rand(100000, 999999);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            execute("DELETE FROM password_resets WHERE email = :email", [':email' => $email]);
            $saved = execute(
                "INSERT INTO password_resets (email, code, used, expires_at) VALUES (:email, :code, 0, :expires)",
                [':email' => $email, ':code' => $code, ':expires' => $expiresAt]
            );

            if (!$saved) {
                response(false, 'حدث خطأ أثناء إنشاء كود التحقق، حاول مرة أخرى');
            }

            if (sendVerificationEmail($email, (string)$code)) {
                response(true, 'تم إرسال كود التحقق إلى بريدك الإلكتروني بنجاح');
            }
            response(false, 'فشل إرسال البريد الإلكتروني، يرجى التحقق من إعدادات السيرفر');
            break;

        case 'updateProfile':
            if (!isset($_SESSION['user_id'])) {
                response(false, 'انتهت صلاحية الجلسة، يرجى تسجيل الدخول مرة أخرى');
            }

            $userId = $_SESSION['user_id'];
            $name = trim($_POST['username'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if (empty($name) || empty($phone)) {
                response(false, 'الاسم ورقم الهاتف حقول إجبارية');
            }

            $currentUser = queryOne("SELECT * FROM users WHERE id = :id", [':id' => $userId]);
            if (!$currentUser) {
                unset($_SESSION['user_id']);
                response(false, 'المستخدم غير مسجل في النظام، يرجى إعادة تسجيل الدخول');
            }

            execute("UPDATE users SET name = :name, phone = :phone WHERE id = :id", [
                ':name' => $name, ':phone' => $phone, ':id' => $userId,
            ]);

            response(true, 'تم حفظ التغييرات بنجاح');
            break;

        case 'googleLogin':
            $idToken = getVal('id_token');
            if (empty($idToken)) {
                response(false, 'لم يتم إرسال التوكن');
            }
            $result = loginWithGoogle($idToken);
            if ($result['success']) {
                $user = $result['user'];
                unset($user['password']);
                $user['avatar_url'] = uploadUrl('avatars', $user['avatar_path'] ?? null);
                response(true, $result['message'], $user);
            }
            response(false, $result['message']);
            break;

        case 'registerWithOTP':
            $email = getVal('email');
            $phone = getVal('phone');
            $name = getVal('name');
            $password = getVal('password');

            if (empty($email) || empty($phone) || empty($name) || empty($password)) {
                response(false, 'الرجاء تعبئة جميع الحقول');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                response(false, 'البريد الإلكتروني غير صحيح');
            }
            if (!preg_match('/^07[0-9]{8,10}$/', $phone)) {
                response(false, 'رقم الهاتف يجب أن يبدأ بـ 07 ويتكون من 10-12 رقم');
            }
            if (queryOne("SELECT id FROM users WHERE email = :email", [':email' => $email])) {
                response(false, 'هذا البريد الإلكتروني مسجل بالفعل');
            }
            if (queryOne("SELECT id FROM users WHERE phone = :phone", [':phone' => $phone])) {
                response(false, 'رقم الهاتف هذا مسجل بالفعل');
            }

            $otp = generateOTP();
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            $_SESSION['temp_registration'] = [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'otp' => $otp,
                'expires' => $expires,
            ];

            if (sendVerificationEmail($email, $otp)) {
                response(true, 'تم إرسال رمز التحقق إلى بريدك الإلكتروني', ['email' => $email]);
            }
            response(false, 'فشل إرسال رمز التحقق، يرجى المحاولة مرة أخرى');
            break;

        case 'verifyOTP':
            $otp = getVal('otp');
            if (empty($otp) || !isset($_SESSION['temp_registration'])) {
                response(false, 'لم يتم العثور على عملية تسجيل');
            }

            $temp = $_SESSION['temp_registration'];

            if ($temp['otp'] !== $otp) {
                response(false, 'رمز التحقق غير صحيح');
            }
            if (strtotime($temp['expires']) < time()) {
                unset($_SESSION['temp_registration']);
                response(false, 'انتهت صلاحية رمز التحقق، يرجى المحاولة مرة أخرى');
            }

            $sql = "INSERT INTO users (name, email, password, phone, balance, isAdmin, adminType, isVerified, lastActivity)
                    VALUES (:name, :email, :password, :phone, 0, 0, 'user', 1, NOW())";

            if (execute($sql, [
                ':name' => $temp['name'], ':email' => $temp['email'],
                ':password' => $temp['password'], ':phone' => $temp['phone'],
            ])) {
                $id = getLastInsertId();
                $user = queryOne("SELECT * FROM users WHERE id = :id", [':id' => $id]);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['is_admin'] = $user['isAdmin'];
                $_SESSION['is_verified'] = 1;

                unset($_SESSION['temp_registration'], $user['password']);

                sendNotification((int)$id, '🎉 مرحباً بك', 'أهلاً بك في متجر Tokmart', 'info', '/app');

                response(true, 'تم إنشاء الحساب بنجاح', $user);
            }
            response(false, 'فشل إنشاء الحساب');
            break;

        case 'resendOTP':
            if (!isset($_SESSION['temp_registration'])) {
                response(false, 'لم يتم العثور على عملية تسجيل');
            }

            $temp = $_SESSION['temp_registration'];
            $newOtp = generateOTP();
            $_SESSION['temp_registration']['otp'] = $newOtp;
            $_SESSION['temp_registration']['expires'] = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            if (sendVerificationEmail($temp['email'], $newOtp)) {
                response(true, 'تم إرسال رمز تحقق جديد');
            }
            response(false, 'فشل إرسال الرمز');
            break;

        case 'login':
            $identifier = getVal('identifier');
            $password = getVal('password');

            $user = queryOne(
                "SELECT * FROM users WHERE email = :identifier OR phone = :identifier",
                [':identifier' => $identifier]
            );

            if ($user && password_verify($password, (string)$user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['is_admin'] = $user['isAdmin'];
                $_SESSION['admin_type'] = $user['adminType'];
                unset($user['password']);
                $user['avatar_url'] = uploadUrl('avatars', $user['avatar_path'] ?? null);
                execute("UPDATE users SET lastActivity = NOW() WHERE id = :id", [':id' => $user['id']]);
                response(true, 'تم تسجيل الدخول بنجاح', $user);
            }
            response(false, 'بيانات الدخول غير صحيحة');
            break;

        case 'logout':
            session_destroy();
            response(true, 'تم تسجيل الخروج بنجاح');
            break;

        case 'updateUser':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }

            $id = intval(getVal('id', 0));
            if ($id != getSafeUserId()) {
                response(false, 'لا يمكنك تعديل حساب مستخدم آخر');
            }

            $name = getVal('name');
            $phone = getVal('phone');
            $email = getVal('email');

            if (empty($name) || empty($phone) || empty($email)) {
                response(false, 'الرجاء تعبئة جميع الحقول');
            }
            if (!preg_match('/^07[0-9]{8,10}$/', $phone)) {
                response(false, 'رقم الهاتف غير صحيح');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                response(false, 'البريد الإلكتروني غير صحيح');
            }

            $sql = "UPDATE users SET name = :name, phone = :phone, email = :email";
            $params = [':id' => $id, ':name' => $name, ':phone' => $phone, ':email' => $email];

            $avatarData = getImageData('avatar');
            if (!empty($avatarData)) {
                $oldUser = queryOne("SELECT avatar_path FROM users WHERE id = :id", [':id' => $id]);
                if ($oldUser && $oldUser['avatar_path']) {
                    deleteImageFile($oldUser['avatar_path'], 'avatars');
                }
                $avatarPath = saveImageFromBase64($avatarData, UPLOAD_DIR . '/avatars', 200, 200, 70);
                $sql .= ", avatar_path = :avatar_path";
                $params[':avatar_path'] = $avatarPath;
            }

            $currentPassword = getVal('currentPassword');
            $newPassword = getVal('newPassword');

            if (!empty($currentPassword) && !empty($newPassword)) {
                $user = queryOne("SELECT password FROM users WHERE id = :id", [':id' => $id]);
                if (!$user) {
                    response(false, 'المستخدم غير موجود');
                }
                if (!password_verify($currentPassword, (string)$user['password'])) {
                    response(false, 'كلمة المرور الحالية غير صحيحة');
                }
                if (strlen($newPassword) < 6) {
                    response(false, 'كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل');
                }
                $sql .= ", password = :password";
                $params[':password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id = :id";

            if (execute($sql, $params)) {
                $user = queryOne(
                    "SELECT id, name, email, phone, balance, isAdmin, adminType, avatar_path, registeredAt
                     FROM users WHERE id = :id",
                    [':id' => $id]
                );
                if ($user) {
                    $user['avatar_url'] = uploadUrl('avatars', $user['avatar_path'] ?? null);
                }
                sendNotification($id, '✅ تحديث الحساب', 'تم تحديث بيانات حسابك بنجاح', 'info', '/settings');
                response(true, 'تم تحديث المستخدم', $user);
            }
            response(false, 'فشل تحديث المستخدم');
            break;

        case 'requestPasswordReset':
            $email = getVal('email');
            if (empty($email)) {
                response(false, 'الرجاء إدخال البريد الإلكتروني');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                response(false, 'البريد الإلكتروني غير صحيح');
            }
            if (!queryOne("SELECT id FROM users WHERE email = :email", [':email' => $email])) {
                response(false, 'لا يوجد حساب مرتبط بهذا البريد الإلكتروني');
            }

            execute("DELETE FROM password_resets WHERE email = :email", [':email' => $email]);
            $code = generateResetCode();
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            execute("INSERT INTO password_resets (email, code, expires_at) VALUES (:email, :code, :expires_at)", [
                ':email' => $email, ':code' => $code, ':expires_at' => $expires,
            ]);

            if (sendVerificationEmail($email, $code)) {
                response(true, 'تم إرسال كود التحقق إلى بريدك الإلكتروني');
            }
            response(false, 'فشل إرسال كود التحقق، يرجى المحاولة لاحقاً');
            break;

        case 'verifyResetCode':
            $email = getVal('email');
            $code = getVal('code');
            if (empty($email) || empty($code)) {
                response(false, 'الرجاء تعبئة جميع الحقول');
            }

            $record = queryOne(
                "SELECT * FROM password_resets WHERE email = :email AND code = :code AND used = 0",
                [':email' => $email, ':code' => $code]
            );
            if (!$record) {
                response(false, 'الكود غير صحيح أو منتهي الصلاحية');
            }
            if (strtotime($record['expires_at']) < time()) {
                response(false, 'انتهت صلاحية الكود، يرجى طلب كود جديد');
            }

            execute("UPDATE password_resets SET used = 1 WHERE id = :id", [':id' => $record['id']]);
            response(true, 'تم التحقق من الكود بنجاح');
            break;

        case 'resetPassword':
            $email = getVal('email');
            $code = getVal('code');
            $newPassword = getVal('new_password');

            if (empty($email) || empty($code) || empty($newPassword)) {
                response(false, 'الرجاء تعبئة جميع الحقول');
            }
            if (strlen($newPassword) < 6) {
                response(false, 'كلمة المرور يجب أن تكون 6 أحرف على الأقل');
            }

            $record = queryOne(
                "SELECT * FROM password_resets WHERE email = :email AND code = :code",
                [':email' => $email, ':code' => $code]
            );
            if (!$record) {
                response(false, 'كود التحقق غير صحيح أو انتهت صلاحيته');
            }

            execute("UPDATE users SET password = :password WHERE email = :email", [
                ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':email' => $email,
            ]);
            execute("DELETE FROM password_resets WHERE email = :email", [':email' => $email]);

            $user = queryOne("SELECT id FROM users WHERE email = :email", [':email' => $email]);
            if ($user) {
                sendNotification($user['id'], '🔐 تم تغيير كلمة المرور', 'تم تغيير كلمة المرور الخاصة بك بنجاح', 'info', '/login');
            }

            response(true, 'تم تغيير كلمة المرور بنجاح');
            break;
    }
}

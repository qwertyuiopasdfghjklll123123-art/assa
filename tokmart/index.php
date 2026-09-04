<?php
/**
 * Public welcome / landing page.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$site = getSiteSettings();
$categories = query("SELECT * FROM categories ORDER BY sortOrder ASC LIMIT 8");
$isLoggedIn = isset($_SESSION['user_id']);

$features = [
    ['icon' => 'fa-solid fa-truck-fast', 'color' => 'linear-gradient(135deg,#058693,#0AA6B5)', 'title' => 'توصيل سريع', 'desc' => 'خلال 24 ساعة لمعظم المناطق'],
    ['icon' => 'fa-solid fa-shield-halved', 'color' => 'linear-gradient(135deg,#3498DB,#2980B9)', 'title' => 'دفع آمن', 'desc' => 'الدفع عند الاستلام أو إلكترونياً أو تحويل بنكي'],
    ['icon' => 'fa-solid fa-headset', 'color' => 'linear-gradient(135deg,#2ECC71,#27AE60)', 'title' => 'دعم فني 24/7', 'desc' => 'فريقنا جاهز للرد على استفساراتك'],
    ['icon' => 'fa-solid fa-rotate-left', 'color' => 'linear-gradient(135deg,#D8B07A,#C49A5E)', 'title' => 'استرجاع سهل', 'desc' => 'سياسة استرجاع وإلغاء مرنة'],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f3d1c">
    <meta name="description" content="<?php echo htmlspecialchars($site['description']); ?>">
    <title><?php echo htmlspecialchars($site['name']); ?> - <?php echo htmlspecialchars($site['slogan']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Alyamama:wght@300..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo APP_BASE_PATH; ?>/assets/css/welcome.css?v=<?php echo CACHE_VERSION; ?>">
</head>
<body>

<header class="welcome-header">
    <div class="brand">
        <?php if ($site['logo_url']): ?>
            <img src="<?php echo htmlspecialchars($site['logo_url']); ?>" alt="<?php echo htmlspecialchars($site['name']); ?>">
        <?php else: ?>
            <i class="fa-solid fa-store"></i>
        <?php endif; ?>
        <span><?php echo htmlspecialchars($site['name']); ?></span>
    </div>
    <nav>
        <?php if ($isLoggedIn): ?>
            <a href="app.php" class="primary"><i class="fa-solid fa-cart-shopping"></i> <span>الذهاب إلى المتجر</span></a>
        <?php else: ?>
            <a href="app.php?page=account">تسجيل الدخول</a>
            <a href="app.php?page=account" class="primary">إنشاء حساب</a>
        <?php endif; ?>
    </nav>
</header>

<section class="hero">
    <div class="hero-text">
        <span class="eyebrow"><?php echo htmlspecialchars($site['slogan']); ?></span>
        <h1>مرحباً بك في <?php echo htmlspecialchars($site['name']); ?></h1>
        <p><?php echo htmlspecialchars($site['description']); ?></p>
        <div class="hero-actions">
            <a href="app.php" class="btn-hero primary"><i class="fa-solid fa-bag-shopping"></i> تصفح المتجر</a>
            <a href="app.php?page=categories" class="btn-hero outline"><i class="fa-solid fa-grid-2"></i> استعرض التصنيفات</a>
        </div>
    </div>
    <div class="hero-visual">
        <?php if ($site['logo_url']): ?>
            <img src="<?php echo htmlspecialchars($site['logo_url']); ?>" alt="">
        <?php else: ?>
            <i class="fa-solid fa-store"></i>
        <?php endif; ?>
    </div>
</section>

<section class="features">
    <?php foreach ($features as $f): ?>
    <div class="feature-card">
        <div class="icon" style="background:<?php echo $f['color']; ?>;"><i class="<?php echo $f['icon']; ?>"></i></div>
        <h3><?php echo $f['title']; ?></h3>
        <p><?php echo $f['desc']; ?></p>
    </div>
    <?php endforeach; ?>
</section>

<?php if (!empty($categories)): ?>
<section class="categories-preview">
    <h2>تسوق حسب التصنيف</h2>
    <div class="cat-grid">
        <?php foreach ($categories as $c): ?>
        <a class="cat-chip" href="app.php?page=categories">
            <i class="fa-solid <?php echo htmlspecialchars($c['icon']); ?>"></i>
            <span><?php echo htmlspecialchars($c['name']); ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<footer class="welcome-footer">
    &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site['name']); ?>. جميع الحقوق محفوظة.
</footer>

</body>
</html>

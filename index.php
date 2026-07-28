<?php require_once __DIR__ . '/includes/bootstrap.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(APP_NAME) ?> — <?= e(APP_TAGLINE) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    .hero { min-height: 100vh; background: var(--bg-body); background-image: radial-gradient(circle at 20% 20%, rgba(37,99,235,.15), transparent 40%), radial-gradient(circle at 80% 70%, rgba(6,182,212,.15), transparent 40%); }
    .nav { display:flex; align-items:center; justify-content:space-between; padding: 22px 6%; }
    .nav-logo { display:flex; align-items:center; gap:10px; font-family: var(--font-display); font-weight:800; font-size:19px; }
    .nav-logo-mark { width:38px; height:38px; border-radius:10px; background: var(--gradient-brand); display:flex; align-items:center; justify-content:center; color:#fff; }
    .hero-content { max-width: 760px; margin: 8vh auto 0; text-align:center; padding: 0 24px; }
    .hero-content h1 { font-size: clamp(32px, 5vw, 56px); line-height:1.1; }
    .hero-content p { font-size:17px; max-width: 560px; margin: 18px auto 30px; }
    .hero-actions { display:flex; gap:14px; justify-content:center; flex-wrap:wrap; }
    .feature-grid { max-width:1100px; margin: 90px auto; padding: 0 24px; display:grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap:20px; }
    .feature-card i { font-size:22px; width:48px; height:48px; border-radius:12px; background: var(--gradient-brand); color:#fff; display:flex; align-items:center; justify-content:center; margin-bottom:14px; }
</style>
</head>
<body>
<div class="hero">
    <nav class="nav">
        <div class="nav-logo"><div class="nav-logo-mark"><i class="fa-solid fa-building"></i></div><?= e(APP_NAME) ?></div>
        <div class="d-flex gap-12">
            <a href="auth/login.php" class="btn btn-outline">Log in</a>
            <a href="auth/register.php" class="btn btn-primary">Get started</a>
        </div>
    </nav>

    <div class="hero-content">
        <h1><?= e(APP_TAGLINE) ?></h1>
        <p>Manage properties, tenants, rent collection, and maintenance from one modern dashboard — built for landlords and property teams who want less spreadsheet chaos.</p>
        <div class="hero-actions">
            <a href="auth/register.php" class="btn btn-primary">Create your account</a>
            <a href="auth/login.php" class="btn btn-outline">I already have an account</a>
        </div>
    </div>

    <div class="feature-grid">
        <div class="card feature-card">
            <i class="fa-solid fa-building-user"></i>
            <h3>Properties &amp; Units</h3>
            <p>Track every property, unit, and occupancy status in one place.</p>
        </div>
        <div class="card feature-card">
            <i class="fa-solid fa-hand-holding-dollar"></i>
            <h3>Rent Collection</h3>
            <p>Record payments, generate receipts, and chase overdue balances automatically.</p>
        </div>
        <div class="card feature-card">
            <i class="fa-solid fa-screwdriver-wrench"></i>
            <h3>Maintenance</h3>
            <p>Tenants submit requests, caretakers get assigned, everyone stays in sync.</p>
        </div>
        <div class="card feature-card">
            <i class="fa-solid fa-chart-line"></i>
            <h3>Reports &amp; Insights</h3>
            <p>Revenue, occupancy, and expense reports — exportable to PDF, Excel, or CSV.</p>
        </div>
    </div>
</div>
</body>
</html>

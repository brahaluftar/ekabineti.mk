<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()");
header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
require_once dirname(__DIR__).'/app/Core/Translator.php';
$locale = in_array($_GET['lang'] ?? 'mk', ['mk','en'], true) ? (string) $_GET['lang'] : 'mk';
$languageSuffix = $locale === 'en' ? '?lang=en' : '';
ob_start(static fn(string $html): string => \EKabineti\Core\Translator::html($html, $locale));

$documentVersion = 'privacy-2.0-2026-08-21';
$effectiveDate = '21 август 2026';
?>
<!doctype html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>Политика за приватност | е-Кабинет</title>
    <link rel="icon" type="image/png" href="/logo.png?v=28">
    <link rel="stylesheet" href="/assets/app.css?v=2026.11.09.02">
    <link rel="stylesheet" href="/assets/public-pages.css?v=2026.11.09.02">
</head>
<body>
<a class="skip-link" href="#permbajtja">Премини на содржината</a>
<header class="site-header">
    <a class="brand" href="/" aria-label="е-Кабинет — Почетна">
        <img src="/logo.png?v=28" alt="Грб на Општина Битола" width="45" height="64">
        <span><strong>Општина Битола</strong><small>е-Кабинет · Управување со проекти и буџет</small></span>
    </a>
    <nav aria-label="Јавни документи">
        <a class="active" href="/privacy.php<?= $languageSuffix ?>" aria-current="page">Приватност</a>
        <a href="/terms.php<?= $languageSuffix ?>">Услови</a>
        <a href="/privacy.php?lang=<?= $locale === 'en' ? 'mk' : 'en' ?>"><?= $locale === 'en' ? 'MK' : 'EN' ?></a>
    </nav>
</header>
<main id="permbajtja" class="page-shell">
    <article class="legal-document" data-document-version="<?= htmlspecialchars($documentVersion, ENT_QUOTES, 'UTF-8') ?>">
        <header class="legal-heading">
            <p class="eyebrow">Јавен документ</p>
            <h1>Политика за приватност на е-Кабинет</h1>
            <p>Оваа политика ја објаснува обработката и заштитата на податоците во платформата за управување со проекти, буџет и известување на Општина Битола.</p>
            <dl class="document-meta">
                <div><dt>Верзија</dt><dd><?= htmlspecialchars($documentVersion, ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div><dt>Во сила од</dt><dd><?= htmlspecialchars($effectiveDate, ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div><dt>Контролор</dt><dd>Општина Битола</dd></div>
            </dl>
        </header>

        <nav class="contents" aria-label="Содржина на политиката">
            <h2>Содржина</h2>
            <ol>
                <li><a href="#te-dhenat">Податоци</a></li>
                <li><a href="#qellimet">Цели</a></li>
                <li><a href="#qasja">Пристап и примачи</a></li>
                <li><a href="#siguria">Безбедност</a></li>
                <li><a href="#ruajtja">Чување</a></li>
                <li><a href="#te-drejtat">Права</a></li>
            </ol>
        </nav>

        <section id="te-dhenat">
            <h2>1. Податоци што се обработуваат</h2>
            <p>Платформата обработува податоци за сметката, како име, корисничко име, е-пошта, статус, улога и сектор; податоци за проектите, целите, задачите, напредокот и ризиците; како и распределби, обврски, резервации, расходи и буџетски промени.</p>
            <p>Се обработуваат и прикачените документи, одлуките од работниот тек, увозите, известувањата, ревизорските траги, correlation ID, времето на дејството и техничките информации потребни за безбедност и дијагностика.</p>
        </section>

        <section id="qellimet">
            <h2>2. Цели на обработката</h2>
            <p>Податоците се користат за планирање и следење на проектите, администрирање на буџетот, контрола на извршувањето, одобрувања, управување со ризици, раководно известување, контролиран увоз, институционални известувања, ревизија и решавање проблеми.</p>
            <p>Податоците не се користат за рекламирање и не се продаваат. Извештаите смеат да се користат само за службени цели и според институционалните овластувања.</p>
        </section>

        <section id="qasja">
            <h2>3. Пристап, улоги и примачи</h2>
            <p>Пристапот е ограничен според улогата и опсегот на секторот. Администраторот на системот има глобален пристап за администрирање, ревизија и решавање проблеми. Другите корисници ги гледаат само модулите и податоците што ги дозволува активната улога.</p>
            <p>Microsoft Graph ги добива само податоците потребни за испраќање на известувањето, како адреса на е-пошта, наслов и содржина. Давателите на инфраструктура можат да обработуваат податоци само за хостирање, безбедност, резервни копии и техничко функционирање.</p>
        </section>

        <section id="siguria">
            <h2>4. Техничка и организациска безбедност</h2>
            <p>Врската со SQL Server користи посветена порта и TLS. Акредитивите се чуваат во приватна конфигурација надвор од document root. Лозинките се чуваат како хаш, а автентикацијата прифаќа само е-пошта или корисничко име.</p>
            <p>Платформата користи заштитени сесии, HttpOnly/SameSite колачиња, CSRF, безбедносни заглавија, авторизација на серверот, приватни документи, скенирање на датотеки, ревизија и логови што ги следи администраторот.</p>
        </section>

        <section id="ruajtja">
            <h2>5. Чување и интегритет</h2>
            <p>Податоците се чуваат онолку долго колку што бараат институционалните, финансиските, ревизорските, архивските и безбедносните постапки. Финансиските евиденции и ревидираните дејства не се менуваат и не се бришат тајно.</p>
            <p>Резервните копии, логовите и приватните документи се заштитени со ограничен пристап. Кога ќе истече применливиот рок, податоците се бришат, анонимизираат или архивираат според одобрената постапка.</p>
        </section>

        <section id="te-drejtat">
            <h2>6. Права и контакт</h2>
            <p>Во согласност со применливите правила, лицето може да побара пристап, исправка или појаснување за своите податоци. Законските и институционалните ограничувања може да бараат чување на финансиските, административните, ревизорските или безбедносните евиденции.</p>
            <p>Барањата и инцидентите поврзани со приватноста се поднесуваат преку официјалните канали на Општина Битола. Пред давање информации може да се побара проверка на идентитетот и овластувањето.</p>
        </section>
    </article>
</main>
<footer class="site-footer">
    <p><strong>е-Кабинет · Општина · Битола</strong></p>
    <p><a href="/privacy.php<?= $languageSuffix ?>">Приватност</a> · <a href="/terms.php<?= $languageSuffix ?>">Услови за користење</a></p>
</footer>
</body>
</html>

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

$documentVersion = 'terms-2.0-2026-08-21';
$effectiveDate = '21 август 2026';
?>
<!doctype html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>Услови за користење | е-Кабинет</title>
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
        <a href="/privacy.php<?= $languageSuffix ?>">Приватност</a>
        <a class="active" href="/terms.php<?= $languageSuffix ?>" aria-current="page">Услови</a>
        <a href="/terms.php?lang=<?= $locale === 'en' ? 'mk' : 'en' ?>"><?= $locale === 'en' ? 'MK' : 'EN' ?></a>
    </nav>
</header>
<main id="permbajtja" class="page-shell">
    <article class="legal-document" data-document-version="<?= htmlspecialchars($documentVersion, ENT_QUOTES, 'UTF-8') ?>">
        <header class="legal-heading">
            <p class="eyebrow">Јавен документ</p>
            <h1>Услови за користење на е-Кабинет</h1>
            <p>Овие услови го уредуваат користењето на платформата на Општина Битола за управување со проекти, буџет, извршување, промени, одобрувања и раководно известување.</p>
            <dl class="document-meta">
                <div><dt>Верзија</dt><dd><?= htmlspecialchars($documentVersion, ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div><dt>Во сила од</dt><dd><?= htmlspecialchars($effectiveDate, ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div><dt>Давател</dt><dd>Општина Битола</dd></div>
            </dl>
        </header>

        <nav class="contents" aria-label="Содржина на условите">
            <h2>Содржина</h2>
            <ol>
                <li><a href="#qasja">Пристап и сметки</a></li>
                <li><a href="#te-dhenat">Податоци и известување</a></li>
                <li><a href="#vendimet">Буџет и одлучување</a></li>
                <li><a href="#siguria">Безбедност и ревизија</a></li>
                <li><a href="#pergjegjesite">Одговорности</a></li>
                <li><a href="#ndryshimet">Промени</a></li>
            </ol>
        </nav>

        <section id="qasja">
            <h2>1. Пристап и сметки</h2>
            <p>е-Кабинет е институционална платформа. Пристап им се дава само на овластени лица според улогата и определениот делокруг. Најавата се врши со е-пошта или корисничко име и лозинка.</p>
            <p>Саморегистрацијата создава сметка на чекање. Сметката не добива пристап до податоците додека администраторот не ја активира и не ѝ додели улога и сектор. Корисникот мора да ги чува акредитивите и веднаш да пријави секое сомневање за злоупотреба.</p>
        </section>

        <section id="te-dhenat">
            <h2>2. Проекти и извештаи</h2>
            <p>Корисникот е одговорен за точноста, целосноста и ажурноста на проектите, напредокот, задачите, ризиците и документите што ги евидентира. Објавените извештаи се прикажуваат на раководниот панел во реално време.</p>
            <p>Не е дозволено евидентирање неточни информации, манипулирање со напредокот, прикачување злонамерни датотеки или користење на податоците надвор од службените одговорности.</p>
        </section>

        <section id="vendimet">
            <h2>3. Буџет, извршување и одлучување</h2>
            <p>Распределбите, обврските, резервациите, расходите и буџетските промени ги евидентираат само овластените улоги и во рамките на соодветниот опсег. Системот го проверува расположивиот износ и ја чува врската со проектот и придружните документи.</p>
            <p>Одобрувањата и одбивањата мора да ја одразуваат вистинската институционална одлука. Системот ги чува извршителот, времето, причината и фазата на работниот тек; завршените дејства не смеат да се менуваат надвор од овластената постапка.</p>
        </section>

        <section id="siguria">
            <h2>4. Безбедност, документи и ревизија</h2>
            <p>Пристапот се применува според улогите и секторите. Документите се чуваат надвор од јавната папка, се проверуваат по вид и големина и стануваат достапни за преземање само по дозволен статус на скенирањето.</p>
            <p>Најавите, промените, одобрувањата, преземањата и техничките проблеми се евидентираат со correlation ID. Администраторите можат да ги следат логовите во реално време, но не смеат да ги користат за цели надвор од администрирањето и безбедноста.</p>
        </section>

        <section id="pergjegjesite">
            <h2>5. Дозволено користење и одговорности</h2>
            <p>Забранети се обидите за пристап надвор од улогата, мешањето во сметките на други, заобиколувањето на контролите, бришењето траги, автоматизацијата без овластување и споделувањето внатрешни информации со неовластени лица.</p>
            <p>Општината може да ограничи или да деактивира сметка заради безбедност, истрага или промена на службената одговорност. Техничкото одржување може да предизвика привремени прекини; евиденцијата зачувана во системот останува официјален извор.</p>
        </section>

        <section id="ndryshimet">
            <h2>6. Известувања и промени на условите</h2>
            <p>Платформата може да испраќа институционални известувања преку Microsoft Graph. Техничкиот неуспех на една е-порака не го поништува дејството зачувано во системот.</p>
            <p>Условите може да се ажурираат поради законски, процедурални, безбедносни или функционални промени. Верзијата и датумот на влегување во сила се објавуваат на оваа страница.</p>
        </section>
    </article>
</main>
<footer class="site-footer">
    <p><strong>е-Кабинет · Општина · Битола</strong></p>
    <p><a href="/privacy.php<?= $languageSuffix ?>">Приватност</a> · <a href="/terms.php<?= $languageSuffix ?>">Услови за користење</a></p>
</footer>
</body>
</html>

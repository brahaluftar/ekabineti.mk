<?php

declare(strict_types=1);

namespace EKabineti\Core;

use EKabineti\Security\Auth;
use EKabineti\Security\Scope;

/**
 * Application shell: top bar (search, notifications, user menu), grouped sidebar navigation
 * (off-canvas drawer below 1200px), compact page header and a bottom tab bar on phones.
 */
final class View
{
    private const ASSET_VERSION = 'mk-2026.11.09.01';

    /** Navigation groups in display order. */
    private const GROUPS = [
        'home' => 'Почетна',
        'work' => 'Проекти и работа',
        'finance' => 'Финансии',
        'cases' => 'Предмети',
        'reports' => 'Извештаи',
        'admin' => 'Администрација',
        'help' => 'Помош',
    ];

    private const MODULES = [
        ['path'=>'/','group'=>'home','icon'=>'dashboard','label'=>'Панел','description'=>'Раководен преглед','action'=>null,'resource'=>null],
        ['path'=>'/mayor-dashboard','group'=>'home','icon'=>'crown','label'=>'Dashboard','description'=>'Неделен панел на Градоначалникот','action'=>null,'resource'=>null,'roles'=>['mayor']],
        ['path'=>'/overview','group'=>'home','icon'=>'activity','label'=>'Следење','description'=>'Напредок, рокови и квалитет','action'=>null,'resource'=>null],
        ['path'=>'/mayor-agenda','group'=>'home','icon'=>'calendar','label'=>'Агенда на Градоначалникот','description'=>'Проекти за дискусија','action'=>null,'resource'=>null,'roles'=>['system_admin','mayor','mayor_admin','deputy_mayor','director'],
            'children'=>[['/mayor-agenda','calendar','Тековна агенда'],['/mayor-agenda/directorates','building','Опции по сектори']]],
        ['path'=>'/directorate-briefing','group'=>'home','icon'=>'building','label'=>'Брифинг на секторот','description'=>'Неделна сумарија на секторот','action'=>null,'resource'=>null,'roles'=>['system_admin','mayor','mayor_admin','deputy_mayor','director']],
        ['path'=>'/executive-areas','group'=>'home','icon'=>'activity','label'=>'Работни области','description'=>'Каде работи извршната власт истовремено','action'=>null,'resource'=>null,'roles'=>['system_admin','mayor','mayor_admin','deputy_mayor']],
        ['path'=>'/mayor-briefing','group'=>'home','icon'=>'book-open','label'=>'Неделен брифинг','description'=>'Неделна сумарија на Градоначалникот','action'=>null,'resource'=>null,'roles'=>['system_admin','mayor','mayor_admin','deputy_mayor']],
        ['path'=>'/projects','group'=>'work','icon'=>'folder-kanban','label'=>'Проекти','description'=>'Проекти и извештаи','action'=>'view','resource'=>'project',
            'children'=>[['/projects','list','Листа на проекти'],['/projects/new','plus','Нов проект','create','project']]],
        ['path'=>'/document-intake','group'=>'work','icon'=>'upload','label'=>'Прикачи извештаи (AI)','description'=>'Word, PDF, Excel → евиденции','action'=>null,'resource'=>null],
        ['path'=>'/shared','group'=>'work','icon'=>'share','label'=>'Споделени со мене','description'=>'Проекти и задачи','action'=>null,'resource'=>null],
        ['path'=>'/risks','group'=>'work','icon'=>'triangle-alert','label'=>'Ризици','description'=>'Регистар на ризици','action'=>'view','resource'=>'risk',
            'children'=>[['/risks','list','Регистар на ризици'],['/risks/new','plus','Нов ризик','create','risk']]],
        ['path'=>'/budget','group'=>'finance','icon'=>'wallet','label'=>'Буџет и финансии','description'=>'Буџет, извршување и промени','action'=>'view','resource'=>'budget'],
        ['path'=>'/revenues','group'=>'finance','icon'=>'coins','label'=>'Приходи','description'=>'Дневен извештај за приходите по вид','action'=>null,'resource'=>null,
            'children'=>[['/revenues','coins','Преглед на приходите'],['/revenues/categories','list','Видови приходи']]],
        ['path'=>'/workflows','group'=>'finance','icon'=>'workflow','label'=>'Miratimet (Workflow)','description'=>'Одлуки и одобрувања','action'=>'view','resource'=>'workflow'],
        ['path'=>'/procurement-projects','group'=>'finance','icon'=>'clipboard-check','label'=>'Листа на јавни набавки','description'=>'Договори и реализација','action'=>null,'resource'=>null],
        ['path'=>'/executed-cases','group'=>'finance','icon'=>'scale','label'=>'Извршени предмети','description'=>'Извршувања и финансиска распределба','action'=>null,'resource'=>null],
        ['path'=>'/cases','group'=>'cases','icon'=>'briefcase','label'=>'Управни предмети','description'=>'Управни предмети и следење','action'=>'view','resource'=>'case',
            'children'=>[['/cases','list','Листа на предмети'],['/cases/new','plus','Нов предмет','create','case']]],
        ['path'=>'/legal-office','group'=>'cases','icon'=>'scale','label'=>'Правна служба','description'=>'Предмети, постапки и законски рокови','action'=>null,'resource'=>null],
        ['path'=>'/reports/multiline','group'=>'reports','icon'=>'chart','label'=>'Повеќегодишен извештај','description'=>'Повеќегодишно известување','action'=>'view','resource'=>'report'],
        ['path'=>'/citizen-services','group'=>'reports','icon'=>'hand-heart','label'=>'Услуги за граѓаните','description'=>'Неделно известување по сектори и услуги','action'=>null,'resource'=>null,
            'children'=>[['/citizen-services','list','Неделен извештај на секторите'],['/citizen-services/by-service','plus','Известување по услуги'],['/citizen-services/by-service/report','chart','Извештај по услуги'],['/citizen-services/catalog','book-open','Каталог на услуги']]],
        ['path'=>'/reports/inspection','group'=>'reports','icon'=>'clipboard-check','label'=>'Известување на Инспекцијата','description'=>'Показатели на Секторот за инспекциски надзор','action'=>null,'resource'=>null],
        ['path'=>'/ai-reporting','group'=>'reports','icon'=>'sparkles-chart','label'=>'AI известување','description'=>'Извештаи од барање на обичен јазик','action'=>null,'resource'=>null,'roles'=>['mayor','mayor_admin','director']],
        ['path'=>'/imports','group'=>'admin','icon'=>'upload','label'=>'Увози','description'=>'Контролиран увоз','action'=>'view','resource'=>'import'],
        ['path'=>'/citizen-services/catalog','group'=>'admin','icon'=>'book-open','label'=>'Каталог на услуги','description'=>'Услуги, одделенија и известувачи','action'=>null,'resource'=>null],
        ['path'=>'/directorates','group'=>'admin','icon'=>'building','label'=>'Сектори','description'=>'Организациска структура','action'=>null,'resource'=>null,'roles'=>['system_admin','mayor']],
        ['path'=>'/users','group'=>'admin','icon'=>'users','label'=>'Корисници','description'=>'Сметки и пристапи','action'=>'manage','resource'=>'user'],
        ['path'=>'/roles','group'=>'admin','icon'=>'shield-check','label'=>'Улоги и дозволи','description'=>'Динамички улоги со избрани дозволи','action'=>null,'resource'=>null,'roles'=>['system_admin']],
        ['path'=>'/audit','group'=>'admin','icon'=>'shield-check','label'=>'Ревизија','description'=>'Траги од дејствата','action'=>'view','resource'=>'audit'],
        ['path'=>'/system-logs','group'=>'admin','icon'=>'terminal','label'=>'Логови','description'=>'Проблеми во реално време','action'=>'view','resource'=>'system_log'],
        ['path'=>'/ai-technical-assistant','group'=>'admin','icon'=>'wrench','label'=>'Технички AI асистент','description'=>'Дијагностика, предлог и одобрување','action'=>null,'resource'=>null,'roles'=>['system_admin']],
        ['path'=>'/help','group'=>'help','icon'=>'book-open','label'=>'Прирачник','description'=>'Полиња, врски и текови','action'=>null,'resource'=>null],
    ];

    /** Preferred bottom-bar destinations on phones (first four visible ones are used). */
    private const QUICK = ['/', '/mayor-dashboard', '/projects', '/budget', '/legal-office', '/cases', '/risks', '/citizen-services', '/overview'];

    public function __construct(private readonly Auth $auth, private readonly Scope $scope)
    {
    }

    public function render(string $title, string $body, array $options = []): never
    {
        $user = $this->auth->user();
        $flash = $_SESSION['_flash'] ?? null;
        $error = $_SESSION['_error'] ?? null;
        unset($_SESSION['_flash'], $_SESSION['_error']);

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $locale = Translator::locale($user);
        $modules = $user ? $this->visibleModules((int) $user['id']) : [];
        $active = $this->activeModule($modules, $path);
        $activeModule = $this->moduleFor($modules, $active);
        // A list page (module root, with its filters and page) is remembered, so "back" from a record returns to it.
        if ($activeModule !== null && $path === $activeModule['path'] && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') self::rememberList($path);
        $pageScript = is_string($options['script'] ?? null) ? $options['script'] : '';
        $usesMap = str_contains($body, 'geo-picker');
        $v = self::ASSET_VERSION;

        $html = '<!doctype html><html lang="mk"><head>'
            .'<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            .'<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#0f4c81">'
            .'<title>'.self::e($title).' · е-Кабинет</title>'
            .'<link rel="icon" type="image/png" href="/logo.png?v='.$v.'">'
            .'<link rel="stylesheet" href="/assets/app.css?v='.$v.'">'
            .($usesMap ? '<link rel="stylesheet" href="/assets/leaflet/leaflet.css?v='.$v.'">' : '')
            .'</head><body class="'.self::e(trim(($user ? 'app' : 'guest').' '.$this->pageClass($path))).'">'
            .'<a class="skip-link" href="#main-content">Премини на содржината</a>';

        if ($user) {
            $html .= $this->topbar($user, $locale)
                .'<div class="layout">'.$this->sidebar($modules, $active, $path)
                .'<div class="drawer-backdrop" data-drawer-close hidden></div>'
                .'<main id="main-content" class="content" tabindex="-1">'
                .$this->pageHeader($title, $activeModule, $active, $path)
                .$this->alerts($flash, $error)
                .'<div class="page-body">'.$body.'</div>'
                .$this->footer($locale)
                .'</main></div>'
                .$this->bottomNav($modules, $active);
        } else {
            $html .= '<header class="topbar topbar--guest">'.$this->brand().$this->guestNavigation($path).'</header>'
                .'<main id="main-content" class="content content--guest" tabindex="-1">'
                .'<header class="page-header"><h1>'.self::e($title).'</h1></header>'
                .$this->alerts($flash, $error).'<div class="page-body">'.$body.'</div>'.$this->footer($locale).'</main>';
        }

        $html .= '<script src="/assets/app.js?v='.$v.'" defer></script>'.$pageScript
            .($usesMap ? '<script src="/assets/leaflet/leaflet.js?v='.$v.'" defer></script><script src="/assets/geolocation.js?v='.$v.'" defer></script>' : '')
            .'</body></html>';
        echo Translator::html($html, $locale);
        exit;
    }

    private function brand(): string
    {
        return '<a class="brand" href="/" aria-label="е-Кабинет — Почетна"><img src="/logo.png?v='.self::ASSET_VERSION.'" width="32" height="46" alt="">'
            .'<span class="brand__text"><strong>е-Кабинет</strong><small>Општина Битола</small></span></a>';
    }

    private function topbar(array $user, string $locale): string
    {
        $userId = (int) $user['id'];
        $unread = $this->unreadNotifications($userId);
        $initials = mb_strtoupper(implode('', array_map(static fn(string $part): string => mb_substr($part, 0, 1), array_slice(preg_split('/\s+/u', trim((string) $user['name'])) ?: ['?'], 0, 2))));
        $query = (string) ($_GET['q'] ?? '');
        $isSearch = (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/') === '/search';
        return '<header class="topbar">'
            .'<button class="icon-button topbar__menu" type="button" aria-expanded="false" aria-controls="app-sidebar" data-drawer-toggle>'.$this->icon('menu').'<span class="visually-hidden">Мени</span></button>'
            .$this->brand()
            .'<form class="topbar__search" method="get" action="/search" role="search"><label class="visually-hidden" for="global-search">Пребарај</label>'
            .'<input id="global-search" type="search" name="q" value="'.self::e($isSearch ? $query : '').'" placeholder="Пребарај проекти, предмети, документи…" maxlength="200">'
            .'<button class="icon-button" type="submit">'.$this->icon('search').'<span class="visually-hidden">Пребарај</span></button></form>'
            .'<div class="topbar__actions">'
            .'<a class="icon-button topbar__search-link" href="/search">'.$this->icon('search').'<span class="visually-hidden">Пребарај</span></a>'
            .'<a class="icon-button notification-bell" href="/notifications">'.$this->icon('bell').'<span class="visually-hidden">Известувања</span>'.($unread > 0 ? '<span class="badge-count" aria-label="'.$unread.' të palexuara">'.($unread > 99 ? '99+' : $unread).'</span>' : '').'</a>'
            .'<details class="user-menu"><summary><span class="avatar" aria-hidden="true">'.self::e($initials ?: '?').'</span><span class="user-menu__name">'.self::e($user['name']).'</span></summary>'
            .'<div class="user-menu__panel"><p class="user-menu__identity"><strong>'.self::e($user['name']).'</strong><small>@'.self::e($user['username']).'</small></p>'
            .'<a href="/profile/preferences">'.$this->icon('sliders', true).'<span>Поставки</span></a>'
            .'<a href="/profile/security">'.$this->icon('shield-check', true).'<span>Безбедност на сметката</span></a>'
            .'<a href="/notifications">'.$this->icon('bell', true).'<span>Известувања</span></a>'
            .'<form class="user-menu__language" method="post" action="/language" data-language-form>'.$this->csrf()
            .'<label><span>Јазик</span><select name="locale" data-language-select><option value="mk"'.($locale === 'mk' ? ' selected' : '').'>Македонски</option><option value="en"'.($locale === 'en' ? ' selected' : '').'>English</option></select></label>'
            .'<button class="button button-secondary button-small" type="submit">Примени</button></form>'
            .'<form method="post" action="/logout">'.$this->csrf().'<button class="user-menu__logout" type="submit">'.$this->icon('log-out', true).'<span>Одјави се</span></button></form>'
            .'</div></details></div></header>';
    }

    private function sidebar(array $modules, string $active, string $path): string
    {
        $userId = (int) $this->auth->user()['id'];
        $groups = '';
        foreach (self::GROUPS as $group => $label) {
            $items = array_filter($modules, static fn(array $module): bool => $module['group'] === $group);
            if ($items === []) continue;
            $links = '';
            foreach ($items as $module) {
                $isActive = $active === $module['path'];
                $links .= '<li><a class="nav-link'.($isActive ? ' is-active' : '').'" href="'.self::e($module['path']).'"'.($isActive ? ' aria-current="page"' : '').' title="'.self::e($module['description']).'">'
                    .$this->icon((string) $module['icon']).'<span>'.self::e($module['label']).'</span></a>';
                $children = $this->children($module, $userId);
                if ($isActive && $children !== []) {
                    $links .= '<ul class="nav-sub">';
                    foreach ($children as [$childPath, $childIcon, $childLabel]) {
                        $current = $path === $childPath;
                        $links .= '<li><a class="nav-sublink'.($current ? ' is-active' : '').'" href="'.self::e($childPath).'"'.($current ? ' aria-current="page"' : '').'>'.$this->icon($childIcon, true).'<span>'.self::e($childLabel).'</span></a></li>';
                    }
                    $links .= '</ul>';
                }
                $links .= '</li>';
            }
            $groups .= '<div class="nav-group"><p class="nav-group__title">'.self::e($label).'</p><ul>'.$links.'</ul></div>';
        }
        return '<nav id="app-sidebar" class="sidebar" aria-label="Модули"><div class="sidebar__head">'.$this->brand()
            .'<button class="icon-button sidebar__close" type="button" data-drawer-close>×<span class="visually-hidden">Затвори го менито</span></button></div>'
            .$groups.'</nav>';
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function children(array $module, int $userId): array
    {
        if ($module['path'] === '/budget') {
            if ($this->scope->isMayor($userId)) return [['/budget','chart','Листа на буџетот'],['/budget/allocations','split','Листа на распределби'],['/execution/commitments','clipboard-check','Листа на обврски'],['/execution/expenditures','receipt','Листа на расходи'],['/budget/lines','chart','Табела на буџетот'],['/budget/freebalance','coins','Извршување според FreeBalance']];
            $children = [['/budget','chart','Преглед на буџетот'],['/budget/lines','chart','Табела на буџетот'],['/budget/allocations','split','Табела на распределби']];
            if ($this->scope->can($userId, 'view', 'execution')) $children[] = ['/execution/commitments','clipboard-check','Табела на преземени обврски'];
            $children[] = ['/budget/freebalance','coins','Според FreeBalance'];
            if ($this->scope->can($userId, 'create', 'allocation')) { $children[] = ['/budget/lines/new','file-plus','Нова буџетска ставка']; $children[] = ['/budget/allocations/new','split','Нова распределба']; }
            if ($this->scope->can($userId, 'view', 'execution')) $children[] = ['/execution','coins','Буџетско извршување'];
            if ($this->scope->can($userId, 'post', 'commitment')) $children[] = ['/execution/commitments/new','clipboard-check','Нова обврска'];
            if ($this->scope->can($userId, 'post', 'expenditure')) $children[] = ['/execution/expenditures/new','receipt','Нов расход'];
            if ($this->scope->can($userId, 'view', 'budget_change')) $children[] = ['/changes','arrows','Буџетски промени'];
            return $children;
        }
        if ($module['path'] === '/imports') {
            // One sub-menu per import; Finance-only imports are listed only for those who may run them.
            $children = [['/imports','upload','Историја на увозите']];
            foreach (\EKabineti\Service\ImportWorkflow::SECTIONS as $slug => $label) {
                if (in_array($slug, ['approved-budget','freebalance-summary'], true) && !$this->scope->canImportBudgetExecution($userId)) continue;
                if ($slug === 'enforcement-executions' && !$this->scope->canImportExecutedCases($userId)) continue;
                $children[] = ['/imports/'.$slug, 'upload', $label];
            }
            return $children;
        }
        $children = [];
        foreach ($module['children'] ?? [] as $child) {
            [$childPath, $icon, $label] = $child;
            if (isset($child[3]) && !$this->scope->can($userId, (string) $child[3], (string) $child[4])) continue;
            if ($childPath === '/cases/new' && $this->scope->isMayor($userId)) continue;
            $children[] = [$childPath, $icon, $label];
        }
        return count($children) > 1 ? $children : [];
    }

    private function pageHeader(string $title, ?array $module, string $active, string $path = ''): string
    {
        $crumbs = '<li><a href="/">Панел</a></li>';
        if ($module !== null && $active !== '/') {
            $href = $path !== $module['path'] ? self::listUrl($module['path']) : $module['path'];
            $crumbs .= '<li><a href="'.self::e($href).'">'.self::e($module['label']).'</a></li>';
        }
        return '<header class="page-header"><nav class="breadcrumb" aria-label="Позиција во платформата"><ol>'.$crumbs.'</ol></nav>'
            .'<h1>'.self::e($title).'</h1></header>';
    }

    private function alerts(?string $flash, ?string $error): string
    {
        return ($flash ? '<div class="alert alert--success" role="status">'.self::e($flash).'</div>' : '')
            .($error ? '<div class="alert alert--error" role="alert">'.self::e($error).'</div>' : '');
    }

    private function footer(string $locale): string
    {
        $suffix = $locale === 'en' ? '?lang=en' : '';
        return '<footer class="site-footer"><span>е-Кабинет · Општина Битола</span><span><a href="/privacy.php'.$suffix.'">Приватност</a> · <a href="/terms.php'.$suffix.'">Услови за користење</a></span><span class="site-footer__credit">Развој: <a href="https://alt-pr.com" target="_blank" rel="noopener">alt-pr.com</a></span></footer>';
    }

    private function bottomNav(array $modules, string $active): string
    {
        $byPath = [];
        foreach ($modules as $module) $byPath[$module['path']] = $module;
        $links = '';
        $count = 0;
        foreach (self::QUICK as $path) {
            if (!isset($byPath[$path]) || $count === 4) continue;
            $module = $byPath[$path];
            $isActive = $active === $path;
            $links .= '<a class="bottom-nav__item'.($isActive ? ' is-active' : '').'" href="'.self::e($path).'"'.($isActive ? ' aria-current="page"' : '').'>'.$this->icon((string) $module['icon']).'<span>'.self::e($this->shortLabel($module)).'</span></a>';
            $count++;
        }
        return '<nav class="bottom-nav" aria-label="Брза навигација">'.$links
            .'<button class="bottom-nav__item" type="button" aria-expanded="false" aria-controls="app-sidebar" data-drawer-toggle>'.$this->icon('more').'<span>Повеќе</span></button></nav>';
    }

    private function shortLabel(array $module): string
    {
        return match ($module['path']) {
            '/budget' => 'Финансии', '/legal-office' => 'Правни', '/cases' => 'Предмети', '/citizen-services' => 'Услуги', '/mayor-dashboard' => 'Dashboard',
            default => (string) $module['label'],
        };
    }

    private function unreadNotifications(int $userId): int
    {
        try {
            return $this->auth->unreadNotifications();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function visibleModules(int $userId): array
    {
        return array_values(array_filter(
            self::MODULES,
            fn (array $module): bool => (!isset($module['roles']) || array_intersect($module['roles'], $this->scope->roles($userId)) !== [])
                && ($module['path'] !== '/mayor-agenda' || $this->scope->canAccessMayorAgenda($userId))
                && ($module['path'] !== '/ai-reporting' || $this->scope->canAccessAiReporting($userId))
                && ($module['path'] !== '/reports/inspection' || $this->scope->canAccessInspectionReporting($userId))
                && ($module['path'] !== '/legal-office' || $this->scope->canAccessLegalOffice($userId))
                && ($module['path'] !== '/executed-cases' || $this->scope->canAccessExecutedCases($userId))
                && ($module['path'] !== '/revenues' || $this->scope->canAccessRevenues($userId))
                && ($module['path'] !== '/citizen-services' || $this->scope->canAccessCitizenServices($userId))
                && ($module['path'] !== '/document-intake' || $this->scope->directorateIds($userId) !== [])
                && ($module['path'] !== '/citizen-services/catalog' || $this->scope->citizenManageDirectorateIds($userId) !== [])
                && ($module['path'] !== '/procurement-projects' || $this->scope->canAccessProcurementList($userId))
                && ($module['action'] === null
                    || $this->scope->can($userId, (string) $module['action'], (string) $module['resource']))
                && !($this->scope->isMayor($userId) && in_array($module['path'], ['/imports', '/audit'], true))
        ));
    }

    private function activeModule(array $modules, string $path): string
    {
        $paths = array_column($modules, 'path');
        if ((str_starts_with($path, '/execution') || str_starts_with($path, '/changes')) && in_array('/budget', $paths, true)) return '/budget';
        if (str_starts_with($path, '/directorates/') && in_array('/mayor-agenda', $paths, true)) return '/mayor-agenda';
        if (str_starts_with($path, '/decisions/') && in_array('/workflows', $paths, true)) return '/workflows';
        $best = '/';
        foreach ($paths as $modulePath) {
            if ($modulePath === '/') continue;
            if (($path === $modulePath || str_starts_with($path, rtrim($modulePath, '/').'/') || str_starts_with($path, $modulePath.'.')) && strlen($modulePath) > strlen($best)) $best = $modulePath;
        }
        return $best === '/' && $path !== '/' ? '' : $best;
    }

    private function moduleFor(array $modules, string $path): ?array
    {
        foreach ($modules as $module) if ($module['path'] === $path) return $module;
        return null;
    }

    private function icon(string $name,bool $compact=false): string
    {
        $drawing=match($name){
            'activity'=>'<path d="M3 12h4l2.5-7 5 14 2.5-7h4"/>',
            'search'=>'<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
            'share'=>'<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.7 10.7 6.6-4.4m-6.6 7 6.6 4.4"/>',
            'crown'=>'<path d="m3 7 4 4 5-7 5 7 4-4-2 11H5L3 7Z"/><path d="M5 18h14"/>',
            'dashboard'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'hand-heart'=>'<path d="M12 21s-7-4.4-7-10a4 4 0 0 1 7-2.7A4 4 0 0 1 19 11c0 5.6-7 10-7 10Z"/><path d="M3 15H1m22 0h-2"/>',
            'sliders'=>'<path d="M4 6h10m4 0h2M4 12h2m4 0h10M4 18h7m4 0h5"/><circle cx="16" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="13" cy="18" r="2"/>',
            'folder-kanban'=>'<path d="M3 6a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6Z"/><path d="M8 11v5m4-3v3m4-6v6"/>',
            'scale'=>'<path d="M12 3v18M6 6h12M5 21h14"/><path d="m6 6-4 7h8L6 6Zm12 0-4 7h8l-4-7Z"/><path d="M2 13a4 4 0 0 0 8 0m4 0a4 4 0 0 0 8 0"/>',
            'wallet'=>'<path d="M4 6a2 2 0 0 1 2-2h12v16H6a2 2 0 0 1-2-2V6Z"/><path d="M4 8h14m0 3h3v5h-3a2.5 2.5 0 0 1 0-5Z"/>',
            'workflow'=>'<circle cx="6" cy="5" r="2"/><circle cx="18" cy="5" r="2"/><circle cx="12" cy="19" r="2"/><path d="M8 5h8M6 7v3a4 4 0 0 0 4 4h2m6-7v3a4 4 0 0 1-4 4h-2v3"/>',
            'triangle-alert'=>'<path d="M10.3 3.7 2.6 18a2 2 0 0 0 1.8 3h15.2a2 2 0 0 0 1.8-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4m0 4h.01"/>',
            'briefcase'=>'<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m-13 5h18m-9-2v4"/>',
            'chart'=>'<path d="M4 20V10m6 10V4m6 16v-7m4 7H2"/>',
            'sparkles-chart'=>'<path d="M4 20V11m5 9v-5m5 5V8m5 12v-9"/><path d="m6 3 .6 1.4L8 5l-1.4.6L6 7l-.6-1.4L4 5l1.4-.6L6 3Zm12 0 .5 1.2L20 5l-1.5.8L18 7l-.5-1.2L16 5l1.5-.8L18 3Z"/>',
            'upload'=>'<path d="M12 16V3m-5 5 5-5 5 5"/><path d="M5 13H3v7h18v-7h-2"/>',
            'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18m-13 4h.01m4 0h.01m4 0h.01m-8 4h.01m4 0h.01"/>',
            'building'=>'<path d="M4 21V5l8-3 8 3v16M2 21h20M8 7h.01M12 7h.01M16 7h.01M8 11h.01M12 11h.01M16 11h.01M9 21v-5h6v5"/>',
            'users'=>'<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0m1-17a4 4 0 0 1 0 8m2 3a6 6 0 0 1 3 6"/>',
            'shield-check'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
            'terminal'=>'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 3 3-3 3m6 0h4"/>',
            'wrench'=>'<path d="M14.7 6.3a4 4 0 0 0-5-5L12 3.6 9.6 6 7.3 3.7a4 4 0 0 0 5 5L4 17a2.1 2.1 0 1 0 3 3l7.7-8.3a4 4 0 0 0 0-5.4Z"/>',
            'bell'=>'<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
            'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
            'more'=>'<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
            'home'=>'<path d="M3 11 12 3l9 8"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
            'log-out'=>'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
            'book-open'=>'<path d="M2 4h6a4 4 0 0 1 4 4v13a4 4 0 0 0-4-4H2V4Zm20 0h-6a4 4 0 0 0-4 4v13a4 4 0 0 1 4-4h6V4Z"/>',
            'plus'=>'<circle cx="12" cy="12" r="9"/><path d="M12 8v8m-4-4h8"/>',
            'list'=>'<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
            'file-plus'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6m-8 5v6m-3-3h6"/>',
            'split'=>'<circle cx="12" cy="5" r="2"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="19" r="2"/><path d="M12 7v4m0 0H6v6m6-6h6v6"/>',
            'coins'=>'<ellipse cx="9" cy="6" rx="6" ry="3"/><path d="M3 6v4c0 1.7 2.7 3 6 3m-6-3v4c0 1.7 2.7 3 6 3"/><ellipse cx="16" cy="15" rx="5" ry="3"/><path d="M11 15v4c0 1.7 2.2 3 5 3s5-1.3 5-3v-4"/>',
            'clipboard-check'=>'<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 4a3 3 0 0 1 6 0v2H9V4Zm0 10 2 2 4-4"/>',
            'receipt'=>'<path d="M5 3 7 5l2-2 2 2 2-2 2 2 2-2 2 2v16l-2-2-2 2-2-2-2 2-2-2-2 2-2-2V3Z"/><path d="M9 9h6m-6 4h6"/>',
            'arrows'=>'<path d="m7 7-4 4 4 4m-4-4h18M17 3l4 4-4 4m4-4H3"/>',
            default=>'<circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/>',
        };
        return '<svg class="icon'.($compact?' icon--sm':'').'" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$drawing.'</svg>';
    }

    private function guestNavigation(string $path): string
    {
        $locale = Translator::locale();
        $suffix = $locale === 'en' ? '?lang=en' : '';
        return '<nav class="guest-nav" aria-label="Главна навигација"><a'.($path === '/login' ? ' aria-current="page"' : '').' href="/login">Најави се</a>'
            .'<a'.($path === '/register' ? ' aria-current="page"' : '').' href="/register">Регистрирај се</a>'
            .'<a href="?lang='.($locale === 'en' ? 'mk' : 'en').'">'.($locale === 'en' ? 'MK' : 'EN').'</a></nav>';
    }

    private function pageClass(string $path): string
    {
        return str_starts_with($path, '/budget') || str_starts_with($path, '/execution') || str_starts_with($path, '/changes')
            ? 'area-finance'
            : '';
    }

    public function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="'.self::e($this->auth->csrf()).'">';
    }

    /** Stores the address of a list page (path + filters + page) for this session. */
    public static function rememberList(string $path): void
    {
        if (!isset($_SESSION) || !str_starts_with($path, '/')) return;
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? $path);
        $_SESSION['_list_urls'][$path] = mb_substr($uri, 0, 2000);
    }

    /**
     * Address to go back to a list: the filtered page last seen in this session, or the plain list. Only an address of
     * that same list is used (no open redirect); $anchor (e.g. "#project-12") brings the row back into view.
     */
    public static function listUrl(string $path, string $anchor = ''): string
    {
        $stored = (string) ($_SESSION['_list_urls'][$path] ?? '');
        $url = ($stored === $path || str_starts_with($stored, $path.'?')) ? $stored : $path;
        return $url.($anchor !== '' && preg_match('/^#[A-Za-z0-9_-]+$/', $anchor) ? $anchor : '');
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.').' ден.';
    }
}

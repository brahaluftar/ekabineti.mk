<?php

declare(strict_types=1);

namespace EKabineti\Core;

final class Translator
{
    public static function locale(?array $user = null): string
    {
        $locale = (string) ($_SESSION['_locale'] ?? ($user['locale'] ?? 'mk'));
        return in_array($locale, ['mk', 'en'], true) ? $locale : 'mk';
    }

    public static function text(string $text, string $locale): string
    {
        return $locale === 'en' ? strtr($text, self::english()) : $text;
    }

    public static function html(string $html, string $locale): string
    {
        if ($locale !== 'en') return $html;
        $html = str_replace('<html lang="mk">', '<html lang="en">', $html);
        $map = self::english();
        $html = preg_replace_callback('/(^|>)([^<]+)(?=<|$)/u', static function(array $match) use ($map): string {
            return $match[1].self::htmlText($match[2], $map);
        }, $html) ?? $html;
        return preg_replace_callback('/\b(aria-label|title|placeholder|alt)="([^"]*)"/u', static function(array $match) use ($map): string {
            return $match[1].'="'.self::translatedUnit($match[2], $map).'"';
        }, $html) ?? $html;
    }

    /** @param array<string,string> $map */
    private static function htmlText(string $text, array $map): string
    {
        if (!preg_match('/^(\s*)(.*?)(\s*)$/us', $text, $parts)) return $text;
        return $parts[1].self::translatedUnit($parts[2], $map).$parts[3];
    }

    /** @param array<string,string> $map */
    private static function translatedUnit(string $text, array $map): string
    {
        if ($text === '' || isset($map[$text])) return $map[$text] ?? $text;
        if (str_contains($text, ' · ')) {
            $pieces = explode(' · ', $text);
            $translated = array_map(static fn(string $piece): string => self::translatedUnit($piece, $map), $pieces);
            return implode(' · ', $translated);
        }
        foreach (['Сектор:'=>'Directorate:','Менаџер:'=>'Manager:','Напредок:'=>'Progress:'] as $prefix=>$translatedPrefix) {
            if (str_starts_with($text, $prefix)) return $translatedPrefix.substr($text, strlen($prefix));
        }
        if (preg_match('/^Уредување на ризик #(\d+)$/u', $text, $risk)) return 'Edit risk #'.$risk[1];
        if (preg_match('/^(.+) (\([A-Z]{3}\))$/u', $text, $suffix) && isset($map[$suffix[1]])) return $map[$suffix[1]].' '.$suffix[2];
        if (preg_match('/^(\d+\/\d+) завршени$/u', $text, $count)) return $count[1].' completed';
        if (preg_match('/^(\d+) проекти$/u', $text, $count)) return $count[1].' projects';
        if (preg_match('/^(\d+) ризици$/u', $text, $count)) return $count[1].' risks';
        if (preg_match('/^(\d+) на агендата$/u', $text, $count)) return $count[1].' on the agenda';
        return $text;
    }

    /** @return array<string,string> */
    private static function english(): array
    {
        static $map;
        if ($map !== null) return $map;
        $map = [
            'Развој:'=>'Developed by:',
            'Избор на проекти за агендата со Градоначалникот'=>'Selecting projects for the Mayor agenda',
            'Изберете сектор за да ги означите проектите и ризиците за кои треба да се дискутира. Раководителот на секторот има пристап само до својот сектор; Администраторот на Градоначалникот, Градоначалникот и Администраторот на системот имаат глобален приказ.'=>'Select a directorate to mark the projects and risks that should be discussed. A Director can access only their assigned directorate; the Mayor Administration, Mayor and System Administrator roles have a global view.',
            'Немате определен сектор за управување со агендата.'=>'You do not have an assigned directorate for agenda management.',
            'Немате дозвола за агендата на овој сектор.'=>'You do not have permission for this directorate agenda.',
            'Немате дозвола за агендата на Градоначалникот.'=>'You do not have permission for the Mayor agenda.',
            'Агенда на Градоначалникот'=>'Mayor agenda','Проекти за дискусија'=>'Projects for discussion','Назад кон агендата'=>'Back to agenda',
            'Агенда за дискусија и внимание на Градоначалникот'=>'Agenda for the Mayor’s discussion and attention',
            'Активните проекти, ризици и теми избрани од секторите.'=>'Active projects, risks and topics selected by the directorates.',
            'Известувања во реално време'=>'Real-time reports','Буџет одобрен од Советот на општината'=>'Budget approved by the Municipal Assembly',
            'Евидентирај ставка од одобрениот буџет'=>'Register an approved budget line','Буџетски ставки на проектот'=>'Project budget lines',
            'Поврзи го проектот со буџетска ставка'=>'Link the project to a budget line','Задачи и следни чекори'=>'Tasks and next steps',
            'Историја на извештаите'=>'Reporting history','Превентивни мерки и мерки за намалување'=>'Preventive and mitigating measures',
            'Наведеното намалување се применува во резидуалниот резултат само кога мерката е означена „Завршена“. Мора да се намали барем веројатноста или влијанието.'=>'The stated reduction affects the residual score only after the measure is marked “Completed”. At least probability or impact must be reduced.',
            'Листата на проекти ги прикажува ID-то, шифрата, називот, статусот и дел од целта.'=>'The project list shows the ID, code, name, status and part of the objective.',
            'Најпрво изберете го секторот.'=>'Select the directorate first.','Регистар на ризици'=>'Risk register','Буџетски промени'=>'Budget changes',
            'Буџетско извршување'=>'Budget execution','Раководен панел'=>'Executive dashboard','Повеќередов извештај'=>'Multi-line report',
            'Администрирање на секторите'=>'Directorate administration','Управување со корисници'=>'User management','Системски логови'=>'System logs',
            'Безбедност на сметката'=>'Account security','Профил'=>'Profile','Мој профил'=>'My profile','Поставки и безбедност'=>'Preferences and security','Безбедност'=>'Security','Промена на лозинка'=>'Password change','Проекти и агенда на секторот'=>'Directorate projects and agenda',
            'Прирачник на системот'=>'System manual','Прирачник и помош'=>'Manual and help','Полиња, врски и текови'=>'Fields, relationships and flows',
            'Новата лозинка мора да има најмалку 14 знаци, мала буква, голема буква, број и симбол.'=>'The new password must contain at least 14 characters, a lowercase letter, an uppercase letter, a number and a symbol.',
            'Сметката се активира откако администраторот ќе ви додели улога и пристап.'=>'The account is activated after an administrator assigns its role and access.',
            'Немате дозвола за ова дејство.'=>'You do not have permission for this action.','Страницата не е пронајдена.'=>'Page not found.',
            'Настана внатрешна грешка. Референца:'=>'An internal error occurred. Reference:','Премини на содржината'=>'Skip to content',
            'Општина Битола'=>'Municipality of Bitola','Управување со проекти и буџет'=>'Project and budget management',
            'Работен простор'=>'Workspace','Главна навигација'=>'Main navigation','Навигација на е-Кабинет'=>'e-Kabineti navigation',
            'Позиција во платформата'=>'Position in platform','Активен корисник'=>'Active user','Промени лозинка'=>'Change password',
            'Услови за користење'=>'Terms of use','Приватност'=>'Privacy','Регистрирај се'=>'Register','Саморегистрација'=>'Self-registration',
            'Е-пошта или корисничко име'=>'Email or username','Тековна лозинка'=>'Current password','Нова лозинка'=>'New password',
            'Повторете ја новата лозинка'=>'Repeat new password','Повторете ја лозинката'=>'Repeat password','Лозинка'=>'Password',
            'Име и презиме'=>'Full name','Испрати регистрација'=>'Submit registration','Немате сметка?'=>'Do not have an account?',
            'Имате сметка?'=>'Already have an account?','Прифаќам'=>'I accept','политиката за приватност'=>'the privacy policy',
            'Тековен план'=>'Current plan','Расположиво'=>'Available','Потрошено'=>'Spent','Преземено'=>'Committed','Последни проекти'=>'Latest projects',
            'Нов проект'=>'New project','Нова задача'=>'New task','Нов извештај'=>'New report','Нова распределба'=>'New allocation',
            'Нова обврска'=>'New commitment','Нов расход'=>'New expenditure','Ново барање'=>'New request','Евидентирај одлука'=>'Record decision',
            'Нов ризик'=>'New risk','Додај мерка'=>'Add measure','Преглед на увозот'=>'Import preview','Потврди го пакетот'=>'Confirm batch',
            'Финансиски увози V15'=>'V15 financial imports','Капитални инвестиции'=>'Capital Investments','Буџетски расходи'=>'Budget expenditures',
            'Постоечки увоз на проекти'=>'Existing project import','Историја на увозите'=>'Import history','Преглед на пакетот'=>'Batch preview',
            'Валидни редови'=>'Valid rows','Редови со грешки'=>'Rows with errors','Вкупно буџетирано'=>'Total budgeted','Вкупно расходи'=>'Total expenditures',
            'Состојба на остатокот на'=>'Available snapshot as of','Извор за колоната Буџет'=>'Funding source for Budget column','Изберете извор'=>'Select funding source',
            'Остаток според увозот'=>'Imported available snapshot','Состојба на'=>'As of','Состојба на увозот на'=>'Import snapshot as of',
            'Валидирај ги капиталните инвестиции'=>'Validate Capital Investments','Валидирај ги расходите'=>'Validate Expenditures','Валидирај ги проектите'=>'Validate Projects',
            'Потврди и објави го пакетот'=>'Confirm and post batch','Клучни податоци'=>'Key data','Грешки'=>'Errors','Редови'=>'Rows',
            'Преземи CSV образец'=>'Download CSV template','Преземи Excel образец'=>'Download Excel template','Excel или CSV'=>'Excel or CSV','CSV или XLSX'=>'CSV or XLSX',
            'Прикачете CSV или XLSX. Системот создава преглед, го проверува финансискиот опсег и објавува само по атомска потврда. Ефективен лимит:'=>'Upload CSV or XLSX. The system creates a preview, checks financial scope, and posts only after atomic confirmation. Effective limit:',
            'Директно ги прифаќа колоните: Шифра на проектот, Капитални, Буџет, Остаток, СЕКТОР. Остатокот се чува како информативна состојба; оперативната расположивост и понатаму се пресметува од трансакциите.'=>'Directly accepts the columns: Project code, Capital investments, Budget, Available, DIRECTORATE. Available is stored as an informational snapshot; operational availability remains transaction-derived.',
            'Секој ред се поврзува со активна обврска преку единствена референца. Изворната референца на фактурата или налогот за плаќање не смее да се повторува.'=>'Each row is linked to an active commitment through its unique reference. The invoice or payment-order source reference cannot be duplicated.',
            'Потврдата ги објавува сите редови во една трансакција.'=>'Confirmation posts every row in one transaction.',
            'Повеќегодишно известување'=>'Multi-year reporting',
            'Извозот ги користи точно активните филтри и вашиот пристап.'=>'The export uses the active filters and your access scope exactly.',
            'Нема ставки од одобрениот буџет.'=>'There are no approved budget lines.',
            'Назив / опис'=>'Name / description',
            'Активните ризици се подредени според резидуалниот резултат, по спроведувањето на завршените мерки.'=>'Active risks are ranked by residual score after completed measures are applied.',
            'Изберете категорија и објаснување'=>'Select a category and its explanation',
            'Стратешки — целите, политиките и приоритетите'=>'Strategic — objectives, policies and priorities',
            'Финансиски/Буџетски — финансирањето, трошоците и приходите'=>'Financial/Budget — funding, costs and revenue',
            'Оперативен — процесите, капацитетите и континуитетот'=>'Operational — processes, capacity and continuity',
            'Набавка/Договор — тендерите, операторите и договорите'=>'Procurement/Contract — tendering, operators and contracts',
            'Правен/Усогласеност — законите, правилниците и постапките'=>'Legal/Compliance — laws, regulations and procedures',
            'Рок/Реализација — доцнењата, зависностите и испораката'=>'Schedule/Delivery — delays, dependencies and delivery',
            'Технички/Дигитален — системите, интеграциите и безбедноста'=>'Technical/Digital — systems, integrations and security',
            'Човечки ресурси — персоналот, вештините и одговорностите'=>'Human resources — staffing, skills and responsibilities',
            'Репутација/Чинители — јавноста, партнерите и комуникацијата'=>'Reputation/Stakeholders — public, partners and communication',
            'Животна средина/Безбедност — здравјето, безбедноста и влијанието врз животната средина'=>'Environment/Safety — health, safety and environmental impact',
            'Превентивна — ја спречува причината пред да се материјализира'=>'Preventive — stops the cause before it materializes',
            'Намалувачка — ја намалува веројатноста или влијанието'=>'Mitigating — reduces probability or impact',
            'Пренесувачка — ја пренесува изложеноста на друга страна'=>'Transfer — shifts exposure to another party',
            'Избегнувачка — го менува планот за да ја елиминира изложеноста'=>'Avoidance — changes the plan to eliminate exposure',
            'Резервна — резервно дејство ако ризикот се случи'=>'Contingency — fallback action if the risk occurs',
            'Завршена — примени го намалувањето'=>'Completed — apply the reduction','Не се применува до завршувањето'=>'Not applied until completion',
            'Планирано намалување'=>'Planned reduction','Ефект врз ризикот'=>'Effect on risk',
            'Ова дејство можат да го извршат само Администраторот на системот и Градоначалникот. Секторот се создава активен и веднаш се појавува во проектите и доделувањето пристап.'=>'Only the System Administrator and Mayor can perform this action. The directorate is created as active and appears immediately in projects and access assignments.',
            'Назив на секторот'=>'Directorate name','Создај сектор'=>'Create directorate','Постојни сектори'=>'Existing directorates',
            'Регистрирај нов корисник'=>'Register a new user','Почетна лозинка'=>'Initial password','Активни корисници'=>'Active users',
            'Подготвено за потврда'=>'Ready for confirmation','Се анализира'=>'Being analyzed','Со грешки'=>'Contains errors','Објавено'=>'Posted',
            'Поднесено'=>'Submitted','Одобрено'=>'Approved','Одбиено'=>'Rejected','Вратено'=>'Returned','Поднеси'=>'Submit',
            'Прераспределба'=>'Reallocation','Зголемување'=>'Increase','Намалување'=>'Decrease','Пренос'=>'Carry-over','Поништување'=>'Cancellation','Техничка корекција'=>'Technical correction',
            'Избегнување'=>'Avoidance','Пренесување'=>'Transfer','Контролирано прифаќање'=>'Controlled acceptance',
            'На чекање'=>'On hold','Ублажено'=>'Mitigated','завршени'=>'completed',
            'Назад кон панелот'=>'Return to dashboard','Примени'=>'Apply',
            'Администратор на системот'=>'System Administrator','Градоначалник'=>'Mayor','Администратор на Градоначалникот'=>'Mayor’s Office Administrator',
            'Раководител на сектор'=>'Director','Менаџер на проект'=>'Project Manager','Финансиски службеник'=>'Finance Officer','Известувач'=>'Reporter','Заменик-градоначалник'=>'Deputy Mayor',
            'Полето е задолжително'=>'The field is required','Колоната недостасува во датотеката'=>'The column is missing from the file',
            'Вредноста се повторува во датотеката'=>'The value is duplicated in the file','Шифрата на секторот не постои'=>'The directorate code does not exist',
            'Секторот не се совпаѓа со регистарот'=>'The directorate did not match the registry','Надвор од вашиот опсег'=>'Outside your access scope',
            'Потребна е финансиска улога за секторот'=>'A financial role is required for the directorate','Износот не е валиден'=>'The amount is invalid',
            'Остатокот го надминува буџетот'=>'Available amount exceeds the budget','Изворите не се изедначуваат со вкупниот буџет'=>'Funding sources do not reconcile with the total budget',
            'Вредноста е предолга'=>'The value is too long','Датумот не е валиден'=>'The date is invalid',
            'Референцата е веќе објавена'=>'The reference was posted previously','Обврската не е пронајдена'=>'The commitment was not found',
            'Обврската не е активна'=>'The commitment is not active','Вредноста не се совпаѓа со обврската'=>'The value does not match the commitment',
            'Износот го надминува салдото на обврската'=>'The amount exceeds the commitment balance','Датумите не се во валиден редослед'=>'The dates are not in a valid order',
            'Проект'=>'Project','Уредување на проектот'=>'Edit project','Уредување на задачата'=>'Edit task','Уредување на секторот'=>'Edit directorate',
            'Секторот и напредокот не се менуваат тука. Напредокот се ажурира само со нов извештај.'=>'The directorate and progress cannot be changed here. Progress is updated only through a new report.',
            'За ретроактивен проект, вклучете ја опцијата за да се создадат во истата трансакција и распределбата и обврската со ист износ.'=>'For a retrospective project, enable the option to create both the allocation and commitment for the same amount in one transaction.',
            'Ретроактивен проект — создај ја и обврската'=>'Retrospective project — also create the commitment',
            'Добавувач / корисник'=>'Supplier / beneficiary','Зачувај ја буџетската врска'=>'Save budget link','Следен чекор'=>'Next step','Стави во карантин'=>'Place in quarantine',
            'Зачувај ја задачата'=>'Save task','Зачувај'=>'Save',
            'Бројот на работниот тек е технички идентификатор. Колоната „Прашање / објект“ го покажува конкретното барање, износот, образложението, секторот и чекорот што чека одлука.'=>'The workflow number is the technical identifier. The “Subject / object” column shows the specific request, amount, justification, directorate and step awaiting a decision.',
            'Работен тек и прашање'=>'Workflow and subject','Изберете прашање што чека одлука'=>'Select the subject awaiting a decision',
            'Најпрво изберете го секторот. Листата на проекти ги прикажува ID-то, шифрата, називот, статусот и дел од целта.'=>'Select the directorate first. The project list shows the ID, code, name, status and part of the objective.',
            'Намали'=>'Reduce','Додај ја мерката'=>'Add measure',
            'Деактивирањето го отстранува секторот од новите обрасци, но не ги брише проектите, буџетот или историјата.'=>'Deactivation removes the directorate from new forms but does not delete projects, budgets or history.',
            'Корисници и пристап'=>'Users and access','Нов корисник'=>'New user','Нов сектор'=>'New directorate',
            'Панел'=>'Dashboard','Панел на Градоначалникот'=>'Mayor Dashboard','Неделен панел на Градоначалникот'=>'Mayor weekly dashboard','Неделен панел'=>'Weekly dashboard','Проекти'=>'Projects','Буџет'=>'Budget','Извршување'=>'Execution','Промени'=>'Changes','Ризици'=>'Risks',
            'Извештаи'=>'Reports','Увози'=>'Imports','Сектори'=>'Directorates','Корисници'=>'Users','Ревизија'=>'Audit','Логови'=>'Logs','Модули'=>'Modules',
            'Раководен преглед'=>'Executive overview','Проекти и извештаи'=>'Projects and reports','Планирање и распределби'=>'Planning and allocations',
            'Обврски и расходи'=>'Commitments and expenditures','Буџетски барања'=>'Budget requests','Одлуки и одобрувања'=>'Decisions and approvals',
            'Контролиран увоз'=>'Controlled import','Организациска структура'=>'Organizational structure','Сметки и пристапи'=>'Accounts and access',
            'Траги од дејствата'=>'Action trail','Проблеми во реално време'=>'Real-time issues','Филтрирај ја листата'=>'Filter list','Исчисти ги филтрите'=>'Clear filters',
            'Извези го панелот во Excel'=>'Export dashboard to Excel','Извези CSV'=>'Export CSV','Извези во Excel'=>'Export Excel','Пребарај'=>'Search',
            'Сите сектори'=>'All directorates','Сите статуси'=>'All statuses','Сите категории'=>'All categories','Сите видови'=>'All types',
            'Фискална година'=>'Fiscal year','Сектор'=>'Directorate','Шифра на проектот / ID'=>'Project code / ID','Шифра на проектот'=>'Project code','Вид на записот'=>'Record type','Резултат на ризикот'=>'Risk score','Следен преглед'=>'Next review','Назив на проектот'=>'Project name','Статус на проектот'=>'Project status','Детали'=>'Details',
            'Поврзан проект'=>'Linked project','Опис на ризикот'=>'Risk description','Опис'=>'Description','Категорија'=>'Category',
            'Почетна веројатност 1-5'=>'Initial probability 1-5','Почетно влијание 1-5'=>'Initial impact 1-5','Почетен ризик'=>'Initial risk',
            'Резидуален ризик'=>'Residual risk','Главна стратегија'=>'Primary response','Преглед'=>'Review date','Причина'=>'Cause','Последица'=>'Consequence',
            'Мерка / дејство'=>'Measure / action','Намалување на веројатноста'=>'Probability reduction','Намалување на влијанието'=>'Impact reduction',
            'Доказ / резултат'=>'Evidence / result','Одговорно лице'=>'Owner','Рок'=>'Due date','Почеток'=>'Start','Завршување'=>'End',
            'Датум на почеток'=>'Start date','Сметководствен датум'=>'Accounting date','Предложен датум'=>'Proposed date','Период'=>'Period',
            'Напредок'=>'Progress','Статус'=>'Status','Дејство'=>'Action','Коментар'=>'Comment','Образложение'=>'Justification','Влијание'=>'Impact',
            'Итност'=>'Urgency','Извор'=>'Source','Цел'=>'Target','Износ'=>'Amount','Година'=>'Year','Назив'=>'Name','Шифра'=>'Code','Наслов'=>'Title',
            'Цел'=>'Objective','Менаџер'=>'Manager','Документи'=>'Documents','Класификација'=>'Classification','Датотека'=>'File','Скенирање'=>'Scanning',
            'Големина'=>'Size','Следен чекор'=>'Next step','Зависности / белешки'=>'Dependencies / notes','Тежина во проектот (%)'=>'Project weight (%)',
            'Придонес'=>'Contribution','Сопствени приходи'=>'Own-source revenue','Блок дотација за здравство'=>'Government health grant',
            'Блок дотација за образование'=>'Government education grant','Блок дотација'=>'Government grant','Дотација здравство'=>'Health grant',
            'Дотација образование'=>'Education grant','Планирано'=>'Planned','Тековно'=>'Current','Проекција'=>'Forecast','Распределено'=>'Allocated','Слободни'=>'Free','Вкупно'=>'Total',
            'Вид'=>'Type','Приоритет'=>'Priority','Избрано од'=>'Selected by','Прашање за дискусија'=>'Discussion item','Тековна состојба'=>'Current state',
            'Датум'=>'Date','Без поврзан проект'=>'No linked project','Без проект'=>'No project','Без одговорно лице'=>'No owner','Без датум'=>'No date','Без улога'=>'No role',
            'Нема податоци.'=>'No data.','Нема извештаи.'=>'No reports.','Нема документи.'=>'No documents.','Нема задачи.'=>'No tasks.',
            'Нема евидентирани мерки.'=>'No measures have been registered.','Нема активни точки за дискусија.'=>'There are no active discussion items.',
            'Одобри'=>'Approve','Врати'=>'Return','Одбиј'=>'Reject','Зачувај ги промените'=>'Save changes','Зачувај ја ставката'=>'Save line','Зачувај ја мерката'=>'Save measure',
            'Зачувај го пристапот'=>'Save access','Евидентирај го ризикот'=>'Register risk','Евидентирај ја ставката'=>'Register line','Евидентирај'=>'Record',
            'Објави го извештајот'=>'Publish report','Објави го расходот'=>'Post expenditure','Објави ја обврската'=>'Post commitment','Создај ја задачата'=>'Create task',
            'Создај корисник'=>'Create user','Создај'=>'Create','Уреди го проектот'=>'Edit project','Отвори и управувај'=>'Open and manage','Уреди'=>'Edit',
            'Откажи'=>'Cancel','Одјави се'=>'Sign out','Најава'=>'Sign in','Најави се'=>'Sign in','Активни'=>'Active','Активно'=>'Active','Неактивни'=>'Inactive','Неактивно'=>'Inactive',
            'Блокирано'=>'Blocked','Отворен'=>'Open','Затворен'=>'Closed','Во следење'=>'Monitoring','Во реализација'=>'In progress','Чека дискусија'=>'Awaiting discussion',
            'Не е започната'=>'Not started','Завршена'=>'Completed','Завршено'=>'Completed','Планирано'=>'Planned','Откажано'=>'Cancelled',
            'Критичен'=>'Critical','Висок'=>'High','Normal'=>'Normal','Стоки и услуги'=>'Goods and Services','Капитални инвестиции'=>'Capital Investments',
            'Плата'=>'Wages','Субвенции'=>'Subsidies','Превентивна'=>'Preventive','Намалувачка'=>'Mitigating','Пренесувачка'=>'Transfer',
            'Избегнувачка'=>'Avoidance','Резервна'=>'Contingency','Македонски'=>'Macedonian','Англиски'=>'English','Јазик'=>'Language',
            'Нова регистрација во е-Кабинет'=>'New e-Kabineti registration','Барањето за регистрација е примено'=>'Registration request received','Вашата сметка во е-Кабинет е создадена'=>'Your e-Kabineti account was created','Пристапот до е-Кабинет е ажуриран'=>'Your e-Kabineti access was updated','Лозинката за е-Кабинет е променета'=>'Your e-Kabineti password was changed','Лозинката на вашата сметка во е-Кабинет е променета. Ако не сте го извршиле вие ова дејство, веднаш контактирајте го Администраторот на системот.'=>'Your e-Kabineti account password was changed. If you did not perform this action, contact the System Administrator immediately.','Критичен ризик'=>'Critical risk','дена'=>'days','Рокот за известување е по'=>'The reporting deadline is in','rezultati:'=>'score:',
            'Политика за приватност на е-Кабинет'=>'e-Kabineti Privacy Policy','Политика за приватност'=>'Privacy Policy','Услови за користење на е-Кабинет'=>'e-Kabineti Terms of Use',
            'Оваа политика ја објаснува обработката и заштитата на податоците во платформата за управување со проекти, буџет и известување на Општина Битола.'=>'This policy explains the processing and protection of data in the Municipality of Bitola’s project, budget and reporting management platform.',
            'Овие услови го уредуваат користењето на платформата на Општина Битола за управување со проекти, буџет, извршување, промени, одобрувања и раководно известување.'=>'These terms govern use of the Municipality of Bitola platform for project, budget, execution, change, approval and executive reporting management.',
            'Јавен документ'=>'Public document','Верзија'=>'Version','Во сила од'=>'Effective from','Контролор'=>'Controller','Давател'=>'Provider','Содржина'=>'Contents',
            'Податоци што се обработуваат'=>'Data processed','Цели на обработката'=>'Purposes of processing','Пристап, улоги и примачи'=>'Access, roles and recipients',
            'Техничка и организациска безбедност'=>'Technical and organizational security','Чување и интегритет'=>'Retention and integrity','Права и контакт'=>'Rights and contact',
            'Платформата обработува податоци за сметката, како име, корисничко име, е-пошта, статус, улога и сектор; податоци за проектите, целите, задачите, напредокот и ризиците; како и распределби, обврски, резервации, расходи и буџетски промени.'=>'The platform processes account data such as name, username, email, status, role and directorate; project, objective, task, progress and risk data; as well as allocations, commitments, reservations, expenditures and budget changes.',
            'Се обработуваат и прикачените документи, одлуките од работниот тек, увозите, известувањата, ревизорските траги, correlation ID, времето на дејството и техничките информации потребни за безбедност и дијагностика.'=>'Uploaded documents, workflow decisions, imports, notifications, audit trails, correlation IDs, action times and technical information required for security and diagnostics are also processed.',
            'Податоците се користат за планирање и следење на проектите, администрирање на буџетот, контрола на извршувањето, одобрувања, управување со ризици, раководно известување, контролиран увоз, институционални известувања, ревизија и решавање проблеми.'=>'Data is used for project planning and monitoring, budget administration, execution control, approvals, risk management, executive reporting, controlled imports, institutional notifications, audit and troubleshooting.',
            'Податоците не се користат за рекламирање и не се продаваат. Извештаите смеат да се користат само за службени цели и според институционалните овластувања.'=>'Data is not used for advertising and is not sold. Reports must be used only for official purposes and under institutional authorizations.',
            'Пристапот е ограничен според улогата и опсегот на секторот. Администраторот на системот има глобален пристап за администрирање, ревизија и решавање проблеми. Другите корисници ги гледаат само модулите и податоците што ги дозволува активната улога.'=>'Access is restricted by role and directorate scope. The System Administrator has global access for administration, audit and troubleshooting. Other users see only modules and data allowed by their active role.',
            'Microsoft Graph ги добива само податоците потребни за испраќање на известувањето, како адреса на е-пошта, наслов и содржина. Давателите на инфраструктура можат да обработуваат податоци само за хостирање, безбедност, резервни копии и техничко функционирање.'=>'Microsoft Graph receives only data needed to send a notification, such as email address, subject and content. Infrastructure providers may process data only for hosting, security, backup and technical operation.',
            'Врската со SQL Server користи посветена порта и TLS. Акредитивите се чуваат во приватна конфигурација надвор од document root. Лозинките се чуваат како хаш, а автентикацијата прифаќа само е-пошта или корисничко име.'=>'The SQL Server connection uses the dedicated port and TLS. Credentials are stored in private configuration outside the document root. Passwords are hashed and authentication accepts only email or username.',
            'Платформата користи заштитени сесии, HttpOnly/SameSite колачиња, CSRF, безбедносни заглавија, авторизација на серверот, приватни документи, скенирање на датотеки, ревизија и логови што ги следи администраторот.'=>'The platform uses protected sessions, HttpOnly/SameSite cookies, CSRF, security headers, server-side authorization, private documents, file scanning, audit and administrator-visible logs.',
            'Податоците се чуваат онолку долго колку што бараат институционалните, финансиските, ревизорските, архивските и безбедносните постапки. Финансиските евиденции и ревидираните дејства не се менуваат и не се бришат тајно.'=>'Data is retained as required by institutional, financial, audit, archival and security procedures. Financial records and audited actions are not silently changed or deleted.',
            'Резервните копии, логовите и приватните документи се заштитени со ограничен пристап. Кога ќе истече применливиот рок, податоците се бришат, анонимизираат или архивираат според одобрената постапка.'=>'Backups, logs and private documents are protected by restricted access. When the applicable period ends, data is deleted, anonymized or archived under the approved procedure.',
            'Во согласност со применливите правила, лицето може да побара пристап, исправка или појаснување за своите податоци. Законските и институционалните ограничувања може да бараат чување на финансиските, административните, ревизорските или безбедносните евиденции.'=>'Subject to applicable rules, a person may request access, correction or clarification of their data. Legal and institutional restrictions may require retention of financial, administrative, audit or security records.',
            'Барањата и инцидентите поврзани со приватноста се поднесуваат преку официјалните канали на Општина Битола. Пред давање информации може да се побара проверка на идентитетот и овластувањето.'=>'Privacy requests and incidents are submitted through the official channels of the Municipality of Bitola. Identity and authorization may be verified before information is disclosed.',
            'Пристап и сметки'=>'Access and accounts','Податоци и известување'=>'Data and reporting','Буџет и одлучување'=>'Budget and decision-making','Безбедност и ревизија'=>'Security and audit','Одговорности'=>'Responsibilities',
            'е-Кабинет е институционална платформа. Пристап им се дава само на овластени лица според улогата и определениот делокруг. Најавата се врши со е-пошта или корисничко име и лозинка.'=>'e-Kabineti is an institutional platform. Access is granted only to authorized persons according to assigned role and scope. Sign-in uses email or username and password.',
            'Саморегистрацијата создава сметка на чекање. Сметката не добива пристап до податоците додека администраторот не ја активира и не ѝ додели улога и сектор. Корисникот мора да ги чува акредитивите и веднаш да пријави секое сомневање за злоупотреба.'=>'Self-registration creates a pending account. It cannot access data until an administrator activates it and assigns a role and directorate. The user must protect credentials and immediately report suspected misuse.',
            'Корисникот е одговорен за точноста, целосноста и ажурноста на проектите, напредокот, задачите, ризиците и документите што ги евидентира. Објавените извештаи се прикажуваат на раководниот панел во реално време.'=>'The user is responsible for the accuracy, completeness and timeliness of entered projects, progress, tasks, risks and documents. Published reports appear on the executive dashboard in real time.',
            'Не е дозволено евидентирање неточни информации, манипулирање со напредокот, прикачување злонамерни датотеки или користење на податоците надвор од службените одговорности.'=>'False information, progress manipulation, malicious uploads and use of data outside official responsibilities are prohibited.',
            'Распределбите, обврските, резервациите, расходите и буџетските промени ги евидентираат само овластените улоги и во рамките на соодветниот опсег. Системот го проверува расположивиот износ и ја чува врската со проектот и придружните документи.'=>'Allocations, commitments, reservations, expenditures and budget changes are recorded only by authorized roles within the relevant scope. The system checks availability and preserves links to projects and supporting documents.',
            'Одобрувањата и одбивањата мора да ја одразуваат вистинската институционална одлука. Системот ги чува извршителот, времето, причината и фазата на работниот тек; завршените дејства не смеат да се менуваат надвор од овластената постапка.'=>'Approvals and rejections must reflect the actual institutional decision. The system records actor, time, reason and workflow stage; completed actions must not be changed outside the authorized procedure.',
            'Пристапот се применува според улогите и секторите. Документите се чуваат надвор од јавната папка, се проверуваат по вид и големина и стануваат достапни за преземање само по дозволен статус на скенирањето.'=>'Access is enforced by role and directorate. Documents are stored outside the public folder, checked for type and size, and become downloadable only after an allowed scan status.',
            'Најавите, промените, одобрувањата, преземањата и техничките проблеми се евидентираат со correlation ID. Администраторите можат да ги следат логовите во реално време, но не смеат да ги користат за цели надвор од администрирањето и безбедноста.'=>'Sign-ins, changes, approvals, downloads and technical issues are recorded with a correlation ID. Administrators may monitor logs in real time but must not use them outside administration and security.',
            'Дозволено користење и одговорности'=>'Permitted use and responsibilities','Забранети се обидите за пристап надвор од улогата, мешањето во сметките на други, заобиколувањето на контролите, бришењето траги, автоматизацијата без овластување и споделувањето внатрешни информации со неовластени лица.'=>'Attempts to exceed role access, interfere with other accounts, bypass controls, erase traces, automate without authorization or disclose internal information to unauthorized persons are prohibited.',
            'Општината може да ограничи или да деактивира сметка заради безбедност, истрага или промена на службената одговорност. Техничкото одржување може да предизвика привремени прекини; евиденцијата зачувана во системот останува официјален извор.'=>'The Municipality may restrict or disable an account for security, investigation or changed official responsibility. Maintenance may cause temporary interruption; the system record remains the official source.',
            'Известувања и промени на условите'=>'Notifications and changes to terms','Платформата може да испраќа институционални известувања преку Microsoft Graph. Техничкиот неуспех на една е-порака не го поништува дејството зачувано во системот.'=>'The platform may send institutional notifications through Microsoft Graph. Technical email failure does not invalidate an action stored in the system.',
            'Условите може да се ажурираат поради законски, процедурални, безбедносни или функционални промени. Верзијата и датумот на влегување во сила се објавуваат на оваа страница.'=>'The terms may be updated due to legal, procedural, security or functional changes. The version and effective date are published on this page.',
            'Почетна'=>'Home',
            'Проекти и работа'=>'Projects and work',
            'Финансии'=>'Finance',
            'Предмети'=>'Cases',
            'Администрација'=>'Administration',
            'Помош'=>'Help',
            'Мени'=>'Мени',
            'Затвори го менито'=>'Close menu',
            'Повеќе'=>'More',
            'Брза навигација'=>'Quick navigation',
            'Пребарај проекти, предмети, документи…'=>'Search projects, cases, documents…',
            'Известувања'=>'Notifications',
            'Мои известувања'=>'My notifications',
            'Означи како прочитано'=>'Mark as read',
            'Означи ги сите како прочитани'=>'Mark all as read',
            'Немате известувања.'=>'You have no notifications.',
            'Поставки'=>'Preferences',
            'Безбедност на сметката'=>'Account security',
            'Miratimet (Workflow)'=>'Approvals (workflow)',
            'Управни предмети'=>'Administrative cases',
            'Повеќегодишен извештај'=>'Multi-year report',
            'Правни'=>'Legal',
            'Услуги'=>'Services',
            '‹ Претходна страница'=>'‹ Previous page',
            'Следна страница ›'=>'Next page ›',
            'Страничење'=>'Pagination',
            'Активни пристапи'=>'Active access',
            'Додај пристап'=>'Add access',
            'Отстрани го пристапот'=>'Remove access',
            '— Не додавај улога —'=>'— Do not add a role —',
            'Важност'=>'Validity',
            'Глобални'=>'Global',
            'Повторно поднеси во работниот тек'=>'Resubmit to workflow',
            'Барања вратени за дополнување'=>'Requests returned for completion',
            'Слободни (распределено − преземено)'=>'Free (allocated − committed)',
            'Распределено'=>'Allocated',
            'Локација'=>'Location',
            'Адреса'=>'Address',
            'Се игнорира за глобалните улоги (Градоначалник, Финансии, Администратор…).'=>'Ignored for global roles (Mayor, Finance, Administrator…).',
            'Секој ред е улога доделена за еден сектор или глобално. Другите улоги се чуваат; отстранете ги само оние што ги означувате.'=>'Each row is a role granted for a directorate or globally. Other roles are kept; only the ones you tick are removed.',
            'Корисникот нема ниеден активен пристап.'=>'The user has no active access.',
            'Зачувај ги промените'=>'Save changes',
            'Откажи'=>'Cancel',
            'Премногу неуспешни обиди за оваа сметка. Обидете се по 15 минути.'=>'Too many failed attempts for this account. Try again in 15 minutes.',
            'Премногу обиди од оваа адреса. Обидете се по 15 минути.'=>'Too many attempts from this address. Try again in 15 minutes.',
            'Премногу обиди за регистрација. Обидете се по еден час.'=>'Too many registration attempts. Try again in one hour.',
            'Веќе постои запис со истите единствени податоци (шифра, број или референца).'=>'A record with the same unique data (code, number or reference) already exists.',
            'Записот има врски со други податоци и не може да се промени на овој начин.'=>'The record is linked to other data and cannot be changed this way.',
            'Барањето мора да се одобри во сите чекори од работниот тек пред објавувањето.'=>'The request must be approved in all workflow steps before posting.',
            'Последниот чекор е одобрен и буџетската промена е објавена.'=>'The final step was approved and the budget change was posted.',
            'Барањето е повторно поднесено во работниот тек.'=>'The request was resubmitted to the workflow.',
            'Корисникот има историја во ревизијата и не може да се избрише. Деактивирајте го наместо да го бришете.'=>'The user has audit history and cannot be deleted. Deactivate the user instead.',
            'Досието е доверливо и достапно само за Правната служба.'=>'The file is confidential and accessible only to the Legal Office.',
            'Досието е ограничено на одговорното лице, заменикот и Раководителот на Правната служба.'=>'The file is restricted to the responsible officer, the deputy and the Legal Office director.',
            'Доверлив предмет'=>'Confidential case',
            'Веќе постои акт со овој број.'=>'An act with this number already exists.',
            'Веќе постои предмет со овој број.'=>'A case with this number already exists.',
            'Референцата на обврската веќе постои.'=>'The commitment reference already exists.',
            'Изворната референца е веќе објавена.'=>'The source reference has already been posted.',
            'Обврската не е активна.'=>'The commitment is not active.',
            'Сите известувања се означени како прочитани.'=>'All notifications were marked as read.',
            'Известувањето е означено како прочитано.'=>'The notification was marked as read.',
            'Овој работен тек е затворен или чека повторно поднесување.'=>'This workflow is closed or awaiting resubmission.',
            'Веќе постои распределба за оваа ставка, проект, година и период.'=>'An allocation already exists for this line, project, year and period.',
            'Судски документ'=>'Court document',
            'Решение'=>'Ruling',
            'Правно мислење'=>'Legal opinion',
            'Решение за извршување'=>'Enforcement decision',
            'Внатрешен'=>'Internal',
            'Доверливо'=>'Confidential',
            'Ограничен'=>'Restricted',
        ];
        uksort($map, static fn(string $a,string $b):int => mb_strlen($b) <=> mb_strlen($a));
        return $map;
    }
}

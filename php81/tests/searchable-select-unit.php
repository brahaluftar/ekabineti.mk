<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$failures=[];
$assert=static function(bool $condition,string $message)use(&$failures):void{if(!$condition)$failures[]=$message;};
$js=(string)file_get_contents(APP_ROOT.'/public/assets/app.js');
$css=(string)file_get_contents(APP_ROOT.'/public/assets/app.css');

$assert(str_contains($js,'const MIN_OPTIONS = 8;')&&str_contains($js,"document.querySelectorAll('select').forEach(enhance);"),'every select with more than 7 options becomes searchable');
$assert(str_contains($js,"select.multiple || select.dataset.plain !== undefined || select.matches('[data-page-size], [data-language-select]')"),'multiple selects, data-plain, page-size and language selects stay native');
$assert(str_contains($js,"normalize('NFD')")&&str_contains($js,'terms.every((term) => text.includes(term))'),'search is accent-insensitive and matches every term (code and name)');
$assert(str_contains($js,"['asc', 'Подреди А–Ш', 'A–Z'], ['desc', 'Подреди Ш–А', 'Z–A']")&&str_contains($js,"localeCompare(b.textContent.trim(), 'mk', { numeric: true"),'options can be ordered A–Z / Z–A (numeric-aware) or kept in the original order');
$assert(str_contains($js,"select.value = option.value;")&&str_contains($js,"select.dispatchEvent(new Event('change', { bubbles: true }))"),'the native select keeps the submitted value and dependent scripts get change events');
$assert(str_contains($js,"select.addEventListener('invalid'")&&str_contains($js,"!o.hidden && !o.disabled"),'required validation and options hidden by other scripts are respected');
$assert(str_contains($js,"role', 'combobox'")&&str_contains($js,"aria-activedescendant")&&str_contains($js,"event.key === 'ArrowDown'"),'the combobox is keyboard and screen-reader accessible');
$assert(str_contains($js,"button.className = 'combo__sort picker-sort';"),'search pickers get the same ordering control');
$assert(str_contains($css,'select.combo__native {')&&str_contains($css,'.combo__list {'),'styles for the combobox exist');

if($failures){fwrite(STDERR,"FAIL\n - ".implode("\n - ",$failures)."\n");exit(1);}echo "searchable-select-unit: OK\n";

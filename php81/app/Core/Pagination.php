<?php

declare(strict_types=1);

namespace EKabineti\Core;

/**
 * Server-side pagination of one table on a page. Each table has a key: its parameters are <key>_page and <key>_per
 * (page and per for the empty key). The chosen page size (10, 20, 50, 100 or 200; default 20) is remembered in the
 * session for that table of that page.
 */
final class Pagination
{
    public const SIZES = [10, 20, 50, 100, 200];
    public const DEFAULT_SIZE = 20;

    public int $total = 0;
    private int $page;

    public function __construct(public readonly string $key, int $page, public readonly int $size)
    {
        $this->page = max(1, $page);
    }

    public static function fromRequest(string $key = ''): self
    {
        $store = (strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '/').'#'.$key;
        $size = filter_var($_GET[self::param($key, 'per')] ?? null, FILTER_VALIDATE_INT);
        if (is_int($size) && in_array($size, self::SIZES, true)) {
            if (isset($_SESSION)) $_SESSION['_page_sizes'][$store] = $size;
        } else {
            $size = (int) ($_SESSION['_page_sizes'][$store] ?? self::DEFAULT_SIZE);
            if (!in_array($size, self::SIZES, true)) $size = self::DEFAULT_SIZE;
        }
        $page = filter_var($_GET[self::param($key, 'page')] ?? 1, FILTER_VALIDATE_INT, ['options'=>['default'=>1, 'min_range'=>1]]);
        return new self($key, (int) $page, $size);
    }

    public static function param(string $key, string $name): string
    {
        return $key === '' ? $name : $key.'_'.$name;
    }

    public function page(): int { return $this->page; }
    public function pages(): int { return max(1, (int) ceil($this->total / $this->size)); }
    public function offset(): int { return ($this->page - 1) * $this->size; }

    /**
     * Runs one page of a SELECT. A TOP n cap at the start is removed (all rows are reachable page by page); the total comes
     * from COUNT(*) OVER() in the same query, or from a COUNT over a derived table for SELECT DISTINCT.
     */
    public function query(Database $db, string $sql, array $params = []): array
    {
        $sql = (string) preg_replace('/^\s*SELECT(\s+DISTINCT)?\s+TOP\s*\(?\s*\d+\s*\)?\s+/i', 'SELECT$1 ', $sql, 1);
        $order = self::orderByPosition($sql);
        if ($order === null) { $order = strlen($sql); $sql .= ' ORDER BY (SELECT NULL)'; }
        $distinct = preg_match('/^\s*SELECT\s+DISTINCT\s/i', $sql) === 1;
        $paged = $distinct ? $sql : (string) preg_replace('/^\s*SELECT\s+/i', 'SELECT COUNT(*) OVER() AS pagination_total,', $sql, 1);
        $count = static fn(): int => (int) $db->scalar('SELECT COUNT(*) FROM ('.substr($sql, 0, $order).') pagination_count', $params);
        $fetch = fn(): array => $db->all($paged.' OFFSET '.$this->offset().' ROWS FETCH NEXT '.$this->size.' ROWS ONLY', $params);
        $rows = $fetch();
        if ($distinct) $this->total = $count();
        elseif ($rows !== []) $this->total = (int) ($rows[0]['pagination_total'] ?? $this->offset() + count($rows));
        else $this->total = $this->page > 1 ? $count() : 0;
        if ($rows === [] && $this->page > $this->pages()) {
            // A page past the end (e.g. after filtering): show the last page.
            $this->page = $this->pages();
            $rows = $this->total > 0 ? $fetch() : [];
        }
        if (!$distinct) foreach ($rows as &$row) unset($row['pagination_total']);
        return $rows;
    }

    /** The current page of rows already loaded (lists merged from several sources or filtered in PHP). */
    public function slice(array $rows): array
    {
        $rows = array_values($rows);
        $this->total = count($rows);
        $this->page = min($this->page, $this->pages());
        return array_slice($rows, $this->offset(), $this->size);
    }

    /** Navigation: rows per page, position and page links. Empty for tables of up to 10 rows. */
    public function nav(): string
    {
        if ($this->total <= self::SIZES[0]) return '';
        $pageParam = self::param($this->key, 'page'); $sizeParam = self::param($this->key, 'per');
        $query = array_filter($_GET, static fn($v, $k): bool => is_scalar($v) && $v !== '' && $k !== $pageParam && $k !== $sizeParam, ARRAY_FILTER_USE_BOTH);
        $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = fn(int $page): string => '?'.$e(http_build_query($query + [$pageParam=>$page, $sizeParam=>$this->size]));
        $hidden = '';
        foreach ($query as $k => $v) $hidden .= '<input type="hidden" name="'.$e($k).'" value="'.$e($v).'">';
        $options = '';
        foreach (self::SIZES as $size) $options .= '<option value="'.$size.'"'.($size === $this->size ? ' selected' : '').'>'.$size.'</option>';
        $pages = $this->pages(); $page = $this->page;
        $links = '';
        $link = static fn(string $href, string $label, string $title, bool $current = false): string => $current
            ? '<span class="pager__page is-current" aria-current="page">'.$label.'</span>'
            : '<a class="pager__page" href="'.$href.'" title="'.$title.'">'.$label.'</a>';
        if ($page > 1) $links .= $link($url(1), '«', 'Прва страница').$link($url($page - 1), '‹', 'Претходна страница');
        $from = max(1, $page - 2); $to = min($pages, $page + 2);
        if ($from > 1) $links .= '<span class="pager__gap">…</span>';
        for ($i = $from; $i <= $to; $i++) $links .= $link($url($i), (string) $i, 'Страница '.$i, $i === $page);
        if ($to < $pages) $links .= '<span class="pager__gap">…</span>';
        if ($page < $pages) $links .= $link($url($page + 1), '›', 'Следна страница').$link($url($pages), '»', 'Последна страница');
        $first = $this->offset() + 1; $last = min($this->total, $this->offset() + $this->size);
        return '<nav class="pager" aria-label="Страничење"><form method="get" class="pager__size">'.$hidden.'<label>Редови по страница <select name="'.$e($sizeParam).'" data-page-size>'.$options.'</select></label><noscript><button class="button button-secondary">Прикажи</button></noscript></form>'
            .'<span class="pager__info">'.$first.'–'.$last.' од '.$this->total.'</span><span class="pager__links">'.$links.'</span></nav>';
    }

    /** Position of the last ORDER BY outside parentheses and string literals, or null. */
    public static function orderByPosition(string $sql): ?int
    {
        $depth = 0; $found = null; $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $c = $sql[$i];
            if ($c === "'") { $i = strpos($sql, "'", $i + 1); if ($i === false) break; continue; }
            if ($c === '(') $depth++;
            elseif ($c === ')') $depth--;
            elseif ($depth === 0 && ($c === 'O' || $c === 'o') && preg_match('/\GORDER\s+BY\b/i', $sql, $m, 0, $i) && ($i === 0 || !ctype_alnum($sql[$i - 1]) && $sql[$i - 1] !== '_')) $found = $i;
        }
        return $found;
    }
}

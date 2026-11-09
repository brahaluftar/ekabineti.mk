<?php

declare(strict_types=1);

namespace EKabineti\Controller;

use EKabineti\Core\Controller;
use EKabineti\Core\HttpException;
use EKabineti\Core\View;
use EKabineti\Service\RoleAdmin;

/** Administration → Rolet dhe lejet: roles built on a system role, with their permissions chosen one by one. */
final class RoleController extends Controller
{
    private function view(): View { return new View($this->auth, $this->scope); }
    private function admin(): RoleAdmin { return new RoleAdmin($this->db); }
    private function requireAdmin(): void { if (!$this->scope->isSystemAdmin($this->userId())) throw new HttpException(403, 'Само Администраторот на системот ги администрира улогите и дозволите.'); }

    /** GET /roles */
    public function index(): never
    {
        $this->requireAdmin();
        $admin = $this->admin();
        $rows = '';
        foreach ($admin->roles() as $r) {
            $type = (int) $r['is_system'] ? '<span class="status-badge status-draft">Системска улога</span>' : '<span class="status-badge status-active">Создадена</span>';
            $state = (int) $r['active'] ? '' : ' <span class="status-badge status-cancelled">Неактивно</span>';
            $rows .= '<tr><td><strong>'.View::e($r['name']).'</strong>'.$state.'<br><small class="muted">'.View::e($r['code']).'</small>'.($r['description'] ? '<br><small>'.View::e($r['description']).'</small>' : '').'</td><td>'.$type.'</td><td>'.($r['base_role_code'] ? View::e(($r['base_role_name'] ?? $r['base_role_code']).' · '.$r['base_role_code']) : '—').'</td><td class="num right">'.($r['code'] === 'system_admin' ? 'сите' : (int) $r['permissions']).'</td><td class="num right">'.(int) $r['users'].'</td><td><a class="button button-secondary" href="/roles/'.(int) $r['id'].'/edit">Уреди ги дозволите</a></td></tr>';
        }
        $bases = '';
        foreach ($admin->baseRoles() as $b) $bases .= '<option value="'.View::e($b['code']).'">'.View::e($b['name'].' · '.$b['code']).'</option>';
        $body = '<section class="card"><h2>Нова улога</h2><p class="muted">Секоја нова улога се гради врз <strong>основна улога</strong> на системот: се однесува како неа во модулите што ја проверуваат улогата (на пр. раководител за својот сектор, известувач, правен службеник), додека <strong>lejet</strong> (прегледај, создај, одобри…) се избираат една по една. По создавањето, улогата им се доделува на корисниците од Администрација → Корисници.</p>'
            .'<form method="post" action="/roles" class="form-grid">'.$this->view()->csrf()
            .'<label>Назив на улогата<input name="name" maxlength="100" required placeholder="на пр. Службеник за матична евиденција"></label>'
            .'<label>Единствена шифра<input name="code" maxlength="50" pattern="[a-z][a-z0-9_]{2,49}" required placeholder="на пр. civil_status_officer"></label>'
            .'<label>Основна улога<select name="base_role_code" required><option value="">Изберете основна улога</option>'.$bases.'</select></label>'
            .'<label class="wide">Опис<input name="description" maxlength="500"></label>'
            .'<label class="checkbox"><input type="checkbox" name="copy_permissions" value="1" checked> Почни со дозволите на основната улога</label>'
            .'<div><button class="primary">Создај улога</button></div></form></section>'
            .'<section class="card"><h2>Улоги</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Улога</th><th>Вид</th><th>Основна улога</th><th class="num right">Дозволи</th><th class="num right">Активни корисници</th><th>Дејство</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
        $this->view()->render('Улоги и дозволи', $body);
    }

    /** POST /roles */
    public function create(): never
    {
        $this->requireAdmin();
        try { $id = $this->admin()->create((string) $this->request->input('code', ''), (string) $this->request->input('name', ''), (string) $this->request->input('description', ''), (string) $this->request->input('base_role_code', ''), (string) $this->request->input('copy_permissions', '') === '1'); }
        catch (\InvalidArgumentException $exception) { throw new HttpException(422, $exception->getMessage()); }
        $role = $this->admin()->role($id) ?? [];
        $this->audit->record($this->userId(), 'role.created', 'Role', $id, [], ['code'=>$role['code'] ?? null, 'name'=>$role['name'] ?? null, 'base_role_code'=>$role['base_role_code'] ?? null, 'permissions'=>$this->admin()->permissionIds($id)]);
        $this->redirect('/roles/'.$id.'/edit', 'Улогата е создадена. Проверете ги и изберете ги дозволите.');
    }

    /** GET /roles/{id}/edit */
    public function edit(int $id): never
    {
        $this->requireAdmin();
        $admin = $this->admin();
        $role = $admin->role($id);
        if (!$role) throw new HttpException(404, 'Улогата не е пронајдена.');
        $system = (int) $role['is_system'] === 1;
        $isAdminRole = $role['code'] === 'system_admin';
        $chosen = array_flip($admin->permissionIds($id));
        $bases = '';
        foreach ($admin->baseRoles() as $b) $bases .= '<option value="'.View::e($b['code']).'"'.($b['code'] === $role['base_role_code'] ? ' selected' : '').'>'.View::e($b['name'].' · '.$b['code']).'</option>';
        $groups = '';
        foreach ($admin->permissionGroups() as $group => $permissions) {
            $boxes = '';
            foreach ($permissions as $p) $boxes .= '<label class="checkbox"><input type="checkbox" name="permission_ids[]" value="'.$p['id'].'"'.($isAdminRole || isset($chosen[$p['id']]) ? ' checked' : '').($isAdminRole ? ' disabled' : '').'> '.View::e($p['label']).' <small class="muted">'.View::e($p['key']).'</small></label>';
            $groups .= '<fieldset class="citizen-service-row"><legend>'.View::e($group).'</legend>'.$boxes.'</fieldset>';
        }
        $body = '<div class="page-actions"><a class="button button-secondary" href="/roles">← Улоги и дозволи</a></div>'
            .'<form method="post" action="/roles/'.$id.'" class="stack">'.$this->view()->csrf()
            .'<section class="card"><h2>'.View::e($role['name']).' <small class="muted">'.View::e($role['code']).'</small></h2>'
            .($system ? '<p class="muted">Системска улога: шифрата не се менува и улогата не се брише. Дозволите можат да се менуваат'.($isAdminRole ? ', освен за Администраторот на системот, кој секогаш ги има сите дозволи.' : '.').'</p>' : '<p class="muted">Улога создадена во апликацијата. Се однесува како основната улога во модулите што ја проверуваат улогата; дозволите подолу одредуваат што може да гледа и да прави.</p>')
            .'<div class="form-grid"><label>Назив<input name="name" maxlength="100" required value="'.View::e($role['name']).'"></label>'
            .($system ? '' : '<label>Основна улога<select name="base_role_code" required>'.$bases.'</select></label>')
            .'<label class="wide">Опис<input name="description" maxlength="500" value="'.View::e($role['description'] ?? '').'"></label></div></section>'
            .'<section class="card"><h2>Дозволи</h2>'.$groups.'</section>'
            .'<div class="form-actions"><button class="primary">Зачувај ги улогата и дозволите</button></div></form>';
        if (!$system) {
            $body .= '<section class="card"><h2>Статус</h2><div class="inline-actions"><form method="post" action="/roles/'.$id.'/status">'.$this->view()->csrf().'<input type="hidden" name="active" value="'.((int) $role['active'] ? '0' : '1').'"><button class="button-secondary">'.((int) $role['active'] ? 'Деактивирај ја улогата' : 'Активирај ја улогата').'</button></form>'
                .'<form method="post" action="/roles/'.$id.'/delete" class="inline-actions" data-confirm="Да се избрише ли улогата '.View::e($role['code']).'?">'.$this->view()->csrf().'<input name="reason" maxlength="500" placeholder="Причина за бришење" aria-label="Причина за бришење" required><button class="button button-danger">Избриши ја улогата</button></form></div>'
                .'<p class="muted">Неактивната улога не дава никаков пристап и не може да се доделува. Се брише само улога што никогаш не била доделена.</p></section>';
        }
        $this->view()->render('Улога: '.$role['name'], $body);
    }

    /** POST /roles/{id} */
    public function update(int $id): never
    {
        $this->requireAdmin();
        $admin = $this->admin();
        $before = $admin->role($id);
        if (!$before) throw new HttpException(404, 'Улогата не е пронајдена.');
        $ids = is_array($_POST['permission_ids'] ?? null) ? array_map('intval', $_POST['permission_ids']) : [];
        try { [$was, $now] = $admin->update($id, (string) $this->request->input('name', ''), (string) $this->request->input('description', ''), (int) $before['is_system'] ? null : (string) $this->request->input('base_role_code', ''), $ids); }
        catch (\InvalidArgumentException $exception) { throw new HttpException(422, $exception->getMessage()); }
        $after = $admin->role($id) ?? [];
        $this->audit->record($this->userId(), 'role.updated', 'Role', $id, ['name'=>$before['name'], 'description'=>$before['description'], 'base_role_code'=>$before['base_role_code'], 'permissions'=>$was], ['name'=>$after['name'] ?? null, 'description'=>$after['description'] ?? null, 'base_role_code'=>$after['base_role_code'] ?? null, 'permissions'=>$now]);
        $this->redirect('/roles/'.$id.'/edit', 'Улогата и дозволите се зачувани. Важат веднаш за корисниците со оваа улога.');
    }

    /** POST /roles/{id}/status (active 0/1) */
    public function status(int $id): never
    {
        $this->requireAdmin();
        $active = (string) $this->request->input('active', '') === '1';
        try { $this->admin()->setActive($id, $active); }
        catch (\InvalidArgumentException $exception) { throw new HttpException(422, $exception->getMessage()); }
        $this->audit->record($this->userId(), $active ? 'role.activated' : 'role.deactivated', 'Role', $id, [], ['active'=>$active]);
        $this->redirect('/roles/'.$id.'/edit', $active ? 'Улогата е активирана.' : 'Улогата е деактивирана: повеќе не дава пристап и не се доделува.');
    }

    /** POST /roles/{id}/delete (reason) */
    public function delete(int $id): never
    {
        $this->requireAdmin();
        $reason = $this->requireField('reason', 500);
        try { $role = $this->admin()->delete($id); }
        catch (\InvalidArgumentException $exception) { throw new HttpException(409, $exception->getMessage()); }
        $this->audit->record($this->userId(), 'role.deleted', 'Role', $id, ['code'=>$role['code'], 'name'=>$role['name'], 'base_role_code'=>$role['base_role_code']], [], $reason);
        $this->redirect('/roles', 'Улогата е избришана.');
    }
}

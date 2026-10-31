<?php
declare(strict_types=1);

namespace Biolab\Core\Auth;

use BIO\MNG\Menu;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Uygulama ve ekran yetkisi, kullanıcıya göre menü.
 *   kullanıcı ─ mng_user_role (şirket bazında) ─▶ rol (APP_CODE) ─ mng_role_form ─▶ ekran (mng_form.FORM_PATH)
 *   menü: mng_menu ağacı; kökün MENU_CODE'u uygulama klasörü (ör. biolab-maliyet)
 *
 * İki katman:
 *   1. Uygulama (bütün uygulamalar): rollerinden birinin APP_CODE'u o uygulama olmalı (canEnterApp).
 *   2. Ekran (yalnızca ENFORCED_APPS): rolüne o ekran verilmiş olmalı (canAccess). Ekranları mng_form'a
 *      kaydedilmemiş bir uygulama bu listeye eklenmez; eklenirse bütün istekleri reddedilir.
 */
final class FormAccess
{
    public const ENFORCED_APPS = ['biolab-maliyet', 'hb-elektroforez', 'biolab-kvkk'];

    public function __construct(private EntityManagerInterface $em) {}

    /**
     * Proxy'nin çağırdığı ekran PHP'sinden ekranın html yolunu çıkarır.
     * "../../../apps/biolab-maliyet/material/php/material-1.0.0" → app: biolab-maliyet,
     *  path: apps/biolab-maliyet/material/material-1.0.0.html
     *
     * @return array{app:string, path:string}|null
     */
    public static function targetFromDataSource(string $dataSource): ?array
    {
        if (!preg_match('#(?:^|/)apps/([\w.-]+)/((?:[\w.-]+/)*?)php/([\w.-]+)$#', str_replace('\\', '/', $dataSource), $m)) {
            return null;
        }
        return ['app' => $m[1], 'path' => 'apps/' . $m[1] . '/' . $m[2] . $m[3] . '.html'];
    }

    public static function isEnforced(string $app): bool
    {
        return in_array($app, self::ENFORCED_APPS, true);
    }

    /**
     * Kullanıcının bu şirkette bu uygulamaya girebilen etkin bir rolü var mı:
     * rolün APP_CODE'u bu uygulama, ya da role bu uygulamanın en az bir ekranı verilmiş
     * (birden çok uygulamayı kapsayan roller, ör. MANAGEMENT, ekranları üzerinden girer).
     */
    public function canEnterApp(int $userId, int $companyId, string $app): bool
    {
        $count = $this->em->createQuery("
            SELECT COUNT(ur.userRoleId)
              FROM BIO\MNG\UserRole ur
              JOIN ur.role r
             WHERE IDENTITY(ur.user) = :USER_ID AND IDENTITY(ur.company) = :COMPANY_ID
               AND ur.activeFl = 'Y' AND ur.recordFl > 0
               AND r.activeFl = 'Y'  AND r.recordFl > 0
               AND (r.appCode = :APP_CODE OR EXISTS (
                    SELECT 1 FROM BIO\MNG\RoleForm rf JOIN rf.form f
                     WHERE rf.role = r AND f.formPath LIKE :APP_PATH
                       AND rf.activeFl = 'Y' AND rf.recordFl > 0
                       AND f.activeFl = 'Y'  AND f.recordFl > 0))
        ")->setParameters([
            'USER_ID'    => $userId,
            'COMPANY_ID' => $companyId,
            'APP_CODE'   => $app,
            'APP_PATH'   => 'apps/' . str_replace(['[', '_', '%'], ['[[]', '[_]', '[%]'], $app) . '/%',   // SQL Server LIKE kaçışı
        ])->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function canAccess(int $userId, int $companyId, string $formPath): bool
    {
        $count = $this->em->createQuery("
            SELECT COUNT(rf.roleFormId)
              FROM BIO\MNG\RoleForm rf
              JOIN rf.form f
              JOIN rf.role r, BIO\MNG\UserRole ur
             WHERE ur.role = r
               AND IDENTITY(ur.user) = :USER_ID AND IDENTITY(ur.company) = :COMPANY_ID
               AND f.formPath = :FORM_PATH
               AND ur.activeFl = 'Y' AND ur.recordFl > 0
               AND rf.activeFl = 'Y' AND rf.recordFl > 0
               AND r.activeFl = 'Y'  AND r.recordFl > 0
               AND f.activeFl = 'Y'  AND f.recordFl > 0
        ")->setParameters(['USER_ID' => $userId, 'COMPANY_ID' => $companyId, 'FORM_PATH' => $formPath])
          ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /** Kullanıcının bu şirketteki rol kodları (ör. ['MLYT_EXPERT']) */
    public function roleCodes(int $userId, int $companyId): array
    {
        return $this->em->createQuery("
            SELECT DISTINCT r.roleCode
              FROM BIO\MNG\UserRole ur
              JOIN ur.role r
             WHERE IDENTITY(ur.user) = :USER_ID AND IDENTITY(ur.company) = :COMPANY_ID
               AND ur.activeFl = 'Y' AND ur.recordFl > 0
               AND r.activeFl = 'Y'  AND r.recordFl > 0
        ")->setParameters(['USER_ID' => $userId, 'COMPANY_ID' => $companyId])
          ->getSingleColumnResult();
    }

    /**
     * Uygulamanın menüsü, kullanıcının erişebildiği ekranlarla süzülmüş olarak.
     * Ekranın url'si uygulama klasörüne göredir (index.html'in açtığı çerçeve için: ./material/material-1.0.0.html).
     *
     * @return list<array{menuId:int, name:string, icon:?string, formCode:?string, url:?string, children:list<array>}>
     */
    public function menuTree(string $app, int $userId, int $companyId, string $lang = 'tr'): array
    {
        $menus = $this->em->createQuery("
            SELECT m, f FROM BIO\MNG\Menu m
              LEFT JOIN m.form f
             WHERE m.activeFl = 'Y' AND m.recordFl > 0
             ORDER BY m.menuOrder, m.menuId
        ")->getResult();

        $root = null;
        $byParent = [];
        foreach ($menus as $m) {
            if ($m->getMenuCode() === $app && $m->getParent() === null) {
                $root = $m;
            }
            $byParent[$m->getParent()?->getMenuId() ?? 0][] = $m;
        }
        if ($root === null) {
            return [];
        }

        $allowed = array_flip($this->allowedFormIds($userId, $companyId));
        $prefix  = 'apps/' . $app . '/';

        $build = function (Menu $parent) use (&$build, $byParent, $allowed, $prefix, $lang): array {
            $nodes = [];
            foreach ($byParent[$parent->getMenuId()] ?? [] as $m) {
                $form = $m->getForm();
                if ($form !== null && (!$form->isActive() || $form->isDeleted() || !isset($allowed[$form->getFormId()]))) {
                    continue;   // ekran ve yetki yok
                }
                $children = $build($m);
                if ($form === null && !$children) {
                    continue;   // altında görünür ekran kalmayan grup
                }
                $path = $form?->getFormPath();
                $nodes[] = [
                    'menuId'   => $m->getMenuId(),
                    'name'     => ($lang === 'en' && $m->getMenuNameEn()) ? $m->getMenuNameEn() : $m->getMenuName(),
                    'icon'     => $m->getMenuIcon(),
                    'formCode' => $form?->getFormCode(),
                    'url'      => $path !== null ? (str_starts_with($path, $prefix) ? './' . substr($path, strlen($prefix)) : '/' . $path) : null,
                    'children' => $children,
                ];
            }
            return $nodes;
        };

        return $build($root);
    }

    /** @return list<int> */
    private function allowedFormIds(int $userId, int $companyId): array
    {
        return array_map('intval', $this->em->createQuery("
            SELECT DISTINCT IDENTITY(rf.form)
              FROM BIO\MNG\RoleForm rf
              JOIN rf.role r, BIO\MNG\UserRole ur
             WHERE ur.role = r
               AND IDENTITY(ur.user) = :USER_ID AND IDENTITY(ur.company) = :COMPANY_ID
               AND ur.activeFl = 'Y' AND ur.recordFl > 0
               AND rf.activeFl = 'Y' AND rf.recordFl > 0
               AND r.activeFl = 'Y'  AND r.recordFl > 0
        ")->setParameters(['USER_ID' => $userId, 'COMPANY_ID' => $companyId])
          ->getSingleColumnResult());
    }
}

<?php

declare(strict_types=1);

namespace Biolab\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Biolab Yönetim — Tanımlamalar grubunun kullanıcı ve yetki ekranları:
 *   Kullanıcı Tanımlama (DEF_USER), Kullanıcı Rol Tanımlama (DEF_USER_ROLE),
 *   Rol Form Tanımlama (DEF_ROLE_FORM), Kullanıcı Şirket Tanımlama (DEF_USER_COMPANY) → DEF_ADMIN
 *
 * Form ve menü kayıtları geliştirme veritabanına önce Form/Menü Tanımlama ekranlarından girildi; bu yüzden
 * her kayıt yoksa eklenir (IF NOT EXISTS). Geliştirmede yalnızca DEF_ADMIN yetkileri eklenir, sunucuda hepsi oluşur.
 * Rol Form Tanımlama ekranı yetkisiz olduğu sürece menüde görünmediğinden ilk yetkiler buradan verilir.
 */
final class Version20261106170000 extends AbstractMigration
{
    // FORM_CODE => [form adı, form adı (en), yol, menü adı, menü adı (en), menü sırası]
    private const SCREENS = [
        'DEF_USER'         => ['Kullanıcı Tanımlama',        'User Definition',         'apps/biolab_definitions/mng_user-1.0.0.html',    'Kullanıcı Tanımlama',        'User Definition',      11],
        'DEF_USER_ROLE'    => ['Kullanıcı Rol Tanımlama',    'User Role Definition',    'apps/biolab_definitions/userRole-1.0.0.html',    'Kullanıcı Rol Tanımlama',    'User Role Definition', 12],
        'DEF_ROLE_FORM'    => ['Rol Form Tanımlama',         'Role Form Definition',    'apps/biolab_definitions/roleForm-1.0.0.html',    'Rol Form Tanımlama',         'Role Form',            13],
        'DEF_USER_COMPANY' => ['Kullanıcı Şirket Tanımlama', 'User Company Definition', 'apps/biolab_definitions/userCompany-1.0.0.html', 'Kullanıcı Şirket Tanımlama', 'User Company',         14],
    ];

    public function getDescription(): string
    {
        return 'DEF: Kullanıcı, Kullanıcı Rol, Rol Form, Kullanıcı Şirket tanımlama ekranları (Tanımlamalar grubu) → DEF_ADMIN';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SCREENS as $code => [$name, $nameEn, $path, $menuName, $menuNameEn, $order]) {
            // Ekran
            $this->addSql("IF NOT EXISTS (SELECT 1 FROM mng_form WHERE FORM_CODE = '$code')
                INSERT INTO mng_form (FORM_CODE, FORM_NAME, FORM_NAME_EN, FORM_PATH) VALUES ('$code', N'$name', '$nameEn', '$path')");

            // Menü satırı: Tanımlamalar grubunun altına
            $this->addSql("INSERT INTO mng_menu (MENU_CODE, PARENT_MENU_ID, FORM_ID, MENU_NAME, MENU_NAME_EN, MENU_ICON, MENU_ORDER)
                SELECT NULL, g.MENU_ID, f.FORM_ID, N'$menuName', '$menuNameEn', NULL, $order
                  FROM mng_menu g JOIN mng_form f ON f.FORM_CODE = '$code'
                 WHERE g.MENU_CODE = 'DEF_GRP_DEFINITIONS'
                   AND NOT EXISTS (SELECT 1 FROM mng_menu x WHERE x.FORM_ID = f.FORM_ID AND x.RECORD_FL > 0)");

            // Rol → ekran
            $this->addSql("INSERT INTO mng_role_form (ROLE_ID, FORM_ID)
                SELECT r.ROLE_ID, f.FORM_ID FROM mng_role r CROSS JOIN mng_form f
                 WHERE r.ROLE_CODE = 'DEF_ADMIN' AND f.FORM_CODE = '$code'
                   AND NOT EXISTS (SELECT 1 FROM mng_role_form x WHERE x.ROLE_ID = r.ROLE_ID AND x.FORM_ID = f.FORM_ID)");
        }
    }

    public function down(Schema $schema): void
    {
        // Yalnızca yetkiler geri alınır: form ve menü kayıtları geliştirmede ekrandan girildi, silinmeleri o veriyi de götürür
        $in = "'" . implode("', '", array_keys(self::SCREENS)) . "'";
        $this->addSql("DELETE rf FROM mng_role_form rf
              JOIN mng_role r ON r.ROLE_ID = rf.ROLE_ID
              JOIN mng_form f ON f.FORM_ID = rf.FORM_ID
             WHERE r.ROLE_CODE = 'DEF_ADMIN' AND f.FORM_CODE IN ($in)");
    }
}

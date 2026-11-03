<?php

declare(strict_types=1);

namespace Biolab\MigrationsQms;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * QMS menü sırası: kurulum ve kullanım önceliğine göre (önce tanımlar, sonra doküman işleri).
 *   Organizasyon → Doküman tipleri → Doküman yükleme → Dokümanlarım → Doküman ana listesi → Denetim kaydı
 * Uygulama açılınca kullanıcının erişebildiği ilk ekran açılır: yönetici için Organizasyon,
 * yalnızca QMS_USER rolü olan kullanıcı için Dokümanlarım.
 */
final class Version20261103090000 extends AbstractMigration
{
    private const NEW_ORDER = [
        'QMS_ORG'         => 10,
        'QMS_DOC_TYPE'    => 20,
        'QMS_DOCUMENT'    => 30,
        'QMS_MY_DOCS'     => 40,
        'QMS_MASTER_LIST' => 50,
        'QMS_AUDIT_LOG'   => 60,
    ];

    // Version20261102180000'deki sıra (geri alma için)
    private const OLD_ORDER = [
        'QMS_MY_DOCS'     => 10,
        'QMS_DOCUMENT'    => 20,
        'QMS_MASTER_LIST' => 30,
        'QMS_DOC_TYPE'    => 40,
        'QMS_ORG'         => 50,
        'QMS_AUDIT_LOG'   => 60,
    ];

    public function getDescription(): string
    {
        return 'QMS: menü sırası öncelik sırasına göre (Organizasyon en üstte)';
    }

    public function up(Schema $schema): void
    {
        $this->setOrder(self::NEW_ORDER);
    }

    public function down(Schema $schema): void
    {
        $this->setOrder(self::OLD_ORDER);
    }

    /** @param array<string,int> $order FORM_CODE → MENU_ORDER */
    private function setOrder(array $order): void
    {
        foreach ($order as $formCode => $menuOrder) {
            $this->addSql("UPDATE m SET m.MENU_ORDER = {$menuOrder}
                  FROM mng_menu m JOIN mng_form f ON f.FORM_ID = m.FORM_ID
                 WHERE f.FORM_CODE = '{$formCode}'");
        }
    }
}

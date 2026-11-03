<?php
declare(strict_types=1);

/**
 * Biolab QMS'in ilk yönetici kullanıcısını oluşturur (migration şifre içermesin diye ayrı):
 *   php qms-create-admin.php
 * Kullanıcı adı, ad soyad ve şifre sorulur; kullanıcı BIOLAB şirketine bağlanır ve
 * QMS_ADMIN, QMS_QUALITY, QMS_USER rolleri verilir. Diğer kullanıcılar uygulamadan tanımlanır.
 */

use QMS\MNG\AppUser;
use QMS\MNG\Company;
use QMS\MNG\Role;
use QMS\MNG\UserCompany;
use QMS\MNG\UserRole;

if (PHP_SAPI !== 'cli') {
    exit("Yalnızca komut satırından çalışır.\n");
}

$em = require __DIR__ . '/bootstrap-qms.php';

$ask = static function (string $label): string {
    echo $label . ': ';
    return trim((string) fgets(STDIN));
};

$username = $ask('Kullanıcı adı');
$fullName = $ask('Ad soyad');
$password = $ask('Şifre (en az 10 karakter)');
$again    = $ask('Şifre (tekrar)');

if ($username === '' || $fullName === '') {
    exit("Kullanıcı adı ve ad soyad girilmeli.\n");
}
if (mb_strlen($password) < 6) {
    exit("Şifre en az 6 karakter olmalı.\n");
}
if ($password !== $again) {
    exit("Şifreler aynı değil.\n");
}
if ($em->getRepository(AppUser::class)->findOneBy(['username' => $username]) !== null) {
    exit("Bu kullanıcı adı zaten var: {$username}\n");
}

$company = $em->getRepository(Company::class)->findOneBy(['code' => 'BIOLAB']);
if ($company === null) {
    exit("BIOLAB şirketi bulunamadı. Önce migration-qms.bat ile migration uygulanmalı.\n");
}

$em->wrapInTransaction(static function () use ($em, $username, $fullName, $password, $company): void {
    $user = new AppUser($username, $fullName, $password);
    $em->persist($user);
    $em->persist(new UserCompany($user, $company, true));

    foreach (['QMS_ADMIN', 'QMS_QUALITY', 'QMS_USER'] as $code) {
        $role = $em->getRepository(Role::class)->findOneBy(['roleCode' => $code]);
        if ($role === null) {
            throw new \RuntimeException("Rol bulunamadı: {$code}");
        }
        $em->persist(new UserRole($user, $role, $company));
    }
});

echo "Yönetici oluşturuldu: {$username} (QMS_ADMIN, QMS_QUALITY, QMS_USER)\n";

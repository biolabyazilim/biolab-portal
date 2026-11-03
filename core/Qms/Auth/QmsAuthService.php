<?php
declare(strict_types=1);

namespace Biolab\Core\Qms\Auth;

use Biolab\Core\Auth\LoginResult;
use Biolab\Core\Http\Session;
use QMS\MNG\AppUser;
use QMS\MNG\Company;
use QMS\MNG\UserCompany;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Biolab QMS girişi: Biolab\Core\Auth\AuthService'in QMS karşılığı. Kullanıcılar Biolab_QMS.mng_user'dadır
 * (portaldan bağımsız); oturum Session::QMS_NAME çerezinde tutulur. EntityManager: bootstrap-qms.php
 * Hata mesajları bilerek geneldir: kullanıcı adının var olup olmadığı dışarıya sızmaz.
 */
final class QmsAuthService
{
    public const MAX_ATTEMPTS = 3;
    public const LOCK_MINUTES = 15;

    public const MSG_INVALID = 'Kullanıcı adı veya şifre hatalı. (' . self::MAX_ATTEMPTS
        . ' hatalı denemeden sonra hesap ' . self::LOCK_MINUTES . ' dk kilitlenir)';

    public const MSG_NO_COMPANY = 'Kullanıcınıza tanımlı aktif bir şirket yok. Sistem yöneticisine başvurun.';

    public function __construct(private EntityManagerInterface $em) {}

    public const MSG_NO_APP = 'Bu uygulama için yetkiniz olmadığından giriş yapamazsınız. Sistem yöneticisine başvurun.';

    /**
     * @param string|null $app Girişin yapıldığı uygulama (mng_role.APP_CODE). Verilirse kullanıcının o uygulamaya
     *                         ait etkin bir rolü yoksa oturum açılmaz.
     */
    public function login(string $username, string $password, ?string $app = null): LoginResult
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return LoginResult::fail('Kullanıcı adı ve şifre girin.');
        }

        $now  = new \DateTimeImmutable();
        $user = $this->em->getRepository(AppUser::class)->findOneBy(['username' => $username]);

        if ($user === null || $user->isDeleted() || !$user->isActive()) {
            // Kullanıcı yokken de şifre kontrolü kadar süre harcansın (yanıt süresinden kullanıcı adı anlaşılmasın)
            password_hash($password, PASSWORD_DEFAULT);
            return LoginResult::fail(self::MSG_INVALID);
        }

        if ($user->isLocked($now)) {
            return LoginResult::fail(self::lockedMessage($user->getLockedUntil(), $now));
        }

        if (!$user->verifyPassword($password)) {
            $user->registerFailedLogin(self::MAX_ATTEMPTS, self::LOCK_MINUTES, $now);
            $this->em->flush();

            return $user->isLocked($now)
                ? LoginResult::fail(self::lockedMessage($user->getLockedUntil(), $now))
                : LoginResult::fail(self::MSG_INVALID);
        }

        // Şifre doğru; kullanıcının şirketi yoksa giriş yapılmaz (D.Sys'teki UserCompany kuralı)
        $company = $this->findLoginCompany($user);
        if ($company === null) {
            return LoginResult::fail(self::MSG_NO_COMPANY);
        }

        // Uygulamanın giriş ekranından geliyorsa o uygulamanın rolü de olmalı; yoksa oturum hiç açılmaz
        if ($app !== null && !(new QmsFormAccess($this->em))->canEnterApp($user->getUserId(), $company->getCompanyId(), $app)) {
            return LoginResult::fail(self::MSG_NO_APP);
        }

        Session::regenerate();
        $_SESSION['USER_ID']   = $user->getUserId();
        $_SESSION['USERNAME']  = $user->getUsername();
        $_SESSION['FULL_NAME'] = $user->getFullName();
        $_SESSION['LOGIN_AT']  = $now->format(DATE_ATOM);

        // D.Sys proxy'sinin (jsonp.php) ve ekranların beklediği oturum değişkenleri
        $_SESSION['USER_ID_PARENT']   = $user->getUserId();
        $_SESSION['COMPANY_ID']       = $company->getCompanyId();
        $_SESSION['COMPANY_CODE']     = $company->getCode();
        $_SESSION['COMPANY_NAME']     = $company->getCompanyName();
        $_SESSION['lang']             = $company->getLanguage()->getCode();
        $_SESSION['langCultureInfo']  = $company->getLanguage()->getCode();
        $_SESSION['LOGIN_START_TIME'] = $now->getTimestamp();
        $_SESSION['SESSION_CODE']     = strtoupper(bin2hex(random_bytes(16)));   // proxy yanıtındaki sTimeout bununla belirlenir
        //bizde tek alan (FULL_NAME). Proxy bu anahtarları her yanıtta okur.
        $_SESSION['PERSON_NAME']      = $user->getFullName();
        $_SESSION['PERSON_SURNAME']   = '';
        $_SESSION['IS_OWNER']         = false;
        $_SESSION['IP_ADDRESS']       = $_SERVER['REMOTE_ADDR'] ?? null;

        $user->markLogin();
        $this->em->flush();

        return LoginResult::ok(self::currentUser());
    }

    /**
     * Girişte kullanılacak şirket: varsayılan (DEFAULT_FL = 'Y') aktif bağlantı, yoksa ilk aktif bağlantı.
     */
    private function findLoginCompany(AppUser $user): ?Company
    {
        $rows = $this->em->createQueryBuilder()
            ->select('uc', 'c', 'l')
            ->from(UserCompany::class, 'uc')
            ->join('uc.company', 'c')
            ->join('c.language', 'l')
            ->where('uc.user = :user')
            ->andWhere("uc.activeFl = 'Y'")->andWhere('uc.recordFl > 0')
            ->andWhere("c.activeFl = 'Y'")->andWhere('c.recordFl > 0')
            ->orderBy('uc.defaultFl', 'DESC')
            ->addOrderBy('uc.userCompanyId', 'ASC')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $rows ? $rows[0]->getCompany() : null;
    }

    /** @return array{userId:int, username:string, fullName:string, companyId:int, companyName:string}|null */
    public static function currentUser(): ?array
    {
        if (empty($_SESSION['USER_ID'])) {
            return null;
        }
        return [
            'userId'      => (int) $_SESSION['USER_ID'],
            'username'    => (string) $_SESSION['USERNAME'],
            'fullName'    => (string) $_SESSION['FULL_NAME'],
            'companyId'   => (int) ($_SESSION['COMPANY_ID'] ?? 0),
            'companyName' => (string) ($_SESSION['COMPANY_NAME'] ?? ''),
        ];
    }

    private static function lockedMessage(?\DateTimeImmutable $until, \DateTimeImmutable $now): string
    {
        $minutes = $until ? max(1, (int) ceil(($until->getTimestamp() - $now->getTimestamp()) / 60)) : self::LOCK_MINUTES;
        return "Çok sayıda hatalı deneme nedeniyle hesabınız kilitlendi. {$minutes} dk sonra tekrar deneyin.";
    }
}

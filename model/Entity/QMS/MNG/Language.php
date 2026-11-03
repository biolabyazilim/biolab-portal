<?php
declare(strict_types=1);

namespace QMS\MNG;

use Biolab\Model\Common\Qms\QmsAuditTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mng_language')]
class Language
{
    use QmsAuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    private ?int $languageId = null;            // LANGUAGE_ID

    #[ORM\Column(length: 5, unique: true)]
    private string $code;                       // CODE  (tr, en)

    #[ORM\Column(length: 50, options: ['collation' => 'Turkish_CI_AS'])]
    private string $name;                       // NAME  (Türkçe, English)

    public function __construct(string $code, string $name)
    {
        $this->code = $code;
        $this->name = $name;
    }

    public function getLanguageId(): ?int { return $this->languageId; }
    public function getCode(): string     { return $this->code; }
    public function getName(): string     { return $this->name; }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }
}
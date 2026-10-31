<?php
declare(strict_types=1);

namespace BIO\MNG;

use Biolab\Model\Common\AuditTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mng_gender')]
class Gender
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'smallint')]
    private int $genderId;                        // GENDER_ID

    #[ORM\Column(length: 100, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $gender = null;               // GENDER

    #[ORM\Column(length: 1, nullable: true)]
    private ?string $genderAbr = null;            // GENDER_ABR (F / M)

    public function __construct(int $genderId, ?string $gender, ?string $genderAbr)
    {
        $this->genderId  = $genderId;
        $this->gender    = $gender;
        $this->genderAbr = $genderAbr;
    }

    public function getGenderId(): int        { return $this->genderId; }
    public function getGender(): ?string      { return $this->gender; }
    public function getGenderAbr(): ?string   { return $this->genderAbr; }
    public function setGender(?string $g): self    { $this->gender = $g; return $this; }
    public function setGenderAbr(?string $a): self { $this->genderAbr = $a; return $this; }
}
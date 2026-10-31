<?php
declare(strict_types=1);

namespace BIO\BCR;

use Doctrine\ORM\Mapping as ORM;
use Biolab\Model\Common\AuditTrait;

/**
 * Rapor yorum blokları (A1…A4, B1…B6, C1…C9, V1…V7 ve C3/C4 ek notları).
 * Metinler uzmanın algoritma belgesinden (hplcalgoson01.06.docx) alınır; laboratuvar sonradan değiştirebilir.
 * Hangi bloğun seçileceği kodda (Biolab\Core\Hb\HbReportRules) belirlenir; burada yalnızca metin durur.
 * Onaylanan rapor yorumun kopyasını saklar; blok metni sonradan değişse de eski rapor değişmez.
 */
#[ORM\Entity]
#[ORM\Table(name: 'hb_comment_block')]
class HbCommentBlock
{
    use AuditTrait;   // C_BY, C_AT, U_BY, U_AT, D_BY, D_AT, ACTIVE_FL, RECORD_FL

    public const TYPE_AGE     = 'AGE';       // yaşa göre blok (A, B, C)
    public const TYPE_VARIANT = 'VARIANT';   // anormal fraksiyon bloğu (V)
    public const TYPE_NOTE    = 'NOTE';      // bir bloğa eklenen not (C3N, C4N)

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $hbCommentBlockId = null;         // HB_COMMENT_BLOCK_ID

    #[ORM\Column(length: 10, unique: true)]
    private string $code;                          // CODE (ör. C3, V1, C3N)

    #[ORM\Column(length: 1, options: ['fixed' => true])]
    private string $groupCode;                     // GROUP_CODE (A / B / C / V)

    #[ORM\Column(length: 10)]
    private string $blockType;                     // BLOCK_TYPE (AGE / VARIANT / NOTE)

    #[ORM\Column(length: 200, options: ['collation' => 'Turkish_CI_AS'])]
    private string $title;                         // TITLE (ör. "HbA2 sınırda yüksek")

    #[ORM\Column(length: 300, nullable: true, options: ['collation' => 'Turkish_CI_AS'])]
    private ?string $criteria = null;              // CRITERIA (belgede yazdığı gibi, bilgi amaçlı)

    // VARCHAR(MAX) + UTF-8 harmanlama: metinlerde β, δ, α, ≥, µ var (Turkish_CI_AS bunları saklayamaz)
    #[ORM\Column(type: 'text', options: ['collation' => 'Turkish_100_CI_AS_SC_UTF8'])]
    private string $body;                          // BODY (paragraflar satır sonuyla ayrılır)

    #[ORM\Column(type: 'smallint')]
    private int $sortNo;                           // SORT_NO

    public function __construct(string $code, string $groupCode, string $blockType, string $title, ?string $criteria, string $body, int $sortNo)
    {
        $this->code      = $code;
        $this->groupCode = $groupCode;
        $this->blockType = $blockType;
        $this->title     = $title;
        $this->criteria  = $criteria;
        $this->body      = $body;
        $this->sortNo    = $sortNo;
    }

    public function getHbCommentBlockId(): ?int { return $this->hbCommentBlockId; }
    public function getCode(): string           { return $this->code; }
    public function getGroupCode(): string      { return $this->groupCode; }
    public function getBlockType(): string      { return $this->blockType; }
    public function getTitle(): string          { return $this->title; }
    public function getCriteria(): ?string      { return $this->criteria; }
    public function getBody(): string           { return $this->body; }
    public function setBody(string $v): self    { $this->body = $v; return $this; }
    public function getSortNo(): int            { return $this->sortNo; }
}

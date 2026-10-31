<?php
namespace MyProject\Hydrators;

use Doctrine\DBAL\FetchMode;
use Doctrine\ORM\Internal\Hydration\AbstractHydrator;

class CustomHydrator extends AbstractHydrator
{

    protected function hydrateAllData()
    {
        return $data = $this->_stmt->fetchAll(FetchMode::ASSOCIATIVE);
//        print_r($data);
//        $this->gatherScalarRowData($data);
//        print_r($data);
    }


}
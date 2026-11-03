<?php

// lib/php/proxy/db.inc.php
declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;

final class DBManager
{
    private static ?EntityManagerInterface $em = null;
    public bool $connected = false;

    public function __construct()
    {
        if (self::$em === null) {
            // proxy → php → lib → biolab-portal kökü; QMS ekranları Biolab_QMS veritabanına bağlanır (jsonp.php → QMS_REQUEST)
            self::$em = require __DIR__ . '/../../../' . (defined('QMS_REQUEST') && QMS_REQUEST ? 'bootstrap-qms.php' : 'bootstrap.php');
        }
        $this->connected = true;
    }

    public function getDB(): EntityManagerInterface
    {
        return self::$em;
    }
}

global $dbm, $db;
$dbm = new DBManager();
$db  = $dbm->getDB();
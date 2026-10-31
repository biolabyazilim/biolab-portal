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
            // proxy → php → lib → public → biolab-portal kökü
            self::$em = require __DIR__ . '/../../../bootstrap.php';
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
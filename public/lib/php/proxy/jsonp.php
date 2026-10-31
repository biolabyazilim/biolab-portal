<?php
/**
 * Tarayıcıya açık tek proxy giriş kapısı.
 * Asıl kod (jsonp.php, loginUser.php, db.inc.php) ve ayarlar public dışında, lib/php/proxy altında durur.
 * Ekran PHP'lerinin yolları (app=../../../apps/...) bu klasöre göre çözülür.
 */
require __DIR__ . '/../../../../lib/php/proxy/jsonp.php';

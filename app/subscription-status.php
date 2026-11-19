<?php
declare(strict_types=1);

function sync_expired_restaurant_subscriptions(PDO $db):void
{
    $latest='SELECT restaurant_id,MAX(id) id FROM subscriptions GROUP BY restaurant_id';
    $db->exec("UPDATE subscriptions s JOIN ($latest) latest ON latest.id=s.id SET s.status='EXPIRED' WHERE s.status IN ('ACTIVE','TRIAL') AND UTC_DATE()>DATE_ADD(s.expires_on,INTERVAL 3 DAY)");
    $db->exec("UPDATE restaurants r JOIN ($latest) latest ON latest.restaurant_id=r.id JOIN subscriptions s ON s.id=latest.id SET r.is_active=0 WHERE s.status='EXPIRED' AND UTC_DATE()>DATE_ADD(s.expires_on,INTERVAL 3 DAY)");
}

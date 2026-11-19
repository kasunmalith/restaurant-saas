<?php
if ($path!=='/login' || $_SERVER['REQUEST_METHOD']!=='POST') return;
// Count attempts by source address across sessions; never trust forwarded IP headers.
$bucket=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'unknown'));
$db->beginTransaction();
try {
    $s=$db->prepare('INSERT IGNORE INTO login_attempts(bucket,attempts,window_started) VALUES(?,0,UTC_TIMESTAMP())');$s->execute([$bucket]);
    $s=$db->prepare('SELECT attempts,window_started < UTC_TIMESTAMP()-INTERVAL 15 MINUTE AS expired FROM login_attempts WHERE bucket=? FOR UPDATE');$s->execute([$bucket]);$limit=$s->fetch();
    if ($limit['expired']) {$s=$db->prepare('UPDATE login_attempts SET attempts=0,window_started=UTC_TIMESTAMP() WHERE bucket=?');$s->execute([$bucket]);$limit['attempts']=0;}
    if ((int)$limit['attempts']>=20) {
        $db->commit();http_response_code(429);header('Retry-After: 900');
        layout('Try again later','<main class="shell"><h1>Too many sign-in attempts</h1><p>Please wait 15 minutes before trying again.</p></main>');
    }
    $s=$db->prepare('UPDATE login_attempts SET attempts=attempts+1 WHERE bucket=?');$s->execute([$bucket]);$db->commit();
}catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}

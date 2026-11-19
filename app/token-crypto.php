<?php
declare(strict_types=1);

function table_token_key(array $env): string
{
    $material=(string)($env['APP_KEY']??'');
    if ($material==='') throw new RuntimeException('APP_KEY is not configured.');
    return hash('sha256',$material,true);
}

function encrypt_table_token(string $token,array $env): string
{
    $iv=random_bytes(12);
    $tag='';
    $cipher=openssl_encrypt($token,'aes-256-gcm',table_token_key($env),OPENSSL_RAW_DATA,$iv,$tag,'restaurant-table-token');
    if ($cipher===false) throw new RuntimeException('Unable to protect table token.');
    return base64_encode($iv.$tag.$cipher);
}

function decrypt_table_token(?string $payload,array $env): ?string
{
    if (!$payload) return null;
    $raw=base64_decode($payload,true);
    if ($raw===false || strlen($raw)<29) return null;
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',table_token_key($env),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'restaurant-table-token');
    return $plain===false?null:$plain;
}

function table_public_hash(string $token,array $env): string
{
    return table_public_hash_from_digest(hash('sha256',$token),$env);
}

function table_public_hash_from_digest(string $tokenDigest,array $env): string
{
    return strtoupper(substr(hash_hmac('sha256',$tokenDigest,$env['APP_KEY']),0,16));
}

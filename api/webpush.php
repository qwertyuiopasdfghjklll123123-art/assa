<?php
/**
 * Minimal Web Push sender (RFC 8291 aes128gcm encryption + RFC 8292 VAPID), no dependencies.
 * Lets the restaurant send notifications that arrive on the customer's phone even when the app is closed.
 */

function b64u_enc(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** Wraps a raw uncompressed P-256 point (65 bytes) as a PEM public key. */
function p256_raw_to_pem(string $raw): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function p256_new_key(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($key)['ec'];
    $pad = fn($v) => str_pad($v, 32, "\0", STR_PAD_LEFT);
    openssl_pkey_export($key, $pem);
    return ['key' => $key, 'pem' => $pem, 'public' => "\x04" . $pad($d['x']) . $pad($d['y'])];
}

/** Loads (or creates on first use) the server's VAPID key pair. */
function vapid_keys(JsonDB $db): array
{
    $k = $db->get('secrets', 'vapid');
    if (!$k) {
        $n = p256_new_key();
        $k = ['pem' => $n['pem'], 'public' => b64u_enc($n['public'])];
        $db->merge('secrets', 'vapid', $k);
    }
    return $k;
}

/** ECDSA DER signature → raw r||s (64 bytes) as JWT ES256 expects. */
function der_to_raw_sig(string $der): string
{
    $o = 2;
    if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $rl = ord($der[$o + 1]);
    $r = substr($der, $o + 2, $rl);
    $o += 2 + $rl;
    $sl = ord($der[$o + 1]);
    $s = substr($der, $o + 2, $sl);
    $fix = fn($v) => str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
    return $fix($r) . $fix($s);
}

function vapid_header(array $vapid, string $endpoint, string $subject): string
{
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $h = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
    openssl_sign("$h.$c", $der, openssl_pkey_get_private($vapid['pem']), OPENSSL_ALGO_SHA256);
    return 'vapid t=' . "$h.$c." . b64u_enc(der_to_raw_sig($der)) . ', k=' . $vapid['public'];
}

/** Encrypts $payload for one subscription (RFC 8291, single aes128gcm record). */
function webpush_encrypt(string $payload, string $uaPublicB64, string $authB64): string
{
    $uaPublic = b64u_dec($uaPublicB64);
    $auth = b64u_dec($authB64);
    $as = p256_new_key();
    $secret = openssl_pkey_derive(openssl_pkey_get_public(p256_raw_to_pem($uaPublic)), $as['key'], 32);
    if ($secret === false) throw new RuntimeException('ecdh-failed');

    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $as['public'], $auth);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

    return $salt . pack('N', 4096) . chr(65) . $as['public'] . $cipher . $tag;
}

/**
 * Sends $message to every subscription; returns the endpoints that are gone (404/410)
 * so the caller can delete them.
 *
 * @param array<int, array{endpoint:string,p256dh:string,auth:string}> $subs
 */
function webpush_send_many(JsonDB $db, array $subs, array $message, string $subject): array
{
    if (!$subs || !function_exists('curl_multi_init')) return [];
    $vapid = vapid_keys($db);
    $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $mh = curl_multi_init();
    $handles = [];
    foreach ($subs as $s) {
        try {
            $body = webpush_encrypt($payload, $s['p256dh'], $s['auth']);
        } catch (Throwable) {
            continue;
        }
        $ch = curl_init($s['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 86400',
                'Urgency: high',
                'Authorization: ' . vapid_header($vapid, $s['endpoint'], $subject),
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = [$ch, $s['endpoint']];
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $status === CURLM_OK);

    $gone = [];
    foreach ($handles as [$ch, $endpoint]) {
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 404 || $code === 410) $gone[] = $endpoint;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $gone;
}

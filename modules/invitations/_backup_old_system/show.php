<?php
if (!isset($_SESSION)) { session_start(); }
require_once __DIR__ . '/../users/_guard_admin_general.php';
require_once __DIR__ . '/_helpers.php';
$pdo = DB::pdo();
$token = $_GET['token'] ?? '';
$q = $pdo->prepare("SELECT i.*, c.nombre as club_nombre, t.nombre as torneo_nombre
                    FROM invitations i
                    JOIN clubes c ON c.id=i.club_id
                    JOIN tournaments t ON t.id=i.torneo_id
                    WHERE i.token=:tk");
$q->execute([':tk'=>$token]);
$inv = $q->fetch();
if (!$inv) { exit('Invitación no encontrada'); }
$url = inv_public_url($inv['torneo_id'], $inv['club_id'], $inv['token']);
$wa_text = rawurlencode(
  "Hola " . ($inv['invitado_delegado'] ?? 'delegado') . ",\n" .
  "Te invitan al torneo '" . $inv['torneo_nombre'] . "' del club '" . $inv['club_nombre'] . "'.\n" .
  "V�lida del {$inv['acceso1']} al {$inv['acceso2']}.\n" .
  "Accede: {$url}\n\n" .
  "Usuario inicial: invitado{$inv['club_id']}\n" .
  "Clave: invi1234 (c�mbiala al ingresar)."
);
$wa = "https://wa.me/?text={$wa_text}";
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Invitación creada</title></head><body>
<h1>Invitación creada</h1>
<p><strong>Club:</strong> <?=htmlspecialchars($inv['club_nombre'])?></p>
<p><strong>Torneo:</strong> <?=htmlspecialchars($inv['torneo_nombre'])?></p>
<p><strong>Vigencia:</strong> <?=htmlspecialchars($inv['acceso1'])?> ? <?=htmlspecialchars($inv['acceso2'])?></p>
<p><strong>URL:</strong> <a href="<?=htmlspecialchars($url)?>"><?=htmlspecialchars($url)?></a></p>
<p>
  <a href="<?=$wa?>">Enviar por WhatsApp</a>
  <?php if (!empty($inv['invitado_email'])): ?>
    | <a href="send_email.php?token=<?=urlencode($inv['token'])?>">Enviar por Email</a>
  <?php endif; ?>
</p>
<p><a href="/modules/invitations/list.php">Volver</a></p>
</body></html>

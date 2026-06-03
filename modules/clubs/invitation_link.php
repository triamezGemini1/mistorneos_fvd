<?php
/**
 * Link de Invitación para Afiliarse al Club
 * Página dedicada para admin_club para compartir link de invitación
 * Diseño Moderno y Responsivo
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../lib/ClubHelper.php';

Auth::requireRole(['admin_club']);

$current_user = Auth::user();
$admin_club_user_id = Auth::id();
$user_club_id = $current_user['club_id'] ?? null;

// club_id por GET: permite ver el link de invitación de un club concreto (debe ser de la organización)
$requested_club_id = isset($_GET['club_id']) ? (int)$_GET['club_id'] : 0;
$club_id_to_use = $user_club_id;

if ($requested_club_id > 0) {
    if (ClubHelper::isClubManagedByAdmin($admin_club_user_id, $requested_club_id)) {
        $club_id_to_use = $requested_club_id;
    }
}

// Obtener información del club
$club_info = null;
$error_message = '';

try {
    if (!$club_id_to_use) {
        throw new Exception('No tienes una asociación asignada. Selecciónala en Asociaciones de la organización.');
    }
    
    $stmt = DB::pdo()->prepare("SELECT * FROM clubes WHERE id = ? AND estatus = 1");
    $stmt->execute([$club_id_to_use]);
    $club_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$club_info) {
        throw new Exception('El club no existe o está inactivo');
    }
} catch (Exception $e) {
    $error_message = $e->getMessage();
    error_log("Error obteniendo info del club: " . $e->getMessage());
}

// Generar link de invitación
$app_url = $_ENV['APP_URL'] ?? 'http://localhost/mistorneos_fvd';
$invitation_link = AppHelpers::url("register_by_club.php?club_id=") . $club_id_to_use;
$admin_nombre = $current_user['nombre'] ?? $current_user['username'] ?? 'Administrador';

// Obtener ruta del PDF de invitación
$pdf_path = null;
try {
    require_once __DIR__ . '/../../lib/InvitationPDFGenerator.php';
    $pdf_path = InvitationPDFGenerator::getClubPDFPath($club_id_to_use);
    // Si no existe el PDF, generarlo
    if (!$pdf_path) {
        $pdf_result = InvitationPDFGenerator::generateClubInvitationPDF($club_id_to_use);
        if ($pdf_result['success']) {
            $pdf_path = $pdf_result['pdf_path'];
        }
    }
} catch (Exception $e) {
    error_log("Error obteniendo PDF del club: " . $e->getMessage());
}
$pdf_url = $pdf_path ? ($app_url . '/' . $pdf_path) : null;
?>

<style>
:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --success-gradient: linear-gradient(135deg, #25D366 0%, #128C7E 100%);
    --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    --card-shadow-hover: 0 8px 30px rgba(0, 0, 0, 0.12);
    --border-radius: 16px;
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.invitation-page {
    background: var(--primary-gradient);
    min-height: 100vh;
    padding: 2rem 0;
    position: relative;
    overflow-x: hidden;
}

.invitation-page::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: 
        radial-gradient(circle at 20% 30%, rgba(37, 211, 102, 0.1) 0%, transparent 50%),
        radial-gradient(circle at 80% 70%, rgba(102, 126, 234, 0.1) 0%, transparent 50%);
    pointer-events: none;
}

.invitation-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 1.5rem;
    position: relative;
    z-index: 1;
}

/* Header Moderno */
.page-header {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    border-radius: var(--border-radius);
    padding: 3rem 2.5rem;
    margin-bottom: 2rem;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(255, 255, 255, 0.2);
    position: relative;
}

.page-header > div:first-child {
    text-align: center;
}

.page-header .icon-wrapper {
    width: 90px;
    height: 90px;
    background: var(--success-gradient);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    box-shadow: 0 8px 25px rgba(37, 211, 102, 0.35);
    position: relative;
    animation: float 3s ease-in-out infinite;
}

@keyframes float {
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-10px); }
}

.page-header .icon-wrapper::after {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    background: var(--success-gradient);
    opacity: 0.3;
    z-index: -1;
    animation: pulse-ring 2s ease-out infinite;
}

@keyframes pulse-ring {
    0% { transform: scale(0.8); opacity: 1; }
    100% { transform: scale(1.2); opacity: 0; }
}

.page-header .icon-wrapper i {
    font-size: 2.75rem;
    color: white;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
}

.page-header h1 {
    color: #1a202c;
    font-weight: 700;
    margin-bottom: 0.75rem;
    font-size: 2.25rem;
    letter-spacing: -0.5px;
}

.page-header p {
    color: #4a5568;
    font-size: 1.125rem;
    margin: 0;
    font-weight: 400;
}

/* Cards Modernas */
.modern-card {
    background: rgba(255, 255, 255, 0.98);
    backdrop-filter: blur(20px);
    border-radius: var(--border-radius);
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--card-shadow);
    border: 1px solid rgba(255, 255, 255, 0.2);
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.modern-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--success-gradient);
    transform: scaleX(0);
    transition: transform 0.3s ease;
}

.modern-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--card-shadow-hover);
}

.modern-card:hover::before {
    transform: scaleX(1);
}

.card-header-modern {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1.25rem;
    border-bottom: 2px solid #e2e8f0;
}

.card-header-modern .icon-box {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    flex-shrink: 0;
}

.card-header-modern .icon-box.primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.card-header-modern .icon-box.success { background: var(--success-gradient); }
.card-header-modern .icon-box.info { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }

.card-header-modern h2 {
    margin: 0;
    color: #1a202c;
    font-weight: 600;
    font-size: 1.5rem;
    letter-spacing: -0.3px;
}

/* Link Input Moderno */
.link-input-modern {
    position: relative;
    margin-bottom: 1rem;
}

.link-input-modern .input-wrapper {
    display: flex;
    gap: 0.75rem;
    align-items: stretch;
}

.link-input-modern input {
    flex: 1;
    padding: 1rem 1.25rem;
    font-size: 0.95rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    transition: var(--transition);
    background: #f7fafc;
    font-family: 'Monaco', 'Menlo', 'Courier New', monospace;
}

.link-input-modern input:focus {
    outline: none;
    border-color: #25D366;
    background: white;
    box-shadow: 0 0 0 4px rgba(37, 211, 102, 0.1);
}

.btn-modern {
    padding: 1rem 2rem;
    font-weight: 600;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.95rem;
    white-space: nowrap;
}

.btn-modern-success {
    background: var(--success-gradient);
    color: white;
    box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3);
}

.btn-modern-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(37, 211, 102, 0.4);
}

.btn-modern-outline {
    background: white;
    color: #25D366;
    border: 2px solid #25D366;
}

.btn-modern-outline:hover {
    background: #25D366;
    color: white;
    transform: translateY(-2px);
}

/* Mensaje Preview */
.message-preview-modern {
    background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.75rem;
    margin-bottom: 1.5rem;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    white-space: pre-wrap;
    line-height: 1.7;
    font-size: 0.95rem;
    color: #2d3748;
    max-height: 450px;
    overflow-y: auto;
    position: relative;
}

.message-preview-modern::-webkit-scrollbar {
    width: 8px;
}

.message-preview-modern::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 4px;
}

.message-preview-modern::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 4px;
}

.message-preview-modern::-webkit-scrollbar-thumb:hover {
    background: #a0aec0;
}

/* Info Card con Gradiente */
.info-card-gradient {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: var(--border-radius);
    padding: 2.5rem;
    margin-top: 1.5rem;
    box-shadow: var(--card-shadow);
    position: relative;
    overflow: hidden;
}

.info-card-gradient::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
    animation: rotate 20s linear infinite;
}

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.info-card-gradient h3 {
    color: white;
    margin-bottom: 1.75rem;
    font-weight: 600;
    font-size: 1.5rem;
    position: relative;
    z-index: 1;
}

.info-item-modern {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.25rem;
    position: relative;
    z-index: 1;
}

.info-item-modern:last-child {
    margin-bottom: 0;
}

.info-item-modern i {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
    backdrop-filter: blur(10px);
}

.info-item-modern strong {
    display: block;
    font-size: 0.875rem;
    opacity: 0.9;
    margin-bottom: 0.25rem;
}

.info-item-modern > div {
    flex: 1;
}

/* Action Buttons */
.action-buttons-modern {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 1.5rem;
}

.help-text-modern {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #718096;
    font-size: 0.875rem;
    margin-top: 0.75rem;
}

/* Responsive */
@media (max-width: 992px) {
    .invitation-container {
        padding: 0 1rem;
    }
    
    .page-header {
        padding: 2rem 1.5rem;
    }
    
    .page-header h1 {
        font-size: 1.75rem;
    }
    
    .page-header .d-flex {
        flex-direction: column;
        align-items: center !important;
    }
    
    .page-header .btn {
        margin-top: 1rem !important;
        width: 100%;
        max-width: 250px;
    }
    
    .modern-card {
        padding: 1.5rem;
    }
}

@media (max-width: 768px) {
    .invitation-page {
        padding: 1rem 0;
    }
    
    .page-header {
        padding: 1.5rem 1rem;
    }
    
    .page-header .icon-wrapper {
        width: 70px;
        height: 70px;
    }
    
    .page-header .icon-wrapper i {
        font-size: 2rem;
    }
    
    .page-header h1 {
        font-size: 1.5rem;
    }
    
    .modern-card {
        padding: 1.25rem;
    }
    
    .link-input-modern .input-wrapper {
        flex-direction: column;
    }
    
    .btn-modern {
        width: 100%;
        justify-content: center;
    }
    
    .action-buttons-modern {
        flex-direction: column;
    }
    
    .action-buttons-modern .btn-modern {
        width: 100%;
    }
}

/* Animaciones */
.fade-in {
    animation: fadeIn 0.5s ease-in;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<div class="invitation-page fade-in">
    <div class="invitation-container">
        <!-- Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="flex-grow-1">
                    <div class="icon-wrapper">
                        <i class="fab fa-whatsapp"></i>
                    </div>
                    <h1>Link de Invitación al Club</h1>
                    <p>Comparte este enlace para invitar a tus amigos a afiliarse a tu club</p>
                </div>
                <a href="?page=home" class="btn btn-outline-light btn-sm" style="white-space: nowrap; margin-top: 0.5rem;">
                    <i class="fas fa-arrow-left me-2"></i>Volver al Dashboard
                </a>
            </div>
        </div>
        
        <?php if ($error_message): ?>
            <div class="modern-card">
                <div class="alert alert-danger border-0 mb-0" style="background: #fee; color: #c33;">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Error:</strong> <?= htmlspecialchars($error_message) ?>
                </div>
            </div>
        <?php elseif ($club_info): ?>
            
            <!-- Card 1: Link de Invitación -->
            <div class="modern-card">
                <div class="card-header-modern">
                    <div class="icon-box success">
                        <i class="fas fa-link"></i>
                    </div>
                    <h2>Enlace de Registro</h2>
                </div>
                
                <p class="text-muted mb-4" style="color: #718096;">
                    <i class="fas fa-info-circle me-2 text-info"></i>
                    Copia este enlace y compártelo en grupos de WhatsApp, redes sociales o cualquier medio.
                </p>
                
                <div class="link-input-modern">
                    <label for="invitationLink" class="form-label fw-bold mb-2" style="color: #2d3748;">
                        Link de Invitación
                    </label>
                    <div class="input-wrapper">
                        <input type="text" 
                               class="form-control" 
                               id="invitationLink" 
                               value="<?= htmlspecialchars($invitation_link) ?>" 
                               readonly>
                        <button class="btn-modern btn-modern-success" 
                                type="button" 
                                onclick="copyInvitationLink()">
                            <i class="fas fa-copy"></i>
                            <span>Copiar</span>
                        </button>
                    </div>
                    <div class="help-text-modern">
                        <i class="fas fa-check-circle text-success" id="copySuccess" style="display: none;"></i>
                        <span id="copyMessage">Haz clic en "Copiar" para copiar al portapapeles</span>
                    </div>
                </div>
            </div>
            
            <!-- Card 2: Mensaje WhatsApp -->
            <div class="modern-card">
                <div class="card-header-modern">
                    <div class="icon-box success">
                        <i class="fab fa-whatsapp"></i>
                    </div>
                    <h2>Mensaje para WhatsApp</h2>
                </div>
                
                <p class="text-muted mb-4" style="color: #718096;">
                    <i class="fas fa-info-circle me-2 text-info"></i>
                    Usa este mensaje preformateado para invitar a tus amigos por WhatsApp.
                </p>
                
                <div class="message-preview-modern" id="whatsappMessage">
🎉 *¡INVITACIÓN A AFILIARTE!*

Hola, soy *<?= htmlspecialchars($admin_nombre) ?>*

Te invito a formar parte de nuestro club de dominó.

━━━━━━━━━━━━━━━━━━
🏢 *INFORMACIÓN DEL CLUB*
━━━━━━━━━━━━━━━━━━

📍 *Nombre:* <?= htmlspecialchars($club_info['nombre']) ?>

<?php if ($club_info['delegado']): ?>
👤 *Delegado:* <?= htmlspecialchars($club_info['delegado']) ?>

<?php endif; ?>
<?php if ($club_info['telefono']): ?>
📞 *Teléfono:* <?= htmlspecialchars($club_info['telefono']) ?>

<?php endif; ?>
<?php if ($club_info['direccion']): ?>
📍 *Dirección:* <?= htmlspecialchars($club_info['direccion']) ?>

<?php endif; ?>
━━━━━━━━━━━━━━━━━━
✨ *BENEFICIOS DE AFILIARTE*
━━━━━━━━━━━━━━━━━━

✅ Participar en torneos organizados
✅ Acceso a estadísticas y resultados
✅ Formar parte de nuestra comunidad
✅ Invitar a más amigos

━━━━━━━━━━━━━━━━━━
🔗 *REGÍSTRATE AQUÍ*
━━━━━━━━━━━━━━━━━━

Haz clic en el siguiente enlace para completar tu registro:

<?= htmlspecialchars($invitation_link) ?>

━━━━━━━━━━━━━━━━━━

¡Esperamos contar contigo! 🎲

_La Estación del Dominó_
                </div>
                
                <div class="action-buttons-modern">
                    <button class="btn-modern btn-modern-success" 
                            onclick="copyWhatsAppMessage()"
                            type="button">
                        <i class="fas fa-copy"></i>
                        <span>Copiar Mensaje</span>
                    </button>
                    <button class="btn-modern btn-modern-outline" 
                            onclick="shareToWhatsApp()"
                            type="button">
                        <i class="fab fa-whatsapp"></i>
                        <span>Compartir por WhatsApp</span>
                    </button>
                </div>
            </div>
            
            <!-- Card 3: Información del Club -->
            <div class="info-card-gradient">
                <h3><i class="fas fa-building me-2"></i>Información de tu Club</h3>
                <div class="info-item-modern">
                    <i class="fas fa-chess"></i>
                    <div>
                        <strong>Nombre</strong>
                        <div><?= htmlspecialchars($club_info['nombre']) ?></div>
                    </div>
                </div>
                <?php if ($club_info['delegado']): ?>
                <div class="info-item-modern">
                    <i class="fas fa-user-tie"></i>
                    <div>
                        <strong>Delegado</strong>
                        <div><?= htmlspecialchars($club_info['delegado']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($club_info['telefono']): ?>
                <div class="info-item-modern">
                    <i class="fas fa-phone"></i>
                    <div>
                        <strong>Teléfono</strong>
                        <div><?= htmlspecialchars($club_info['telefono']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($club_info['direccion']): ?>
                <div class="info-item-modern">
                    <i class="fas fa-map-marker-alt"></i>
                    <div>
                        <strong>Dirección</strong>
                        <div><?= htmlspecialchars($club_info['direccion']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
        <?php endif; ?>
    </div>
</div>

<script>
function copyInvitationLink() {
    const linkInput = document.getElementById('invitationLink');
    if (!linkInput) return;
    
    linkInput.select();
    linkInput.setSelectionRange(0, 99999);
    
    const text = linkInput.value;
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showCopySuccess();
        }).catch(() => {
            fallbackCopy(text);
        });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    const tempTextarea = document.createElement('textarea');
    tempTextarea.value = text;
    tempTextarea.style.position = 'fixed';
    tempTextarea.style.opacity = '0';
    document.body.appendChild(tempTextarea);
    tempTextarea.select();
    tempTextarea.setSelectionRange(0, 99999);
    
    try {
        document.execCommand('copy');
        document.body.removeChild(tempTextarea);
        showCopySuccess();
    } catch (err) {
        document.body.removeChild(tempTextarea);
        alert('Error al copiar. Por favor, selecciona y copia manualmente.');
    }
}

function showCopySuccess() {
    const copySuccess = document.getElementById('copySuccess');
    const copyMessage = document.getElementById('copyMessage');
    if (copySuccess && copyMessage) {
        copySuccess.style.display = 'inline';
        copyMessage.textContent = '¡Link copiado al portapapeles!';
        copyMessage.className = 'text-success';
        
        setTimeout(() => {
            copySuccess.style.display = 'none';
            copyMessage.textContent = 'Haz clic en "Copiar" para copiar al portapapeles';
            copyMessage.className = 'text-muted';
        }, 3000);
    }
}

function copyWhatsAppMessage() {
    const messageDiv = document.getElementById('whatsappMessage');
    if (!messageDiv) return;
    
    const text = messageDiv.textContent || messageDiv.innerText;
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showMessageCopySuccess();
        }).catch(() => {
            fallbackCopyMessage(text);
        });
    } else {
        fallbackCopyMessage(text);
    }
}

function fallbackCopyMessage(text) {
    const tempTextarea = document.createElement('textarea');
    tempTextarea.value = text;
    tempTextarea.style.position = 'fixed';
    tempTextarea.style.opacity = '0';
    document.body.appendChild(tempTextarea);
    tempTextarea.select();
    tempTextarea.setSelectionRange(0, 99999);
    
    try {
        document.execCommand('copy');
        document.body.removeChild(tempTextarea);
        showMessageCopySuccess();
    } catch (err) {
        document.body.removeChild(tempTextarea);
        alert('Error al copiar. Por favor, selecciona y copia manualmente.');
    }
}

function showMessageCopySuccess() {
    const btn = event.target.closest('button');
    if (btn) {
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i><span>¡Copiado!</span>';
        btn.classList.remove('btn-modern-success', 'btn-modern-outline');
        btn.style.background = 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)';
        btn.style.color = 'white';
        
        setTimeout(() => {
            btn.innerHTML = originalText;
            btn.style.background = '';
            btn.style.color = '';
            if (originalText.includes('Copiar Mensaje')) {
                btn.classList.add('btn-modern-success');
            } else {
                btn.classList.add('btn-modern-outline');
            }
        }, 2000);
    }
}

function shareToWhatsApp() {
    const messageDiv = document.getElementById('whatsappMessage');
    if (!messageDiv) return;
    
    let text = messageDiv.textContent || messageDiv.innerText;
    
    // Agregar información del PDF si está disponible
    const pdfUrl = '<?= $pdf_url ? htmlspecialchars($pdf_url, ENT_QUOTES, "UTF-8") : "" ?>';
    if (pdfUrl) {
        text += '\n\n━━━━━━━━━━━━━━━━━━\n';
        text += '📄 *DOCUMENTO PDF DE INVITACIÓN*\n';
        text += '━━━━━━━━━━━━━━━━━━\n\n';
        text += 'Descarga la invitación completa en formato PDF:\n';
        text += pdfUrl + '\n\n';
        text += 'El PDF incluye toda la información del club y el link de registro.';
    }
    
    const encodedText = encodeURIComponent(text);
    const whatsappUrl = 'https://wa.me/?text=' + encodedText;
    window.location.href = whatsappUrl;
}
</script>
